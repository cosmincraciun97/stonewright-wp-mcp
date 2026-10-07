<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * What kind of request the helper takes a request for, and which REST route it thinks WordPress
 * will serve. The entry script decides the kind: only index.php, the front controller, can be a
 * "rest" request or a "front" page, and the REST route is read the way WordPress reads it.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueRequestContextTest extends TestCase {

	protected function setUp(): void {
		MuRuntime::begin();
	}

	protected function tearDown(): void {
		MuRuntime::end();
	}

	/**
	 * Starts a request and returns what a private method of the helper says about it.
	 *
	 * @param array<string, mixed> $post
	 */
	private function ask( string $method, string $script, string $uri, array $post = [], string $home = 'https://example.test' ): mixed {
		MuRuntime::set_option( 'home', $home );
		parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $get );
		MuRuntime::request( $script, $get, [], 'GET' );
		$_POST                  = $post;
		$_SERVER['REQUEST_URI'] = $uri;

		return ( new \ReflectionMethod( \Stonewright_Rescue::class, $method ) )->invoke( null );
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> The entry script, the URI and the context. */
	public static function contexts(): array {
		$route = '?rest_route=/mcp/stonewright';
		return [
			'the login page'                             => [ 'wp-login.php', '/wp-login.php', 'login' ],
			'the login page with a route parameter'      => [ 'wp-login.php', '/wp-login.php' . $route, 'login' ],
			'the login page in capitals'                 => [ 'WP-LOGIN.PHP', '/WP-LOGIN.PHP' . $route, 'other' ],
			'the dashboard'                              => [ 'wp-admin/index.php', '/wp-admin/' . $route, 'admin' ],
			'admin-ajax'                                 => [ 'wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php' . $route, 'admin' ],
			'admin-post'                                 => [ 'wp-admin/admin-post.php', '/wp-admin/admin-post.php', 'admin' ],
			'the network dashboard'                      => [ 'wp-admin/network/index.php', '/wp-admin/network/' . $route, 'admin' ],
			'the dashboard in capitals'                  => [ 'WP-ADMIN/index.php', '/WP-ADMIN/' . $route, 'admin' ],
			'xmlrpc.php'                                 => [ 'xmlrpc.php', '/xmlrpc.php' . $route, 'other' ],
			'wp-cron.php'                                => [ 'wp-cron.php', '/wp-cron.php' . $route, 'other' ],
			'wp-comments-post.php'                       => [ 'wp-comments-post.php', '/wp-comments-post.php' . $route, 'other' ],
			'wp-signup.php'                              => [ 'wp-signup.php', '/wp-signup.php' . $route, 'other' ],
			'wp-activate.php'                            => [ 'wp-activate.php', '/wp-activate.php' . $route, 'other' ],
			'wp-trackback.php'                           => [ 'wp-trackback.php', '/wp-trackback.php' . $route, 'other' ],
			'wp-mail.php'                                => [ 'wp-mail.php', '/wp-mail.php' . $route, 'other' ],
			'a script of a plugin'                       => [ 'wp-content/plugins/shop/ajax.php', '/wp-content/plugins/shop/ajax.php' . $route, 'other' ],
			'a script that only ends like index.php'     => [ 'not-index.php', '/not-index.php' . $route, 'other' ],
			'the front page'                             => [ 'index.php', '/', 'front' ],
			'a page'                                     => [ 'index.php', '/sample-page/', 'front' ],
			'a page that mentions the REST base'         => [ 'index.php', '/news/wp-json/mcp/stonewright', 'front' ],
			'a page with the REST base in a query value' => [ 'index.php', '/?next=/wp-json/mcp/stonewright', 'front' ],
			'a REST request, pretty form'                => [ 'index.php', '/wp-json/wp/v2/posts', 'rest' ],
			'a REST request, index.php form'             => [ 'index.php', '/index.php/wp-json/wp/v2/posts', 'rest' ],
			'a REST request, plain form'                 => [ 'index.php', '/index.php' . $route, 'rest' ],
			'a REST request, plain form on a page path'  => [ 'index.php', '/sample-page/' . $route, 'rest' ],
			'the REST index'                             => [ 'index.php', '/wp-json/', 'rest' ],
			'the REST base without a slash'              => [ 'index.php', '/wp-json', 'rest' ],
		];
	}

	/** @dataProvider contexts */
	public function test_the_entry_script_decides_the_kind_of_request( string $script, string $uri, string $context ): void {
		self::assertSame( $context, $this->ask( 'request_context', $script, $uri ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: string|null}> The entry script, the URI and the route. */
	public static function routes(): array {
		$route = '?rest_route=/mcp/stonewright';
		return [
			'the login page with a route parameter'  => [ 'wp-login.php', '/wp-login.php' . $route, null ],
			'the login page behind the REST base'    => [ 'wp-login.php', '/wp-json/mcp/stonewright', null ],
			'the dashboard with a route parameter'   => [ 'wp-admin/index.php', '/wp-admin/' . $route, null ],
			'admin-ajax with a route parameter'      => [ 'wp-admin/admin-ajax.php', '/wp-admin/admin-ajax.php' . $route, null ],
			'the dashboard in capitals'              => [ 'WP-ADMIN/index.php', '/WP-ADMIN/' . $route, null ],
			'xmlrpc.php with a route parameter'      => [ 'xmlrpc.php', '/xmlrpc.php' . $route, null ],
			'a plugin script with a route parameter' => [ 'wp-content/plugins/shop/ajax.php', '/wp-content/plugins/shop/ajax.php' . $route, null ],
			'the front page'                         => [ 'index.php', '/', null ],
			'the REST base in the middle of a path'  => [ 'index.php', '/news/wp-json/mcp/stonewright', null ],
			'the pretty form'                        => [ 'index.php', '/wp-json/mcp/stonewright', '/mcp/stonewright' ],
			'the pretty form, a trailing slash'      => [ 'index.php', '/wp-json/mcp/stonewright/', '/mcp/stonewright' ],
			'the pretty form, slashes doubled'       => [ 'index.php', '//wp-json//mcp/stonewright', '/mcp/stonewright' ],
			'the pretty form, an encoded slash'      => [ 'index.php', '/wp-json/mcp%2Fstonewright', '/mcp/stonewright' ],
			'the index.php form'                     => [ 'index.php', '/index.php/wp-json/mcp/stonewright', '/mcp/stonewright' ],
			'the REST index'                         => [ 'index.php', '/wp-json/', '/' ],
			'the plain form'                         => [ 'index.php', '/index.php' . $route, '/mcp/stonewright' ],
			'the plain form, the query beats a path' => [ 'index.php', '/wp-json/wp/v2/posts' . $route, '/mcp/stonewright' ],
			'an empty route parameter'               => [ 'index.php', '/wp-json/mcp/stonewright?rest_route=', null ],
		];
	}

	/** @dataProvider routes */
	public function test_the_route_is_read_the_way_wordpress_reads_it( string $script, string $uri, ?string $route ): void {
		self::assertSame( $route, $this->ask( 'rest_route', $script, $uri ) );
	}

	public function test_the_form_field_beats_the_query_and_the_path(): void {
		self::assertSame( '/wp/v2/users', $this->ask( 'rest_route', 'index.php', '/wp-json/mcp/stonewright?rest_route=/mcp/stonewright', [ 'rest_route' => '/wp/v2/users' ] ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: string|null}> The URI, the home URL and the route. */
	public static function home_paths(): array {
		return [
			'a site in a sub-directory'                       => [ '/blog/wp-json/mcp/stonewright', 'https://example.test/blog', '/mcp/stonewright' ],
			'the home path in another case'                   => [ '/blog/wp-json/mcp/stonewright', 'https://example.test/Blog', '/mcp/stonewright' ],
			'index.php after the home path'                   => [ '/blog/index.php/wp-json/mcp/stonewright', 'https://example.test/blog/', '/mcp/stonewright' ],
			'the REST base outside the home path'             => [ '/wp-json/mcp/stonewright', 'https://example.test/blog', null ],
			'a path that only starts like the home path'      => [ '/blogger/wp-json/mcp/stonewright', 'https://example.test/blog', null ],
			'a path as long as the home path'                 => [ '/abcd/wp-json/mcp/stonewright', 'https://example.test/blog', null ],
			'the home path twice'                             => [ '/blog/blog/wp-json/mcp/stonewright', 'https://example.test/blog', null ],
			'a nested home path'                              => [ '/a/b/wp-json/mcp/stonewright', 'https://example.test/a/b', '/mcp/stonewright' ],
		];
	}

	/** @dataProvider home_paths */
	public function test_the_rest_base_has_to_follow_the_home_path( string $uri, string $home, ?string $route ): void {
		self::assertSame( $route, $this->ask( 'rest_route', 'index.php', $uri, [], $home ) );
	}
}
