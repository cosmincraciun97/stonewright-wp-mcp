<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\WordPress\ClientDocumentRefusals;
use Stonewright\WpMcp\Authorization\WordPress\ClientDocuments;
use Stonewright\WpMcp\Authorization\WordPress\ClientStore;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ClientDocuments
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ClientDocumentRefusals
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ClientStore
 */
final class ClientDocumentsTest extends TestCase {

	private const URL = 'https://client.example.test/oauth/client.json';
	private const CLAUDE_URL = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	private const CLAUDE_DOCUMENT = '{"client_id":"https://claude.ai/oauth/mcp-oauth-client-metadata","client_name":"Claude","client_uri":"https://claude.ai","redirect_uris":["https://claude.ai/api/mcp/auth_callback"],"grant_types":["authorization_code","refresh_token","urn:ietf:params:oauth:grant-type:jwt-bearer"],"response_types":["code"],"token_endpoint_auth_method":"none"}';

	private HttpRig $http;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$this->http = new HttpRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/** @return array<string, mixed> */
	private static function document( array $changes = [] ): array {
		return array_replace(
			[
				'client_id'                  => self::URL,
				'client_name'                => 'Example <b>Client</b>',
				'client_uri'                 => 'https://client.example.test/',
				'redirect_uris'              => [ 'http://127.0.0.1/callback', 'http://localhost/callback' ],
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
			],
			$changes
		);
	}

	/** @dataProvider document_urls */
	public function test_only_https_urls_with_a_path_identify_documents( string $client_id, bool $document ): void {
		self::assertSame( $document, ClientDocuments::is_document_url( $client_id ) );
	}

	public function document_urls(): array {
		return [
			'path'                => [ self::URL, true ],
			'path and query'      => [ 'https://client.example.test/oauth/metadata?variant=a', true ],
			'explicit port 443'   => [ 'https://client.example.test:443/oauth/client.json', true ],
			'registered id'       => [ '0123456789abcdef0123456789abcdef', false ],
			'plain http'          => [ 'http://client.example.test/oauth/client.json', false ],
			'no path'             => [ 'https://client.example.test', false ],
			'root path'           => [ 'https://client.example.test/', false ],
			'user information'    => [ 'https://user@client.example.test/oauth/client.json', false ],
			'dot segment'         => [ 'https://client.example.test/oauth/./client.json', false ],
			'dot-dot segment'     => [ 'https://client.example.test/oauth/../client.json', false ],
			'encoded dot segment' => [ 'https://client.example.test/oauth/%2e%2e/client.json', false ],
			'fragment'            => [ 'https://client.example.test/oauth/client.json#x', false ],
			'other port'          => [ 'https://client.example.test:8443/oauth/client.json', false ],
			'ipv4 literal'        => [ 'https://203.0.113.5/oauth/client.json', false ],
			'ipv6 literal'        => [ 'https://[2001:db8::1]/oauth/client.json', false ],
			'single label host'   => [ 'https://intranet/oauth/client.json', false ],
			'whitespace'          => [ "https://client.example.test/oauth/client.json\n", false ],
			'empty'               => [ '', false ],
		];
	}

	public function test_document_clients_get_a_stable_key_that_fits_the_client_column(): void {
		$key = ClientDocuments::client_key( self::URL );

		self::assertSame( RowKeys::client_document( self::URL ), $key );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', $key );
		self::assertNotSame( $key, ClientDocuments::client_key( self::URL . '?v=2' ) );
		self::assertSame( '0123456789abcdef0123456789abcdef', ClientDocuments::client_key( '0123456789abcdef0123456789abcdef' ) );
	}

	public function test_a_fetched_document_becomes_a_stored_public_client(): void {
		$this->http->publish_document( self::URL, self::document() );

		$client = $this->http->documents->resolve( self::URL );

		self::assertSame( ClientDocuments::client_key( self::URL ), $client['client_id'] );
		self::assertSame( self::URL, $client['client_id_metadata_document'] );
		self::assertSame( 'Example <b>Client</b>', $client['client_name'] );
		self::assertSame( [ 'http://127.0.0.1/callback', 'http://localhost/callback' ], $client['redirect_uris'] );
		self::assertSame( 'none', $client['token_endpoint_auth_method'] );
		self::assertSame( [ [ self::URL, '93.184.216.34' ] ], $this->http->fetches );
		$row = $this->http->rig->row( 'clients', 'client_id', ClientDocuments::client_key( self::URL ) );
		self::assertSame( ClientStore::DOCUMENT_PURPOSE, $row['registration_purpose'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 3600 ), $row['registration_expires_at'] );
		self::assertSame( '0', $row['is_confidential'] );
		self::assertSame( '["http://127.0.0.1/callback","http://localhost/callback"]', $row['redirect_uris'] );
	}

	public function test_a_cached_document_is_reused_until_its_lifetime_ends(): void {
		$this->http->publish_document( self::URL, self::document() );
		$this->http->documents->resolve( self::URL );
		$this->http->rig->at( StorageRig::T + 3599 );
		$this->http->documents->resolve( self::URL );
		self::assertCount( 1, $this->http->fetches );

		$this->http->publish_document( self::URL, self::document( [ 'client_name' => 'Renamed client' ] ) );
		$this->http->rig->at( StorageRig::T + 3600 );
		$client = $this->http->documents->resolve( self::URL );

		self::assertCount( 2, $this->http->fetches );
		self::assertSame( 'Renamed client', $client['client_name'] );
		self::assertCount( 1, $this->http->rig->rows( 'clients' ) );
	}

	/** @dataProvider cache_controls */
	public function test_the_cache_lifetime_follows_cache_control_within_bounds( string $cache_control, int $lifetime ): void {
		$this->http->publish_document( self::URL, self::document(), $cache_control );

		$this->http->documents->resolve( self::URL );

		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + $lifetime ), $this->http->rig->row( 'clients', 'client_id', ClientDocuments::client_key( self::URL ) )['registration_expires_at'] );
	}

	public function cache_controls(): array {
		return [ [ '', 3600 ], [ 'public, max-age=7200', 7200 ], [ 'max-age=10', 300 ], [ 'max-age=99999999', 86400 ], [ 'no-store', 300 ], [ 'no-cache, max-age=900', 300 ] ];
	}

	/** @dataProvider unusable_documents */
	public function test_unusable_documents_are_refused_and_not_stored( ?array $response ): void {
		$this->http->addresses['client.example.test'] = [ '93.184.216.34' ];
		$this->http->responses[ self::URL ] = $response;

		$this->assert_refused();
		self::assertSame( [], $this->http->rig->rows( 'clients' ) );
	}

	public function unusable_documents(): array {
		$ok = static fn ( array $document ): array => [ 'status' => 200, 'body' => (string) json_encode( $document, JSON_UNESCAPED_SLASHES ), 'cache_control' => '' ];
		return [
			'transport failure'      => [ null ],
			'redirect not followed'  => [ [ 'status' => 302, 'body' => '', 'cache_control' => '' ] ],
			'not found'              => [ [ 'status' => 404, 'body' => '{}', 'cache_control' => '' ] ],
			'not json'               => [ [ 'status' => 200, 'body' => '<html></html>', 'cache_control' => '' ] ],
			'json list'              => [ [ 'status' => 200, 'body' => '[]', 'cache_control' => '' ] ],
			'too large'              => [ $ok( self::document( [ 'client_name' => str_repeat( 'n', 6000 ) ] ) ) ],
			'other client id'        => [ $ok( self::document( [ 'client_id' => 'https://other.example.test/oauth/client.json' ] ) ) ],
			'missing client id'      => [ $ok( array_diff_key( self::document(), [ 'client_id' => true ] ) ) ],
			'no redirect uris'       => [ $ok( array_diff_key( self::document(), [ 'redirect_uris' => true ] ) ) ],
			'redirect with fragment' => [ $ok( self::document( [ 'redirect_uris' => [ 'https://client.example.test/cb#x' ] ] ) ) ],
			'plain http redirect'    => [ $ok( self::document( [ 'redirect_uris' => [ 'http://client.example.test/cb' ] ] ) ) ],
			'private key client'     => [ $ok( self::document( [ 'token_endpoint_auth_method' => 'private_key_jwt' ] ) ) ],
			'shared secret'          => [ $ok( self::document( [ 'client_secret' => 'sentinel-secret-value' ] ) ) ],
			'only unsupported grant' => [ $ok( self::document( [ 'grant_types' => [ 'client_credentials' ] ] ) ) ],
			'refresh grant only'     => [ $ok( self::document( [ 'grant_types' => [ 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer' ] ] ) ) ],
			'script link'            => [ $ok( self::document( [ 'logo_uri' => 'javascript:alert(1)' ] ) ) ],
			'repeated member'        => [ [ 'status' => 200, 'body' => '{"client_id":"' . self::URL . '","client_id":"' . self::URL . '","redirect_uris":["http://127.0.0.1/callback"]}', 'cache_control' => '' ] ],
		];
	}

	public function test_an_omitted_authentication_method_means_a_public_client(): void {
		$this->http->publish_document( self::URL, array_diff_key( self::document(), [ 'token_endpoint_auth_method' => true ] ) );

		self::assertSame( 'none', $this->http->documents->resolve( self::URL )['token_endpoint_auth_method'] );
	}

	/** @dataProvider non_public_addresses */
	public function test_documents_on_non_public_addresses_are_never_fetched( string $address ): void {
		$this->http->publish_document( self::URL, self::document() );
		$this->http->addresses['client.example.test'] = [ '93.184.216.34', $address ];

		$this->assert_refused();
		self::assertSame( [], $this->http->fetches );
	}

	public function non_public_addresses(): array {
		$cases = [];
		foreach ( [ '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '192.0.2.1', '198.18.0.1', '224.0.0.1', '255.255.255.255', '::1', '::', 'fd00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1', '2002:a00:1::1', '2001:db8::1', 'ff02::1', 'not-an-address' ] as $address ) {
			$cases[ $address ] = [ $address ];
		}
		return $cases;
	}

	public function test_a_host_without_addresses_is_refused(): void {
		$this->http->responses[ self::URL ] = [ 'status' => 200, 'body' => (string) json_encode( self::document() ), 'cache_control' => '' ];

		$this->assert_refused();
		self::assertSame( [], $this->http->fetches );
	}

	public function test_public_addresses_are_recognized(): void {
		foreach ( [ '93.184.216.34', '1.1.1.1', '8.8.8.8', '2606:4700:4700::1111', '2a00:1450:4001:81b::200e' ] as $address ) {
			self::assertTrue( ClientDocuments::public_address( $address ), $address );
		}
	}

	public function test_an_expired_document_that_can_no_longer_be_fetched_is_refused(): void {
		$this->http->publish_document( self::URL, self::document() );
		$this->http->documents->resolve( self::URL );
		$this->http->responses[ self::URL ] = [ 'status' => 500, 'body' => '', 'cache_control' => '' ];
		$this->http->rig->at( StorageRig::T + 4000 );

		$this->assert_refused();
	}

	public function test_the_client_metadata_document_claude_publishes_is_accepted(): void {
		$this->http->addresses['claude.ai'] = [ '160.79.104.10' ];
		$this->http->responses[ self::CLAUDE_URL ] = [ 'status' => 200, 'body' => self::CLAUDE_DOCUMENT, 'cache_control' => 'public, max-age=300' ];

		$client = $this->http->documents->resolve( self::CLAUDE_URL );

		self::assertSame( ClientDocuments::client_key( self::CLAUDE_URL ), $client['client_id'] );
		self::assertSame( 'Claude', $client['client_name'] );
		self::assertSame( [ 'https://claude.ai/api/mcp/auth_callback' ], $client['redirect_uris'] );
		self::assertSame( 'none', $client['token_endpoint_auth_method'] );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $client['grant_types'] );
		self::assertSame( [ 'code' ], $client['response_types'] );
		self::assertSame( [], ClientDocumentRefusals::all() );
	}

	public function test_the_stored_client_keeps_only_the_grant_types_this_server_supports(): void {
		$this->http->addresses['claude.ai'] = [ '160.79.104.10' ];
		$this->http->responses[ self::CLAUDE_URL ] = [ 'status' => 200, 'body' => self::CLAUDE_DOCUMENT, 'cache_control' => '' ];

		$this->http->documents->resolve( self::CLAUDE_URL );

		$row = $this->http->rig->row( 'clients', 'client_id', ClientDocuments::client_key( self::CLAUDE_URL ) );
		$metadata = json_decode( (string) $row['client_metadata'], true );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $metadata['grant_types'] );
		self::assertStringNotContainsString( 'jwt-bearer', (string) $row['client_metadata'] );
		$reread = $this->http->storage->clients()->find( ClientDocuments::client_key( self::CLAUDE_URL ) );
		self::assertSame( [ 'authorization_code', 'refresh_token' ], $reread['grant_types'] );
	}

	public function test_unknown_response_types_and_extra_metadata_do_not_refuse_a_document(): void {
		$this->http->publish_document( self::URL, self::document( [ 'grant_types' => [ 'authorization_code', 'device_code' ], 'response_types' => [ 'code', 'id_token' ], 'jwks_uri' => 'https://client.example.test/jwks', 'software_id' => 'x', 'contacts' => [ 'a@example.test' ] ] ) );

		$client = $this->http->documents->resolve( self::URL );

		self::assertSame( [ 'authorization_code' ], $client['grant_types'] );
		self::assertSame( [ 'code' ], $client['response_types'] );
		self::assertArrayNotHasKey( 'jwks_uri', $client );
	}

	public function test_a_document_without_the_authorization_code_grant_is_still_refused(): void {
		$this->http->addresses['claude.ai'] = [ '160.79.104.10' ];
		$document = (array) json_decode( self::CLAUDE_DOCUMENT, true );
		$document['grant_types'] = [ 'refresh_token', 'urn:ietf:params:oauth:grant-type:jwt-bearer' ];
		$this->http->responses[ self::CLAUDE_URL ] = [ 'status' => 200, 'body' => (string) json_encode( $document, JSON_UNESCAPED_SLASHES ), 'cache_control' => '' ];

		$log = $this->assert_refused( self::CLAUDE_URL );

		self::assertSame( [], $this->http->rig->rows( 'clients' ) );
		self::assertSame( [ 'reason' => 'metadata_refused', 'detail' => 'grant_types' ], self::logged_reason( $log ) );
	}

	public function test_a_document_whose_client_id_differs_from_its_url_is_still_refused(): void {
		$this->http->addresses['claude.ai'] = [ '160.79.104.10' ];
		$document = (array) json_decode( self::CLAUDE_DOCUMENT, true );
		$document['client_id'] = 'https://claude.ai/oauth/another-document';
		$this->http->responses[ self::CLAUDE_URL ] = [ 'status' => 200, 'body' => (string) json_encode( $document, JSON_UNESCAPED_SLASHES ), 'cache_control' => '' ];

		$log = $this->assert_refused( self::CLAUDE_URL );

		self::assertSame( [], $this->http->rig->rows( 'clients' ) );
		self::assertSame( [ 'reason' => 'client_id_mismatch', 'detail' => '' ], self::logged_reason( $log ) );
	}

	/** @dataProvider refusal_reasons */
	public function test_every_refusal_logs_its_reason_and_records_it_for_the_troubleshoot_page( ?array $response, array $addresses, array $reason ): void {
		$this->http->addresses['client.example.test'] = $addresses;
		$this->http->responses[ self::URL ] = $response;

		$log = $this->assert_refused();

		self::assertSame( $reason, self::logged_reason( $log ) );
		$recorded = ClientDocumentRefusals::all();
		self::assertSame( [ 'client.example.test' ], array_keys( $recorded ) );
		self::assertSame( $reason['reason'], $recorded['client.example.test']['reason'] );
		self::assertSame( $reason['detail'], $recorded['client.example.test']['detail'] );
		self::assertSame( StorageRig::T, $recorded['client.example.test']['at'] );
		self::assertStringNotContainsString( 'sentinel-secret-value', $log );
	}

	public function refusal_reasons(): array {
		$public = [ '93.184.216.34' ];
		$ok = static fn ( array $document ): array => [ 'status' => 200, 'body' => (string) json_encode( $document, JSON_UNESCAPED_SLASHES ), 'cache_control' => '' ];
		return [
			'dns empty'                  => [ null, [], [ 'reason' => 'dns_empty', 'detail' => '' ] ],
			'non public address'         => [ null, [ '93.184.216.34', '10.0.0.1' ], [ 'reason' => 'non_public_address', 'detail' => '' ] ],
			'transport failure'          => [ [ 'status' => 0, 'body' => '', 'cache_control' => '', 'error' => 'http_request_failed' ], $public, [ 'reason' => 'fetch_failed', 'detail' => 'http_request_failed' ] ],
			'transport failure, no code' => [ null, $public, [ 'reason' => 'fetch_failed', 'detail' => '' ] ],
			'blocked by a firewall'      => [ [ 'status' => 403, 'body' => '<html>blocked</html>', 'cache_control' => '' ], $public, [ 'reason' => 'http_status', 'detail' => '403' ] ],
			'redirect'                   => [ [ 'status' => 302, 'body' => '', 'cache_control' => '' ], $public, [ 'reason' => 'http_status', 'detail' => '302' ] ],
			'too large'                  => [ $ok( self::document( [ 'client_name' => str_repeat( 'n', 6000 ) ] ) ), $public, [ 'reason' => 'too_large', 'detail' => '' ] ],
			'not json'                   => [ [ 'status' => 200, 'body' => '<html></html>', 'cache_control' => '' ], $public, [ 'reason' => 'invalid_document', 'detail' => '' ] ],
			'other client id'            => [ $ok( self::document( [ 'client_id' => 'https://other.example.test/c.json' ] ) ), $public, [ 'reason' => 'client_id_mismatch', 'detail' => '' ] ],
			'shared secret'              => [ $ok( self::document( [ 'client_secret' => 'sentinel-secret-value' ] ) ), $public, [ 'reason' => 'has_secret', 'detail' => '' ] ],
			'unsafe link'                => [ $ok( self::document( [ 'client_uri' => 'javascript:alert(1)' ] ) ), $public, [ 'reason' => 'metadata_refused', 'detail' => 'client_uri' ] ],
			'bad callback'               => [ $ok( self::document( [ 'redirect_uris' => [ 'http://client.example.test/cb' ] ] ) ), $public, [ 'reason' => 'metadata_refused', 'detail' => 'redirect_uris' ] ],
			'confidential client'        => [ $ok( self::document( [ 'token_endpoint_auth_method' => 'private_key_jwt' ] ) ), $public, [ 'reason' => 'metadata_refused', 'detail' => 'token_endpoint_auth_method' ] ],
		];
	}

	public function test_a_later_success_clears_the_recorded_refusal_of_that_host(): void {
		$this->http->addresses['client.example.test'] = [ '93.184.216.34' ];
		$this->http->responses[ self::URL ] = [ 'status' => 403, 'body' => '', 'cache_control' => '' ];
		$this->assert_refused();
		self::assertCount( 1, ClientDocumentRefusals::all() );

		$this->http->publish_document( self::URL, self::document() );
		$this->http->documents->resolve( self::URL );

		self::assertSame( [], ClientDocumentRefusals::all() );
	}

	public function test_only_the_latest_refusals_are_remembered(): void {
		foreach ( range( 1, ClientDocumentRefusals::LIMIT + 3 ) as $number ) {
			ClientDocumentRefusals::record( 'host' . $number . '.example.test', 'http_status', '403', 1000 + $number );
		}

		$recorded = ClientDocumentRefusals::all();

		self::assertCount( ClientDocumentRefusals::LIMIT, $recorded );
		self::assertArrayHasKey( 'host' . ( ClientDocumentRefusals::LIMIT + 3 ) . '.example.test', $recorded );
		self::assertArrayNotHasKey( 'host1.example.test', $recorded );
	}

	public function test_a_registered_client_id_is_not_a_document(): void {
		$this->assert_refused( '0123456789abcdef0123456789abcdef' );
		self::assertSame( [], $this->http->fetches );
	}

	/** Resolve a document that must be refused; returns what the refusal logged. */
	private function assert_refused( string $url = self::URL ): string {
		return HttpRig::quietly(
			function () use ( $url ): void {
				try {
					$this->http->documents->resolve( $url );
					self::fail( 'The client metadata document must be refused.' );
				} catch ( OAuthFault $fault ) {
					self::assertSame( 'invalid_client', $fault->error() );
				}
			}
		);
	}

	/** @return array{reason: string, detail: string} The reason of the one refusal in a log. */
	private static function logged_reason( string $log ): array {
		$lines = array_values( array_filter( explode( "\n", trim( $log ) ), static fn ( string $line ): bool => str_contains( $line, 'stonewright.oauth_client_document_refused' ) ) );
		self::assertCount( 1, $lines, $log );
		$entry = json_decode( substr( $lines[0], (int) strpos( $lines[0], '{' ) ), true ); // The log line starts with a timestamp.
		self::assertSame( 'warning', $entry['level'] );
		return [ 'reason' => (string) $entry['context']['reason'], 'detail' => (string) ( $entry['context']['detail'] ?? '' ) ];
	}
}
