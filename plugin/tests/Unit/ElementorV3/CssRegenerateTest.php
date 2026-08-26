<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\CssRegenerate;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\CssRegenerate
 */
final class CssRegenerateTest extends TestCase {
	private string $css_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_css_regenerate_events'] = [];
		$GLOBALS['stonewright_test_posts'][ 301 ] = (object) [
			'ID'           => 301,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'CSS target',
			'post_content' => '',
			'post_excerpt' => '',
			'meta'         => [
				'_elementor_css' => [ 'time' => 1 ],
			],
		];
		$GLOBALS['stonewright_test_user_caps']        = [ 'edit_post' => true ];
		$GLOBALS['stonewright_test_user_logged_in']   = true;
		$GLOBALS['stonewright_test_options']          = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_asset_responses']  = [];
		$uploads       = wp_upload_dir();
		$this->css_dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$this->remove_css_assets();
	}

	protected function tearDown(): void {
		Post::$factory = null;
		unset( $GLOBALS['stonewright_test_posts'][ 301 ], $GLOBALS['stonewright_test_posts'][ 202 ] );
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_asset_responses'] = [];
		unset( $GLOBALS['stonewright_test_css_regenerate_events'] );
		$this->remove_css_assets();
	}

	public function test_ability_contract_is_registered_and_gated(): void {
		self::assertContains( CssRegenerate::class, AbilityRegistry::list() );
		self::assertContains( 'stonewright/elementor-css-regenerate', ToolProfile::profile_tools( 'elementor-design' ) );

		$ability = new CssRegenerate();
		self::assertSame( 'stonewright/elementor-css-regenerate', $ability->name() );
		$properties = $ability->input_schema()['properties'];
		self::assertArrayHasKey( 'post_id', $properties );
		self::assertSame( [ 'auto', 'post', 'loop' ], $properties['asset_kind']['enum'] );
		self::assertArrayHasKey( 'confirmation_token', $properties );
		self::assertArrayHasKey( 'write_receipt', $properties );
		self::assertTrue( $ability->permission_callback( [ 'post_id' => 301 ] ) );
	}

	public function test_records_permission_confirmation_backup_lock_lease_update_health_audit(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		$ability = new CssRegenerate();

		$permission = $ability->permission_callback( [ 'post_id' => 301 ] );
		self::assertTrue( $permission );

		$result = $ability->execute( [ 'post_id' => 301, 'asset_kind' => 'auto' ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 'post', $result['asset_kind'] );
		self::assertSame( 'post-301.css', $result['filename'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['path_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['url_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['before_manifest_sha256'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['after_manifest_sha256'] );
		self::assertNotSame( $result['before_manifest_sha256'], $result['after_manifest_sha256'] );
		self::assertNotEmpty( $result['probes'] );
		self::assertNotSame( '', $result['backup']['snapshot_id'] ?? '' );
		self::assertArrayNotHasKey( 'path', $result );
		self::assertArrayNotHasKey( 'url', $result );
		self::assertStringNotContainsString( $this->css_dir, (string) wp_json_encode( $result ) );

		$events = $GLOBALS['stonewright_test_css_regenerate_events'];
		self::assertSame(
			[ 'permission', 'confirmation', 'backup', 'post_lock', 'css_lease', 'update_file', 'health', 'audit' ],
			$events
		);
		self::assertNotContains( 'render', $events );
		self::assertNotContains( 'clear_cache', $events );
		self::assertNotContains( 'delete_css', $events );
		self::assertNotContains( 'update', $events );
	}

	public function test_production_safe_requires_confirmation_before_backup(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$updates = 0;
		Post::$factory = static function () use ( &$updates ): object {
			++$updates;
			return new \stdClass();
		};
		$ability = new CssRegenerate();
		$ability->permission_callback( [ 'post_id' => 301 ] );

		$result = $ability->execute( [ 'post_id' => 301 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( 0, $updates );
		self::assertSame( [ 'permission' ], $GLOBALS['stonewright_test_css_regenerate_events'] );
	}

	public function test_returns_transaction_rollback_status_on_failure(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->write_css( 'post-999.css', 'sibling' );
		Post::$factory = function ( int $post_id ): object {
			return new class( $post_id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update_file(): void {
					file_put_contents( $this->get_path(), 'partial-post' );
					unlink( $this->css_dir . '/post-999.css' );
				}

				public function get_path(): string {
					return $this->css_dir . '/post-' . $this->post_id . '.css';
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/post-' . $this->post_id . '.css';
				}
			};
		};

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'old-post', $this->read_css( 'post-301.css' ) );
		self::assertSame( 'sibling', $this->read_css( 'post-999.css' ) );
		self::assertSame( 'succeeded', $result->get_error_data()['rollback_status'] ?? null );
	}

	private function configure_update_file( int $post_id ): void {
		Post::$factory = function ( int $id ) use ( $post_id ): object {
			self::assertSame( $post_id, $id );
			return new class( $id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update_file(): void {
					file_put_contents( $this->get_path(), 'post-css' );
				}

				public function update(): void {
					throw new \RuntimeException( 'update() must not run' );
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
		foreach ( [ 'post-301.css', 'post-999.css', 'loop-202.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
}
