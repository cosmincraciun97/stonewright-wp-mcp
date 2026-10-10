<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ProbeToken;

/**
 * The internal token that lets the health probe load wp-admin as the user who made the change,
 * without the user's cookie or an Application Password.
 *
 * @covers \Stonewright\WpMcp\Security\ProbeToken
 */
final class ProbeTokenTest extends TestCase {

	private const PATH  = '/wp-admin/admin.php';
	private const NONCE = '0123456789abcdef';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_user_caps_by_id'] = [ 7 => [ 'read' => true ] ];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		unset( $GLOBALS['stonewright_test_current_user_cache'], $GLOBALS['stonewright_test_set_current_user'] );
		$GLOBALS['stonewright_test_cache_current_user'] = true;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_X_STONEWRIGHT_PROBE'], $_SERVER['REQUEST_URI'], $_GET['sw_probe'] );
		$GLOBALS['stonewright_test_filters'] = [];
		ProbeToken::reset_for_tests();
		if ( class_exists( 'WP_Session_Tokens', false ) ) {
			\WP_Session_Tokens::reset();
		}
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_STONEWRIGHT_PROBE'], $_SERVER['REQUEST_URI'], $_GET['sw_probe'] );
		ProbeToken::reset_for_tests();
		foreach ( [ 'wordpress_test', 'wordpress_sec_test', 'wordpress_logged_in_test' ] as $cookie ) {
			unset( $_COOKIE[ $cookie ] );
		}
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_filters']    = [];
		unset(
			$GLOBALS['stonewright_test_cache_current_user'],
			$GLOBALS['stonewright_test_current_user_cache'],
			$GLOBALS['stonewright_test_set_current_user']
		);
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_a_token_authenticates_its_user_exactly_once(): void {
		$token = ProbeToken::issue( 7, self::PATH, self::NONCE );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', (string) $token );
		self::assertSame( 7, ProbeToken::consume( (string) $token, self::PATH, self::NONCE ) );
		self::assertSame( 0, ProbeToken::consume( (string) $token, self::PATH, self::NONCE ) );
	}

	public function test_only_a_hash_of_the_token_is_stored(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );

		$stored = (string) wp_json_encode( $GLOBALS['stonewright_test_transients'] );
		self::assertStringNotContainsString( $token, $stored );
		self::assertStringContainsString( 'stonewright_probe_', $stored );
	}

	public function test_it_is_bound_to_the_request_path_and_the_probe_nonce(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );

		self::assertSame( 0, ProbeToken::consume( $token, '/wp-admin/options.php', self::NONCE ), 'Another path.' );
		self::assertSame( 0, ProbeToken::consume( $token, self::PATH, 'ffffffffffffffff' ), 'Another probe request.' );
	}

	public function test_a_wrong_attempt_does_not_use_the_token_up(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );

		ProbeToken::consume( $token, '/elsewhere', self::NONCE );

		self::assertSame( 7, ProbeToken::consume( $token, self::PATH, self::NONCE ) );
	}

	public function test_it_works_for_get_requests_only(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		self::assertSame( 0, ProbeToken::consume( $token, self::PATH, self::NONCE ) );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertSame( 7, ProbeToken::consume( $token, self::PATH, self::NONCE ) );
	}

	public function test_it_lives_for_minutes_not_longer(): void {
		ProbeToken::issue( 7, self::PATH, self::NONCE );

		$ttls = array_values( $GLOBALS['stonewright_test_transient_ttls'] );
		self::assertNotEmpty( $ttls );
		self::assertGreaterThanOrEqual( 60, max( $ttls ) );
		self::assertLessThanOrEqual( 600, max( $ttls ) );
	}

	public function test_an_expired_token_is_refused(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );
		$key   = array_key_first( $GLOBALS['stonewright_test_transients'] );
		$GLOBALS['stonewright_test_transients'][ $key ]['expires'] = time() - 1;

		self::assertSame( 0, ProbeToken::consume( $token, self::PATH, self::NONCE ) );
	}

	public function test_malformed_tokens_never_touch_storage(): void {
		self::assertSame( 0, ProbeToken::consume( 'short', self::PATH, self::NONCE ) );
		self::assertSame( 0, ProbeToken::consume( str_repeat( 'g', 48 ), self::PATH, self::NONCE ) );
		self::assertSame( 0, ProbeToken::consume( '', self::PATH, self::NONCE ) );
	}

	public function test_no_token_can_be_issued_for_nobody(): void {
		self::assertNull( ProbeToken::issue( 0, self::PATH, self::NONCE ) );
		self::assertNull( ProbeToken::issue( 7, '', self::NONCE ) );
		self::assertNull( ProbeToken::issue( 7, self::PATH, '' ) );
	}

	public function test_a_request_carrying_a_valid_token_is_logged_in_for_that_request_only(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );
		$_SERVER['HTTP_X_STONEWRIGHT_PROBE'] = $token;
		$_SERVER['REQUEST_URI']              = self::PATH . '?page=stonewright-rescue&sw_probe=' . self::NONCE;
		$_GET['sw_probe']                    = self::NONCE;
		$logged                              = [];
		ProbeToken::set_login_handler(
			static function ( int $user_id ) use ( &$logged ): void {
				$logged[] = $user_id;
			}
		);

		ProbeToken::authenticate_request();
		ProbeToken::authenticate_request();

		self::assertSame( [ 7 ], $logged, 'The token is used up by the first request.' );
	}

	public function test_a_request_with_a_wrong_token_is_not_logged_in(): void {
		$_SERVER['HTTP_X_STONEWRIGHT_PROBE'] = str_repeat( 'a', 48 );
		$_SERVER['REQUEST_URI']              = self::PATH . '?sw_probe=' . self::NONCE;
		$_GET['sw_probe']                    = self::NONCE;
		$logged                              = [];
		ProbeToken::set_login_handler(
			static function ( int $user_id ) use ( &$logged ): void {
				$logged[] = $user_id;
			}
		);

		ProbeToken::authenticate_request();

		self::assertSame( [], $logged );
	}

	public function test_a_request_without_the_header_costs_nothing(): void {
		$GLOBALS['stonewright_test_options'] = [];

		ProbeToken::authenticate_request();

		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
	}

	// -- The default login: a session that exists only for the probe request -----------------

	public function test_without_the_wordpress_session_api_the_default_login_does_nothing(): void {
		if ( class_exists( 'WP_Session_Tokens', false ) ) {
			self::markTestSkipped( 'The session stand-ins were already loaded by an earlier test in this process.' );
		}

		ProbeToken::login_for_this_request( 7 );

		self::assertArrayNotHasKey( 'wordpress_test', $_COOKIE );
		self::assertFalse( ProbeToken::is_probe_request() );
	}

	public function test_the_default_login_opens_a_short_session_for_the_user_and_sets_request_local_cookies(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$before = time();

		ProbeToken::login_for_this_request( 7 );

		self::assertCount( 1, \WP_Session_Tokens::$created );
		$session = \WP_Session_Tokens::$created[0];
		self::assertSame( 7, $session['user'] );
		self::assertGreaterThanOrEqual( $before + 100, $session['expiration'], 'A couple of minutes, not days.' );
		self::assertLessThanOrEqual( time() + 130, $session['expiration'] );
		foreach ( [ 'wordpress_test' => 'auth', 'wordpress_sec_test' => 'secure_auth', 'wordpress_logged_in_test' => 'logged_in' ] as $cookie => $scheme ) {
			self::assertSame( '7|' . $session['expiration'] . '|' . $scheme . '|' . $session['token'], $_COOKIE[ $cookie ] ?? null, $cookie );
		}
	}

	public function test_the_session_is_destroyed_when_the_request_ends_and_only_once(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		ProbeToken::login_for_this_request( 7 );
		$token = \WP_Session_Tokens::$created[0]['token'];
		self::assertSame( [], \WP_Session_Tokens::$destroyed, 'Alive during the request.' );

		ProbeToken::end_sessions();
		ProbeToken::end_sessions();

		self::assertSame( [ $token ], \WP_Session_Tokens::$destroyed );
	}

	public function test_a_request_counts_as_a_probe_request_only_after_a_valid_token_logged_it_in(): void {
		self::assertFalse( ProbeToken::is_probe_request() );
		$_SERVER['REQUEST_URI'] = self::PATH . '?sw_probe=' . self::NONCE;
		$_GET['sw_probe']       = self::NONCE;
		ProbeToken::set_login_handler( static function ( int $user_id ): void {} );

		$_SERVER['HTTP_X_STONEWRIGHT_PROBE'] = str_repeat( 'a', 48 );
		ProbeToken::authenticate_request();
		self::assertFalse( ProbeToken::is_probe_request(), 'A wrong token proves nothing.' );

		$_SERVER['HTTP_X_STONEWRIGHT_PROBE'] = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );
		ProbeToken::authenticate_request();
		self::assertTrue( ProbeToken::is_probe_request() );
	}

	// -- The request is the token's user from the first lookup, and nobody else ---------------

	private function present_token( string $token, string $path = self::PATH ): void {
		$_SERVER['HTTP_X_STONEWRIGHT_PROBE'] = $token;
		$_SERVER['REQUEST_URI']              = $path . '?page=stonewright-rescue&sw_probe=' . self::NONCE;
		$_GET['sw_probe']                    = self::NONCE;
	}

	public function test_a_valid_token_makes_the_request_the_token_user_and_never_caches_nobody_first(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$this->present_token( (string) ProbeToken::issue( 7, self::PATH, self::NONCE ) );

		ProbeToken::authenticate_request();

		self::assertSame( 7, get_current_user_id(), 'The first lookup of the request is the probe user, not user 0.' );
		self::assertSame( 7, $GLOBALS['stonewright_test_set_current_user'] ?? null );
		self::assertTrue( ProbeToken::is_probe_request() );
	}

	public function test_the_token_use_is_recorded_for_the_probe_user_after_the_identity_is_set(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$this->present_token( (string) ProbeToken::issue( 7, self::PATH, self::NONCE ) );

		ProbeToken::authenticate_request();

		$rows = $this->token_audit_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 7, (int) $rows[0]['data']['user_id'] );
		self::assertSame( 'ok', $rows[0]['data']['result_status'] );
	}

	public function test_the_probe_identity_ends_with_the_request(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$this->present_token( (string) ProbeToken::issue( 7, self::PATH, self::NONCE ) );
		ProbeToken::authenticate_request();
		self::assertSame( 7, get_current_user_id() );

		ProbeToken::end_sessions();

		self::assertSame( 0, get_current_user_id(), 'Nothing stays elevated once the probe request is over.' );
		self::assertCount( 1, \WP_Session_Tokens::$destroyed );
	}

	public function test_ending_the_sessions_leaves_a_different_current_user_alone(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$this->present_token( (string) ProbeToken::issue( 7, self::PATH, self::NONCE ) );
		ProbeToken::authenticate_request();
		wp_set_current_user( 9 );

		ProbeToken::end_sessions();

		self::assertSame( 9, get_current_user_id() );
	}

	/** @return array<string, array{0:string}> */
	public static function refused_requests(): array {
		return [
			'never issued' => [ 'unknown' ],
			'expired'      => [ 'expired' ],
			'foreign path' => [ 'foreign' ],
			'replayed'     => [ 'replayed' ],
		];
	}

	/**
	 * @dataProvider refused_requests
	 */
	public function test_a_refused_token_leaves_the_request_anonymous_and_unelevated( string $case ): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );
		$path  = self::PATH;
		if ( 'unknown' === $case ) {
			$token = str_repeat( 'b', 48 );
		} elseif ( 'expired' === $case ) {
			$key = array_key_first( $GLOBALS['stonewright_test_transients'] );
			$GLOBALS['stonewright_test_transients'][ $key ]['expires'] = time() - 1;
		} elseif ( 'foreign' === $case ) {
			$path = '/wp-admin/options.php';
		} else {
			self::assertSame( 7, ProbeToken::consume( $token, self::PATH, self::NONCE ) );
			$GLOBALS['stonewright_test_wpdb_inserts'] = [];
			unset( $GLOBALS['stonewright_test_current_user_cache'] );
		}
		$this->present_token( $token, $path );

		ProbeToken::authenticate_request();

		self::assertSame( 0, get_current_user_id() );
		self::assertArrayNotHasKey( 'stonewright_test_set_current_user', $GLOBALS );
		self::assertFalse( ProbeToken::is_probe_request() );
		self::assertSame( [], \WP_Session_Tokens::$created );
		self::assertArrayNotHasKey( 'wordpress_logged_in_test', $_COOKIE );
	}

	public function test_a_request_without_the_header_is_never_given_an_identity(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		ProbeToken::issue( 7, self::PATH, self::NONCE );

		ProbeToken::authenticate_request();

		self::assertArrayNotHasKey( 'stonewright_test_set_current_user', $GLOBALS );
		self::assertSame( 0, get_current_user_id() );
	}

	/** @return list<array<string, mixed>> */
	private function token_audit_rows(): array {
		return array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn ( array $row ): bool => 'stonewright/security-probe-token' === ( $row['data']['ability_name'] ?? '' )
			)
		);
	}

	public function test_an_accepted_token_is_recorded_without_the_token(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );
		ProbeToken::consume( $token, self::PATH, self::NONCE );

		$rows = $this->token_audit_rows();
		self::assertCount( 1, $rows );
		self::assertStringNotContainsString( $token, (string) wp_json_encode( $rows ) );

		// The token is used up, so it no longer exists: presenting it again leaves nothing behind.
		ProbeToken::consume( $token, self::PATH, self::NONCE );
		self::assertCount( 1, $this->token_audit_rows() );
	}

	public function test_a_token_that_does_not_exist_leaves_no_trace_at_all(): void {
		$before_transients = $GLOBALS['stonewright_test_transients'];
		$before_options    = $GLOBALS['stonewright_test_options'];

		for ( $i = 0; $i < 5; $i++ ) {
			self::assertSame( 0, ProbeToken::consume( bin2hex( random_bytes( 24 ) ), self::PATH, self::NONCE ) );
		}

		self::assertSame( [], $this->token_audit_rows(), 'Anyone can send a header: it must not write audit rows.' );
		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
		self::assertSame( $before_transients, $GLOBALS['stonewright_test_transients'], 'And it touches no coalescing transient.' );
		self::assertSame( $before_options, $GLOBALS['stonewright_test_options'] );
	}

	public function test_an_existing_token_that_is_refused_is_recorded_as_a_refusal(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );

		self::assertSame( 0, ProbeToken::consume( $token, '/wp-admin/options.php', self::NONCE ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		self::assertSame( 0, ProbeToken::consume( $token, self::PATH, self::NONCE ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$rows = $this->token_audit_rows();
		self::assertNotEmpty( $rows, 'The refusal of a token that exists is recorded (repeats are coalesced by the audit log).' );
		self::assertSame( [ 'blocked' ], array_values( array_unique( array_column( array_column( $rows, 'data' ), 'result_status' ) ) ) );
		$refusals = count( $rows );
		self::assertSame( 7, ProbeToken::consume( $token, self::PATH, self::NONCE ), 'A refused attempt does not use the token up.' );
		self::assertCount( $refusals + 1, $this->token_audit_rows(), 'The acceptance is recorded too.' );
	}

	// -- A verified probe request keeps Elementor from downloading Google fonts --------------------------

	private const FONT_FILTER = 'elementor/frontend/print_google_fonts';

	/** Whether Elementor, asked on this request, would still print Google fonts. */
	private function elementor_prints_google_fonts(): bool {
		return (bool) apply_filters( self::FONT_FILTER, true );
	}

	public function test_a_verified_login_token_request_switches_the_google_font_filter_on(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$this->present_token( (string) ProbeToken::issue( 7, self::PATH, self::NONCE ) );

		ProbeToken::authenticate_request();

		self::assertTrue( ProbeToken::is_probe_request() );
		self::assertFalse( $this->elementor_prints_google_fonts() );
	}

	public function test_a_verified_mark_token_request_switches_the_google_font_filter_on(): void {
		$this->present_token( (string) ProbeToken::issue_mark( self::PATH, self::NONCE ) );

		ProbeToken::authenticate_request();

		self::assertTrue( ProbeToken::is_probe_request() );
		self::assertFalse( $this->elementor_prints_google_fonts() );
	}

	public function test_a_normal_request_leaves_the_google_font_filter_off(): void {
		ProbeToken::authenticate_request();

		self::assertFalse( ProbeToken::is_probe_request() );
		self::assertTrue( $this->elementor_prints_google_fonts() );
		self::assertFalse( has_filter( self::FONT_FILTER ) );
	}

	/** @return array<string, array{0:string,1:string}> */
	public static function refused_probe_requests(): array {
		return [
			'login token, forged'     => [ 'login', 'forged' ],
			'login token, used'       => [ 'login', 'used' ],
			'login token, expired'    => [ 'login', 'expired' ],
			'mark token, forged'      => [ 'mark', 'forged' ],
			'mark token, used'        => [ 'mark', 'used' ],
			'mark token, expired'     => [ 'mark', 'expired' ],
			'mark token, wrong path'  => [ 'mark', 'path' ],
			'mark token, wrong nonce' => [ 'mark', 'nonce' ],
		];
	}

	/**
	 * @dataProvider refused_probe_requests
	 */
	public function test_a_forged_used_or_expired_token_does_not_switch_the_google_font_filter_on( string $kind, string $case ): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$token = 'login' === $kind ? (string) ProbeToken::issue( 7, self::PATH, self::NONCE ) : (string) ProbeToken::issue_mark( self::PATH, self::NONCE );
		$path  = self::PATH;
		if ( 'forged' === $case ) {
			$token = str_repeat( 'c', 48 );
		} elseif ( 'expired' === $case ) {
			$key = array_key_first( $GLOBALS['stonewright_test_transients'] );
			$GLOBALS['stonewright_test_transients'][ $key ]['expires'] = time() - 1;
		} elseif ( 'used' === $case ) {
			if ( 'login' === $kind ) {
				self::assertSame( 7, ProbeToken::consume( $token, self::PATH, self::NONCE ) );
			} else {
				self::assertTrue( ProbeToken::consume_mark( $token, self::PATH, self::NONCE ) );
			}
			unset( $GLOBALS['stonewright_test_current_user_cache'] );
		} elseif ( 'path' === $case ) {
			$path = '/somewhere-else/';
		}
		$this->present_token( $token, $path );
		if ( 'nonce' === $case ) {
			$_GET['sw_probe'] = 'ffffffffffffffff';
		}

		ProbeToken::authenticate_request();

		self::assertFalse( ProbeToken::is_probe_request() );
		self::assertTrue( $this->elementor_prints_google_fonts() );
		self::assertFalse( has_filter( self::FONT_FILTER ) );
	}

	public function test_an_unauthenticated_header_parameter_or_cookie_never_switches_the_filter_on(): void {
		$_SERVER['REQUEST_URI']                   = self::PATH . '?sw_probe=' . self::NONCE . '&probe=1';
		$_GET['sw_probe']                         = self::NONCE;
		$_GET['probe']                            = '1';
		$_COOKIE['sw_probe']                      = self::NONCE;
		$_SERVER['HTTP_X_STONEWRIGHT_PROBE_MARK'] = '1';
		$_SERVER['HTTP_X_STONEWRIGHT_PROBE']      = 'true';

		ProbeToken::authenticate_request();
		unset( $_GET['probe'], $_COOKIE['sw_probe'], $_SERVER['HTTP_X_STONEWRIGHT_PROBE_MARK'] );

		self::assertFalse( ProbeToken::is_probe_request() );
		self::assertTrue( $this->elementor_prints_google_fonts() );
		self::assertFalse( has_filter( self::FONT_FILTER ) );
	}

	// -- The mark-only token: it marks a probe request and logs nobody in -----------------------------

	public function test_a_mark_token_logs_nobody_in_and_the_current_user_stays_zero(): void {
		require_once dirname( __DIR__, 2 ) . '/fixtures/rescue/session-stubs.php';
		$logged = [];
		ProbeToken::set_login_handler(
			static function ( int $user_id ) use ( &$logged ): void {
				$logged[] = $user_id;
			}
		);
		$this->present_token( (string) ProbeToken::issue_mark( self::PATH, self::NONCE ) );

		ProbeToken::authenticate_request();

		self::assertSame( [], $logged );
		self::assertSame( 0, get_current_user_id() );
		self::assertArrayNotHasKey( 'stonewright_test_set_current_user', $GLOBALS );
		self::assertSame( [], \WP_Session_Tokens::$created );
		self::assertArrayNotHasKey( 'wordpress_logged_in_test', $_COOKIE );
		self::assertTrue( ProbeToken::is_probe_request() );
	}

	public function test_a_mark_token_works_once_and_is_bound_to_path_nonce_and_method(): void {
		$token = (string) ProbeToken::issue_mark( self::PATH, self::NONCE );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{48}$/', $token );

		self::assertFalse( ProbeToken::consume_mark( $token, '/elsewhere/', self::NONCE ), 'Another path.' );
		self::assertFalse( ProbeToken::consume_mark( $token, self::PATH, 'ffffffffffffffff' ), 'Another probe request.' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		self::assertFalse( ProbeToken::consume_mark( $token, self::PATH, self::NONCE ), 'Only GET is honoured.' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::assertTrue( ProbeToken::consume_mark( $token, self::PATH, self::NONCE ) );
		self::assertFalse( ProbeToken::consume_mark( $token, self::PATH, self::NONCE ), 'Used up by the first request.' );
	}

	public function test_a_mark_token_cannot_be_used_as_a_login_token(): void {
		$token = (string) ProbeToken::issue_mark( self::PATH, self::NONCE );

		self::assertSame( 0, ProbeToken::consume( $token, self::PATH, self::NONCE ), 'It names no user.' );
		self::assertTrue( ProbeToken::consume_mark( $token, self::PATH, self::NONCE ), 'And the login check did not use it up.' );
	}

	public function test_a_login_token_is_not_a_mark_token(): void {
		$token = (string) ProbeToken::issue( 7, self::PATH, self::NONCE );

		self::assertFalse( ProbeToken::consume_mark( $token, self::PATH, self::NONCE ), 'A mark check never accepts a login token.' );
		self::assertSame( 7, ProbeToken::consume( $token, self::PATH, self::NONCE ), 'And it does not use it up.' );
	}

	public function test_a_stored_record_with_no_user_and_no_mark_flag_never_marks_a_request(): void {
		$token = (string) ProbeToken::issue_mark( self::PATH, self::NONCE );
		$key   = array_key_first( $GLOBALS['stonewright_test_transients'] );
		unset( $GLOBALS['stonewright_test_transients'][ $key ]['mark'] );
		$this->present_token( $token );

		ProbeToken::authenticate_request();

		self::assertFalse( ProbeToken::is_probe_request() );
		self::assertSame( 0, get_current_user_id() );
		self::assertFalse( has_filter( self::FONT_FILTER ) );
	}

	public function test_a_mark_token_lives_for_minutes_and_only_a_hash_is_stored(): void {
		$token = (string) ProbeToken::issue_mark( self::PATH, self::NONCE );

		$ttls = array_values( $GLOBALS['stonewright_test_transient_ttls'] );
		self::assertGreaterThanOrEqual( 60, max( $ttls ) );
		self::assertLessThanOrEqual( 600, max( $ttls ) );
		self::assertStringNotContainsString( $token, (string) wp_json_encode( $GLOBALS['stonewright_test_transients'] ) );
	}

	public function test_no_mark_token_can_be_issued_without_a_path_or_a_valid_nonce(): void {
		self::assertNull( ProbeToken::issue_mark( '', self::NONCE ) );
		self::assertNull( ProbeToken::issue_mark( self::PATH, '' ) );
		self::assertNull( ProbeToken::issue_mark( self::PATH, 'bad nonce!' ) );
		self::assertSame( [], $GLOBALS['stonewright_test_transients'] );
	}

	public function test_an_accepted_mark_token_is_recorded_without_the_token(): void {
		$token = (string) ProbeToken::issue_mark( self::PATH, self::NONCE );
		$this->present_token( $token );

		ProbeToken::authenticate_request();

		$rows = $this->token_audit_rows();
		self::assertCount( 1, $rows );
		self::assertSame( 'ok', $rows[0]['data']['result_status'] );
		self::assertStringNotContainsString( $token, (string) wp_json_encode( $rows ) );
	}
}
