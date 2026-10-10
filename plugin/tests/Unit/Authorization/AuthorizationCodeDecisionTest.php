<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Decisions\AuthorizationCodeDecision;
use Stonewright\WpMcp\Authorization\Model\CodeGrantState;
use Stonewright\WpMcp\Authorization\Model\CodeDemand;
use Stonewright\WpMcp\Authorization\Protocol\CodeProof;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;

final class AuthorizationCodeDecisionTest extends TestCase {

	public static function code( array $changes = [] ): CodeGrantState {
		return CodeGrantState::from_array( array_replace( [ 'code_key' => 'code-a', 'client_key' => 'client-a', 'subject_key' => 'subject-a', 'redirect_uri' => 'http://127.0.0.1:49152/callback', 'code_challenge' => ( new CodeProof() )->challenge( str_repeat( 'a', 43 ) ), 'scopes' => [ 'mcp', 'read' ], 'resources' => [ 'https://example.test/mcp' ], 'expires_at' => 1700000300, 'used' => false, 'issued_family_key' => null ], $changes ) );
	}

	public static function demand( array $changes = [] ): CodeDemand {
		$data = array_replace( [ 'code_key' => 'code-a', 'client_key' => 'client-a', 'redirect_uri' => 'http://127.0.0.1:49152/callback', 'verifier' => str_repeat( 'a', 43 ), 'resources' => [ 'https://example.test/mcp' ] ], $changes );
		return new CodeDemand( $data['code_key'], $data['client_key'], $data['redirect_uri'], $data['verifier'], $data['resources'] );
	}

	public static function decision(): AuthorizationCodeDecision {
		return new AuthorizationCodeDecision( new RefreshPolicy() );
	}

	public function test_redemption_consumes_one_code_and_starts_a_ninety_day_family_with_a_thirty_day_credential(): void {
		$result = self::decision()->redeem( self::code(), self::demand(), 1700000000, 'family-a', 'access-a', 'refresh-a' );
		self::assertNull( $result->fault );
		self::assertTrue( $result->state->to_array()['used'] );
		self::assertSame( 'family-a', $result->state->to_array()['issued_family_key'] );
		self::assertSame( 1700003600, $result->issuance->access_deadline );
		self::assertSame( 1700000000 + 7776000, $result->family->to_array()['family_deadline'] );
		self::assertSame( 1700000000 + 2592000, $result->issuance->refresh_deadline );
		self::assertSame( [ 'expires_at' => 1700000000 + 2592000, 'consumed' => false, 'consumed_at' => null, 'successor_key' => null ], $result->family->to_array()['credential_history']['refresh-a'] );
		self::assertFalse( $result->issuance->redelivery );
		self::assertSame( [ 'mcp', 'read' ], $result->issuance->access_scopes );
		self::assertSame( $result->state->to_array(), CodeGrantState::from_array( $result->state->to_array() )->to_array() );
	}

	public function test_reuse_denies_a_second_family_and_requests_revocation_of_the_original(): void {
		$decision = self::decision();
		$first = $decision->redeem( self::code(), self::demand(), 1700000000, 'family-a', 'access-a', 'refresh-a' );
		$repeat = $decision->redeem( $first->state, self::demand(), 1700000001, 'family-b', 'access-b', 'refresh-b' );
		self::assertSame( 'invalid_grant', $repeat->fault->error() );
		self::assertNull( $repeat->family );
		self::assertNull( $repeat->issuance );
		self::assertSame( 'family-a', $repeat->revoke_family_key );
	}

	/** @dataProvider rejected_demands */
	public function test_wrong_bindings_and_expiry_do_not_consume_a_code( array $changes, int $now ): void {
		$code = self::code();
		$result = self::decision()->redeem( $code, self::demand( $changes ), $now, 'family-a', 'access-a', 'refresh-a' );
		self::assertNotNull( $result->fault );
		self::assertSame( $code->to_array(), $result->state->to_array() );
		self::assertNull( $result->issuance );
		self::assertNull( $result->revoke_family_key );
	}

	public function rejected_demands(): array {
		return [ [ [ 'client_key' => 'client-b' ], 1700000000 ], [ [ 'code_key' => 'code-b' ], 1700000000 ], [ [ 'redirect_uri' => 'http://127.0.0.1:49153/callback' ], 1700000000 ], [ [ 'verifier' => str_repeat( 'b', 43 ) ], 1700000000 ], [ [ 'resources' => [ 'https://example.test/other' ] ], 1700000000 ], [ [], 1700000300 ] ];
	}

	public function test_serialized_code_cannot_claim_used_without_a_family(): void {
		$this->expectException( \InvalidArgumentException::class );
		self::code( [ 'used' => true ] );
	}

	public function test_unused_code_may_be_forgotten_at_expiry_but_a_used_code_is_retained_for_replay_detection(): void {
		self::assertSame( 1700000300, self::code()->retained_until() );
		$used = self::decision()->redeem( self::code(), self::demand(), 1700000000, 'family-a', 'access-a', 'refresh-a' )->state;
		self::assertSame( 1700000300 + CodeGrantState::USED_CODE_RETENTION, $used->retained_until() );
		self::assertGreaterThanOrEqual( 3600, CodeGrantState::USED_CODE_RETENTION );
	}

	public function test_identifier_failure_is_a_server_error_with_http_500(): void {
		$result = self::decision()->redeem( self::code(), self::demand(), 1700000000, 'family-a', 'family-a', 'refresh-a' );
		self::assertSame( 'server_error', $result->fault->error() );
		self::assertSame( 500, $result->fault->status() );
		self::assertFalse( $result->state->to_array()['used'] );
	}
}
