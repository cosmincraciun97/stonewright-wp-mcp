<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\ClientAddress;
use Stonewright\WpMcp\Authorization\WordPress\ClientStore;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRequest;
use Stonewright\WpMcp\Authorization\WordPress\OAuthRestRoutes;
use Stonewright\WpMcp\Authorization\WordPress\RequestLimiter;
use Stonewright\WpMcp\Authorization\WordPress\RowKeys;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\HttpSurface;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\BodyRequest;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\HttpRig;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ClientAddress
 * @covers \Stonewright\WpMcp\Authorization\WordPress\OAuthRequest
 * @covers \Stonewright\WpMcp\Authorization\WordPress\ClientStore
 */
final class ClientAddressTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	protected function tearDown(): void {
		HttpSurface::use_site( null );
		HttpSurface::use_limiter( null );
		HttpSurface::use_documents( null );
		AuthorizationLifecycle::use_storage( null );
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/** @param list<string> $trusted */
	private static function trust( array $trusted ): void {
		$GLOBALS['stonewright_test_filters'][ ClientAddress::FILTER ] = static fn (): array => $trusted;
	}

	public function test_the_filter_name_and_constant_are_the_documented_ones(): void {
		self::assertSame( 'stonewright_trusted_proxies', ClientAddress::FILTER );
		self::assertSame( 'STONEWRIGHT_TRUSTED_PROXIES', ClientAddress::CONSTANT );
	}

	public function test_nothing_is_trusted_by_default(): void {
		self::assertSame( [], ClientAddress::trusted_proxies() );
	}

	public function test_a_forwarded_address_is_ignored_without_configuration(): void {
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		self::assertSame( '192.0.2.10', ClientAddress::resolve() );
		self::assertSame( '192.0.2.10', OAuthRequest::remote_address() );
		self::assertSame( '192.0.2.10', ClientAddress::resolve_from( '192.0.2.10', '203.0.113.50', [] ) );
	}

	public function test_a_forwarded_address_from_an_untrusted_connection_is_ignored_even_when_proxies_are_configured(): void {
		self::trust( [ '10.0.0.0/8' ] );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		self::assertSame( '192.0.2.10', ClientAddress::resolve() );
	}

	public function test_a_trusted_proxy_hands_over_the_forwarded_client(): void {
		self::trust( [ '10.0.0.5' ] );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		self::assertSame( '203.0.113.50', ClientAddress::resolve() );
		self::assertSame( '203.0.113.50', OAuthRequest::remote_address() );
	}

	public function test_a_trusted_proxy_without_a_forwarding_header_counts_as_itself(): void {
		self::trust( [ '10.0.0.5' ] );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';

		self::assertSame( '10.0.0.5', ClientAddress::resolve() );
	}

	public function test_the_right_most_address_that_is_not_a_trusted_proxy_wins(): void {
		$trusted = [ '10.0.0.0/8', '198.51.100.7' ];

		// A client-supplied left part cannot displace the address the first trusted proxy saw.
		self::assertSame( '203.0.113.50', ClientAddress::resolve_from( '10.0.0.5', '1.2.3.4, 203.0.113.50, 10.0.0.9, 198.51.100.7', $trusted ) );
		self::assertSame( '203.0.113.50', ClientAddress::resolve_from( '10.0.0.5', '203.0.113.50,10.0.0.9', $trusted ) );
		self::assertSame( '203.0.113.50', ClientAddress::resolve_from( '10.0.0.5', "  203.0.113.50 ,\t10.0.0.9 ", $trusted ) );
		// Every hop is trusted: the connection itself is the answer.
		self::assertSame( '10.0.0.5', ClientAddress::resolve_from( '10.0.0.5', '10.0.0.9, 198.51.100.7', $trusted ) );
	}

	public function test_cidr_ranges_and_single_addresses_match_for_both_families(): void {
		$trusted = [ '10.1.0.0/16', '192.0.2.77', '2001:db8:aaaa::/48', '2001:db8:bbbb::1' ];

		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '10.1.200.3', '203.0.113.1', $trusted ) );
		self::assertSame( '10.2.0.3', ClientAddress::resolve_from( '10.2.0.3', '203.0.113.1', $trusted ), 'Outside the /16.' );
		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '192.0.2.77', '203.0.113.1', $trusted ) );
		self::assertSame( '192.0.2.78', ClientAddress::resolve_from( '192.0.2.78', '203.0.113.1', $trusted ), 'A single address is not a range.' );
		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '2001:db8:aaaa:1:2:3:4:5', '203.0.113.1', $trusted ) );
		self::assertSame( '2001:db8:aaab::1', ClientAddress::resolve_from( '2001:db8:aaab::1', '203.0.113.1', $trusted ), 'Outside the /48.' );
		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '2001:db8:bbbb:0:0:0:0:1', '203.0.113.1', $trusted ), 'Spelling does not matter.' );
		self::assertSame( '2001:db8:bbbb::2', ClientAddress::resolve_from( '2001:db8:bbbb::2', '203.0.113.1', $trusted ) );
	}

	public function test_an_ipv6_client_behind_a_trusted_proxy_is_returned(): void {
		self::assertSame( '2001:db8:5:6::9', ClientAddress::resolve_from( '10.0.0.5', '2001:db8:5:6::9', [ '10.0.0.5' ] ) );
		self::assertSame( '2001:db8:5:6::9', ClientAddress::resolve_from( '2001:db8:aaaa::1', '2001:db8:5:6::9, 2001:db8:aaaa::2', [ '2001:db8:aaaa::/64' ] ) );
	}

	public function test_an_ipv4_mapped_connection_matches_an_ipv4_entry_and_the_reverse(): void {
		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '::ffff:10.0.0.5', '203.0.113.1', [ '10.0.0.0/8' ] ) );
		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '10.0.0.5', '203.0.113.1', [ '::ffff:10.0.0.5' ] ) );
		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '10.0.0.5', '203.0.113.1, ::ffff:10.0.0.9', [ '10.0.0.0/8' ] ) );
	}

	public function test_malformed_forwarded_values_are_ignored(): void {
		$trusted = [ '10.0.0.5' ];
		foreach ( [ '', '   ', ',', ', ,', 'unknown', 'not-an-ip', '203.0.113.999', '203.0.113.1:8080', '[2001:db8::1]:443', '203.0.113.1, garbage', '203.0.113.1, 10.0.0.5, garbage', '203.0.113.1;evil', "203.0.113.1\n198.51.100.1", '203.0.113.1/24', '::g' ] as $header ) {
			self::assertSame( '10.0.0.5', ClientAddress::resolve_from( '10.0.0.5', $header, $trusted ), 'Header: ' . json_encode( $header ) );
		}
		// Only the entries read from the right are looked at: junk left of the client the proxy saw is never reached.
		self::assertSame( '203.0.113.1', ClientAddress::resolve_from( '10.0.0.5', 'garbage, 203.0.113.1', $trusted ) );
	}

	public function test_a_missing_or_malformed_connection_address_stays_empty(): void {
		self::trust( [ '10.0.0.5' ] );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		self::assertSame( '', ClientAddress::resolve() );
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		self::assertSame( '', ClientAddress::resolve() );
		self::assertSame( '', ClientAddress::resolve_from( '', '203.0.113.50', [ '10.0.0.5' ] ) );
	}

	public function test_a_malformed_trust_list_trusts_nothing_it_cannot_read(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		foreach ( [ [ 'garbage' ], [ '10.0.0.5/33' ], [ '10.0.0.5/-1' ], [ '10.0.0.5/x' ], [ '2001:db8::/129' ], [ '' ], [ 42 ], [ null ], [ [ '10.0.0.5' ] ], [ '10.0.0.0/0' ], [ '::/0' ] ] as $list ) {
			self::trust( $list );
			self::assertSame( '10.0.0.5', ClientAddress::resolve(), 'List: ' . json_encode( $list ) );
		}
		$GLOBALS['stonewright_test_filters'][ ClientAddress::FILTER ] = static fn (): string => '10.0.0.5';
		self::assertSame( '203.0.113.50', ClientAddress::resolve(), 'A comma list in a string is read.' );
		$GLOBALS['stonewright_test_filters'][ ClientAddress::FILTER ] = static fn (): mixed => null;
		self::assertSame( '10.0.0.5', ClientAddress::resolve() );
	}

	public function test_one_unreadable_entry_does_not_hide_the_others(): void {
		self::trust( [ 'garbage', '10.0.0.5', '10.0.0.5/99' ] );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		self::assertSame( '203.0.113.50', ClientAddress::resolve() );
		self::assertSame( [ '10.0.0.5' ], ClientAddress::trusted_proxies() );
	}

	public function test_the_filter_receives_the_configured_list(): void {
		$GLOBALS['stonewright_test_filters'][ ClientAddress::FILTER ] = static fn ( mixed $list ): array => array_merge( (array) $list, [ '10.0.0.5' ] );

		self::assertSame( [ '10.0.0.5' ], ClientAddress::trusted_proxies() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_constant_trusts_a_list_and_the_filter_can_extend_it(): void {
		define( 'STONEWRIGHT_TRUSTED_PROXIES', '10.9.0.0/16, 2001:db8:cccc::/48 ,garbage' );
		$_SERVER['REMOTE_ADDR'] = '10.9.4.4';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		self::assertSame( [ '10.9.0.0/16', '2001:db8:cccc::/48' ], ClientAddress::trusted_proxies() );
		self::assertSame( '203.0.113.50', ClientAddress::resolve() );

		$GLOBALS['stonewright_test_filters'][ ClientAddress::FILTER ] = static fn ( mixed $list ): array => array_merge( (array) $list, [ '192.0.2.0/24' ] );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.4';
		self::assertSame( '203.0.113.50', ClientAddress::resolve() );
	}

	public function test_an_oauth_request_takes_the_address_from_the_resolver(): void {
		self::trust( [ '10.0.0.5' ] );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';

		$request = OAuthRequest::from_rest( new BodyRequest( '/stonewright/v1/oauth/token', '', [ 'content_type' => 'application/x-www-form-urlencoded' ] ) );

		self::assertSame( '203.0.113.50', $request->address );
	}

	public function test_the_registering_address_hash_uses_the_resolved_client(): void {
		self::trust( [ '10.0.0.5' ] );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
		$rig = new StorageRig();
		$store = new ClientStore( $rig->db, $rig->clock );

		$assigned = $store->create(
			[
				'redirect_uris'              => [ StorageRig::REDIRECT ],
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
				'token_endpoint_auth_method' => 'none',
				'client_name'                => 'Synthetic client',
			]
		);

		self::assertSame( RowKeys::address( '203.0.113.50' ), $rig->row( 'clients', 'client_id', $assigned['client_id'] )['registered_by_ip_hash'] );
	}

	/** @return list<int> Status of each token request. */
	private function burst( HttpRig $http, int $requests ): array {
		$client = $http->rig->register_client();
		$fields = [ 'grant_type' => 'authorization_code', 'code' => 'invalid-code', 'redirect_uri' => StorageRig::REDIRECT, 'client_id' => $client, 'code_verifier' => StorageRig::VERIFIER ];
		$statuses = [];
		for ( $request = 0; $request < $requests; $request++ ) {
			$statuses[] = OAuthRestRoutes::token( new BodyRequest( '/stonewright/v1/oauth/token', http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ), [ 'content_type' => 'application/x-www-form-urlencoded' ] ) )->get_status();
		}
		return $statuses;
	}

	private function http(): HttpRig {
		$http = new HttpRig( 'pretty', [ 'token' => [ 2, 60 ] ] );
		HttpSurface::use_site( $http->site );
		HttpSurface::use_limiter( $http->limiter );
		HttpSurface::use_documents( $http->documents );
		AuthorizationLifecycle::use_storage( $http->storage );
		return $http;
	}

	public function test_a_spoofed_forwarded_header_does_not_open_a_new_budget_without_configuration(): void {
		$http = $this->http();
		$statuses = [];
		foreach ( [ '203.0.113.1', '203.0.113.2', '203.0.113.3' ] as $spoofed ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $spoofed;
			$statuses = array_merge( $statuses, $this->burst( $http, 1 ) );
		}

		self::assertSame( [ 400, 400, 429 ], $statuses );
		self::assertSame( RequestLimiter::bucket( 'token', '192.0.2.10' ), $http->rig->rows( 'rate_limits' )[0]['bucket_key'] );
		self::assertCount( 1, $http->rig->rows( 'rate_limits' ) );
	}

	public function test_clients_behind_a_trusted_proxy_have_budgets_of_their_own(): void {
		self::trust( [ '192.0.2.10' ] );
		$http = $this->http();
		$statuses = [];
		foreach ( [ '203.0.113.1', '203.0.113.1', '203.0.113.1', '203.0.113.2' ] as $client ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $client;
			$statuses = array_merge( $statuses, $this->burst( $http, 1 ) );
		}

		self::assertSame( [ 400, 400, 429, 400 ], $statuses );
		$buckets = array_column( $http->rig->rows( 'rate_limits' ), 'bucket_key' );
		sort( $buckets );
		$expected = [ RequestLimiter::bucket( 'token', '203.0.113.1' ), RequestLimiter::bucket( 'token', '203.0.113.2' ) ];
		sort( $expected );
		self::assertSame( $expected, $buckets );
	}

	public function test_forwarded_ipv6_clients_keep_the_64_bit_bucket(): void {
		self::trust( [ '192.0.2.10' ] );
		$http = $this->http();
		$statuses = [];
		foreach ( [ '2001:db8:5:6::1', '2001:db8:5:6::2', '2001:db8:5:6:aaaa::3', '2001:db8:5:7::1' ] as $client ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $client;
			$statuses = array_merge( $statuses, $this->burst( $http, 1 ) );
		}

		self::assertSame( [ 400, 400, 429, 400 ], $statuses );
	}
}
