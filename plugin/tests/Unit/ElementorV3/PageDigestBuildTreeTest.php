<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use Elementor\Core\Files\CSS\Post;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildTree;
use Stonewright\WpMcp\Abilities\ElementorV3\PageDigest;
use Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\PageDigest
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildTree
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases
 */
final class PageDigestBuildTreeTest extends TestCase {

	private int $post_id = 9201;
	private string $css_dir;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'edit_posts' => true,
			'edit_pages' => true,
			'edit_post'  => true,
		];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_home_url'] = 'https://example.test/';
		$uploads       = wp_upload_dir();
		$this->css_dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$this->remove_css_assets();
		file_put_contents( $this->css_dir . '/custom-frontend.min.css', 'frontend-safe' );
		Post::$factory = function ( int $post_id ): object {
			$path = $this->css_dir . '/post-' . $post_id . '.css';
			return new class( $path ) {
				public function __construct( private string $path ) {
				}

				public function update_file(): void {
					file_put_contents( $this->path, 'post-css-safe' );
				}

				public function get_path(): string {
					return $this->path;
				}

				public function get_url(): string {
					return 'https://example.test/wp-content/uploads/elementor/css/' . basename( $this->path );
				}
			};
		};
		$GLOBALS['stonewright_test_posts'][ $this->post_id ] = (object) [
			'ID'           => $this->post_id,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Digest Page',
			'post_content' => '',
			'post_excerpt' => '',
			'post_name'    => 'digest-page',
			'meta'         => [
				'_elementor_edit_mode' => 'builder',
				'_elementor_data'      => wp_json_encode(
					[
						[
							'id'       => 'sec001',
							'elType'   => 'container',
							'settings' => [],
							'elements' => [
								[
									'id'         => 'wid001',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => [ 'title' => 'Hello world from digest' ],
									'elements'   => [],
								],
							],
						],
					]
				),
			],
		];
	}

	protected function tearDown(): void {
		Post::$factory = null;
		$this->remove_css_assets();
		$GLOBALS['stonewright_test_posts'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		unset(
			$GLOBALS['stonewright_test_home_url'],
			$GLOBALS['stonewright_test_before_option_update'],
			$GLOBALS['stonewright_test_option_cas_miss_remaining']
		);
	}

	public function test_registry_has_new_abilities(): void {
		self::assertInstanceOf( PageDigest::class, AbilityRegistry::ability_by_name( 'stonewright/elementor-page-digest' ) );
		self::assertInstanceOf( BuildTree::class, AbilityRegistry::ability_by_name( 'stonewright/elementor-build-tree' ) );
	}

	public function test_settings_key_aliases_normalize_justify_content(): void {
		$result = SettingsKeyAliases::normalize(
			[
				'justify_content' => 'center',
				'bg_color'        => '#fff',
				'letter_spacing'  => [ 'size' => 1.5, 'unit' => 'px' ],
			]
		);
		self::assertSame( 'center', $result['settings']['flex_justify_content'] );
		self::assertArrayNotHasKey( 'justify_content', $result['settings'] );
		self::assertSame( '#fff', $result['settings']['background_color'] );
		self::assertSame( [ 'size' => 1.5, 'unit' => 'px' ], $result['settings']['typography_letter_spacing'] );
		self::assertNotEmpty( $result['applied'] );
	}

	public function test_page_digest_returns_compact_outline(): void {
		$result = ( new PageDigest() )->execute( [ 'post_id' => $this->post_id ] );
		self::assertIsArray( $result );
		self::assertSame( $this->post_id, $result['post_id'] );
		self::assertTrue( $result['active'] );
		self::assertGreaterThanOrEqual( 2, $result['counts']['total'] );
		self::assertNotEmpty( $result['outline'] );
		self::assertArrayHasKey( 'index_path', $result['outline'][0] );
		self::assertStringContainsString( 'Hello', (string) ( $result['outline'][1]['heading'] ?? $result['outline'][0]['heading'] ?? '' ) );
		self::assertLessThan( 800, (int) $result['estimated_tokens'] );
	}

	public function test_build_tree_dry_run_and_path_error(): void {
		$ability = new BuildTree();
		$ok      = $ability->execute(
			[
				'post_id' => $this->post_id,
				'dry_run' => true,
				'tree'    => [
					[
						'id'       => 'a1b2c3d',
						'elType'   => 'container',
						'settings' => [ 'justify_content' => 'center' ],
						'elements' => [],
					],
				],
			]
		);
		self::assertIsArray( $ok );
		self::assertTrue( $ok['ok'] );
		self::assertTrue( $ok['dry_run'] );
		self::assertGreaterThanOrEqual( 1, (int) $ok['aliases_applied'] );

		$err = $ability->execute(
			[
				'post_id' => $this->post_id,
				'dry_run' => true,
				'tree'    => [
					[
						'elType' => 'widget',
						// missing widgetType
						'settings' => [],
						'elements' => [],
					],
				],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $err );
		self::assertStringContainsString( 'tree[0]', $err->get_error_message() );
	}

	public function test_build_tree_writes_with_snapshot(): void {
		$ability = new BuildTree();
		$result  = $ability->execute(
			[
				'post_id' => $this->post_id,
				'tree'    => [
					[
						'id'       => 'root001',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'head001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Built' ],
								'elements'   => [],
							],
						],
					],
				],
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertNotSame( '', $result['snapshot_id'] );
		self::assertGreaterThanOrEqual( 2, (int) $result['element_count'] );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertSame( 'stonewright/elementor-post-write-verify', $result['next_step']['then'] );
		self::assertFileDoesNotExist( $this->css_dir . '/post-' . $this->post_id . '.css' );
		self::assertSame( 'frontend-safe', (string) file_get_contents( $this->css_dir . '/custom-frontend.min.css' ) );
	}

	public function test_build_tree_does_not_regenerate_css(): void {
		$css_calls = 0;
		Post::$factory = function () use ( &$css_calls ): object {
			return new class( $css_calls ) {
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

		$result = ( new BuildTree() )->execute(
			[
				'post_id' => $this->post_id,
				'tree'    => [
					[
						'id'       => 'lease01',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 0, $css_calls );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertArrayNotHasKey( 'stonewright_elementor_lock_' . $this->post_id, $GLOBALS['stonewright_test_options'] );
	}

	public function test_build_tree_continues_when_lock_renew_cas_misses_but_lease_is_still_owned(): void {
		$GLOBALS['stonewright_test_option_cas_miss_remaining'] = 1;

		$result = ( new BuildTree() )->execute(
			[
				'post_id' => $this->post_id,
				'tree'    => [
					[
						'id'       => 'cas001',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'cashead',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'CAS miss still owned' ],
								'elements'   => [],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'stonewright/elementor-css-regenerate', $result['next_step']['tool'] );
		self::assertFileDoesNotExist( $this->css_dir . '/post-' . $this->post_id . '.css' );
		self::assertSame( 'frontend-safe', (string) file_get_contents( $this->css_dir . '/custom-frontend.min.css' ) );
		self::assertStringContainsString( 'CAS miss still owned', (string) get_post_meta( $this->post_id, '_elementor_data', true ) );
	}

	public function test_build_tree_restores_snapshot_when_the_lock_is_lost_after_document_write(): void {
		$original = (string) get_post_meta( $this->post_id, '_elementor_data', true );
		$post_id  = $this->post_id;
		$GLOBALS['stonewright_test_before_option_update'] = static function ( string $option ) use ( $post_id ): void {
			if ( ! str_starts_with( $option, 'stonewright_elementor_lock_' ) ) {
				return;
			}
			$GLOBALS['stonewright_test_options'][ $option ] = [
				'post_id'     => $post_id,
				'owner'       => 'foreign-writer',
				'acquired_at' => time(),
				'expires_at'  => time() + 120,
			];
		};

		$result = ( new BuildTree() )->execute(
			[
				'post_id' => $this->post_id,
				'tree'    => [
					[
						'id'       => 'lost001',
						'elType'   => 'container',
						'settings' => [],
						'elements' => [
							[
								'id'         => 'losthead',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => [ 'title' => 'Should be rolled back' ],
								'elements'   => [],
							],
						],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_lock_lost', $result->get_error_code() );
		self::assertSame( $original, (string) get_post_meta( $this->post_id, '_elementor_data', true ) );
		self::assertSame( 'frontend-safe', (string) file_get_contents( $this->css_dir . '/custom-frontend.min.css' ) );
		self::assertFileDoesNotExist( $this->css_dir . '/post-' . $this->post_id . '.css' );
	}

	private function remove_css_assets(): void {
		foreach ( [ 'post-' . $this->post_id . '.css', 'custom-frontend.min.css' ] as $name ) {
			$path = $this->css_dir . '/' . $name;
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
	}
}
