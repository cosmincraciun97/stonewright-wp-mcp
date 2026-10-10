<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The optional mode in which an authenticated request to the Stonewright MCP routes is loaded
 * in safe boot while a rescue incident is open. It is off by default, it needs every one of
 * its conditions (the setting, an open incident, a credential of the scheme the route accepts,
 * the front controller as the entry script, and the route WordPress will serve), and it never
 * touches authentication or permissions.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueMcpRouteSafeBootTest extends TestCase {

	private const BASIC  = 'Basic dGVzdDpwYXNzd29yZC1mb3ItdGVzdHM=';
	private const BEARER = 'Bearer example-access-credential';
	private const HOME   = 'https://example.test';

	protected function setUp(): void {
		MuRuntime::begin();
		$incident = MuRuntime::entry( 'cs-1', [ 'state' => 'incident', 'incident' => [ 'recorded_at' => 1, 'file' => 'wp-content/themes/site-a/functions.php', 'line' => 1, 'type' => 1, 'message_sha256' => str_repeat( 'a', 64 ), 'source' => 'shutdown' ] ] );
		MuRuntime::write_journal( [ $incident ] );
		MuRuntime::set_option( 'stonewright_rescue_mcp_safe_boot', '1' );
	}

	protected function tearDown(): void {
		MuRuntime::end();
	}

	/**
	 * Runs a request the way the web server hands it to PHP: the entry script, the URI (its query
	 * becomes $_GET), the method, the form fields of the body, and the Authorization header.
	 *
	 * @param array<string, string> $post
	 */
	private function request( string $uri, ?string $authorization, string $script = 'index.php', string $method = 'GET', array $post = [], string $home = self::HOME ): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'] );
		MuRuntime::set_option( 'home', $home );
		parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $get );
		MuRuntime::request( $script, $get, [], $method );
		$_POST                  = $post;
		$_SERVER['REQUEST_URI'] = $uri;
		if ( null !== $authorization ) {
			$_SERVER['HTTP_AUTHORIZATION'] = $authorization;
		}
		MuRuntime::start();
	}

	public function test_the_mode_is_off_by_default(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_rescue_mcp_safe_boot'] );

		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );
	}

	/** @return array<string, array{0: mixed}> */
	public static function values_that_leave_it_off(): array {
		return [
			'an empty string' => [ '' ],
			'zero'            => [ '0' ],
			'false'           => [ false ],
			'the word no'     => [ 'no' ],
			'an array'        => [ [ '1' ] ],
		];
	}

	/** @dataProvider values_that_leave_it_off */
	public function test_only_an_explicit_on_value_turns_it_on( mixed $value ): void {
		MuRuntime::set_option( 'stonewright_rescue_mcp_safe_boot', $value );

		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> The URI, the credential and the home URL. */
	public static function eligible_requests(): array {
		return [
			'the Application Password route'                  => [ '/wp-json/mcp/stonewright', self::BASIC, self::HOME ],
			'the OAuth route'                                 => [ '/wp-json/mcp/stonewright-oauth', self::BEARER, self::HOME ],
			'a sub-path of the OAuth route'                   => [ '/wp-json/mcp/stonewright-oauth/sessions', self::BEARER, self::HOME ],
			'a route in another case, with a slash'           => [ '/wp-json/MCP/Stonewright/', self::BASIC, self::HOME ],
			'a route with an encoded slash'                   => [ '/wp-json/mcp%2Fstonewright', self::BASIC, self::HOME ],
			'a site in a sub-directory'                       => [ '/blog/wp-json/mcp/stonewright', self::BASIC, self::HOME . '/blog' ],
			'a sub-directory site, home path in another case' => [ '/blog/wp-json/mcp/stonewright', self::BASIC, self::HOME . '/Blog' ],
			'index.php in the path'                           => [ '/index.php/wp-json/mcp/stonewright', self::BASIC, self::HOME ],
			'index.php in the path of a sub-directory site'   => [ '/blog/index.php/wp-json/mcp/stonewright', self::BASIC, self::HOME . '/blog' ],
			'the plain permalink form'                        => [ '/index.php?rest_route=/mcp/stonewright', self::BASIC, self::HOME ],
			'the plain permalink form on the front page'      => [ '/?rest_route=/mcp/stonewright', self::BASIC, self::HOME ],
			'the plain permalink form behind a page path'     => [ '/sample-page/?rest_route=/mcp/stonewright', self::BASIC, self::HOME ],
			'the plain form of the OAuth route'               => [ '/index.php?rest_route=/mcp/stonewright-oauth', self::BEARER, self::HOME ],
			'the route parameter beating a core path'         => [ '/wp-json/wp/v2/posts?rest_route=/mcp/stonewright', self::BASIC, self::HOME ],
		];
	}

	/** @dataProvider eligible_requests */
	public function test_an_authenticated_request_to_an_mcp_route_is_loaded_in_safe_boot( string $uri, string $authorization, string $home ): void {
		$this->request( $uri, $authorization, 'index.php', 'GET', [], $home );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( 0, \Stonewright_Rescue::safe_boot_user(), 'no user is chosen here: Stonewright authenticates the request' );
		self::assertSame( [ MuRuntime::PLUGIN ], MuRuntime::filter( 'option_active_plugins', [ 'akismet/akismet.php', MuRuntime::PLUGIN ] ) );
	}

	public function test_a_post_request_with_a_json_body_to_an_mcp_route_is_loaded_in_safe_boot(): void {
		$this->request( '/wp-json/mcp/stonewright', self::BASIC, 'index.php', 'POST' );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_a_form_field_that_names_the_mcp_route_counts_because_wordpress_serves_it(): void {
		$this->request( '/', self::BASIC, 'index.php', 'POST', [ 'rest_route' => '/mcp/stonewright' ] );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
	}

	/** @return array<string, array{0: string, 1: string|null}> */
	public static function requests_that_stay_in_normal_boot(): array {
		return [
			'no credential on the route'                => [ '/wp-json/mcp/stonewright', null ],
			'an empty credential'                       => [ '/wp-json/mcp/stonewright', '' ],
			'a bearer credential on the basic route'    => [ '/wp-json/mcp/stonewright', self::BEARER ],
			'a basic credential on the OAuth route'     => [ '/wp-json/mcp/stonewright-oauth', self::BASIC ],
			'a scheme-less credential'                  => [ '/wp-json/mcp/stonewright', 'dGVzdDpwYXNz' ],
			'another MCP server'                        => [ '/wp-json/mcp/other-server', self::BASIC ],
			'a longer route name'                       => [ '/wp-json/mcp/stonewright-extra', self::BASIC ],
			'another namespace of Stonewright'          => [ '/wp-json/stonewright/v1/abilities/run', self::BASIC ],
			'a core route'                              => [ '/wp-json/wp/v2/posts', self::BASIC ],
			'the front end'                             => [ '/', self::BASIC ],
			'a page that mentions the route in a query' => [ '/?next=/wp-json/mcp/stonewright', self::BASIC ],
			'a route parameter without a leading slash' => [ '/?rest_route=mcp/stonewright', self::BASIC ],
			'an empty route parameter'                  => [ '/?rest_route=', self::BASIC ],
			'a route parameter that is a list'          => [ '/?rest_route[]=/mcp/stonewright', self::BASIC ],
			'the route parameter beating the MCP path'  => [ '/wp-json/mcp/stonewright?rest_route=/wp/v2/users', self::BASIC ],
		];
	}

	/** @dataProvider requests_that_stay_in_normal_boot */
	public function test_every_other_request_stays_in_normal_boot( string $uri, ?string $authorization ): void {
		$this->request( $uri, $authorization );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );
	}

	/** @return array<string, array{0: string, 1: string, 2: string, 3: string}> The entry script, the URI, the method and the credential. */
	public static function entry_scripts_that_are_not_the_front_controller(): array {
		$route = '?rest_route=/mcp/stonewright';
		return [
			'the login page, POST'                      => [ 'wp-login.php', '/wp-login.php' . $route, 'POST', self::BASIC ],
			'the login page, GET'                       => [ 'wp-login.php', '/wp-login.php' . $route, 'GET', self::BASIC ],
			'the login page with the OAuth route'       => [ 'wp-login.php', '/wp-login.php?rest_route=/mcp/stonewright-oauth', 'POST', self::BEARER ],
			'the login page behind the REST base'       => [ 'wp-login.php', '/wp-json/mcp/stonewright', 'POST', self::BASIC ],
			'the login page written in capitals'        => [ 'WP-LOGIN.PHP', '/WP-LOGIN.PHP' . $route, 'POST', self::BASIC ],
			'admin-ajax'                                => [ 'wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php' . $route, 'POST', self::BASIC ],
			'admin-post'                                => [ 'wp-admin/admin-post.php', '/wp-admin/admin-post.php' . $route, 'POST', self::BASIC ],
			'the dashboard'                             => [ 'wp-admin/index.php', '/wp-admin/index.php' . $route, 'GET', self::BASIC ],
			'a screen of the network dashboard'         => [ 'wp-admin/network/index.php', '/wp-admin/network/' . $route, 'GET', self::BASIC ],
			'the dashboard written in capitals'         => [ 'WP-ADMIN/index.php', '/WP-ADMIN/index.php' . $route, 'GET', self::BASIC ],
			'xmlrpc'                                    => [ 'xmlrpc.php', '/xmlrpc.php' . $route, 'POST', self::BASIC ],
			'wp-cron'                                   => [ 'wp-cron.php', '/wp-cron.php' . $route . '&doing_wp_cron=1', 'GET', self::BASIC ],
			'the comment form handler'                  => [ 'wp-comments-post.php', '/wp-comments-post.php' . $route, 'POST', self::BASIC ],
			'wp-signup'                                 => [ 'wp-signup.php', '/wp-signup.php' . $route, 'POST', self::BASIC ],
			'wp-activate'                               => [ 'wp-activate.php', '/wp-activate.php' . $route, 'GET', self::BASIC ],
			'wp-trackback'                              => [ 'wp-trackback.php', '/wp-trackback.php' . $route, 'POST', self::BASIC ],
			'wp-mail'                                   => [ 'wp-mail.php', '/wp-mail.php' . $route, 'GET', self::BASIC ],
			'a script of a plugin'                      => [ 'wp-content/plugins/shop/ajax.php', '/wp-content/plugins/shop/ajax.php' . $route, 'POST', self::BASIC ],
			'a script that only ends like index.php'    => [ 'not-index.php', '/not-index.php' . $route, 'POST', self::BASIC ],
			'the front page, POST'                      => [ 'index.php', '/', 'POST', self::BASIC ],
			'a front URL that contains the REST base'   => [ 'index.php', '/news/wp-json/mcp/stonewright', 'GET', self::BASIC ],
			'a front URL that ends in the route'        => [ 'index.php', '/news/wp-json/mcp/stonewright/', 'POST', self::BASIC ],
			'a front URL with the REST base after a dot' => [ 'index.php', '/x.php/news/wp-json/mcp/stonewright', 'GET', self::BASIC ],
		];
	}

	/** @dataProvider entry_scripts_that_are_not_the_front_controller */
	public function test_no_other_entry_script_and_no_front_url_is_loaded_in_safe_boot( string $script, string $uri, string $method, string $authorization ): void {
		$this->request( $uri, $authorization, $script, $method );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot(), $script . ' ' . $uri );
		self::assertSame( [], $GLOBALS['stonewright_test_filters'] );
		self::assertSame( [], MuRuntime::$capture->cookies );
		self::assertSame( [], MuRuntime::$capture->headers );
	}

	/** @return array<string, array{0: string, 1: string, 2: array<string, string>}> The URI, the home URL and the form fields. */
	public static function paths_outside_the_rest_base(): array {
		return [
			'the REST base outside the home path'            => [ '/wp-json/mcp/stonewright', self::HOME . '/blog', [] ],
			'a path that only starts like the home path'      => [ '/blogger/wp-json/mcp/stonewright', self::HOME . '/blog', [] ],
			'a form field that names another route'           => [ '/wp-json/mcp/stonewright', self::HOME, [ 'rest_route' => '/wp/v2/users' ] ],
			'a form field that beats the route parameter'     => [ '/?rest_route=/mcp/stonewright', self::HOME, [ 'rest_route' => '/wp/v2/users' ] ],
			'a form field that is a list'                     => [ '/wp-json/mcp/stonewright', self::HOME, [ 'rest_route' => [ '/mcp/stonewright' ] ] ],
		];
	}

	/**
	 * @param array<string, mixed> $post
	 * @dataProvider paths_outside_the_rest_base
	 */
	public function test_the_route_wordpress_will_serve_decides( string $uri, string $home, array $post ): void {
		$this->request( $uri, self::BASIC, 'index.php', 'POST', $post, $home );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_a_basic_credential_the_server_already_parsed_counts(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		MuRuntime::request( 'index.php' );
		$_SERVER['REQUEST_URI']   = '/wp-json/mcp/stonewright';
		$_SERVER['PHP_AUTH_USER'] = 'editor';

		MuRuntime::start();

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_the_redirected_authorization_header_counts(): void {
		MuRuntime::request( 'index.php' );
		$_SERVER['REQUEST_URI']                 = '/wp-json/mcp/stonewright-oauth';
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = self::BEARER;

		MuRuntime::start();

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
	}

	/** @return array<string, array{0: list<array<string, mixed>>|string}> */
	public static function journals_without_an_open_incident(): array {
		return [
			'only an armed entry'       => [ [ [ 'state' => 'armed' ] ] ],
			'only a verified entry'     => [ [ [ 'state' => 'verified' ] ] ],
			'a rolled back entry'       => [ [ [ 'state' => 'rolled_back' ] ] ],
			'no entries'                => [ [] ],
			'a journal nobody can read' => [ 'not json' ],
		];
	}

	/** @param list<array<string, mixed>>|string $entries @dataProvider journals_without_an_open_incident */
	public function test_it_needs_an_open_incident( array|string $entries ): void {
		if ( is_string( $entries ) ) {
			MuRuntime::write_journal( [], $entries );
		} else {
			MuRuntime::write_journal( array_map( static fn ( array $override ): array => MuRuntime::entry( 'cs-x', $override ), $entries ) );
		}

		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_a_rollback_that_failed_is_an_open_incident_too(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-2', [ 'state' => 'rollback_failed' ] ) ] );

		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_it_needs_a_journal(): void {
		unlink( MuRuntime::journal_path() );

		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertFalse( \Stonewright_Rescue::is_safe_boot() );
	}

	public function test_it_leaves_authentication_and_permission_checks_alone(): void {
		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
		foreach ( [ 'authenticate', 'rest_authentication_errors', 'determine_current_user', 'rest_pre_dispatch', 'application_password_is_api_request', 'wp_authenticate_application_password_errors', 'user_has_cap', 'map_meta_cap' ] as $hook ) {
			self::assertFalse( MuRuntime::has_filter( $hook ), $hook );
		}
		foreach ( [ 'set_current_user', 'wp_login', 'wp_logout', 'admin_init', 'rest_api_init' ] as $hook ) {
			self::assertFalse( MuRuntime::has_action( $hook ), $hook );
		}
	}

	public function test_it_sets_no_cookie_and_sends_no_redirect(): void {
		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertSame( [], MuRuntime::$capture->cookies );
		self::assertSame( [], MuRuntime::$capture->headers );
		self::assertSame( 0, MuRuntime::$capture->exits );
	}

	public function test_a_rescue_key_on_an_mcp_route_is_not_redeemed(): void {
		MuRuntime::admin( 7 );
		MuRuntime::boot();
		$issued = \Stonewright_Rescue::issue_key( 7 );
		$this->request( '/wp-json/mcp/stonewright?stonewright_rescue=' . $issued['key'], self::BASIC );

		self::assertSame( [], MuRuntime::$capture->cookies );
		self::assertNotNull( MuRuntime::option( 'stonewright_rescue_key_' . explode( '.', $issued['key'] )[0] ) );
	}

	public function test_writes_to_the_plugin_and_theme_options_are_ignored_in_this_mode_too(): void {
		$this->request( '/wp-json/mcp/stonewright', self::BASIC );

		self::assertSame( 'stored', MuRuntime::filter( 'pre_update_option_active_plugins', 'new', 'stored', 'active_plugins' ) );
		$allowed = \Stonewright_Rescue::with_stored_selection( static fn (): mixed => MuRuntime::filter( 'pre_update_option_template', 'new', 'stored', 'template' ) );
		self::assertSame( 'new', $allowed, 'a rollback that runs on the MCP route can still restore the selection' );
	}

	public function test_a_valid_session_cookie_wins_over_the_route_mode(): void {
		$token = MuRuntime::open_session( 7 );
		MuRuntime::request( 'index.php', [], [ 'stonewright_rescue_session' => $token, 'wordpress_logged_in_abc' => 'x' ] );
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/stonewright';
		$_SERVER['HTTP_AUTHORIZATION'] = self::BASIC;

		MuRuntime::start();

		self::assertTrue( \Stonewright_Rescue::is_safe_boot() );
		self::assertSame( 7, \Stonewright_Rescue::safe_boot_user(), 'the session is tied to its administrator' );
	}

	public function test_a_session_that_does_not_apply_leaves_the_route_mode_to_decide(): void {
		$token = MuRuntime::open_session( 7 );
		MuRuntime::request( 'index.php', [], [ 'stonewright_rescue_session' => $token ] );
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/stonewright';
		$_SERVER['HTTP_AUTHORIZATION'] = self::BASIC;

		MuRuntime::start();

		self::assertTrue( \Stonewright_Rescue::is_safe_boot(), 'without a login cookie the session does not apply, the route mode does' );
		self::assertSame( 0, \Stonewright_Rescue::safe_boot_user() );
	}
}
