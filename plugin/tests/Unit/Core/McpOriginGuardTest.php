<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\McpOriginGuard;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Support\AbilitySourceFacts;

/**
 * Streamable HTTP servers validate the Origin header: a request without one (a command-line
 * or server-side client) passes, a request from one of the site's own origins or from an
 * origin the operator listed passes, and any other Origin is refused with 403 and a
 * JSON-RPC error body that carries no request id.
 *
 * @covers \Stonewright\WpMcp\Core\McpOriginGuard
 */
final class McpOriginGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_home_url']  = 'https://example.test/';
		$GLOBALS['stonewright_test_site_url']  = 'https://example.test';
		$GLOBALS['stonewright_test_filters']   = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_home_url'], $GLOBALS['stonewright_test_site_url'] );
		$GLOBALS['stonewright_test_filters'] = [];
	}

	private static function request( ?string $origin, string $route = '/mcp/stonewright', string $method = 'POST' ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, $route );
		if ( null !== $origin ) {
			$request->set_header( 'origin', $origin );
		}
		return $request;
	}

	private static function assert_refused( mixed $response ): void {
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 403, $response->get_status() );
		$body = $response->get_data();
		self::assertIsArray( $body );
		self::assertSame( '2.0', $body['jsonrpc'] );
		self::assertArrayHasKey( 'id', $body );
		self::assertNull( $body['id'], 'The refusal carries no request id.' );
		self::assertSame( -32008, $body['error']['code'] );
		self::assertSame( 'Permission denied: Origin not allowed.', $body['error']['message'] );
	}

	public function test_a_request_without_an_origin_passes_on_both_mcp_routes(): void {
		foreach ( [ '/mcp/stonewright', '/mcp/stonewright-oauth' ] as $route ) {
			foreach ( [ 'POST', 'GET', 'DELETE', 'OPTIONS' ] as $method ) {
				self::assertNull(
					McpOriginGuard::guard( null, null, self::request( null, $route, $method ) ),
					$method . ' ' . $route . ' without an Origin header must not be touched.'
				);
			}
		}
	}

	public function test_an_empty_origin_value_counts_as_no_origin(): void {
		self::assertNull( McpOriginGuard::guard( null, null, self::request( '' ) ) );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( '   ' ) ) );
	}

	public function test_the_site_own_origins_pass(): void {
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'https://example.test' ) ) );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'https://example.test', '/mcp/stonewright-oauth' ) ) );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'HTTPS://EXAMPLE.TEST' ) ), 'Scheme and host compare case-insensitively.' );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'https://example.test:443' ) ), 'The default port is the same origin.' );
	}

	public function test_the_home_and_site_origins_are_each_allowed(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://www.example.test/';
		$GLOBALS['stonewright_test_site_url'] = 'https://cms.example.test/wp';

		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'https://www.example.test' ) ) );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'https://cms.example.test' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://example.test' ) ) );
	}

	public function test_scheme_and_port_are_part_of_the_origin(): void {
		$GLOBALS['stonewright_test_home_url'] = 'http://localhost:8080/';
		$GLOBALS['stonewright_test_site_url'] = 'http://localhost:8080';

		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'http://localhost:8080' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'http://localhost' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'http://localhost:9090' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://localhost:8080' ) ) );
	}

	public function test_a_foreign_origin_is_refused_with_a_json_rpc_error_on_both_routes(): void {
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://attacker.example' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://attacker.example', '/mcp/stonewright-oauth' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://attacker.example', '/mcp/stonewright', 'OPTIONS' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://example.test.attacker.example' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://attacker.example/example.test' ) ) );
	}

	public function test_the_refusal_never_echoes_the_origin_and_varies_on_it(): void {
		$response = McpOriginGuard::guard( null, null, self::request( 'https://attacker.example' ) );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertStringNotContainsString( 'attacker.example', (string) wp_json_encode( $response->get_data() ) );
		self::assertSame( 'Origin', $response->get_headers()['Vary'] );
	}

	/**
	 * @dataProvider malformed_origins
	 */
	public function test_a_malformed_or_opaque_origin_is_refused( string $origin ): void {
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( $origin ) ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformed_origins(): array {
		return [
			'opaque null'           => [ 'null' ],
			'script scheme'         => [ 'javascript:alert(1)' ],
			'file scheme'           => [ 'file://example.test' ],
			'bare host'             => [ 'example.test' ],
			'path'                  => [ 'https://example.test/path' ],
			'query'                 => [ 'https://example.test?x=1' ],
			'userinfo'              => [ 'https://user@example.test' ],
			'two origins'           => [ 'https://example.test, https://attacker.example' ],
			'space separated'       => [ 'https://example.test https://attacker.example' ],
			'wildcard'              => [ '*' ],
			'scheme only'           => [ 'https://' ],
		];
	}

	public function test_requests_to_other_routes_are_never_touched(): void {
		foreach ( [ '/wp/v2/posts', '/stonewright/v1/abilities', '/mcp/another-server', '/mcp', '/oauth/token' ] as $route ) {
			self::assertNull(
				McpOriginGuard::guard( null, null, self::request( 'https://attacker.example', $route ) ),
				$route . ' is not an MCP route of this plugin.'
			);
		}
	}

	public function test_the_route_match_ignores_case_trailing_slash_and_the_rest_prefix(): void {
		foreach ( [ '/MCP/Stonewright', '/mcp/stonewright/', '/Mcp/StoneWright-OAuth', '/mcp/stonewright-oauth/', '/wp-json/mcp/stonewright', '/index.php/wp-json/mcp/stonewright-oauth' ] as $route ) {
			self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://attacker.example', $route ) ) );
		}
	}

	public function test_an_earlier_answer_is_left_alone(): void {
		$earlier = new \WP_REST_Response( [ 'earlier' => true ], 200 );

		self::assertSame( $earlier, McpOriginGuard::guard( $earlier, null, self::request( 'https://attacker.example' ) ) );
	}

	public function test_a_non_request_value_is_ignored(): void {
		self::assertNull( McpOriginGuard::guard( null, null, null ) );
		self::assertNull( McpOriginGuard::guard( null, null, 'not a request' ) );
	}

	public function test_the_operator_can_add_origins_through_the_documented_filter(): void {
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'http://localhost:6274' ) ) );

		$seen                                                                        = [];
		$GLOBALS['stonewright_test_filters'][ McpOriginGuard::ALLOWED_ORIGINS_FILTER ] = static function ( array $origins, $request ) use ( &$seen ): array {
			$seen = [ $origins, $request ];
			return [ 'http://localhost:6274', 'HTTPS://Tools.Example.Test/', 'chrome-extension://abcdef' ];
		};

		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'http://localhost:6274' ) ) );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'https://tools.example.test' ) ) );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'chrome-extension://abcdef' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'http://localhost:6275' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://attacker.example' ) ) );
		self::assertSame( [], $seen[0], 'The filter starts from an empty list; the site own origins are always allowed.' );
		self::assertInstanceOf( \WP_REST_Request::class, $seen[1] );
	}

	public function test_the_filter_cannot_open_the_route_to_everyone_or_to_opaque_origins(): void {
		$GLOBALS['stonewright_test_filters'][ McpOriginGuard::ALLOWED_ORIGINS_FILTER ] = static fn(): array => [
			'*',
			'null',
			'https://*.example.test',
			'',
			'https://example.test/with/path',
			'not an origin',
			42,
			[ 'https://nested.example.test' ],
		];

		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'null' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://anything.example.test' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://attacker.example' ) ) );
		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://nested.example.test' ) ) );
	}

	public function test_a_filter_that_returns_something_else_than_a_list_adds_nothing(): void {
		$GLOBALS['stonewright_test_filters'][ McpOriginGuard::ALLOWED_ORIGINS_FILTER ] = static fn(): string => 'https://tools.example.test';

		self::assert_refused( McpOriginGuard::guard( null, null, self::request( 'https://tools.example.test' ) ) );
		self::assertNull( McpOriginGuard::guard( null, null, self::request( 'https://example.test' ) ) );
	}

	public function test_origin_normalisation(): void {
		self::assertSame( 'https://example.test', McpOriginGuard::normalize_origin( 'https://example.test' ) );
		self::assertSame( 'https://example.test', McpOriginGuard::normalize_origin( ' HTTPS://Example.TEST:443 ' ) );
		self::assertSame( 'http://example.test', McpOriginGuard::normalize_origin( 'http://example.test:80' ) );
		self::assertSame( 'http://example.test:8080', McpOriginGuard::normalize_origin( 'http://example.test:8080' ) );
		self::assertSame( 'http://[::1]:8080', McpOriginGuard::normalize_origin( 'http://[::1]:8080' ) );
		self::assertSame( 'https://example.test', McpOriginGuard::normalize_origin( 'https://example.test/' ), 'A lone trailing slash is tolerated.' );
		self::assertNull( McpOriginGuard::normalize_origin( 'https://example.test/x' ) );
		self::assertNull( McpOriginGuard::normalize_origin( 'https://example.test:99999' ) );
		self::assertNull( McpOriginGuard::normalize_origin( 'null' ) );
		self::assertNull( McpOriginGuard::normalize_origin( '' ) );
	}

	public function test_registration_hooks_the_check_before_the_route_guards(): void {
		McpOriginGuard::register();

		self::assertNotFalse( has_filter( 'rest_pre_dispatch' ) );
		self::assertSame( [ McpOriginGuard::class, 'guard' ], $GLOBALS['stonewright_test_filters']['rest_pre_dispatch'] );
		// The bearer guard of the OAuth route and the REST audit both run at priority 5.
		self::assertLessThan( 5, McpOriginGuard::PRIORITY );
	}

	public function test_the_plugin_boot_registers_the_guard(): void {
		$method = new \ReflectionMethod( PluginRegistration::class, 'register_hooks' );
		$lines  = (array) file( (string) $method->getFileName() );
		$source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		// A call that sits in a comment does not register anything, so the comments are stripped first.
		self::assertMatchesRegularExpression(
			'/\bMcpOriginGuard::register\(\s*\)\s*;/',
			AbilitySourceFacts::code_only( '<?php ' . $source ),
			'PluginRegistration::register_hooks() must call McpOriginGuard::register().'
		);
	}

	public function test_the_origin_check_names_the_routes_the_server_registration_creates(): void {
		self::assertSame( [ '/mcp/stonewright', '/mcp/stonewright-oauth' ], McpOriginGuard::mcp_routes() );
	}
}
