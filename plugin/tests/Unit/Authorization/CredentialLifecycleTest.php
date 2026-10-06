<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Decisions\IntrospectionDecision;
use Stonewright\WpMcp\Authorization\Decisions\RevocationDecision;
use Stonewright\WpMcp\Authorization\Model\CredentialFacts;
use Stonewright\WpMcp\Authorization\Model\FamilyState;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Model\RotationDemand;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;
use Stonewright\WpMcp\Authorization\Refresh\RotationDecision;
use Stonewright\WpMcp\Tests\Unit\Authorization\Fixtures\SyntheticFamilies;

final class CredentialLifecycleTest extends TestCase {

	private function credential( string $kind = 'refresh_token' ): CredentialFacts {
		return new CredentialFacts( $kind, 'credential-a', 'family-a', 'client-a', 'subject-a', [ 'mcp', 'read' ], [ 'https://example.test/mcp' ], 1700003600 );
	}

	public function test_unknown_revocation_is_indistinguishable_from_success(): void {
		$decision = new RevocationDecision();
		$unknown = $decision->revoke( null, 'client-a', true, 'refresh_family' );
		$known = $decision->revoke( $this->credential(), 'client-a', true, 'refresh_family' );
		self::assertSame( $unknown['response'], $known['response'] );
		self::assertSame( 200, $known['response']['status'] );
		self::assertSame( [], $unknown['effects'] );
		self::assertSame( [ [ 'kind' => 'family', 'key' => 'family-a' ] ], $known['effects'] );
	}

	public function test_access_revocation_cascade_is_an_explicit_policy(): void {
		$decision = new RevocationDecision();
		self::assertSame( [ [ 'kind' => 'credential', 'key' => 'credential-a' ] ], $decision->revoke( $this->credential( 'access_token' ), 'client-a', true, 'refresh_family' )['effects'] );
		self::assertSame( [ [ 'kind' => 'family', 'key' => 'family-a' ] ], $decision->revoke( $this->credential( 'access_token' ), 'client-a', true, 'family_all_tokens' )['effects'] );
	}

	/** @dataProvider invalid_revocation_callers */
	public function test_revocation_authenticates_before_the_unknown_token_response( ?CredentialFacts $facts, string $client, bool $authorized ): void {
		$this->expectException( OAuthFault::class );
		( new RevocationDecision() )->revoke( $facts, $client, $authorized, 'refresh_family' );
	}

	public function invalid_revocation_callers(): array {
		return [ [ null, 'client-a', false ], [ $this->credential(), 'client-b', true ], [ null, '', true ] ];
	}

	public function test_introspection_discloses_only_allowed_claims_and_never_internal_keys(): void {
		$result = ( new IntrospectionDecision() )->inspect( $this->credential( 'access_token' ), true, true, false, 1700000000, [ 'client_id', 'scope', 'exp', 'aud' ] );
		self::assertEquals( [ 'active' => true, 'client_id' => 'client-a', 'scope' => 'mcp read', 'exp' => 1700003600, 'aud' => [ 'https://example.test/mcp' ] ], $result );
		self::assertArrayNotHasKey( 'family_key', $result );
		self::assertArrayNotHasKey( 'sub', $result );
	}

	public function test_unknown_revoked_expired_and_undisclosable_credentials_are_inactive(): void {
		$decision = new IntrospectionDecision();
		self::assertSame( [ 'active' => false ], $decision->inspect( null, true, true, false, 1700000000, [] ) );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, true, 1700000000, [] ) );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700003600, [] ) );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, false, false, 1700000000, [] ) );
		$this->expectException( OAuthFault::class );
		$decision->inspect( null, false, false, false, 1700000000, [] );
	}

	public function test_refresh_credentials_are_active_only_as_the_live_head_of_their_family(): void {
		$decision = new IntrospectionDecision();
		$head = static fn ( int $expires_at, array $changes = [] ): FamilyState => SyntheticFamilies::initial( array_replace( [ 'current_refresh_key' => 'credential-a', 'credential_history' => [ 'credential-a' => [ 'expires_at' => $expires_at, 'consumed' => false, 'successor_key' => null ] ] ], $changes ) );
		$family = $head( 1700003600 );
		self::assertTrue( $decision->inspect( $this->credential(), true, true, false, 1700000000, [], $family )['active'] );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700000000, [] ), 'Refresh activity needs family facts.' );
		$rotated = ( new RotationDecision( new RefreshPolicy() ) )->decide( $family, new RotationDemand( 'family-a', 'credential-a', 'client-a', [ 'https://example.test/mcp' ], null ), 1700000000, 'access-x', 'credential-b' )->state;
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700000001, [], $rotated ), 'Consumed, even inside the duplicate window.' );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700000500, [], $head( 1700000500 ) ), 'Idle-expired.' );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700001000, [], $head( 1700001000, [ 'family_deadline' => 1700001000 ] ) ), 'Family deadline passed.' );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700000000, [], $family->revoke() ), 'Revoked family.' );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700000000, [], $head( 1700003600, [ 'family_key' => 'family-b' ] ) ), 'Another family.' );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential(), true, true, false, 1700000000, [], $head( 1700003600, [ 'client_key' => 'client-b' ] ) ), 'Another client.' );
	}

	public function test_access_credentials_keep_their_own_expiry(): void {
		$decision = new IntrospectionDecision();
		$expired_family = SyntheticFamilies::initial( [ 'family_deadline' => 1700001000, 'credential_history' => [ 'refresh-0' => [ 'expires_at' => 1700001000, 'consumed' => false, 'successor_key' => null ] ] ] );
		self::assertTrue( $decision->inspect( $this->credential( 'access_token' ), true, true, false, 1700002000, [], $expired_family )['active'] );
		self::assertSame( [ 'active' => false ], $decision->inspect( $this->credential( 'access_token' ), true, true, false, 1700003600, [], $expired_family ) );
	}
}
