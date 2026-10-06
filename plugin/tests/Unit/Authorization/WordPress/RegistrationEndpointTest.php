<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\OAuthReply;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRequest;
use Stonewright\WpMcp\Authorization\WordPress\RegistrationEndpoint;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\RegistrationEndpoint
 */
final class RegistrationEndpointTest extends TestCase {

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		$this->http = new HttpRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	private function register( array $document, array $headers = [] ): OAuthReply {
		return ( new RegistrationEndpoint( $this->http->storage->clients(), $this->http->site, $this->http->rig->clock ) )->handle( $this->http->json( $document, $headers ) );
	}

	/** @return array<string, mixed> */
	private static function document( array $changes = [] ): array {
		return array_replace(
			[
				'client_name'                => 'Synthetic MCP client',
				'redirect_uris'              => [ 'http://127.0.0.1:7999/callback' ],
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
			],
			$changes
		);
	}

	public function test_a_public_client_registers_with_the_observed_response(): void {
		$reply = $this->register( self::document() );

		self::assertSame( 201, $reply->status );
		self::assertSame( [ 'client_id', 'client_name', 'redirect_uris', 'token_endpoint_auth_method', 'grant_types', 'response_types' ], array_keys( (array) $reply->body ) );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/D', $reply->body['client_id'] );
		self::assertSame( 'Synthetic MCP client', $reply->body['client_name'] );
		self::assertSame( [ 'http://127.0.0.1:7999/callback' ], $reply->body['redirect_uris'] );
		self::assertSame( 'none', $reply->body['token_endpoint_auth_method'] );
		self::assertSame( 'no-store', $reply->headers['Cache-Control'] );
		self::assertSame( $reply->body['client_id'], $reply->audit['client_id'] );
		$row = $this->http->rig->row( 'clients', 'client_id', $reply->body['client_id'] );
		self::assertSame( '["http://127.0.0.1:7999/callback"]', $row['redirect_uris'] );
		self::assertSame( RowKeys::address( '192.0.2.10' ), $row['registered_by_ip_hash'] );
		self::assertNotSame( hash( 'sha256', '192.0.2.10' ), $row['registered_by_ip_hash'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T ), $row['created_at'] );
		self::assertNull( $row['last_used_at'] );
	}

	public function test_an_omitted_authentication_method_registers_a_public_client(): void {
		$document = self::document();
		unset( $document['token_endpoint_auth_method'], $document['grant_types'], $document['response_types'] );

		$reply = $this->register( $document );

		self::assertSame( 201, $reply->status );
		self::assertSame( 'none', $reply->body['token_endpoint_auth_method'] );
		self::assertSame( [ 'authorization_code' ], $reply->body['grant_types'] );
	}

	public function test_callbacks_of_real_clients_register(): void {
		$redirects = [ 'http://localhost:8787/callback', 'http://127.0.0.1:33418', 'http://[::1]:8123/callback', 'https://editor.example.test/redirect', 'http://localhost:54321/oauth/callback' ];

		$reply = $this->register( self::document( [ 'redirect_uris' => $redirects ] ) );

		self::assertSame( 201, $reply->status );
		self::assertSame( $redirects, $reply->body['redirect_uris'] );
	}

	/** @dataProvider refused_registrations */
	public function test_refused_registrations_follow_rfc_7591_and_store_nothing( string $body, string $error ): void {
		$reply = ( new RegistrationEndpoint( $this->http->storage->clients(), $this->http->site, $this->http->rig->clock ) )->handle( new OAuthRequest( 'POST', [ 'content-type' => 'application/json' ], $body, '192.0.2.10' ) );

		self::assertSame( 400, $reply->status );
		self::assertSame( $error, $reply->body['error'] );
		self::assertIsString( $reply->body['error_description'] );
		self::assertSame( 'no-store', $reply->headers['Cache-Control'] );
		self::assertSame( [], $this->http->rig->rows( 'clients' ) );
	}

	public function refused_registrations(): array {
		$json = static fn ( array $changes ): string => (string) json_encode( self::document( $changes ), JSON_UNESCAPED_SLASHES );
		return [
			'fragment callback'      => [ $json( [ 'redirect_uris' => [ 'https://client.example.test/cb#x' ] ] ), 'invalid_redirect_uri' ],
			'plain http callback'    => [ $json( [ 'redirect_uris' => [ 'http://client.example.test/cb' ] ] ), 'invalid_redirect_uri' ],
			'no callbacks'           => [ $json( [ 'redirect_uris' => [] ] ), 'invalid_redirect_uri' ],
			'confidential client'    => [ $json( [ 'token_endpoint_auth_method' => 'client_secret_basic' ] ), 'invalid_client_metadata' ],
			'implicit grant'         => [ $json( [ 'grant_types' => [ 'implicit' ] ] ), 'invalid_client_metadata' ],
			'unknown scope'          => [ $json( [ 'scope' => 'mcp admin' ] ), 'invalid_client_metadata' ],
			'script link'            => [ $json( [ 'client_uri' => 'javascript:alert(1)' ] ), 'invalid_client_metadata' ],
			'not json'               => [ 'client_name=x', 'invalid_request' ],
			'json list'              => [ '[]', 'invalid_request' ],
			'repeated member'        => [ '{"redirect_uris":["http://127.0.0.1/cb"],"redirect_uris":["https://attacker.example.test/cb"]}', 'invalid_request' ],
			'oversized body'         => [ $json( [ 'client_name' => str_repeat( 'n', 40000 ) ] ), 'invalid_request' ],
		];
	}

	public function test_a_long_client_name_is_kept_in_the_profile_and_cut_for_the_column(): void {
		$name = str_repeat( 'Name ', 200 );

		$reply = $this->register( self::document( [ 'client_name' => $name ] ) );

		self::assertSame( 201, $reply->status );
		self::assertSame( $name, $reply->body['client_name'] );
		self::assertSame( 191, strlen( (string) $this->http->rig->row( 'clients', 'client_id', $reply->body['client_id'] )['client_name'] ) );
		self::assertSame( $name, $this->http->storage->clients()->find( $reply->body['client_id'] )['client_name'] );
	}

	public function test_a_diagnostics_self_test_registration_is_removed_before_the_response(): void {
		$token = str_repeat( 'ab', 16 );
		$hash = hash( 'sha256', $token );
		set_transient( RegistrationEndpoint::SELF_TEST_TRANSIENT . $hash, $hash, 30 );

		$reply = $this->register( self::document( [ 'client_name' => 'Stonewright diagnostics' ] ), [ 'X-Stonewright-Self-Test' => $token ] );

		self::assertSame( 201, $reply->status );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/D', $reply->body['client_id'] );
		self::assertSame( [], $this->http->rig->rows( 'clients' ) );
		self::assertSame( 0, $this->http->storage->clients()->self_test_clients() );
		self::assertFalse( get_transient( RegistrationEndpoint::SELF_TEST_TRANSIENT . $hash ) );

		$second = $this->register( self::document(), [ 'X-Stonewright-Self-Test' => $token ] );
		self::assertSame( 201, $second->status );
		self::assertCount( 1, $this->http->rig->rows( 'clients' ) );
	}

	public function test_a_self_test_that_cannot_remove_its_client_stays_marked_for_cleanup(): void {
		$token = str_repeat( 'cd', 16 );
		$hash = hash( 'sha256', $token );
		set_transient( RegistrationEndpoint::SELF_TEST_TRANSIENT . $hash, $hash, 30 );
		$this->http->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_starts_with( $sql, 'DELETE FROM wptests_stonewright_oauth_clients' );

		HttpRig::quietly( fn () => $this->register( self::document(), [ 'X-Stonewright-Self-Test' => $token ] ) );

		$this->http->rig->wpdb->fail_when = null;
		self::assertSame( 1, $this->http->storage->clients()->self_test_clients() );
		self::assertSame( 1, $this->http->storage->clients()->prune( StorageRig::T + 61 ) );
		self::assertSame( 0, $this->http->storage->clients()->self_test_clients() );
	}
}
