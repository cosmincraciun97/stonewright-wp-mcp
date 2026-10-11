<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ThemeBuilder;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate;

/**
 * @covers \Stonewright\WpMcp\Abilities\ThemeBuilder\ApplyTemplate
 */
final class ApplyTemplateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_next_post_id']           = 8100;
		$GLOBALS['stonewright_test_user_logged_in']         = true;
		$GLOBALS['stonewright_test_user_caps']              = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_can_callback']      = static fn( string $cap ): bool => in_array( $cap, [ 'edit_posts', 'edit_post', 'edit_theme_options' ], true );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']                = [];
		$GLOBALS['stonewright_test_posts']                  = [];
		$GLOBALS['stonewright_test_post_meta_calls']        = [];
		$GLOBALS['stonewright_test_inserted_posts']         = [];
		$GLOBALS['stonewright_test_user_logged_in']         = false;
		$GLOBALS['stonewright_test_user_caps']              = [];
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
	}

	public function test_applies_template_spec_conditions_cache_and_verify_hint_in_one_call(): void {
		$result = ( new ApplyTemplate() )->execute(
			[
				'title'         => 'Single Exhibitor',
				'template_type' => 'single',
				'conditions'    => [
					[ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'exhibitor' ],
				],
				'spec'          => self::minimal_spec(),
				'verify_url'    => 'https://example.test/exhibitors/acme',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['applied'] );
		self::assertTrue( $result['created'] );
		self::assertSame( 'single', $result['template_type'] );
		self::assertSame( 8100, $result['template_id'] );
		self::assertSame( 'stonewright/theme-builder-apply-template', $result['next_required_call']['ability'] );
		self::assertSame( 'stonewright-theme-builder-apply-template', $result['next_required_call']['mcp_tool'] );
		self::assertSame( [], $result['repair_hints'] );
		self::assertSame( 200, $result['verify']['http_status'] );
		self::assertGreaterThan( 0, $result['element_count'] );

		$meta = $GLOBALS['stonewright_test_posts'][8100]->meta ?? [];
		self::assertSame( 'single', $meta['_elementor_template_type'] ?? null );
		self::assertSame( [ 'include/singular/exhibitor' ], $meta['_elementor_conditions'] ?? null );
		self::assertNotEmpty( $meta['_elementor_data'] ?? '' );

		$cache = $GLOBALS['stonewright_test_options']['elementor_pro_theme_builder_conditions'] ?? [];
		self::assertSame( [ 'include/singular/exhibitor' ], $cache['single'][8100] ?? null );
	}

	public function test_dry_run_returns_preview_without_creating_template(): void {
		$result = ( new ApplyTemplate() )->execute(
			[
				'title'         => 'Dry Run Header',
				'template_type' => 'header',
				'conditions'    => [ [ 'type' => 'include', 'name' => 'general' ] ],
				'spec'          => self::minimal_spec(),
				'dry_run'       => true,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertFalse( $result['applied'] );
		self::assertSame( 0, $result['template_id'] );
		self::assertSame( [], $GLOBALS['stonewright_test_inserted_posts'] );
		self::assertNotEmpty( $result['preview'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function foreign_verify_urls(): array {
		return [
			'other host'                 => [ 'https://other.example.org/page' ],
			'loopback'                   => [ 'http://127.0.0.1:8080/status' ],
			'cloud metadata address'     => [ 'http://169.254.169.254/latest/meta-data/' ],
			'private network'            => [ 'http://10.0.0.5/admin' ],
			'host that starts with ours' => [ 'https://example.test.other.example.org/' ],
			'credentials before our host' => [ 'https://user:pass@example.test/page' ],
			'userinfo trick'             => [ 'https://example.test@other.example.org/' ],
			'other port on our host'     => [ 'https://example.test:8443/page' ],
			'file scheme'                => [ 'file:///etc/passwd' ],
			'ftp scheme'                 => [ 'ftp://example.test/page' ],
			'no scheme'                  => [ '//other.example.org/page' ],
		];
	}

	/**
	 * @dataProvider foreign_verify_urls
	 */
	public function test_verify_url_on_another_host_is_never_requested_and_reports_no_status( string $url ): void {
		$requested = [];
		$GLOBALS['stonewright_test_home_url']       = 'https://example.test/';
		$GLOBALS['stonewright_test_wp_remote_get_calls'] = [];
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $called ) use ( &$requested ): array {
			$requested[] = $called;
			return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => 'secret-internal-body' ];
		};

		$result = ( new ApplyTemplate() )->execute(
			[
				'title'         => 'Single Exhibitor',
				'template_type' => 'single',
				'conditions'    => [ [ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'exhibitor' ] ],
				'spec'          => self::minimal_spec(),
				'verify_url'    => $url,
			]
		);

		self::assertIsArray( $result );
		self::assertSame( [], $requested, 'the URL must not be fetched' );
		self::assertNotContains( $url, array_column( $GLOBALS['stonewright_test_wp_remote_get_calls'], 'url' ) );
		self::assertTrue( $result['verify']['requested'] );
		self::assertFalse( $result['verify']['ok'] );
		self::assertSame( 0, $result['verify']['http_status'] );
		self::assertArrayNotHasKey( 'body_bytes', $result['verify'] );
		self::assertStringContainsString( 'this site', (string) ( $result['verify']['error'] ?? '' ) );
		unset( $GLOBALS['stonewright_test_asset_responses'][ $url ], $GLOBALS['stonewright_test_home_url'] );
	}

	public function test_verify_url_on_the_site_host_uses_the_safe_request_function_without_redirects(): void {
		$calls = [];
		$url   = 'https://Example.test/exhibitors/acme';
		$GLOBALS['stonewright_test_home_url']       = 'https://example.test/';
		$GLOBALS['stonewright_test_wp_remote_get_calls'] = [];
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function ( string $called, array $args ) use ( &$calls ): array {
			$calls[] = [ $called, $args ];
			return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => 'page' ];
		};

		$result = ( new ApplyTemplate() )->execute(
			[
				'title'         => 'Single Exhibitor',
				'template_type' => 'single',
				'conditions'    => [ [ 'type' => 'include', 'name' => 'singular', 'sub_name' => 'exhibitor' ] ],
				'spec'          => self::minimal_spec(),
				'verify_url'    => $url,
			]
		);

		self::assertIsArray( $result );
		self::assertCount( 1, $calls );
		self::assertSame( 0, $calls[0][1]['redirection'] ?? null );
		self::assertNotContains( $url, array_column( $GLOBALS['stonewright_test_wp_remote_get_calls'], 'url' ), 'wp_remote_get must not be used for the verify URL' );
		self::assertSame( 200, $result['verify']['http_status'] );
		self::assertTrue( $result['verify']['ok'] );
		unset( $GLOBALS['stonewright_test_asset_responses'][ $url ], $GLOBALS['stonewright_test_home_url'] );
	}

	private static function minimal_spec(): array {
		return [
			'page'     => [ 'title' => 'Template' ],
			'sections' => [
				[
					'id'     => 'content',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Exhibitor' ],
					],
				],
			],
		];
	}
}
