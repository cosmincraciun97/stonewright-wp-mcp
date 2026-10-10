<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use Cloudflare\APO\WordPress\Hooks;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\PageCachePurger;

require_once __DIR__ . '/Fixtures/CloudflareHooksStub.php';

/** @covers \Stonewright\WpMcp\Elementor\PageCachePurger */
final class PageCachePurgerTest extends TestCase {
	private const PRETTY_URL = 'https://example.test/about/';

	/** @var array<int,array{string,array<int,mixed>}> */
	private array $calls = [];

	protected function setUp(): void {
		$this->calls                         = [];
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		unset( $GLOBALS['cloudflareHooks'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_actions'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		unset( $GLOBALS['cloudflareHooks'] );
	}

	/** @return array<string,callable> */
	private function recorders( string ...$names ): array {
		$functions = [];
		foreach ( $names as $name ) {
			$functions[ $name ] = function ( ...$args ) use ( $name ): mixed {
				$this->calls[] = [ $name, $args ];
				return null;
			};
		}
		return $functions;
	}

	public function test_no_page_cache_plugin_runs_only_core_and_says_so(): void {
		$purger = new PageCachePurger( $this->recorders( 'clean_post_cache' ) );

		$result = $purger->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( 'no_page_cache_plugin', $result['skipped_reason'] );
		self::assertSame( [ [ 'clean_post_cache', [ 301 ] ] ], $this->calls );
		self::assertArrayNotHasKey( 'failed', $result );
	}

	public function test_litespeed_cache_is_purged_through_its_post_action(): void {
		$received = [];
		add_action(
			'litespeed_purge_post',
			static function ( ...$args ) use ( &$received ): void {
				$received[] = $args;
			}
		);

		$result = ( new PageCachePurger( $this->recorders( 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'litespeed_cache', 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( [ [ 301 ] ], $received );
		self::assertArrayNotHasKey( 'skipped_reason', $result );
	}

	public function test_an_action_without_a_listener_is_not_called(): void {
		$result = ( new PageCachePurger( $this->recorders( 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertNotContains( 'litespeed_cache', $result['ran'] );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public static function function_purgers(): array {
		return [
			'wp rocket'      => [ 'rocket_clean_post', 'wp_rocket', 'WP Rocket' ],
			'w3 total cache' => [ 'w3tc_flush_post', 'w3_total_cache', 'W3 Total Cache' ],
			'wp super cache' => [ 'wp_cache_post_change', 'wp_super_cache', 'WP Super Cache' ],
			'fastest cache'  => [ 'wpfc_clear_post_cache_by_id', 'wp_fastest_cache', 'WP Fastest Cache' ],
		];
	}

	/** @dataProvider function_purgers */
	public function test_a_function_purger_is_called_with_only_the_post_id( string $function, string $id, string $label ): void {
		$result = ( new PageCachePurger( $this->recorders( $function, 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ $id, 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( [ $function, [ 301 ] ], $this->calls[0] );
		self::assertSame( $label, PageCachePurger::label( $id ) );
		self::assertArrayNotHasKey( 'skipped_reason', $result );
	}

	public function test_siteground_optimizer_is_purged_for_the_post_url_only(): void {
		$result = ( new PageCachePurger( $this->recorders( 'sg_cachepress_purge_cache', 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'siteground_optimizer', 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( [ 'sg_cachepress_purge_cache', [ self::PRETTY_URL ] ], $this->calls[0] );
	}

	/**
	 * Called without a URL, with the home URL or with a plain-permalink query URL, the function purges the
	 * whole site, so the post is not passed to it.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function siteground_unsafe_urls(): array {
		return [
			'empty'           => [ '', 'no_post_url' ],
			'home'            => [ 'https://example.test/', 'url_is_site_root' ],
			'home no slash'   => [ 'https://example.test', 'url_is_site_root' ],
			'plain permalink' => [ 'https://example.test/?p=301', 'url_has_query' ],
			'query on a path' => [ 'https://example.test/about/?x=1', 'url_has_query' ],
			'other host'      => [ 'https://other.test/about/', 'url_not_on_this_site' ],
		];
	}

	/** @dataProvider siteground_unsafe_urls */
	public function test_siteground_optimizer_is_skipped_when_the_url_would_purge_the_whole_site( string $url, string $reason ): void {
		$result = ( new PageCachePurger( $this->recorders( 'sg_cachepress_purge_cache', 'clean_post_cache' ) ) )->run( 301, $url );

		self::assertSame( [ 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( $reason, $result['skipped']['siteground_optimizer'] ?? null );
		self::assertSame( 'no_page_cache_plugin', $result['skipped_reason'] );
		self::assertSame( [ [ 'clean_post_cache', [ 301 ] ] ], $this->calls );
	}

	public function test_cloudflare_plugin_is_purged_for_the_post_id_through_its_hooks_object(): void {
		$hooks                      = new Hooks();
		$GLOBALS['cloudflareHooks'] = $hooks;

		$result = ( new PageCachePurger( $this->recorders( 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'cloudflare', 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( [ 301 ], $hooks->purged );
	}

	public function test_a_foreign_object_in_the_cloudflare_global_is_ignored(): void {
		$GLOBALS['cloudflareHooks'] = new class() {
			public function purgeCacheByRelevantURLs( $post_ids ): void {
				throw new \LogicException( 'must not be called' );
			}
		};

		$result = ( new PageCachePurger( $this->recorders( 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'wordpress_post_cache' ], $result['ran'] );
	}

	public function test_every_present_purger_runs_for_this_post_only_in_a_fixed_order(): void {
		$GLOBALS['cloudflareHooks'] = new Hooks();
		add_action(
			'litespeed_purge_post',
			function ( int $post_id ): void {
				$this->calls[] = [ 'litespeed_purge_post', [ $post_id ] ];
			}
		);
		$functions = $this->recorders(
			'rocket_clean_post',
			'w3tc_flush_post',
			'wp_cache_post_change',
			'wpfc_clear_post_cache_by_id',
			'sg_cachepress_purge_cache',
			'clean_post_cache'
		);

		$result = ( new PageCachePurger( $functions ) )->run( 301, self::PRETTY_URL );

		self::assertSame(
			[ 'litespeed_cache', 'wp_rocket', 'w3_total_cache', 'wp_super_cache', 'wp_fastest_cache', 'siteground_optimizer', 'cloudflare', 'wordpress_post_cache' ],
			$result['ran']
		);
		foreach ( $this->calls as $call ) {
			self::assertContains( $call[1], [ [ 301 ], [ self::PRETTY_URL ] ], $call[0] . ' got an argument that is not this post' );
		}
		self::assertCount( 7, $this->calls );
	}

	public function test_the_filter_returning_false_turns_purging_off_before_anything_runs(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = '__return_false';
		$functions = $this->recorders( 'rocket_clean_post', 'clean_post_cache' );

		$result = ( new PageCachePurger( $functions ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [], $result['ran'] );
		self::assertSame( 'disabled_by_filter', $result['skipped_reason'] );
		self::assertSame( [], $this->calls );
	}

	public function test_the_filter_receives_the_present_purgers_the_post_id_and_the_url(): void {
		$seen = null;
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = static function ( $purgers, $post_id, $url ) use ( &$seen ) {
			$seen = [ array_keys( $purgers ), $post_id, $url ];
			return $purgers;
		};

		( new PageCachePurger( $this->recorders( 'rocket_clean_post', 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ [ 'wp_rocket', 'wordpress_post_cache' ], 301, self::PRETTY_URL ], $seen );
	}

	public function test_the_filter_can_add_a_site_purger(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = function ( $purgers ) {
			$purgers['edge_cdn'] = function ( int $id, string $post_url ): void {
				$this->calls[] = [ 'edge_cdn', [ $id, $post_url ] ];
			};
			return $purgers;
		};

		$result = ( new PageCachePurger( $this->recorders( 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'wordpress_post_cache', 'edge_cdn' ], $result['ran'] );
		self::assertSame( [ 'edge_cdn', [ 301, self::PRETTY_URL ] ], $this->calls[1] );
		self::assertArrayNotHasKey( 'skipped_reason', $result, 'a site purger counts as a page cache purger' );
	}

	public function test_the_filter_can_remove_one_purger(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = static function ( $purgers ) {
			unset( $purgers['wp_rocket'] );
			return $purgers;
		};

		$result = ( new PageCachePurger( $this->recorders( 'rocket_clean_post', 'w3tc_flush_post', 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'w3_total_cache', 'wordpress_post_cache' ], $result['ran'] );
		self::assertNotContains( 'rocket_clean_post', array_column( $this->calls, 0 ) );
	}

	public function test_the_filter_emptying_the_list_counts_as_off(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = static fn(): array => [];

		$result = ( new PageCachePurger( $this->recorders( 'rocket_clean_post', 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [], $result['ran'] );
		self::assertSame( 'disabled_by_filter', $result['skipped_reason'] );
	}

	public function test_entries_that_are_not_callable_are_dropped(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = static fn(): array => [
			'broken' => 'no_such_function_anywhere',
			'edge'   => static function (): void {
			},
		];

		$result = ( new PageCachePurger( $this->recorders( 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'edge' ], $result['ran'] );
	}

	public function test_a_failing_purger_is_reported_and_the_others_still_run(): void {
		$functions                      = $this->recorders( 'w3tc_flush_post', 'clean_post_cache' );
		$functions['rocket_clean_post'] = static function (): void {
			throw new \RuntimeException( '/var/www/secret/path failed' );
		};

		$result = ( new PageCachePurger( $functions ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'w3_total_cache', 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( [ [ 'purger' => 'wp_rocket', 'error_class' => 'RuntimeException' ] ], $result['failed'] );
		self::assertStringNotContainsString( 'secret', (string) wp_json_encode( $result ) );
		self::assertArrayNotHasKey( 'skipped_reason', $result );
	}

	public function test_a_php_error_in_a_purger_is_caught_too(): void {
		$functions                                = $this->recorders( 'clean_post_cache' );
		$functions['wpfc_clear_post_cache_by_id'] = static function (): void {
			throw new \TypeError( 'bad' );
		};

		$result = ( new PageCachePurger( $functions ) )->run( 301, self::PRETTY_URL );

		self::assertSame( 'TypeError', $result['failed'][0]['error_class'] );
		self::assertSame( [ 'wordpress_post_cache' ], $result['ran'] );
	}

	public function test_a_throwing_filter_is_reported_and_the_built_in_purgers_run(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = static function () {
			throw new \RuntimeException( 'site code broke' );
		};

		$result = ( new PageCachePurger( $this->recorders( 'rocket_clean_post', 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( [ 'wp_rocket', 'wordpress_post_cache' ], $result['ran'] );
		self::assertSame( 'filter', $result['failed'][0]['purger'] );
		self::assertSame( 'RuntimeException', $result['failed'][0]['error_class'] );
	}

	public function test_a_failing_cloudflare_purge_is_reported(): void {
		$hooks                      = new Hooks();
		$hooks->throw               = true;
		$GLOBALS['cloudflareHooks'] = $hooks;

		$result = ( new PageCachePurger( $this->recorders( 'clean_post_cache' ) ) )->run( 301, self::PRETTY_URL );

		self::assertSame( 'cloudflare', $result['failed'][0]['purger'] );
		self::assertSame( [ 'wordpress_post_cache' ], $result['ran'] );
	}

	public function test_labels_name_each_purger_and_fall_back_to_the_id(): void {
		self::assertSame( 'LiteSpeed Cache', PageCachePurger::label( 'litespeed_cache' ) );
		self::assertSame( 'SiteGround Optimizer', PageCachePurger::label( 'siteground_optimizer' ) );
		self::assertSame( 'Cloudflare', PageCachePurger::label( 'cloudflare' ) );
		self::assertSame( 'edge_cdn', PageCachePurger::label( 'edge_cdn' ) );
	}

	public function test_page_cache_ran_ignores_the_core_object_cache_purge(): void {
		self::assertFalse( PageCachePurger::page_cache_ran( [ 'ran' => [ 'wordpress_post_cache' ] ] ) );
		self::assertTrue( PageCachePurger::page_cache_ran( [ 'ran' => [ 'wp_rocket', 'wordpress_post_cache' ] ] ) );
		self::assertFalse( PageCachePurger::page_cache_ran( [ 'ran' => [] ] ) );
	}
}
