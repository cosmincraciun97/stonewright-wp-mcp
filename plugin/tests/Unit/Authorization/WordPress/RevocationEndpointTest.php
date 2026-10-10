<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\OAuthReply;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRequest;
use Stonewright\WpMcp\Authorization\WordPress\RevocationEndpoint;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\RevocationEndpoint
 */
final class RevocationEndpointTest extends TestCase {

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$this->http = new HttpRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/** @param array<string, string|list<string>> $fields */
	private function revoke( array $fields ): OAuthReply {
		return ( new RevocationEndpoint( $this->http->storage->revocation() ) )->handle( $this->http->form( $fields ) );
	}

	private static function assert_ok( OAuthReply $reply ): void {
		self::assertSame( 200, $reply->status );
		self::assertNull( $reply->body );
		self::assertSame( 'no-store', $reply->headers['Cache-Control'] );
	}

	private function assert_family_closed(): void {
		foreach ( $this->http->rig->rows( 'refresh_tokens' ) as $row ) {
			self::assertSame( '1', $row['revoked'] );
		}
		foreach ( $this->http->rig->rows( 'access_tokens' ) as $row ) {
			self::assertSame( '1', $row['revoked'] );
		}
		self::assertSame( 'revoked', $this->http->rig->rows( 'families' )[0]['phase'] );
	}

	public function test_revoking_a_refresh_token_closes_the_whole_family(): void {
		[ $client, $pair ] = $this->http->rig->connect();

		$reply = $this->revoke( [ 'token' => $pair->refresh_token, 'token_type_hint' => 'refresh_token', 'client_id' => $client ] );

		self::assert_ok( $reply );
		$this->assert_family_closed();
		self::assertContains( $pair->refresh_token, $reply->audit['sensitive_values'] );
		self::assertSame( $client, $reply->audit['client_id'] );
		$this->expectException( OAuthFault::class );
		$this->http->storage->validator()->validate( $pair->access_token );
	}

	public function test_revoking_an_access_token_closes_the_whole_family(): void {
		[ $client, $pair ] = $this->http->rig->connect();

		self::assert_ok( $this->revoke( [ 'token' => $pair->access_token, 'token_type_hint' => 'access_token', 'client_id' => $client ] ) );

		$this->assert_family_closed();
		self::assertNotNull( $this->http->rig->refresh( $pair->refresh_token, $client )->fault );
	}

	public function test_a_mismatched_client_changes_nothing_and_still_answers_200(): void {
		[ $client, $pair ] = $this->http->rig->connect();

		self::assert_ok( $this->revoke( [ 'token' => $pair->refresh_token, 'client_id' => 'ffffffffffffffffffffffffffffffff' ] ) );

		self::assertSame( 'active', $this->http->rig->rows( 'families' )[0]['phase'] );
		self::assertNull( $this->http->rig->refresh( $pair->refresh_token, $client )->fault );
	}

	public function test_the_client_is_named_in_the_audit_facts_only_for_a_recognized_credential(): void {
		[ $client, $pair ] = $this->http->rig->connect();

		$unknown = $this->revoke( [ 'token' => 'not-a-token', 'client_id' => 'made-up-client' ] );
		$mismatched = $this->revoke( [ 'token' => $pair->refresh_token, 'client_id' => 'ffffffffffffffffffffffffffffffff' ] );
		$anonymous = $this->revoke( [ 'token' => 'not-a-token' ] );
		foreach ( [ $unknown, $mismatched, $anonymous ] as $reply ) {
			self::assert_ok( $reply );
			self::assertSame( '', $reply->audit['client_id'] );
			self::assertArrayNotHasKey( 'event', $reply->audit );
		}
		self::assertSame( 'active', $this->http->rig->rows( 'families' )[0]['phase'], 'A mismatched client changes nothing.' );

		$recognized = $this->revoke( [ 'token' => $pair->refresh_token, 'client_id' => $client ] );

		self::assert_ok( $recognized );
		self::assertSame( $client, $recognized->audit['client_id'] );
		self::assertSame( 'revocation', $recognized->audit['event'] );
	}

	public function test_unknown_missing_and_malformed_requests_answer_200(): void {
		self::assert_ok( $this->revoke( [ 'token' => 'not-a-token' ] ) );
		self::assert_ok( $this->revoke( [ 'token_type_hint' => 'refresh_token' ] ) );
		self::assert_ok( $this->revoke( [ 'token' => [ 'one', 'two' ] ] ) );
		self::assert_ok( ( new RevocationEndpoint( $this->http->storage->revocation() ) )->handle( new OAuthRequest( 'POST', [], 'token=%zz', '192.0.2.10' ) ) );
	}

	public function test_a_refresh_token_of_the_earlier_version_can_be_revoked(): void {
		$legacy = new LegacyRows( $this->http->rig->space, StorageRig::T );
		$legacy->client();
		$family = $legacy->family( 'f', 0, null, StorageRig::T - 100 );
		$token = LegacyRows::refresh_payload( $family, 0, (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );

		self::assert_ok( $this->revoke( [ 'token' => $token, 'client_id' => LegacyRows::CLIENT ] ) );

		foreach ( $this->http->rig->rows( 'refresh_tokens' ) as $row ) {
			self::assertSame( '1', $row['revoked'] );
		}
	}

	public function test_a_storage_failure_is_temporarily_unavailable(): void {
		[ $client, $pair ] = $this->http->rig->connect();
		$this->http->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'UPDATE' );

		$reply = null;
		HttpRig::quietly(
			function () use ( $pair, $client, &$reply ): void {
				$reply = $this->revoke( [ 'token' => $pair->refresh_token, 'client_id' => $client ] );
			}
		);

		self::assertInstanceOf( OAuthReply::class, $reply );
		self::assertSame( 503, $reply->status );
		self::assertSame( 'temporarily_unavailable', $reply->body['error'] );
	}
}
