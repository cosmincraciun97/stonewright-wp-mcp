<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\PostWriteVerify
 */
final class PostWriteVerifyTest extends TestCase {

	private object $elementor_instance;
	private string $css_dir;

	protected function setUp(): void {
		$this->elementor_instance = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_posts'][ 301 ] = (object) [
			'ID'           => 301,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Verify target',
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_element_cache' => '<div>stale</div>',
				'_elementor_css'           => [ 'time' => 1 ],
			],
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_options']        = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$uploads       = wp_upload_dir();
		$this->css_dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$this->remove_css_assets();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		unset( $GLOBALS['stonewright_test_posts'][ 301 ] );
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_asset_responses'] = [];
		Post::$factory = null;
		$this->remove_css_assets();
	}

	public function test_ability_is_registered_in_elementor_profile(): void {
		self::assertContains( PostWriteVerify::class, AbilityRegistry::list() );
		self::assertContains( 'stonewright/elementor-post-write-verify', ToolProfile::profile_tools( 'elementor-design' ) );
		$properties = ( new PostWriteVerify() )->input_schema()['properties'];
		self::assertArrayNotHasKey( 'confirmation_token', $properties );
		self::assertArrayNotHasKey( 'regenerate_css', $properties );
	}

	public function test_successful_bounded_checks_do_not_mutate_css_or_cache(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->write_css( 'post-999.css', 'sibling' );
		$before_manifest = $this->manifest();
		$before_meta     = get_post_meta( 301, '_elementor_css', true );
		$css_regenerator_calls    = 0;
		$cache_invalidator_calls  = 0;
		Post::$factory = function () use ( &$css_regenerator_calls ): object {
			return new class( $css_regenerator_calls ) {
				/** @var int */
				private $calls;
				public function __construct( int &$calls ) {
					$this->calls = &$calls;
				}
				public function update_file(): void {
					++$this->calls;
				}
				public function update(): void {
					++$this->calls;
				}
				public function get_path(): string {
					return '';
				}
				public function get_url(): string {
					return '';
				}
			};
		};
		$original_invalidate = $GLOBALS['stonewright_test_cache_invalidator_calls'] ?? null;
		$GLOBALS['stonewright_test_posts'][ 301 ]->meta['_elementor_element_cache'] = '<div>stale</div>';
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					TestCase::assertFalse( $with_css );
					return 301 === $post_id
						? '<div class="elementor-element-hero01">Fresh marker</div>'
						: '';
				}
			},
			'files_manager' => new class() {
				public function clear_cache(): void {
					throw new \RuntimeException( 'Global CSS clear must never run.' );
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute(
			[
				'post_id'       => 301,
				'element_ids'   => [ 'hero01' ],
				'html_contains' => [ 'Fresh marker' ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'passed', $result['verification_status'] );
		self::assertTrue( $result['element_checks'][0]['present'] );
		self::assertTrue( $result['content_checks'][0]['present'] );
		self::assertTrue( $result['browser_required'] );
		self::assertTrue( $result['browser_recipe']['desktop_tablet_mobile'] );
		self::assertArrayNotHasKey( 'html', $result );
		self::assertStringNotContainsString( 'Fresh marker', (string) wp_json_encode( $result ) );
		self::assertSame( $before_manifest, $this->manifest() );
		self::assertSame( $before_meta, get_post_meta( 301, '_elementor_css', true ) );
		self::assertSame( '<div>stale</div>', get_post_meta( 301, '_elementor_element_cache', true ) );
		self::assertSame( 0, $css_regenerator_calls );
		unset( $original_invalidate );
	}

	public function test_empty_render_fails_without_mutating_css_or_meta(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$before_manifest = $this->manifest();
		$before_meta     = get_post_meta( 301, '_elementor_css', true );
		$css_regenerator_calls   = 0;
		$cache_invalidator_calls = 0;
		Post::$factory = function () use ( &$css_regenerator_calls ): object {
			return new class( $css_regenerator_calls ) {
				/** @var int */
				private $calls;
				public function __construct( int &$calls ) {
					$this->calls = &$calls;
				}
				public function update_file(): void {
					++$this->calls;
				}
				public function update(): void {
					++$this->calls;
				}
			};
		};
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					TestCase::assertFalse( $with_css );
					return '';
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute(
			[
				'post_id'     => 301,
				'element_ids' => [ 'hero01' ],
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertSame( $before_manifest, $this->manifest() );
		self::assertSame( $before_meta, get_post_meta( 301, '_elementor_css', true ) );
		self::assertSame( 0, $css_regenerator_calls );
		self::assertSame( 0, $cache_invalidator_calls );
		self::assertSame( '<div>stale</div>', get_post_meta( 301, '_elementor_element_cache', true ) );
	}

	public function test_renderer_type_error_fails_without_mutating_state(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$before_manifest = $this->manifest();
		$before_meta     = get_post_meta( 301, '_elementor_css', true );
		$css_regenerator_calls   = 0;
		$cache_invalidator_calls = 0;
		Post::$factory = function () use ( &$css_regenerator_calls ): object {
			return new class( $css_regenerator_calls ) {
				/** @var int */
				private $calls;
				public function __construct( int &$calls ) {
					$this->calls = &$calls;
				}
				public function update_file(): void {
					++$this->calls;
				}
				public function update(): void {
					++$this->calls;
				}
			};
		};
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					throw new \TypeError( 'synthetic renderer type error' );
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 301 ] );

		self::assertFalse( $result instanceof \WP_Error ? false : $result['ok'] );
		if ( is_array( $result ) ) {
			self::assertFalse( $result['ok'] );
		}
		self::assertSame( $before_manifest, $this->manifest() );
		self::assertSame( $before_meta, get_post_meta( 301, '_elementor_css', true ) );
		self::assertSame( 0, $css_regenerator_calls );
		self::assertSame( 0, $cache_invalidator_calls );
	}

	public function test_production_safe_does_not_require_a_confirmation_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					return '<div class="elementor-element-hero01">Fresh marker</div>';
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute(
			[
				'post_id'       => 301,
				'element_ids'   => [ 'hero01' ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
	}

	/** @return array<string,string> */
	private function manifest(): array {
		$files = [];
		foreach ( scandir( $this->css_dir ) ?: [] as $name ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) ) {
				$files[ $name ] = hash( 'sha256', (string) file_get_contents( $path ) ) . ':' . ( fileperms( $path ) & 0777 );
			}
		}
		ksort( $files );
		return $files;
	}

	private function write_css( string $name, string $bytes ): void {
		file_put_contents( $this->css_dir . '/' . $name, $bytes );
	}

	private function remove_css_assets(): void {
		foreach ( [ 'post-301.css', 'post-701.css', 'post-999.css', 'custom-frontend.min.css', 'custom-pro-widget-nav-menu.min.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
}
