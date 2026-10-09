<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\ProbeToken;

/**
 * The health probe and the request it makes, end to end: the probe issues a token and sends it, and
 * the answering site, which behaves like WordPress in keeping the first answer about the current
 * user for the whole request, must treat that request as the user the token was issued for.
 *
 * @covers \Stonewright\WpMcp\Security\HealthProbe
 * @covers \Stonewright\WpMcp\Security\ProbeToken
 */
final class ProbeRequestIdentityTest extends TestCase {

	private const ADMIN_OK = '<html><body class="wp-admin"><div data-sw-rescue-probe="ok"></div></body></html>';
	private const REST_OK  = '{"name":"Site","namespaces":["wp/v2"]}';

	/** @var list<array{url:string,user:int}> */
	private array $served = [];

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_transient_ttls']  = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$this->served                                = [];
		ProbeToken::reset_for_tests();
		\WP_Session_Tokens::reset();
		HealthProbe::set_transport( fn ( string $url, array $args ) => $this->serve( $url, $args ) );
	}

	protected function tearDown(): void {
		HealthProbe::set_transport( null );
		ProbeToken::reset_for_tests();
		unset(
			$GLOBALS['stonewright_test_cache_current_user'],
			$GLOBALS['stonewright_test_current_user_cache'],
			$GLOBALS['stonewright_test_set_current_user'],
			$_SERVER['HTTP_X_STONEWRIGHT_PROBE'],
			$_SERVER['REQUEST_URI'],
			$_GET['sw_probe']
		);
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		foreach ( [ 'wordpress_test', 'wordpress_sec_test', 'wordpress_logged_in_test' ] as $cookie ) {
			unset( $_COOKIE[ $cookie ] );
		}
	}

	/**
	 * The site answering the probe's request, as a new PHP process would: nobody is logged in until
	 * the token's own hook says so, and the first lookup of the current user sticks.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	private function serve( string $url, array $args ): array {
		$parts = (array) wp_parse_url( $url );
		$query = (string) ( $parts['query'] ?? '' );
		parse_str( $query, $_GET );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = (string) ( $parts['path'] ?? '/' ) . ( '' !== $query ? '?' . $query : '' );
		unset( $_SERVER['HTTP_X_STONEWRIGHT_PROBE'] );
		if ( isset( $args['headers'][ ProbeToken::HEADER ] ) ) {
			$_SERVER['HTTP_X_STONEWRIGHT_PROBE'] = (string) $args['headers'][ ProbeToken::HEADER ];
		}
		$GLOBALS['stonewright_test_cache_current_user'] = true;
		$GLOBALS['stonewright_test_current_user_id']    = 0;
		unset( $GLOBALS['stonewright_test_current_user_cache'] );
		ProbeToken::reset_for_tests();

		ProbeToken::authenticate_request();
		$user           = get_current_user_id();
		$this->served[] = [ 'url' => $url, 'user' => $user ];

		if ( str_contains( $url, '/wp-admin/' ) ) {
			$reply = user_can( $user, 'manage_options' )
				? [ 'response' => [ 'code' => 200 ], 'body' => self::ADMIN_OK, 'headers' => [] ]
				: [ 'response' => [ 'code' => 403 ], 'body' => 'Sorry, you are not allowed to access this page.', 'headers' => [] ];
		} elseif ( str_contains( $url, 'preview=true' ) ) {
			// An unpublished page is shown to the person who may edit it, and is not found for anybody else.
			$reply = $user > 0 && user_can( $user, 'edit_post', 13 )
				? [ 'response' => [ 'code' => 200 ], 'body' => '<html>preview</html>', 'headers' => [] ]
				: [ 'response' => [ 'code' => 404 ], 'body' => 'Not found', 'headers' => [] ];
		} elseif ( str_contains( $url, '/wp-json/' ) ) {
			$reply = [ 'response' => [ 'code' => 200 ], 'body' => self::REST_OK, 'headers' => [] ];
		} else {
			$reply = [ 'response' => [ 'code' => 200 ], 'body' => '<html>page</html>', 'headers' => [] ];
		}
		// The request is over: its session ends, and the process with it.
		ProbeToken::end_sessions();
		return $reply;
	}

	public function test_the_admin_leg_is_answered_as_the_user_the_token_was_issued_for(): void {
		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( 'full', $probe['coverage'] );
		self::assertSame( [ 'passed', 'passed', 'passed' ], array_column( $probe['legs'], 'status' ) );
		$admin = array_values( array_filter( $this->served, static fn ( array $request ): bool => str_contains( $request['url'], '/wp-admin/' ) ) );
		self::assertSame( 7, $admin[0]['user'] );
	}

	/** @return array<string, array{0:string}> */
	public static function unpublished_statuses(): array {
		return [ 'draft' => [ 'draft' ], 'private' => [ 'private' ], 'pending' => [ 'pending' ] ];
	}

	/**
	 * @dataProvider unpublished_statuses
	 */
	public function test_an_unpublished_page_is_probed_through_its_preview_and_passes( string $status ): void {
		$GLOBALS['stonewright_test_posts'][13] = (object) [ 'ID' => 13, 'post_status' => $status, 'post_type' => 'page' ];

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 13, 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'], 'The preview answers 404 to a request that is not logged in.' );
		self::assertSame( 7, $this->served[0]['user'] );
	}

	public function test_the_probe_identity_is_gone_when_the_probe_request_ends(): void {
		HealthProbe::run( [ 'legs' => [ 'admin' ], 'user_id' => 7 ] );

		self::assertSame( 0, get_current_user_id() );
		self::assertCount( 1, \WP_Session_Tokens::$destroyed );
	}

	public function test_a_token_that_was_already_used_does_not_log_a_second_request_in(): void {
		$GLOBALS['stonewright_test_posts'][13] = (object) [ 'ID' => 13, 'post_status' => 'draft', 'post_type' => 'page' ];
		$captured                              = null;
		HealthProbe::set_transport(
			function ( string $url, array $args ) use ( &$captured ) {
				$captured = [ $url, $args ];
				return $this->serve( $url, $args );
			}
		);
		HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 13, 'user_id' => 7 ] );
		self::assertNotNull( $captured );

		$replay = $this->serve( $captured[0], $captured[1] );

		self::assertSame( 404, $replay['response']['code'] );
		self::assertSame( 0, $this->served[ count( $this->served ) - 1 ]['user'] );
	}
}
