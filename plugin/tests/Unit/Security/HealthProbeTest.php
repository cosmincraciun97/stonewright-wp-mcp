<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\HealthProbe;
use Stonewright\WpMcp\Security\ProbeToken;

/**
 * @covers \Stonewright\WpMcp\Security\HealthProbe
 */
final class HealthProbeTest extends TestCase {

	/** @var list<array{url:string,args:array<string,mixed>}> */
	private array $requests = [];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_filters']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'manage_options' => true, 'read' => true, 'edit_post' => true ] ];
		$this->requests                              = [];
		HealthProbe::set_transport( null );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_home_url'] );
		HealthProbe::set_transport( null );
		$GLOBALS['stonewright_test_filters']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/**
	 * @param array<string, array{0:int,1:string}|\WP_Error> $by_leg Response per leg name.
	 */
	private function transport( array $by_leg ): void {
		HealthProbe::set_transport(
			function ( string $url, array $args ) use ( $by_leg ) {
				$this->requests[] = [ 'url' => $url, 'args' => $args ];
				$leg = match ( true ) {
					str_contains( $url, 'page=stonewright-rescue' ), str_contains( $url, '/wp-admin/' ) => 'admin',
					str_contains( $url, '/wp-json/' )                                                     => 'rest',
					str_contains( $url, 'preview=true' ), str_contains( $url, '?p=' )                      => 'post',
					default                                                                              => 'home',
				};
				$reply = $by_leg[ $leg ] ?? [ 200, 'ok' ];
				if ( $reply instanceof \WP_Error ) {
					return $reply;
				}
				return [ 'response' => [ 'code' => $reply[0] ], 'body' => $reply[1], 'headers' => [] ];
			}
		);
	}

	private const REST_OK  = '{"name":"Site","namespaces":["wp/v2"]}';
	private const ADMIN_OK = '<html><body class="wp-admin"><div data-sw-rescue-probe="ok"></div></body></html>';

	public function test_a_site_that_answers_everywhere_passes_with_full_coverage(): void {
		$this->transport( [ 'rest' => [ 200, self::REST_OK ], 'admin' => [ 200, self::ADMIN_OK ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( 'full', $probe['coverage'] );
		self::assertFalse( $probe['fatal'] );
		self::assertSame( [ 'home', 'admin', 'rest' ], array_column( $probe['legs'], 'leg' ) );
		self::assertSame( [ 'passed', 'passed', 'passed' ], array_column( $probe['legs'], 'status' ) );
		self::assertGreaterThan( 0, $probe['checked_at'] );
	}

	public function test_the_wordpress_critical_error_page_is_a_failure(): void {
		$this->transport( [ 'home' => [ 500, '<html><body id="error-page"><div class="wp-die-message"><p>There has been a critical error on this website.</p></div></body></html>' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home' ] ] );

		self::assertSame( 'failed', $probe['status'] );
		self::assertTrue( $probe['fatal'] );
		self::assertSame( 'critical_error_page', $probe['legs'][0]['reason'] );
		self::assertSame( 500, $probe['legs'][0]['http'] );
	}

	public function test_a_translated_critical_error_page_is_still_recognised_by_its_markup(): void {
		$this->transport( [ 'home' => [ 500, '<html><body id="error-page"><div class="wp-die-message"><p>Ein kritischer Fehler ist aufgetreten.</p></div></body></html>' ] ] );

		self::assertSame( 'critical_error_page', HealthProbe::run( [ 'legs' => [ 'home' ] ] )['legs'][0]['reason'] );
	}

	/** @dataProvider fatalMarkerProvider */
	public function test_php_fatal_text_is_a_failure_even_with_a_200_status( string $body ): void {
		$this->transport( [ 'home' => [ 200, '<html><body>Welcome' . $body . '</body></html>' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home' ] ] );

		self::assertSame( 'failed', $probe['status'] );
		self::assertSame( 'fatal_marker', $probe['legs'][0]['reason'] );
	}

	/** @return array<string, array{string}> */
	public static function fatalMarkerProvider(): array {
		return [
			'uncaught'      => [ "<br />\n<b>Fatal error</b>:  Uncaught Error: Call to undefined function sw_missing() in /var/www/site-a/wp-content/themes/t/functions.php:12\nStack trace:\n#0 {main}\n  thrown in /var/www/site-a/wp-content/themes/t/functions.php on line 12" ],
			'plain text'    => [ "\nFatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes) in /var/www/site-a/wp-includes/class-wp.php on line 40" ],
			'parse error'   => [ "\nParse error: syntax error, unexpected token \"}\" in /var/www/site-a/wp-content/themes/t/functions.php on line 3" ],
		];
	}

	public function test_a_bare_http_500_is_a_failure(): void {
		$this->transport( [ 'rest' => [ 500, '' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'rest' ] ] );

		self::assertSame( 'failed', $probe['status'] );
		self::assertSame( 'http_500', $probe['legs'][0]['reason'] );
	}

	public function test_text_that_merely_mentions_an_error_on_a_page_is_not_a_failure(): void {
		$this->transport( [ 'home' => [ 200, '<p>How to fix a fatal error in WordPress: read the debug log.</p>' ] ] );

		self::assertSame( 'passed', HealthProbe::run( [ 'legs' => [ 'home' ] ] )['status'] );
	}

	/** @dataProvider ambiguousStatusProvider */
	public function test_ambiguous_statuses_are_reported_as_unavailable_not_as_a_fatal( int $code ): void {
		$this->transport( [ 'home' => [ $code, 'gateway' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home' ] ] );

		self::assertSame( 'unavailable', $probe['status'] );
		self::assertFalse( $probe['fatal'] );
		self::assertSame( 'http_' . $code, $probe['legs'][0]['reason'] );
	}

	/** @return array<string, array{int}> */
	public static function ambiguousStatusProvider(): array {
		return [ '401' => [ 401 ], '403' => [ 403 ], '502' => [ 502 ], '503' => [ 503 ], '504' => [ 504 ], '429' => [ 429 ] ];
	}

	public function test_blocked_loopbacks_are_reported_honestly_and_never_as_healthy(): void {
		$this->transport(
			[
				'home'  => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ),
				'rest'  => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
				'admin' => new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' ),
			]
		);

		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		self::assertSame( 'unavailable', $probe['status'] );
		self::assertSame( 'none', $probe['coverage'] );
		self::assertFalse( $probe['fatal'] );
		self::assertSame( [ 'unavailable', 'unavailable', 'unavailable' ], array_column( $probe['legs'], 'status' ) );
		self::assertSame( 'http_request_failed', $probe['unavailable_reason'] );
	}

	public function test_partial_evidence_is_reported_as_partial_coverage(): void {
		$this->transport( [ 'home' => new \WP_Error( 'http_request_failed', 'timeout' ), 'rest' => [ 200, self::REST_OK ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'rest' ] ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( 'partial', $probe['coverage'] );
	}

	public function test_one_failing_leg_fails_the_probe_whatever_the_others_say(): void {
		$this->transport( [ 'home' => [ 200, 'ok' ], 'rest' => [ 500, '' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'rest' ] ] );

		self::assertSame( 'failed', $probe['status'] );
		self::assertTrue( $probe['fatal'] );
	}

	public function test_a_login_page_instead_of_the_admin_screen_means_the_token_was_not_honoured(): void {
		$this->transport( [ 'admin' => [ 200, '<html><body class="login"><form id="loginform"></form></body></html>' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'admin' ], 'user_id' => 7 ] );

		self::assertSame( 'unavailable', $probe['status'] );
		self::assertSame( 'login_required', $probe['legs'][0]['reason'] );
	}

	public function test_an_unexpected_rest_body_is_not_evidence(): void {
		$this->transport( [ 'rest' => [ 200, '<html>Please verify you are human</html>' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'rest' ] ] );

		self::assertSame( 'unavailable', $probe['status'] );
		self::assertSame( 'unexpected_body', $probe['legs'][0]['reason'] );
	}

	public function test_every_request_bypasses_page_caches_and_never_carries_user_credentials(): void {
		$this->transport( [ 'rest' => [ 200, self::REST_OK ], 'admin' => [ 200, self::ADMIN_OK ] ] );
		$_COOKIE['wordpress_logged_in_x'] = 'users-own-cookie';

		HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );
		unset( $_COOKIE['wordpress_logged_in_x'] );

		self::assertCount( 3, $this->requests );
		foreach ( $this->requests as $request ) {
			self::assertMatchesRegularExpression( '/[?&]sw_probe=[a-f0-9]{16}\b/', $request['url'] );
			self::assertSame( 'no-cache', $request['args']['headers']['Cache-Control'] );
			self::assertArrayNotHasKey( 'cookies', $request['args'], 'The visitor cookie must never be forwarded.' );
			self::assertArrayNotHasKey( 'Authorization', $request['args']['headers'] );
			self::assertArrayHasKey( 'sslverify', $request['args'] );
			self::assertLessThanOrEqual( 15, $request['args']['timeout'] );
			self::assertLessThanOrEqual( 2, $request['args']['redirection'] );
		}
	}

	public function test_the_admin_leg_uses_a_single_use_internal_token_bound_to_that_request(): void {
		$this->transport( [ 'admin' => [ 200, self::ADMIN_OK ] ] );

		HealthProbe::run( [ 'legs' => [ 'admin' ], 'user_id' => 7 ] );

		$request = $this->requests[0];
		$token   = (string) ( $request['args']['headers'][ ProbeToken::HEADER ] ?? '' );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', $token );
		self::assertStringContainsString( 'page=stonewright-rescue', $request['url'] );

		parse_str( (string) parse_url( $request['url'], PHP_URL_QUERY ), $query );
		$path = (string) parse_url( $request['url'], PHP_URL_PATH );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertSame( 7, ProbeToken::consume( $token, $path, (string) $query['sw_probe'] ), 'The token authenticates the user who made the change.' );
		self::assertSame( 0, ProbeToken::consume( $token, $path, (string) $query['sw_probe'] ), 'It works once.' );
		unset( $_SERVER['REQUEST_METHOD'] );
	}

	public function test_a_user_who_cannot_manage_options_is_probed_through_a_plain_admin_screen(): void {
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 9 => [ 'read' => true, 'edit_posts' => true ] ];
		$this->transport( [ 'admin' => [ 200, '<html><body class="wp-admin"></body></html>' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'admin' ], 'user_id' => 9 ] );

		self::assertStringNotContainsString( 'stonewright-rescue', $this->requests[0]['url'] );
		self::assertSame( 'passed', $probe['status'] );
	}

	public function test_without_a_user_the_admin_leg_is_skipped_and_never_counted(): void {
		$this->transport( [ 'home' => [ 200, 'ok' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'admin' ], 'user_id' => 0 ] );

		self::assertSame( 'skipped', $probe['legs'][1]['status'] );
		self::assertSame( 'no_user', $probe['legs'][1]['reason'] );
		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( 'full', $probe['coverage'] );
		self::assertCount( 1, $this->requests );
	}

	public function test_a_published_post_is_probed_at_its_permalink_without_credentials(): void {
		$GLOBALS['stonewright_test_posts'][12] = (object) [ 'ID' => 12, 'post_status' => 'publish', 'post_type' => 'page' ];
		$this->transport( [ 'post' => [ 200, '<html>page</html>' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 12, 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertStringContainsString( '?p=12', $this->requests[0]['url'] );
		self::assertArrayNotHasKey( ProbeToken::HEADER, $this->requests[0]['args']['headers'] );
	}

	public function test_a_draft_post_is_probed_through_its_preview_with_the_internal_token(): void {
		$GLOBALS['stonewright_test_posts'][13] = (object) [ 'ID' => 13, 'post_status' => 'draft', 'post_type' => 'page' ];
		$this->transport( [ 'post' => [ 200, '<html>preview</html>' ] ] );

		HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 13, 'user_id' => 7 ] );

		self::assertStringContainsString( 'preview=true', $this->requests[0]['url'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', (string) $this->requests[0]['args']['headers'][ ProbeToken::HEADER ] );
	}

	/** @return array<string, array{0:string}> */
	public static function kitStatusProvider(): array {
		return [ 'published kit' => [ 'publish' ], 'draft kit' => [ 'draft' ] ];
	}

	/** @dataProvider kitStatusProvider */
	public function test_a_kit_write_is_probed_at_the_front_page_without_credentials( string $status ): void {
		$GLOBALS['stonewright_test_options']['elementor_active_kit'] = 44;
		$GLOBALS['stonewright_test_posts'][44]                      = (object) [ 'ID' => 44, 'post_status' => $status, 'post_type' => 'elementor_library' ];
		$this->transport( [ 'home' => [ 200, '<html>front page</html>' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 44, 'user_id' => 7 ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( 'full', $probe['coverage'] );
		self::assertSame( 'post', $probe['legs'][0]['leg'] );
		self::assertCount( 1, $this->requests );
		self::assertStringStartsWith( 'https://example.test/?' . ProbeToken::PARAM . '=', $this->requests[0]['url'] );
		self::assertStringNotContainsString( '?p=44', $this->requests[0]['url'] );
		self::assertStringNotContainsString( 'preview', $this->requests[0]['url'] );
		self::assertArrayNotHasKey( ProbeToken::HEADER, $this->requests[0]['args']['headers'], 'A public page needs no probe token.' );
	}

	public function test_a_kit_is_also_recognised_by_its_template_type_when_it_is_not_the_active_kit(): void {
		$GLOBALS['stonewright_test_options']['elementor_active_kit'] = 7;
		$GLOBALS['stonewright_test_posts'][45]                      = (object) [ 'ID' => 45, 'post_status' => 'publish', 'post_type' => 'elementor_library', 'meta' => [ '_elementor_template_type' => 'kit' ] ];
		$this->transport( [ 'home' => [ 200, '<html>front page</html>' ] ] );

		HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 45, 'user_id' => 7 ] );

		self::assertStringNotContainsString( '?p=45', $this->requests[0]['url'] );
	}

	public function test_a_failing_front_page_after_a_kit_write_fails_the_probe(): void {
		$GLOBALS['stonewright_test_options']['elementor_active_kit'] = 44;
		$GLOBALS['stonewright_test_posts'][44]                      = (object) [ 'ID' => 44, 'post_status' => 'publish', 'post_type' => 'elementor_library' ];
		$this->transport( [ 'home' => [ 500, '<body id="error-page"></body>' ] ] );

		self::assertSame( 'failed', HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 44, 'user_id' => 7 ] )['status'] );
	}

	public function test_a_template_that_is_not_a_kit_keeps_its_own_probe(): void {
		$GLOBALS['stonewright_test_options']['elementor_active_kit'] = 44;
		$GLOBALS['stonewright_test_posts'][46]                      = (object) [ 'ID' => 46, 'post_status' => 'publish', 'post_type' => 'elementor_library', 'meta' => [ '_elementor_template_type' => 'section' ] ];
		$this->transport( [ 'post' => [ 200, '<html>template</html>' ] ] );

		HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 46, 'user_id' => 7 ] );

		self::assertStringContainsString( '?p=46', $this->requests[0]['url'] );
	}

	public function test_a_failure_on_the_post_page_fails_the_probe(): void {
		$GLOBALS['stonewright_test_posts'][12] = (object) [ 'ID' => 12, 'post_status' => 'publish', 'post_type' => 'page' ];
		$this->transport( [ 'post' => [ 500, '<body id="error-page"></body>' ] ] );

		self::assertSame( 'failed', HealthProbe::run( [ 'legs' => [ 'post' ], 'post_id' => 12, 'user_id' => 7 ] )['status'] );
	}

	public function test_a_host_that_cannot_loop_back_is_not_made_to_wait_on_every_write(): void {
		$this->transport( [ 'home' => new \WP_Error( 'http_request_failed', 'timeout' ), 'rest' => new \WP_Error( 'http_request_failed', 'timeout' ), 'admin' => new \WP_Error( 'http_request_failed', 'timeout' ) ] );

		HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );
		$first_requests = count( $this->requests );
		HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		self::assertSame( 3, $first_requests );
		self::assertCount( 4, $this->requests, 'While loopbacks fail, a probe is a single short request.' );
		self::assertLessThanOrEqual( 3, $this->requests[3]['args']['timeout'] );
	}

	public function test_the_quick_mode_ends_as_soon_as_the_site_answers_again(): void {
		$this->transport( [ 'home' => new \WP_Error( 'http_request_failed', 'timeout' ), 'rest' => new \WP_Error( 'http_request_failed', 'timeout' ), 'admin' => new \WP_Error( 'http_request_failed', 'timeout' ) ] );
		HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );
		$this->transport( [ 'rest' => [ 200, self::REST_OK ], 'admin' => [ 200, self::ADMIN_OK ] ] );
		HealthProbe::run( [ 'legs' => [ 'home' ] ] ); // The quick probe finds the site again.
		$this->requests = [];

		HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest' ], 'user_id' => 7 ] );

		self::assertCount( 3, $this->requests, 'Full probes are back.' );
	}

	public function test_an_http_status_is_not_a_blocked_loopback(): void {
		$this->transport( [ 'home' => [ 503, 'maintenance' ], 'rest' => [ 503, 'maintenance' ] ] );

		HealthProbe::run( [ 'legs' => [ 'home', 'rest' ] ] );
		HealthProbe::run( [ 'legs' => [ 'home', 'rest' ] ] );

		self::assertCount( 4, $this->requests, 'The quick mode is for transport failures only.' );
	}

	public function test_a_caller_supplied_url_on_the_same_site_is_probed_as_an_extra_leg(): void {
		$this->transport( [ 'home' => [ 200, 'landing page' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'custom' ], 'url' => 'https://example.test/landing/' ] );

		self::assertSame( 'passed', $probe['status'] );
		self::assertSame( 'custom', $probe['legs'][0]['leg'] );
		self::assertStringStartsWith( 'https://example.test/landing/', $this->requests[0]['url'] );
	}

	public function test_a_caller_supplied_url_on_another_host_is_never_requested(): void {
		$this->transport( [] );

		$probe = HealthProbe::run( [ 'legs' => [ 'custom' ], 'url' => 'https://attacker.example.net/collect' ] );

		self::assertSame( [], $this->requests, 'The server must not be made to call a foreign host.' );
		self::assertSame( 'skipped', $probe['legs'][0]['status'] );
		self::assertSame( 'foreign_host', $probe['legs'][0]['reason'] );
		self::assertSame( 'unavailable', $probe['status'] );
	}

	public function test_a_caller_supplied_url_must_be_http_or_https(): void {
		$this->transport( [] );

		$probe = HealthProbe::run( [ 'legs' => [ 'custom' ], 'url' => 'file:///etc/passwd' ] );

		self::assertSame( [], $this->requests );
		self::assertSame( 'foreign_host', $probe['legs'][0]['reason'] );
	}

	public function test_a_leg_that_carries_the_probe_token_never_follows_a_redirect(): void {
		$GLOBALS['stonewright_test_posts'][41] = (object) [ 'ID' => 41, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft', 'post_content' => '', 'post_name' => 'draft', 'meta' => [] ];
		$this->transport( [ 'rest' => [ 200, self::REST_OK ], 'admin' => [ 200, self::ADMIN_OK ] ] );

		HealthProbe::run( [ 'legs' => [ 'home', 'admin', 'rest', 'post', 'custom' ], 'user_id' => 7, 'post_id' => 41, 'url' => 'https://example.test/landing/' ] );

		$with_token = 0;
		foreach ( $this->requests as $request ) {
			$carries = isset( $request['args']['headers'][ ProbeToken::HEADER ] );
			$with_token += $carries ? 1 : 0;
			if ( $carries || str_contains( $request['url'], '/landing/' ) ) {
				self::assertSame( 0, $request['args']['redirection'], $request['url'] . ': the token must not be sent to wherever a redirect points; a caller-chosen URL is not followed either.' );
			}
		}
		self::assertSame( 2, $with_token, 'The admin leg and the draft preview carry the token.' );
		self::assertSame( 2, $this->requests[0]['args']['redirection'], 'The plain home page may follow its own canonical redirect.' );
	}

	/** @dataProvider redirectProvider */
	public function test_a_redirect_answer_is_never_taken_for_a_pass( int $code ): void {
		$this->transport( [ 'home' => [ $code, '' ], 'admin' => [ $code, '' ] ] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home', 'admin' ], 'user_id' => 7 ] );

		self::assertSame( 'unavailable', $probe['status'] );
		self::assertSame( [ 'redirect', 'redirect' ], array_column( $probe['legs'], 'reason' ) );
	}

	/** @return array<string, array{int}> */
	public static function redirectProvider(): array {
		return [ '301' => [ 301 ], '302' => [ 302 ], '307' => [ 307 ] ];
	}

	/** @dataProvider sameOriginProvider */
	public function test_a_caller_supplied_url_must_have_exactly_the_home_scheme_host_and_port( string $home, string $url, bool $probed ): void {
		$GLOBALS['stonewright_test_home_url'] = $home;
		$this->transport( [] );

		$probe = HealthProbe::run( [ 'legs' => [ 'custom' ], 'url' => $url ] );
		unset( $GLOBALS['stonewright_test_home_url'] );

		self::assertCount( $probed ? 1 : 0, $this->requests, $url );
		self::assertSame( $probed ? 'passed' : 'skipped', $probe['legs'][0]['status'], $url );
	}

	/** @return array<string, array{string,string,bool}> */
	public static function sameOriginProvider(): array {
		return [
			'the same origin'                  => [ 'https://example.test/', 'https://example.test/landing/', true ],
			'the default port written out'     => [ 'https://example.test/', 'https://example.test:443/landing/', true ],
			'another letter case'              => [ 'https://example.test/', 'HTTPS://EXAMPLE.TEST/landing/', true ],
			'a different scheme'               => [ 'https://example.test/', 'http://example.test/landing/', false ],
			'a different port'                 => [ 'https://example.test/', 'https://example.test:8443/landing/', false ],
			'a look-alike host'                => [ 'https://example.test/', 'https://example.test.attacker.net/landing/', false ],
			'credentials in the address'       => [ 'https://example.test/', 'https://user:secret@example.test/landing/', false ],
			'a home with its own port'         => [ 'http://example.test:8080/', 'http://example.test:8080/landing/', true ],
			'the home port left out'           => [ 'http://example.test:8080/', 'http://example.test/landing/', false ],
			'a plain http home'                => [ 'http://example.test/', 'http://example.test:80/landing/', true ],
		];
	}

	// -- Judging a probe against the one taken before the change --------------------------------

	/** @dataProvider unreachableProvider */
	public function test_a_leg_that_passed_before_and_cannot_be_reached_now_counts_as_failed( array|\WP_Error $reply ): void {
		$this->transport( [ 'home' => $reply ] );
		$after = HealthProbe::run( [ 'legs' => [ 'home' ] ] );
		self::assertSame( 'unavailable', $after['status'], 'On its own such an answer proves nothing.' );

		$judged = HealthProbe::compare( $after, [ 'home' ] );

		self::assertSame( 'failed', $judged['status'] );
		self::assertTrue( $judged['fatal'] );
		self::assertSame( 'failed', $judged['legs'][0]['status'] );
		self::assertStringStartsWith( 'degraded_', $judged['legs'][0]['reason'] );
		self::assertLessThanOrEqual( 48, strlen( $judged['legs'][0]['reason'] ) );
		self::assertSame( $after['legs'][0]['http'], $judged['legs'][0]['http'] );
	}

	/** @return array<string, array{0:array|\WP_Error}> */
	public static function unreachableProvider(): array {
		return [
			'a refused connection' => [ new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) ],
			'a timeout'            => [ new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) ],
			'an empty answer'      => [ [ 0, '' ] ],
			'a 501'                => [ [ 501, 'x' ] ],
			'a 502'                => [ [ 502, 'Bad Gateway' ] ],
			'a 503'                => [ [ 503, 'Service Unavailable' ] ],
			'a 504'                => [ [ 504, 'Gateway Timeout' ] ],
			'a 599'                => [ [ 599, 'x' ] ],
		];
	}

	/** @dataProvider notADegradationProvider */
	public function test_other_reasons_for_no_answer_are_not_taken_for_a_failure( string $leg, array|\WP_Error $reply ): void {
		$this->transport( [ $leg => $reply ] );
		$after = HealthProbe::run( [ 'legs' => [ $leg ], 'user_id' => 7 ] );

		$judged = HealthProbe::compare( $after, [ $leg ] );

		self::assertSame( 'unavailable', $judged['status'] );
		self::assertSame( $after['legs'], $judged['legs'] );
	}

	/** @return array<string, array{0:string,1:array|\WP_Error}> */
	public static function notADegradationProvider(): array {
		return [
			'a login wall'          => [ 'admin', [ 200, '<form id="loginform"></form>' ] ],
			'a page without marker' => [ 'admin', [ 200, '<html>wp-admin</html>' ] ],
			'an unexpected body'    => [ 'rest', [ 200, 'not json' ] ],
			'a forbidden answer'    => [ 'home', [ 403, 'no' ] ],
			'a rate limit'          => [ 'home', [ 429, 'slow down' ] ],
			'a redirect'            => [ 'home', [ 302, '' ] ],
		];
	}

	public function test_a_leg_that_did_not_pass_before_is_left_as_it_is(): void {
		$this->transport( [ 'home' => [ 503, 'x' ], 'rest' => [ 200, self::REST_OK ] ] );
		$after = HealthProbe::run( [ 'legs' => [ 'home', 'rest' ] ] );

		self::assertSame( $after, HealthProbe::compare( $after, [] ), 'No baseline, no verdict.' );
		self::assertSame( $after, HealthProbe::compare( $after, [ 'rest' ] ), 'Only the legs that passed before can degrade.' );
	}

	public function test_the_comparison_works_leg_by_leg_and_recomputes_the_summary(): void {
		$this->transport( [ 'home' => [ 200, 'ok' ], 'rest' => [ 503, 'x' ] ] );
		$after = HealthProbe::run( [ 'legs' => [ 'home', 'rest' ] ] );
		self::assertSame( 'passed', $after['status'] );

		$judged = HealthProbe::compare( $after, [ 'home', 'rest' ] );

		self::assertSame( 'failed', $judged['status'] );
		self::assertSame( [ 'passed', 'failed' ], array_column( $judged['legs'], 'status' ) );
		self::assertSame( 'partial', $judged['coverage'] );
		self::assertSame( $after['checked_at'], $judged['checked_at'] );
		self::assertSame( 'passed', HealthProbe::compare( $after, [ 'home' ] )['status'], 'The REST leg did not pass before, so it cannot be blamed on the change.' );
	}

	public function test_a_probe_with_nothing_to_judge_comes_back_as_it_was(): void {
		$this->transport( [] );
		$passed = HealthProbe::run( [ 'legs' => [ 'home' ] ] );

		self::assertSame( $passed, HealthProbe::compare( $passed, [ 'home' ] ) );
	}

	public function test_a_probe_can_be_built_from_the_legs_that_were_taken_one_at_a_time(): void {
		$legs = [
			[ 'leg' => 'home', 'status' => 'passed', 'http' => 200, 'reason' => 'ok', 'ms' => 5 ],
			[ 'leg' => 'rest', 'status' => 'unavailable', 'http' => 0, 'reason' => 'http_request_failed', 'ms' => 5 ],
		];

		$summary = HealthProbe::summary_of( $legs );

		self::assertSame( 'passed', $summary['status'] );
		self::assertSame( 'partial', $summary['coverage'] );
		self::assertSame( $legs, $summary['legs'] );
	}
	public function test_an_operator_can_switch_the_probe_off(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_rescue_probe_enabled'] = static fn (): bool => false;
		$this->transport( [] );

		$probe = HealthProbe::run( [ 'legs' => [ 'home' ] ] );

		self::assertSame( 'unavailable', $probe['status'] );
		self::assertSame( 'disabled', $probe['unavailable_reason'] );
		self::assertSame( [], $this->requests );
	}

	public function test_evidence_never_contains_a_url_or_a_response_body(): void {
		$this->transport( [ 'home' => [ 500, '<body id="error-page">secret-body-text</body>' ] ] );

		$encoded = (string) wp_json_encode( HealthProbe::run( [ 'legs' => [ 'home' ] ] ) );

		self::assertStringNotContainsString( 'secret-body-text', $encoded );
		self::assertStringNotContainsString( 'example.test', $encoded );
		self::assertStringNotContainsString( 'sw_probe', $encoded );
	}

	public function test_legs_follow_the_kind_of_change(): void {
		self::assertSame( [ 'home', 'admin', 'rest' ], HealthProbe::legs_for( 'site' ) );
		self::assertSame( [ 'home' ], HealthProbe::legs_for( 'light' ) );
		self::assertSame( [ 'post' ], HealthProbe::legs_for( 'post' ) );
		self::assertSame( [ 'home', 'admin', 'rest' ], HealthProbe::legs_for( 'unknown-scope' ) );
	}

	public function test_the_old_smoke_entry_point_still_answers_with_the_probe_result_shape(): void {
		$this->transport( [ 'rest' => [ 200, self::REST_OK ] ] );

		$smoke = HealthProbe::smoke_summary( HealthProbe::run( [ 'legs' => [ 'rest' ] ] ) );

		self::assertSame( 'passed', $smoke['status'] );
		self::assertSame( 200, $smoke['http'] );
	}
}
