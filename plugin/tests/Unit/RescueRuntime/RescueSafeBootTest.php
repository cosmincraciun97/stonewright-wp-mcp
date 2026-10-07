<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * Safe boot: which requests it applies to (a valid session; never the login page, anonymous
 * front-end traffic or an entry script other than wp-admin and the front controller), what it
 * changes, how the session is tied to one administrator who signs in the normal way, and how it
 * ends.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueSafeBootTest extends TestCase {

	private const ACTIVE = [ 'akismet/akismet.php', MuRuntime::PLUGIN, 'shop/shop.php' ];

	protected function setUp(): void {
		MuRuntime::begin();
	}

	protected function tearDown(): void {
		MuRuntime::end();
	}

	/** Starts the next request with the session cookie of $token. */
	private function request_with_session( string $token, string $script, array $extra_cookies = [], array $get = [] ): void {
		MuRuntime::request( $script, $get, [ 'stonewright_rescue_session' => $token ] + $extra_cookies );
		MuRuntime::start();
	}

	public function test_an_admin_request_with_a_valid_session_is_loaded_in_safe_boot(): void {
		$token = MuRuntime::open_session( 7 );

		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( 7, \Stonewright_Rescue::safe_boot_user() );
		self::assertSame( substr( hash( 'sha256', $token ), 0, 16 ), \Stonewright_Rescue::session_ref() );
	}

	public function test_the_login_page_is_never_loaded_in_safe_boot_whatever_the_session(): void {
		$token = MuRuntime::open_session( 7 );

		$this->request_with_session( $token, 'wp-login.php' );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), 'with a valid session' );
		self::assertSame( self::ACTIVE, MuRuntime::filter( 'option_active_plugins', self::ACTIVE ), 'every plugin loads, so login protections run' );
		self::assertSame( 'site-a', MuRuntime::filter( 'stylesheet', 'site-a' ), 'and so does the theme' );
		self::assertFalse( MuRuntime::has_filter( 'authenticate' ), 'sign-in is left to the site' );

		$this->request_with_session( $token, 'wp-login.php', [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ], [ 'action' => 'login' ] );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), 'with a session and a login cookie' );
		self::assertSame( self::ACTIVE, MuRuntime::filter( 'option_active_plugins', self::ACTIVE ) );
	}

	public function test_the_login_page_tells_the_browser_that_safe_mode_starts_after_sign_in(): void {
		$token = MuRuntime::open_session( 7 );

		$this->request_with_session( $token, 'wp-login.php' );

		$message = (string) MuRuntime::filter( 'login_message', '' );
		self::assertStringContainsString( 'safe mode', $message );
		self::assertStringContainsString( 'after you sign in', $message );
	}

	public function test_safe_boot_starts_once_the_session_administrator_has_signed_in(): void {
		$token = MuRuntime::open_session( 7 );

		// The browser is sent to the login page, which loads with every plugin.
		$this->request_with_session( $token, 'wp-login.php', [], [ 'redirect_to' => 'https://example.test/wp-admin/admin.php?page=stonewright-rescue' ] );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );

		// The administrator signs in there: the session stays.
		MuRuntime::fire( 'wp_login', 'user-7', new \WP_User( 7 ) );
		self::assertCount( 1, MuRuntime::option_names( 'stonewright_rescue_sess_' ) );

		// WordPress sends the browser on to the page the link named: the dashboard, now in safe boot.
		$this->request_with_session( $token, 'wp-admin/admin.php', [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ], [ 'page' => 'stonewright-rescue' ] );
		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( 7, \Stonewright_Rescue::safe_boot_user() );
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		MuRuntime::fire( 'set_current_user' );
		self::assertCount( 1, MuRuntime::option_names( 'stonewright_rescue_sess_' ), 'the administrator is let through' );
		self::assertSame( [ MuRuntime::PLUGIN ], MuRuntime::filter( 'option_active_plugins', self::ACTIVE ) );
	}

	public function test_an_administrator_who_is_already_signed_in_gets_safe_boot_directly(): void {
		$token = MuRuntime::open_session( 7 );

		$this->request_with_session( $token, 'wp-admin/admin.php', [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ], [ 'page' => 'stonewright-rescue' ] );
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		MuRuntime::fire( 'set_current_user' );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( [ MuRuntime::PLUGIN ], MuRuntime::filter( 'option_active_plugins', self::ACTIVE ) );
		self::assertCount( 1, MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
	}

	public function test_a_different_user_signing_in_ends_the_session(): void {
		$token = MuRuntime::open_session( 7 );
		MuRuntime::admin( 9 );
		$this->request_with_session( $token, 'wp-login.php' );

		MuRuntime::fire( 'wp_login', 'user-9', new \WP_User( 9 ) );

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ), 'the session is revoked' );
		$cookie = end( MuRuntime::$capture->cookies );
		self::assertSame( '', $cookie['value'], 'and its cookie is cleared' );
		$this->request_with_session( $token, 'wp-admin/index.php', [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ] );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), 'the next admin request loads normally' );
	}

	public function test_a_sign_in_that_names_no_user_ends_the_session_too(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-login.php' );

		MuRuntime::fire( 'wp_login', 'user-7' );

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
	}

	public function test_signing_in_adds_no_hook_to_a_request_that_has_no_session(): void {
		MuRuntime::request( 'wp-login.php', [], [] );
		MuRuntime::start();

		self::assertFalse( MuRuntime::has_action( 'wp_login' ) );
		self::assertFalse( MuRuntime::has_action( 'wp_logout' ) );
		self::assertFalse( MuRuntime::has_filter( 'login_message' ) );
	}

	public function test_only_stonewright_stays_active_in_safe_boot(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertSame( [ MuRuntime::PLUGIN ], MuRuntime::filter( 'option_active_plugins', self::ACTIVE ) );
	}

	public function test_the_network_list_keeps_only_stonewright_too(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );
		$network = [ 'akismet/akismet.php' => 1700000000, MuRuntime::PLUGIN => 1700000100 ];

		self::assertSame( [ MuRuntime::PLUGIN => 1700000100 ], MuRuntime::filter( 'site_option_active_sitewide_plugins', $network ) );
		self::assertSame( [ MuRuntime::PLUGIN => 1700000100 ], MuRuntime::filter( 'option_active_sitewide_plugins', $network ) );
		self::assertSame( [], MuRuntime::filter( 'option_active_sitewide_plugins', [ 'akismet/akismet.php' => 1700000000 ] ) );
	}

	public function test_a_list_that_does_not_hold_stonewright_loads_no_plugin(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertSame( [], MuRuntime::filter( 'option_active_plugins', [ 'akismet/akismet.php' ] ) );
		self::assertSame( [], MuRuntime::filter( 'option_active_plugins', 'not a list' ) );
	}

	public function test_the_default_theme_replaces_the_active_theme(): void {
		MuRuntime::install_theme( 'twentytwentythree' );
		MuRuntime::install_theme( 'twentytwentyfour', true );
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertSame( 'twentytwentyfour', MuRuntime::filter( 'template', 'site-a-parent' ) );
		self::assertSame( 'twentytwentyfour', MuRuntime::filter( 'stylesheet', 'site-a-child' ) );
	}

	public function test_a_theme_directory_without_the_files_of_a_theme_is_not_used(): void {
		MuRuntime::install_theme( 'twentytwentythree' );
		mkdir( MuRuntime::themes_dir() . '/twentytwentyfour', 0777, true );
		file_put_contents( MuRuntime::themes_dir() . '/twentytwentyfour/readme.txt', 'no theme files' );
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertSame( 'twentytwentythree', MuRuntime::filter( 'template', 'site-a' ) );
	}

	public function test_without_a_default_theme_the_theme_is_one_that_does_not_exist(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		$theme = (string) MuRuntime::filter( 'stylesheet', 'site-a' );
		self::assertSame( 'stonewright-rescue-no-theme', $theme );
		self::assertDirectoryDoesNotExist( MuRuntime::themes_dir() . '/' . $theme, 'no theme code is loaded' );
		self::assertFalse( MuRuntime::filter( 'validate_current_theme', true ), 'WordPress does not switch the stored theme' );
	}

	public function test_safe_boot_pages_are_never_cached(): void {
		unset( $GLOBALS['stonewright_test_nocache_headers'] );
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertTrue( $GLOBALS['stonewright_test_nocache_headers'] ?? false );
		self::assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	public function test_no_session_cookie_means_no_safe_boot_anywhere(): void {
		MuRuntime::admin( 7 );
		foreach ( [ 'wp-login.php', 'wp-admin/index.php', 'index.php', 'xmlrpc.php', 'wp-cron.php' ] as $script ) {
			MuRuntime::request( $script, [], [ 'wordpress_logged_in_abc' => 'x' ] );
			MuRuntime::start();
			self::assertFalse( \Stonewright_Rescue::is_safe_boot(), $script );
		}
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );
	}

	/** @return array<string, array{0: string, 1: array<string, string>}> */
	public static function invalid_cookies(): array {
		return [
			'not hex'        => [ 'x', [] ],
			'too short'      => [ str_repeat( 'a', 31 ), [] ],
			'too long'       => [ str_repeat( 'a', 33 ), [] ],
			'unknown token'  => [ str_repeat( 'a', 32 ), [] ],
			'upper case'     => [ strtoupper( str_repeat( 'a', 32 ) ), [] ],
		];
	}

	/** @dataProvider invalid_cookies */
	public function test_a_cookie_that_names_no_session_is_ignored( string $token ): void {
		MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );
	}

	public function test_a_cookie_that_is_not_a_string_is_ignored(): void {
		MuRuntime::open_session( 7 );
		MuRuntime::request( 'wp-admin/index.php', [], [ 'stonewright_rescue_session' => [ str_repeat( 'a', 32 ) ] ] );

		self::assertFalse( MuRuntime::start() );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_an_expired_session_is_ignored_and_removed(): void {
		$token = MuRuntime::open_session( 7 );
		$name  = 'stonewright_rescue_sess_' . substr( hash( 'sha256', $token ), 0, 40 );
		$record = json_decode( (string) MuRuntime::option( $name ), true );
		$record['e'] = time() - 1;
		MuRuntime::set_option( $name, json_encode( $record ) );

		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
		self::assertNull( MuRuntime::option( $name ) );
	}

	public function test_a_session_record_whose_hash_does_not_match_the_cookie_is_ignored(): void {
		$token = MuRuntime::open_session( 7 );
		$name  = 'stonewright_rescue_sess_' . substr( hash( 'sha256', $token ), 0, 40 );
		$record = json_decode( (string) MuRuntime::option( $name ), true );
		$record['h'] = hash( 'sha256', 'another token' );
		MuRuntime::set_option( $name, json_encode( $record ) );

		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_anonymous_front_end_traffic_never_gets_safe_boot(): void {
		$token = MuRuntime::open_session( 7 );

		$this->request_with_session( $token, 'index.php' );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), 'a front-end request without a logged-in cookie' );
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );

		$this->request_with_session( $token, 'index.php', [], [ 'rest_route' => '/wp/v2/posts' ] );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), 'a REST request without a logged-in cookie' );

		$this->request_with_session( $token, 'index.php', [ 'comment_author_x' => 'visitor' ] );
		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), 'other cookies do not count as a login' );
	}

	public function test_front_end_and_rest_requests_of_a_logged_in_browser_get_safe_boot(): void {
		$token = MuRuntime::open_session( 7 );

		$this->request_with_session( $token, 'index.php', [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ] );
		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );

		$this->request_with_session( $token, 'index.php', [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ], [ 'rest_route' => '/wp/v2/posts' ] );
		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_cron_and_xml_rpc_requests_never_get_safe_boot(): void {
		$token = MuRuntime::open_session( 7 );

		foreach ( [ 'wp-cron.php', 'xmlrpc.php' ] as $script ) {
			$this->request_with_session( $token, $script, [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ] );
			self::assertFalse( \Stonewright_Rescue::is_safe_boot(), $script );
		}
	}

	public function test_admin_requests_get_safe_boot_even_before_the_login_cookie_exists(): void {
		$token = MuRuntime::open_session( 7 );

		foreach ( [ 'wp-admin/index.php', 'wp-admin/admin-ajax.php', 'wp-admin/admin-post.php', 'wp-admin/network/index.php' ] as $script ) {
			$this->request_with_session( $token, $script );
			self::assertTrue( \Stonewright_Rescue::is_safe_boot(), $script );
		}
	}

	/** @return array<string, array{0: string}> */
	public static function entry_scripts_without_safe_boot(): array {
		return [
			'wp-cron.php'                     => [ 'wp-cron.php' ],
			'xmlrpc.php'                      => [ 'xmlrpc.php' ],
			'the comment form handler'        => [ 'wp-comments-post.php' ],
			'wp-signup.php'                   => [ 'wp-signup.php' ],
			'wp-activate.php'                 => [ 'wp-activate.php' ],
			'wp-trackback.php'                => [ 'wp-trackback.php' ],
			'wp-mail.php'                     => [ 'wp-mail.php' ],
			'a script of a plugin'            => [ 'wp-content/plugins/shop/ajax.php' ],
			'the login page written in capitals' => [ 'WP-LOGIN.PHP' ],
		];
	}

	/** @dataProvider entry_scripts_without_safe_boot */
	public function test_only_the_front_controller_and_the_admin_get_safe_boot_with_a_session( string $script ): void {
		$token = MuRuntime::open_session( 7 );

		$this->request_with_session( $token, $script, [ 'wordpress_logged_in_8f14e45f' => 'cookie-value' ] );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), $script );
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );
	}

	public function test_the_runtime_does_nothing_while_stonewright_is_not_active(): void {
		$token = MuRuntime::open_session( 7 );
		$GLOBALS['stonewright_test_options']['active_plugins'] = [ 'akismet/akismet.php' ];

		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_the_runtime_does_nothing_while_the_stonewright_plugin_file_is_missing(): void {
		$token = MuRuntime::open_session( 7 );
		unlink( MuRuntime::plugin_file() );

		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_a_session_ends_when_another_user_becomes_the_current_user(): void {
		$token = MuRuntime::open_session( 7 );
		MuRuntime::admin( 9 );
		$this->request_with_session( $token, 'wp-admin/index.php' );
		$GLOBALS['stonewright_test_current_user_id'] = 9;

		try {
			MuRuntime::fire( 'set_current_user' );
			self::fail( 'A different current user must end the request.' );
		} catch ( \RuntimeException $stopped ) {
			self::assertStringContainsString( 'different user', $stopped->getMessage() );
		}

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ), 'the session is revoked' );
		$cookie = end( MuRuntime::$capture->cookies );
		self::assertSame( '', $cookie['value'], 'and its cookie is cleared' );
		self::assertLessThan( time(), $cookie['options']['expires'] );
	}

	public function test_the_session_user_and_an_anonymous_visitor_are_let_through(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		$GLOBALS['stonewright_test_current_user_id'] = 7;
		MuRuntime::fire( 'set_current_user' );
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		MuRuntime::fire( 'set_current_user' );

		self::assertCount( 1, MuRuntime::option_names( 'stonewright_rescue_sess_' ), 'the session is still there' );
	}

	public function test_a_session_user_who_lost_administrator_rights_ends_the_session(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );
		MuRuntime::subscriber( 7 );
		$GLOBALS['stonewright_test_current_user_id'] = 7;

		$this->expectException( \RuntimeException::class );
		MuRuntime::fire( 'set_current_user' );
	}

	public function test_safe_boot_does_not_change_who_can_sign_in(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
		self::assertFalse( MuRuntime::has_filter( 'authenticate' ), 'sign-in belongs to the login page, which is never in safe boot' );
	}

	public function test_logging_out_ends_the_session(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-login.php', [], [ 'action' => 'logout' ] );

		MuRuntime::fire( 'wp_logout' );

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
		self::assertSame( '', end( MuRuntime::$capture->cookies )['value'] );
	}

	public function test_the_leave_link_ends_the_session_when_its_nonce_is_valid(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php', [], [ 'stonewright_rescue_exit' => '1', '_wpnonce' => 'abc123' ] );

		try {
			MuRuntime::fire( 'admin_init' );
			self::fail( 'The request ends with a redirect.' );
		} catch ( \Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuStop ) {
			self::assertContains( 'Location: https://example.test/wp-admin/', array_column( MuRuntime::$capture->headers, 0 ) );
		}
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
	}

	public function test_the_leave_link_does_nothing_with_a_bad_nonce(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php', [], [ 'stonewright_rescue_exit' => '1', '_wpnonce' => 'abc123' ] );
		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		try {
			MuRuntime::fire( 'admin_init' );
		} finally {
			unset( $GLOBALS['stonewright_test_nonce_invalid'] );
		}

		self::assertCount( 1, MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
		self::assertSame( 0, MuRuntime::$capture->exits );
	}

	public function test_the_admin_notice_names_safe_mode_and_links_to_leave_it(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		ob_start();
		MuRuntime::fire( 'admin_notices' );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Stonewright safe mode.', $html );
		self::assertStringContainsString( 'stonewright_rescue_exit=1', $html );
		self::assertStringContainsString( '_wpnonce=', $html );
		self::assertStringContainsString( 'Leave safe mode', $html );
	}

	public function test_the_login_page_and_the_dashboard_say_nothing_outside_safe_boot(): void {
		MuRuntime::request( 'wp-admin/index.php', [], [] );
		MuRuntime::start();

		self::assertFalse( MuRuntime::has_action( 'admin_notices' ) );
		self::assertFalse( MuRuntime::has_filter( 'login_message' ) );
		self::assertSame( '', \Stonewright_Rescue::exit_url() );
	}

	public function test_writes_to_the_plugin_and_theme_options_are_ignored_in_safe_boot(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		foreach ( [ 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'current_theme' ] as $option ) {
			self::assertSame( 'stored', MuRuntime::filter( 'pre_update_option_' . $option, 'new', 'stored', $option ), $option );
		}
		self::assertSame( 'stored', MuRuntime::filter( 'pre_update_site_option_active_sitewide_plugins', 'new', 'stored', 'active_sitewide_plugins', 1 ) );
	}

	public function test_code_that_changes_the_selection_on_purpose_reads_and_writes_the_stored_selection(): void {
		MuRuntime::install_theme( 'twentytwentyfour', true );
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		$inside = \Stonewright_Rescue::with_stored_selection(
			static fn (): array => [
				'plugins' => MuRuntime::filter( 'option_active_plugins', self::ACTIVE ),
				'network' => MuRuntime::filter( 'site_option_active_sitewide_plugins', [ 'akismet/akismet.php' => 1 ] ),
				'theme'   => MuRuntime::filter( 'stylesheet', 'site-a' ),
				'write'   => MuRuntime::filter( 'pre_update_option_active_plugins', 'new', 'stored', 'active_plugins' ),
			]
		);

		self::assertSame( self::ACTIVE, $inside['plugins'], 'a read, change and write of the active plugins works on the real list' );
		self::assertSame( [ 'akismet/akismet.php' => 1 ], $inside['network'] );
		self::assertSame( 'site-a', $inside['theme'] );
		self::assertSame( 'new', $inside['write'] );
		self::assertSame( [ MuRuntime::PLUGIN ], MuRuntime::filter( 'option_active_plugins', self::ACTIVE ), 'safe boot is back afterwards' );
		self::assertSame( 'stored', MuRuntime::filter( 'pre_update_option_active_plugins', 'new', 'stored', 'active_plugins' ) );
		self::assertSame( 'twentytwentyfour', MuRuntime::filter( 'stylesheet', 'site-a' ) );
	}

	public function test_with_stored_selection_returns_what_the_callback_returns(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		self::assertSame( 42, \Stonewright_Rescue::with_stored_selection( static fn (): int => 42 ) );
	}

	public function test_with_stored_selection_is_undone_when_the_callback_throws(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		try {
			\Stonewright_Rescue::with_stored_selection(
				static function (): void {
					throw new \LogicException( 'rollback failed' );
				}
			);
			self::fail( 'The exception must reach the caller.' );
		} catch ( \LogicException ) {
			// Expected.
		}

		self::assertSame( 'stored', MuRuntime::filter( 'pre_update_option_active_plugins', 'new', 'stored', 'active_plugins' ) );
		self::assertSame( [ MuRuntime::PLUGIN ], MuRuntime::filter( 'option_active_plugins', self::ACTIVE ) );
	}

	public function test_nested_with_stored_selection_restores_the_outer_state(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		$result = \Stonewright_Rescue::with_stored_selection(
			static function (): array {
				\Stonewright_Rescue::with_stored_selection( static fn () => null );
				return [ MuRuntime::filter( 'pre_update_option_template', 'new', 'stored', 'template' ) ];
			}
		);

		self::assertSame( [ 'new' ], $result );
	}

	public function test_the_exit_url_is_a_nonce_link_to_the_dashboard_in_safe_boot_only(): void {
		$token = MuRuntime::open_session( 7 );
		$this->request_with_session( $token, 'wp-admin/index.php' );

		$url = \Stonewright_Rescue::exit_url();

		self::assertStringStartsWith( 'https://example.test/wp-admin/?stonewright_rescue_exit=1', $url );
		self::assertStringContainsString( '_wpnonce=', $url );
	}
}
