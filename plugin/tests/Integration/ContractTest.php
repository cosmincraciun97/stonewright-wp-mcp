<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Ability;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\ErrorEnvelope;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 * @covers \Stonewright\WpMcp\Support\ErrorEnvelope
 */
final class ContractTest extends TestCase {

	private const FIXTURE_DIR = __DIR__ . '/../fixtures/abilities';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = array_fill_keys(
			[
				'read',
				'edit_posts',
				'edit_pages',
				'manage_options',
				'edit_plugins',
				'edit_themes',
				'upload_files',
				'edit_theme_options',
				'publish_posts',
				'publish_pages',
			],
			true
		);
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_elementor_v4_atomic' => true,
			'stonewright_memory_enabled'      => true,
			'elementor_active_kit'            => 4,
			// Contract fixture for elementor-add-html uses allow_html_widget=true;
			// site option must be on for that path. Production default remains off.
			'stonewright_allow_html_widgets'  => true,
		];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_next_post_id']    = 1001;
		$GLOBALS['stonewright_test_posts']           = $this->posts();
		$GLOBALS['stonewright_test_comments'] = [
			1 => [
				'comment_ID' => 1,
				'comment_post_ID' => 1001,
				'comment_content' => 'hello',
				'comment_approved' => '1',
				'comment_author' => 'A',
				'comment_date' => '2026-01-01 00:00:00',
			],
		];
		$GLOBALS['stonewright_test_users'] = [
			1 => (object) [ 'ID' => 1, 'user_login' => 'admin', 'user_email' => 'a@example.com', 'display_name' => 'Admin', 'roles' => [ 'administrator' ] ],
			2 => (object) [ 'ID' => 2, 'user_login' => 'editor', 'user_email' => 'e@example.com', 'display_name' => 'Editor', 'roles' => [ 'editor' ] ],
		];
		$GLOBALS['stonewright_test_revisions'] = [
			1 => [
				[ 'ID' => 99001, 'post_parent' => 1, 'post_title' => 'Rev', 'post_content' => 'body', 'post_modified' => '2026-01-01', 'post_type' => 'revision' ],
			],
		];
		$GLOBALS['stonewright_test_posts'][99001] = (object) [
			'ID' => 99001,
			'post_type' => 'revision',
			'post_parent' => 1,
			'post_excerpt' => '',
			'post_title' => 'Rev',
			'post_content' => 'body',
			'post_modified' => '2026-01-01',
			'post_status' => 'inherit',
		];
		$GLOBALS['stonewright_test_user_caps']['moderate_comments'] = true;
		$GLOBALS['stonewright_test_user_caps']['list_users'] = true;
		$GLOBALS['stonewright_test_user_caps']['create_users'] = true;
		$GLOBALS['stonewright_test_user_caps']['edit_users'] = true;
		$GLOBALS['stonewright_test_user_caps']['delete_users'] = true;
		$GLOBALS['stonewright_test_user_caps']['switch_themes'] = true;
		$GLOBALS['stonewright_test_user_caps']['activate_plugins'] = true;
		$GLOBALS['stonewright_test_user_caps']['delete_plugins'] = true;
		$GLOBALS['stonewright_test_user_caps']['manage_woocommerce'] = true;
		$GLOBALS['stonewright_test_user_caps']['edit_css'] = true;
		// Wave-4 ACF/SEO contract fixtures: enable stubs.
		$GLOBALS['stonewright_test_acf_active']  = true;
		$GLOBALS['stonewright_test_acf_fields']  = [ 'color' => 'red' ];
		$GLOBALS['stonewright_test_acf_groups']  = [
			[ 'key' => 'group_test', 'title' => 'Test', 'active' => true, 'location' => [] ],
		];
		$GLOBALS['stonewright_test_seo_plugin']  = 'yoast';
		$GLOBALS['stonewright_test_post_types']  = [
			'post' => (object) [ 'name' => 'post', 'label' => 'Posts', 'public' => true ],
			'page' => (object) [ 'name' => 'page', 'label' => 'Pages', 'public' => true ],
		];

		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_companion_responses'] = [];
		// Seed nav-menu state so menu-* contract fixtures have a real menu to
		// reference (id=5001) and a real registered location to assign to.
		// The menu-create fixture builds its own menu under a different name,
		// so this baseline does not collide with it.
		$GLOBALS['stonewright_test_nav_menus']             = [
			5001 => (object) [
				'term_id' => 5001,
				'name'    => 'Contract Menu',
				'slug'    => 'contract-menu',
				'items'   => [],
			],
		];
		$GLOBALS['stonewright_test_theme_mods']            = [];
		$GLOBALS['stonewright_test_registered_nav_menus']  = [
			'primary' => 'Primary Menu',
			'footer'  => 'Footer Menu',
		];
		$GLOBALS['stonewright_test_next_nav_menu_id']      = 6001;
		$GLOBALS['stonewright_test_next_nav_menu_item_id'] = 6101;
		$this->prepare_sandbox_files();
		$this->seed_contract_snapshot();
		$this->seed_theme_backup();
		$this->seed_stock_http_mock();
	}

	/**
	 * Snapshot used by change-restore success fixture.
	 */
	private function seed_contract_snapshot(): void {
		$post_id = 1;
		if ( ! isset( $GLOBALS['stonewright_test_posts'][ $post_id ] ) ) {
			return;
		}
		$snapshots = [
			'snap_contract_restore' => [
				'snapshot_id'  => 'snap_contract_restore',
				'created_at'   => time(),
				'post_title'   => 'Contract Post',
				'post_status'  => 'publish',
				'post_content' => 'Restored content',
				'post_excerpt' => '',
				'meta'         => [],
				'meta_absent'  => [],
			],
		];
		$GLOBALS['stonewright_test_posts'][ $post_id ]->meta['_stonewright_backups'] = $snapshots;
		if ( ! isset( $GLOBALS['stonewright_test_post_meta'] ) ) {
			$GLOBALS['stonewright_test_post_meta'] = [];
		}
		$GLOBALS['stonewright_test_post_meta'][ $post_id ]['_stonewright_backups'] = $snapshots;
	}

	/**
	 * Opaque owned backup used by theme-backup-restore contract fixtures.
	 */
	private function seed_theme_backup(): void {
		$theme_dir = get_stylesheet_directory();
		$target    = $theme_dir . '/style.css';
		$backup    = WP_CONTENT_DIR . '/uploads/stonewright-theme-contract.swbak';
		$bytes     = "/* restored Stonewright contract theme */\n";
		wp_mkdir_p( dirname( $backup ) );
		file_put_contents( $backup, $bytes );
		file_put_contents( $target, "/* current Stonewright contract theme */\n" );
		$canonical_target = realpath( $target );

		$GLOBALS['stonewright_test_options']['stonewright_theme_backup_index'] = [
			'sw-theme-backup-00000000-0000-4000-8000-000000000001' => [
				'absolute'    => wp_normalize_path( false === $canonical_target ? $target : $canonical_target ),
				'relative'    => 'style.css',
				'backup_path' => wp_normalize_path( $backup ),
				'sha256'      => hash( 'sha256', $bytes ),
				'created_at'  => '2026-07-23 00:00:00',
			],
		];
	}

	/**
	 * Openverse-shaped response for stock-image-search contract fixtures.
	 */
	private function seed_stock_http_mock(): void {
		$GLOBALS['stonewright_test_wp_remote_get'] = static function ( string $url, array $args = [] ): array {
			if ( str_contains( $url, 'api.openverse.org' ) ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[
							'results' => [
								[
									'id'            => 'ov-1',
									'title'         => 'Timber House',
									'url'           => 'https://example.test/stock/photo.jpg',
									'thumbnail'     => 'https://example.test/stock/photo-thumb.jpg',
									'width'         => 1200,
									'height'        => 800,
									'creator'       => 'Test Photographer',
									'license'       => 'cc0',
									'license_url'   => 'https://creativecommons.org/publicdomain/zero/1.0/',
									'foreign_landing_url' => 'https://example.test/landing',
								],
							],
						]
					),
				];
			}

			return [
				'response' => [ 'code' => 200 ],
				'body'     => wp_json_encode( [ 'results' => [] ] ),
			];
		};
	}

	/**
	 * @return array<string, array{class: class-string<Ability>, slug: string}>
	 */
	public static function ability_provider(): array {
		$abilities = [];
		foreach ( AbilityRegistry::list() as $class ) {
			$ability = new $class();
			$slug    = self::fixture_slug( $ability->name() );
			$abilities[ $ability->name() ] = [
				'class' => $class,
				'slug'  => $slug,
			];
		}
		return $abilities;
	}

	/**
	 * @dataProvider ability_provider
	 * @param class-string<Ability> $class
	 */
	public function test_ability_response_matches_declared_contract( string $class, string $slug ): void {
		$fixture = $this->load_fixture( $slug . '.json' );
		$ability = new $class();
		$args    = (array) ( $fixture['args'] ?? $fixture );

		$this->assertArrayNotHasKey( 'skip', $fixture, $slug . '.json must not skip contract coverage.' );
		$this->assertNotSame( 'wp_error', $fixture['expect'] ?? 'ok', $slug . '.json must be a successful output-schema fixture.' );

		$result = $ability->execute( $args );

		$failure = $ability->name();
		if ( $result instanceof \WP_Error ) {
			$failure .= ' — ' . $result->get_error_code() . ': ' . $result->get_error_message();
		}
		$this->assertNotInstanceOf( \WP_Error::class, $result, $failure );
		$this->assert_schema_matches( $ability->output_schema(), $result, $ability->name() );
	}

	/**
	 * @dataProvider ability_provider
	 * @param class-string<Ability> $class
	 */
	public function test_error_fixture_returns_standard_error_envelope( string $class, string $slug ): void {
		$fixture = $this->load_fixture( $slug . '.error.json' );
		$this->assertArrayNotHasKey( 'skip', $fixture, $slug . '.error.json must not skip negative contract coverage.' );

		$ability = new $class();
		if ( true === ( $fixture['expect_permission_error'] ?? false ) ) {
			$args = (array) ( $fixture['permission_args'] ?? $fixture['args'] ?? [] );
			$this->deny_current_user();

			$result = $ability->permission_callback( $args );
			if ( false === $result ) {
				$result = new \WP_Error(
					'stonewright_permission_denied',
					__( 'Current user cannot run this Stonewright ability.', 'stonewright' ),
					[ 'status' => 403 ]
				);
			}

			$this->assertInstanceOf( \WP_Error::class, $result, $ability->name() );
			$this->assert_schema_matches( ErrorEnvelope::schema(), ErrorEnvelope::from_wp_error( $result ), $ability->name() . ' permission error envelope' );
			return;
		}

		$result  = $ability->execute( (array) ( $fixture['args'] ?? $fixture ) );

		$this->assertInstanceOf( \WP_Error::class, $result, $ability->name() );
		$this->assert_schema_matches( ErrorEnvelope::schema(), ErrorEnvelope::from_wp_error( $result ), $ability->name() . ' error envelope' );
	}

	private static function fixture_slug( string $ability_name ): string {
		return str_replace( [ 'stonewright/', '/', '.' ], [ '', '-', '-' ], $ability_name );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function load_fixture( string $filename ): array {
		$path = self::FIXTURE_DIR . '/' . $filename;
		$this->assertFileExists( $path );

		$decoded = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $decoded, $filename );
		return $decoded;
	}

	/**
	 * @param array<string, mixed> $schema
	 */
	private function assert_schema_matches( array $schema, mixed $value, string $label ): void {
		$errors = [];
		$this->validate_schema( $schema, $value, '$', $errors );
		$this->assertSame( [], $errors, $label . "\n" . implode( "\n", $errors ) );
	}

	/**
	 * @param array<string, mixed> $schema
	 * @param array<int, string>   $errors
	 */
	private function validate_schema( array $schema, mixed $value, string $path, array &$errors ): void {
		$type = $schema['type'] ?? null;
		if ( is_array( $type ) ) {
			$matched = false;
			foreach ( $type as $single_type ) {
				if ( $this->matches_type( (string) $single_type, $value ) ) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				$errors[] = "{$path}: expected one of [" . implode( ', ', $type ) . '], got ' . gettype( $value );
				return;
			}
		} elseif ( is_string( $type ) && ! $this->matches_type( $type, $value ) ) {
			$errors[] = "{$path}: expected {$type}, got " . gettype( $value );
			return;
		}

		if ( 'object' === $type && $value instanceof \stdClass ) {
			$value = (array) $value;
		}

		if ( 'object' === $type && is_array( $value ) ) {
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : [];
			foreach ( (array) ( $schema['required'] ?? [] ) as $required ) {
				if ( ! array_key_exists( (string) $required, $value ) ) {
					$errors[] = "{$path}: missing required field {$required}";
				}
			}
			$allow_extra = true === ( $schema['additionalProperties'] ?? null );
			if ( [] !== $properties ) {
				foreach ( $value as $key => $child ) {
					if ( ! array_key_exists( (string) $key, $properties ) ) {
						if ( ! $allow_extra ) {
							$errors[] = "{$path}: extra field {$key}";
						}
						continue;
					}
					$this->validate_schema( (array) $properties[ $key ], $child, "{$path}.{$key}", $errors );
				}
			}
		}

		if ( 'array' === $type && is_array( $value ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $value as $index => $child ) {
				$this->validate_schema( $schema['items'], $child, "{$path}[{$index}]", $errors );
			}
		}
	}

	private function matches_type( string $type, mixed $value ): bool {
		return match ( $type ) {
			'object'  => is_array( $value ) || $value instanceof \stdClass,
			'array'   => is_array( $value ),
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'null'    => null === $value,
			default   => true,
		};
	}

	private function deny_current_user(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	private function prepare_sandbox_files(): void {
		$draft_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		$mu_dir    = WP_CONTENT_DIR . '/mu-plugins';
		wp_mkdir_p( $draft_dir );
		wp_mkdir_p( $mu_dir );
		file_put_contents( $draft_dir . '/contract.php', "<?php\n// contract fixture.\n" );
		file_put_contents( $mu_dir . '/stonewright-sandbox-contract.php', "<?php\n// Active contract fixture.\n" );

		// Widget pending file for elementor.widget_register contract test.
		$widget_pending = $draft_dir . '/widget-contract-reg.pending.php';
		if ( ! file_exists( $widget_pending ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$widget_pending,
				"<?php\ndeclare( strict_types=1 );\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n// Stonewright contract test widget stub.\n"
			);
		}
	}

	/**
	 * @return array<int, object>
	 */
	private function posts(): array {
		return [
			1 => (object) [
				'ID'           => 1,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Contract Page',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'contract-page',
				'meta'         => [
					'_elementor_data'      => '[{"id":"parent1","elType":"container","settings":[],"elements":[]}]',
					'_elementor_edit_mode' => 'builder',
				],
			],
			3 => (object) [
				'ID'             => 3,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Attachment',
				'post_content'   => '',
				'post_excerpt'   => '',
				'post_parent'    => 0,
				'post_name'      => 'attachment',
				'post_mime_type' => 'text/plain',
				'meta'           => [],
			],
			4 => (object) [
				'ID'           => 4,
				'post_type'    => 'elementor_library',
				'post_status'  => 'publish',
				'post_title'   => 'Kit',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'kit',
				'meta'         => [
					'_elementor_page_settings' => [
						'e_atomic_classes'   => [
							[
								'id'         => 'cls_contract',
								'name'       => 'Contract',
								'selectors'  => [ '.contract' ],
								'properties' => [ 'color' => '#111111' ],
							],
						],
						'e_atomic_variables' => [
							[
								'id'           => 'var_contract',
								'name'         => 'Contract Color',
								'type'         => 'color',
								'value'        => '#111111',
								'default_mode' => 'light',
							],
						],
					],
				],
			],
		// A published page used by site-set-front-page contract fixture.
			5 => (object) [
				'ID'           => 5,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Contract Front Page',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'contract-front-page',
				'meta'         => [],
			],
			// Pure V4 atomic document for elementor-v4-update-node contract fixtures.
			6 => (object) [
				'ID'           => 6,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Contract V4 Atomic',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'contract-v4-atomic',
				'meta'         => [
					'_elementor_data'      => '[{"id":"atomic_root","version":"0.0","elType":"e-div-block","isInner":false,"settings":[],"editor_settings":[],"interactions":[],"styles":[],"elements":[{"id":"heading_v4","version":"0.0","elType":"widget","widgetType":"e-heading","isInner":false,"settings":{"tag":{"$$type":"string","value":"h2"}},"editor_settings":[],"interactions":[],"styles":[],"elements":[]}]}]',
					'_elementor_edit_mode' => 'builder',
				],
			],
		];
	}
}
