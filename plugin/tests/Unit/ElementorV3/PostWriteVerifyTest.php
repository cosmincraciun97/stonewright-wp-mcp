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
		$GLOBALS['stonewright_test_posts'][ 701 ] = (object) [
			'ID'           => 701,
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
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_asset_responses'] = [];
		$uploads       = wp_upload_dir();
		$this->css_dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$this->remove_css_assets();
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		unset( $GLOBALS['stonewright_test_posts'][ 701 ] );
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_asset_responses'] = [];
		Post::$factory = null;
		$this->remove_css_assets();
	}

	public function test_ability_is_registered_in_elementor_profile(): void {
		self::assertContains( PostWriteVerify::class, AbilityRegistry::list() );
		self::assertContains( 'stonewright/elementor-post-write-verify', ToolProfile::profile_tools( 'elementor-design' ) );
		$properties = ( new PostWriteVerify() )->input_schema()['properties'];
		self::assertArrayHasKey( 'confirmation_token', $properties );
		self::assertArrayNotHasKey( 'regenerate_css', $properties );
	}

	public function test_invalidates_post_cache_warms_render_and_checks_ids_without_returning_html(): void {
		$css_updates = 0;
		$this->write_css( 'post-999.css', 'sibling' );
		$this->write_css( 'custom-frontend.min.css', 'frontend' );
		$this->write_css( 'custom-pro-widget-nav-menu.min.css', 'pro-nav' );
		Post::$factory = function ( int $post_id ) use ( &$css_updates ): object {
			return new class( $post_id, $this->css_dir, $css_updates ) {
				/** @var int */
				private $updates;

				public function __construct( private int $post_id, private string $css_dir, int &$updates ) {
					$this->updates = &$updates;
				}

				public function update(): void {
					++$this->updates;
					file_put_contents( $this->get_path(), 'post-css' );
				}

				public function get_path(): string {
					return $this->css_dir . '/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					TestCase::assertFalse( $with_css );
					return 701 === $post_id
						? '<div class="elementor-element-hero01">Fresh marker</div>'
						: '';
				}
			},
			'files_manager' => new class() {
				public function clear_cache(): void {
					throw new \RuntimeException( 'Global CSS clear must never run.' );
				}

				public function on_delete_post(): void {
					throw new \RuntimeException( 'CSS delete must never run.' );
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute(
			[
				'post_id'       => 701,
				'element_ids'   => [ 'hero01' ],
				'html_contains' => [ 'Fresh marker' ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'passed', $result['verification_status'] );
		self::assertTrue( $result['cache']['element_cache']['existed'] );
		self::assertTrue( $result['cache']['element_cache']['deleted'] );
		self::assertTrue( $result['element_checks'][0]['present'] );
		self::assertTrue( $result['content_checks'][0]['present'] );
		self::assertSame( 1, $css_updates );
		self::assertSame( 'elementor_post_css_update', $result['css']['method'] );
		self::assertSame( 3, $result['css']['before_file_count'] );
		self::assertSame( 4, $result['css']['after_file_count'] );
		self::assertSame( 'sibling', $this->read_css( 'post-999.css' ) );
		self::assertSame( 'frontend', $this->read_css( 'custom-frontend.min.css' ) );
		self::assertSame( 'pro-nav', $this->read_css( 'custom-pro-widget-nav-menu.min.css' ) );
		self::assertArrayNotHasKey( 'html', $result );
		self::assertStringNotContainsString( 'Fresh marker', (string) wp_json_encode( $result ) );
		self::assertArrayNotHasKey( '_elementor_element_cache', $GLOBALS['stonewright_test_posts'][701]->meta );
	}

	public function test_missing_assertion_returns_failed_not_false_success(): void {
		$this->write_css( 'post-701.css', 'old-post' );
		$original_css_meta = $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_css'];
		$this->configure_post_css_update();
		\Elementor\Plugin::$instance = (object) [
			'frontend' => new class() {
				public function get_builder_content_for_display( int $post_id, bool $with_css ): string {
					TestCase::assertFalse( $with_css );
					return '<div class="elementor-element-other">Rendered</div>';
				}
			},
		];

		$result = ( new PostWriteVerify() )->execute(
			[
				'post_id'       => 701,
				'element_ids'   => [ 'expected' ],
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertFalse( $result['effect_verified'] );
		self::assertSame( 'failed', $result['verification_status'] );
		self::assertSame( 'old-post', $this->read_css( 'post-701.css' ) );
		self::assertSame( $original_css_meta, $GLOBALS['stonewright_test_posts'][701]->meta['_elementor_css'] );
		self::assertSame( '<div>stale</div>', get_post_meta( 701, '_elementor_element_cache', true ) );
		self::assertSame( 'succeeded', $result['css']['rollback_status'] );
		self::assertSame( 'succeeded', $result['cache']['rollback_status'] );
		self::assertFalse( $result['css']['ok'] );
	}

	public function test_production_safe_requires_confirmation_before_css_update(): void {
		$updates = 0;
		Post::$factory = static function () use ( &$updates ): object {
			++$updates;
			return new \stdClass();
		};
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new PostWriteVerify() )->execute( [ 'post_id' => 701 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( 0, $updates );
	}

	private function configure_post_css_update(): void {
		Post::$factory = function ( int $post_id ): object {
			return new class( $post_id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update(): void {
					file_put_contents( $this->get_path(), 'post-css' );
				}

				public function get_path(): string {
					return $this->css_dir . '/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};
	}

	private function write_css( string $name, string $bytes ): void {
		file_put_contents( $this->css_dir . '/' . $name, $bytes );
	}

	private function read_css( string $name ): string {
		return (string) file_get_contents( $this->css_dir . '/' . $name );
	}

	private function remove_css_assets(): void {
		foreach ( [ 'post-701.css', 'post-999.css', 'custom-frontend.min.css', 'custom-pro-widget-nav-menu.min.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
}
