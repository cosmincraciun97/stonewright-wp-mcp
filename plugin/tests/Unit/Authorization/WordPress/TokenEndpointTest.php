<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\ClientDocuments;
use Stonewright\WpMcp\Authorization\WordPress\CredentialKeys;
use Stonewright\WpMcp\Authorization\WordPress\OAuthReply;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRequest;
use Stonewright\WpMcp\Authorization\WordPress\TokenEndpoint;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\LegacyRows;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\TokenEndpoint
 * @covers \Stonewright\WpMcp\Authorization\WordPress\OAuthRequest
 * @covers \Stonewright\WpMcp\Authorization\WordPress\OAuthReply
 */
final class TokenEndpointTest extends TestCase {

	private HttpRig $http;
	private string $client;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$this->http = new HttpRig();
		$this->client = $this->http->rig->register_client();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	private function endpoint(): TokenEndpoint {
		return new TokenEndpoint( $this->http->storage, $this->http->site );
	}

	/** @param array<string, string|list<string>> $fields */
	private function post( array $fields ): OAuthReply {
		return $this->endpoint()->handle( $this->http->form( $fields ) );
	}

	/** @return array<string, string> */
	private function code_fields( string $code, array $changes = [] ): array {
		return array_replace(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => StorageRig::REDIRECT,
				'client_id'     => $this->client,
				'code_verifier' => StorageRig::VERIFIER,
				'resource'      => StorageRig::RESOURCE,
			],
			$changes
		);
	}

	/** @return array<string, mixed> */
	private function connect(): array {
		$reply = $this->post( $this->code_fields( $this->http->rig->authorize( $this->client ) ) );
		self::assertSame( 200, $reply->status, (string) json_encode( $reply->body ) );
		return (array) $reply->body;
	}

	private function refresh( string $refresh_token, array $changes = [] ): OAuthReply {
		return $this->post( array_replace( [ 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => $this->client, 'resource' => StorageRig::RESOURCE ], $changes ) );
	}

	/** @return array<string, mixed> */
	private static function claims( string $jwt ): array {
		$part = explode( '.', $jwt )[1];
		return (array) json_decode( (string) base64_decode( strtr( $part, '-_', '+/' ) ), true );
	}

	private static function assert_error( OAuthReply $reply, int $status, string $error, ?string $reason = null ): void {
		self::assertSame( $status, $reply->status, (string) json_encode( $reply->body ) );
		self::assertSame( $error, $reply->body['error'] ?? null );
		self::assertIsString( $reply->body['error_description'] ?? null );
		self::assertSame( $reason, $reply->body['reason'] ?? null );
		self::assertSame( 'no-store', $reply->headers['Cache-Control'] );
		self::assertSame( 'no-cache', $reply->headers['Pragma'] );
		self::assertSame( '0', $reply->headers['X-Stonewright-Refresh-Consumed'] );
	}

	public function test_an_authorization_code_is_exchanged_for_the_observed_token_response(): void {
		$code = $this->http->rig->authorize( $this->client );
		$reply = $this->post( $this->code_fields( $code ) );

		self::assertSame( 200, $reply->status );
		self::assertSame( [ 'token_type', 'expires_in', 'access_token', 'refresh_token', 'refresh_token_expires_in', 'scope' ], array_keys( (array) $reply->body ) );
		self::assertSame( 'Bearer', $reply->body['token_type'] );
		self::assertSame( 3600, $reply->body['expires_in'] );
		self::assertSame( 2592000, $reply->body['refresh_token_expires_in'] );
		self::assertSame( 'mcp', $reply->body['scope'] );
		self::assertMatchesRegularExpression( '/^def50200[0-9a-f]+$/D', $reply->body['refresh_token'] );
		self::assertSame( [ 'Cache-Control' => 'no-store', 'Pragma' => 'no-cache', 'X-Stonewright-Refresh-Consumed' => '0' ], $reply->headers );
		$claims = self::claims( $reply->body['access_token'] );
		self::assertSame( StorageRig::RESOURCE, $claims['aud'] );
		self::assertSame( '7', $claims['sub'] );
		self::assertSame( [ 'mcp' ], $claims['scopes'] );
		self::assertSame( StorageRig::ISSUER, $claims['iss'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{80}$/D', $claims['jti'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T ), $this->http->rig->row( 'clients', 'client_id', $this->client )['last_used_at'] );
		self::assertSame( $this->client, $reply->audit['client_id'] );
		self::assertContains( $code, $reply->audit['sensitive_values'] );
		self::assertContains( StorageRig::VERIFIER, $reply->audit['sensitive_values'] );
	}

	public function test_the_resource_defaults_to_the_one_approved_at_consent(): void {
		$fields = $this->code_fields( $this->http->rig->authorize( $this->client ) );
		unset( $fields['resource'] );

		$reply = $this->post( $fields );

		self::assertSame( 200, $reply->status );
		self::assertSame( StorageRig::RESOURCE, self::claims( $reply->body['access_token'] )['aud'] );
	}

	/** @dataProvider missing_parameters */
	public function test_missing_parameters_are_an_invalid_request( string $parameter ): void {
		$fields = $this->code_fields( $this->http->rig->authorize( $this->client ) );
		unset( $fields[ $parameter ] );

		self::assert_error( $this->post( $fields ), 400, 'invalid_request' );
	}

	public function missing_parameters(): array {
		return [ [ 'grant_type' ], [ 'code' ], [ 'redirect_uri' ], [ 'client_id' ], [ 'code_verifier' ] ];
	}

	public function test_an_unknown_grant_type_is_unsupported(): void {
		self::assert_error( $this->post( [ 'grant_type' => 'client_credentials', 'client_id' => $this->client ] ), 400, 'unsupported_grant_type' );
	}

	public function test_repeated_or_malformed_parameters_and_other_encodings_are_refused(): void {
		$code = $this->http->rig->authorize( $this->client );
		self::assert_error( $this->post( $this->code_fields( $code, [ 'code' => [ $code, $code ] ] ) ), 400, 'invalid_request' );
		self::assert_error( $this->endpoint()->handle( $this->http->json( $this->code_fields( $code ) ) ), 400, 'invalid_request' );
		self::assert_error( $this->endpoint()->handle( new OAuthRequest( 'POST', [ 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ], 'grant_type=authorization_code&code=%zz', '192.0.2.10' ) ), 400, 'invalid_request' );
		self::assertSame( 200, $this->post( $this->code_fields( $code ) )->status );
	}

	public function test_an_unreadable_code_is_an_invalid_grant(): void {
		self::assert_error( $this->post( $this->code_fields( 'invalid-code-1' ) ), 400, 'invalid_grant' );
		self::assert_error( $this->post( $this->code_fields( LegacyRows::code_payload( (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) ) ) ), 400, 'invalid_grant' );
	}

	public function test_an_unknown_client_is_an_invalid_client(): void {
		$reply = $this->post( $this->code_fields( $this->http->rig->authorize( $this->client ), [ 'client_id' => 'ffffffffffffffffffffffffffffffff' ] ) );

		self::assert_error( $reply, 401, 'invalid_client' );
	}

	public function test_a_wrong_verifier_or_callback_is_refused_and_the_code_stays_usable(): void {
		$code = $this->http->rig->authorize( $this->client );

		self::assert_error( $this->post( $this->code_fields( $code, [ 'code_verifier' => str_repeat( 'w', 43 ) ] ) ), 400, 'invalid_grant' );
		self::assert_error( $this->post( $this->code_fields( $code, [ 'redirect_uri' => 'http://127.0.0.1:7998/callback' ] ) ), 400, 'invalid_grant' );
		self::assert_error( $this->post( $this->code_fields( $code, [ 'resource' => 'https://other.example.test/mcp' ] ) ), 400, 'invalid_target' );
		self::assertSame( 200, $this->post( $this->code_fields( $code ) )->status );
	}

	public function test_a_replayed_code_revokes_the_family_it_created(): void {
		$code = $this->http->rig->authorize( $this->client );
		$first = $this->post( $this->code_fields( $code ) );

		$replay = $this->post( $this->code_fields( $code ) );

		self::assert_error( $replay, 400, 'invalid_grant' );
		self::assertStringContainsString( 'revoked', (string) $replay->audit['body']['hint'] );
		self::assert_error( $this->refresh( $first->body['refresh_token'] ), 400, 'invalid_grant', 'refresh_token_revoked' );
		foreach ( $this->http->rig->rows( 'access_tokens' ) as $row ) {
			self::assertSame( '1', $row['revoked'] );
		}
	}

	public function test_a_refresh_rotates_and_reports_the_consumed_credential(): void {
		$tokens = $this->connect();
		$this->http->rig->at( StorageRig::T + 10 );

		$reply = $this->refresh( $tokens['refresh_token'] );

		self::assertSame( 200, $reply->status );
		self::assertSame( '1', $reply->headers['X-Stonewright-Refresh-Consumed'] );
		self::assertNotSame( $tokens['refresh_token'], $reply->body['refresh_token'] );
		self::assertSame( 2592000, $reply->body['refresh_token_expires_in'] );
		self::assertSame( 'mcp', $reply->body['scope'] );
		self::assertSame( [ 'token_type', 'expires_in', 'access_token', 'refresh_token', 'refresh_token_expires_in', 'scope' ], array_keys( (array) $reply->body ) );
		$this->expectException( OAuthFault::class );
		$this->http->storage->validator()->validate( $tokens['access_token'] );
	}

	public function test_a_duplicate_refresh_inside_the_window_delivers_the_current_credential_again(): void {
		$tokens = $this->connect();
		$this->http->rig->at( StorageRig::T + 10 );
		$rotated = $this->refresh( $tokens['refresh_token'] );
		$this->http->rig->at( StorageRig::T + 30 );

		$again = $this->refresh( $tokens['refresh_token'] );

		self::assertSame( 200, $again->status );
		self::assertSame( '1', $again->headers['X-Stonewright-Refresh-Consumed'] );
		self::assertSame( $this->http->storage->codec()->inspect( $rotated->body['refresh_token'] )->credential_key, $this->http->storage->codec()->inspect( $again->body['refresh_token'] )->credential_key );
		self::assertNotSame( $rotated->body['access_token'], $again->body['access_token'] );
		self::assertSame( 'refresh_redelivered', $again->audit['event'] );
		self::assertArrayNotHasKey( 'event', $rotated->audit );
		self::assertSame( 200, $this->refresh( $again->body['refresh_token'] )->status );
	}

	public function test_a_replayed_refresh_token_revokes_the_family_with_the_observed_reason(): void {
		$tokens = $this->connect();
		$this->http->rig->at( StorageRig::T + 10 );
		$rotated = $this->refresh( $tokens['refresh_token'] );
		$this->http->rig->at( StorageRig::T + 120 );

		$replay = $this->refresh( $tokens['refresh_token'] );

		self::assert_error( $replay, 400, 'invalid_grant', 'refresh_token_revoked' );
		self::assertSame( [ 'error' => 'invalid_grant', 'error_description' => 'The refresh token is no longer valid.', 'reason' => 'refresh_token_revoked' ], $replay->body );
		self::assertStringContainsString( 'replay', (string) $replay->audit['body']['hint'] );
		self::assert_error( $this->refresh( $rotated->body['refresh_token'] ), 400, 'invalid_grant', 'refresh_token_revoked' );
		$reasons = array_column( $this->http->rig->rows( 'refresh_tokens' ), 'revoked_reason' );
		self::assertContains( 'replayed', $reasons );
	}

	public function test_an_idle_expired_refresh_token_reports_its_expiry_without_revocation(): void {
		$tokens = $this->connect();
		$this->http->rig->at( StorageRig::T + 2592000 );

		self::assert_error( $this->refresh( $tokens['refresh_token'] ), 400, 'invalid_grant', 'refresh_token_expired' );
		self::assertSame( 'active', $this->http->rig->rows( 'families' )[0]['phase'] );
	}

	public function test_a_refresh_for_another_client_is_refused_without_revocation(): void {
		$tokens = $this->connect();
		$other = $this->http->rig->register_client( 'Other client' );

		self::assert_error( $this->refresh( $tokens['refresh_token'], [ 'client_id' => $other ] ), 400, 'invalid_grant' );
		self::assertSame( 200, $this->refresh( $tokens['refresh_token'] )->status );
	}

	public function test_a_refresh_without_client_id_uses_the_credential_client(): void {
		$tokens = $this->connect();

		$reply = $this->post( [ 'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'] ] );

		self::assertSame( 200, $reply->status );
		self::assertSame( $this->client, $reply->audit['client_id'] );
	}

	public function test_advertised_scopes_are_narrowed_and_unknown_scopes_refused(): void {
		$tokens = $this->connect();

		self::assert_error( $this->refresh( $tokens['refresh_token'], [ 'scope' => 'mcp admin' ] ), 400, 'invalid_scope' );
		$reply = $this->refresh( $tokens['refresh_token'], [ 'scope' => 'mcp offline_access' ] );
		self::assertSame( 200, $reply->status );
		self::assertSame( 'mcp', $reply->body['scope'] );
	}

	public function test_a_refresh_token_of_the_earlier_version_rotates_within_its_deadline(): void {
		$legacy = new LegacyRows( $this->http->rig->space, StorageRig::T );
		$legacy->client();
		$family = $legacy->family( 'f', 1, null, StorageRig::T - 100 );
		$token = LegacyRows::refresh_payload( $family, 1, (string) get_option( CredentialKeys::ENCRYPTION_KEY_OPTION ) );

		$reply = $this->refresh( $token, [ 'client_id' => LegacyRows::CLIENT ] );

		self::assertSame( 200, $reply->status, (string) json_encode( $reply->body ) );
		self::assertSame( $family['deadline'] - StorageRig::T, $reply->body['refresh_token_expires_in'] );
		self::assertSame( '1', $reply->headers['X-Stonewright-Refresh-Consumed'] );
		self::assertSame( 200, $this->refresh( $reply->body['refresh_token'], [ 'client_id' => LegacyRows::CLIENT ] )->status );
	}

	public function test_a_metadata_document_client_uses_its_url_at_the_token_endpoint(): void {
		$url = 'https://client.example.test/oauth/client.json';
		$this->http->publish_document( $url, [ 'client_id' => $url, 'client_name' => 'Document client', 'redirect_uris' => [ 'http://127.0.0.1/callback' ], 'token_endpoint_auth_method' => 'none' ] );
		$key = $this->http->documents->resolve( $url )['client_id'];
		$code = $this->http->rig->authorize( $key, [ 'registered_redirects' => [ 'http://127.0.0.1/callback' ] ] );

		$reply = $this->post( $this->code_fields( $code, [ 'client_id' => $url ] ) );

		self::assertSame( 200, $reply->status, (string) json_encode( $reply->body ) );
		self::assertSame( $url, $reply->audit['client_id'] );
		self::assertSame( ClientDocuments::client_key( $url ), self::claims( $reply->body['access_token'] )['client_id'] );
		self::assertSame( 200, $this->refresh( $reply->body['refresh_token'], [ 'client_id' => $url ] )->status );
		self::assert_error( $this->refresh( $reply->body['refresh_token'], [ 'client_id' => $key ] ), 401, 'invalid_client' );
	}

	public function test_lost_permission_is_an_invalid_grant_that_keeps_the_code(): void {
		$code = $this->http->rig->authorize( $this->client );
		$GLOBALS['stonewright_test_user_caps_by_id'] = [];

		self::assert_error( $this->post( $this->code_fields( $code ) ), 400, 'invalid_grant' );
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		self::assertSame( 200, $this->post( $this->code_fields( $code ) )->status );
	}

	public function test_a_storage_failure_is_a_server_error_that_commits_nothing(): void {
		$code = $this->http->rig->authorize( $this->client );
		$this->http->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO wptests_stonewright_oauth_refresh_tokens' );

		$logged = HttpRig::quietly( fn () => self::assert_error( $this->post( $this->code_fields( $code ) ), 500, 'server_error' ) );
		self::assertStringContainsString( 'oauth_token_request_failed', $logged );
		self::assertStringNotContainsString( $code, $logged );
		$this->http->rig->wpdb->fail_when = null;
		self::assertSame( 200, $this->post( $this->code_fields( $code ) )->status );
	}

	public function test_missing_signing_keys_are_a_server_error(): void {
		$code = $this->http->rig->authorize( $this->client );
		unset( $GLOBALS['stonewright_test_options'][ CredentialKeys::PRIVATE_KEY_OPTION ] );

		self::assert_error( $this->post( $this->code_fields( $code ) ), 500, 'server_error' );
	}
}
