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
		$GLOBALS['stonewright_test_asset_responses']     = [];
		$GLOBALS['stonewright_test_wp_update_post_calls'] = [];
		$GLOBALS['stonewright_test_actions']              = [];
		$uploads      = wp_upload_dir();
		$this->css_dir = rtrim( (string) $uploads['basedir'], '/\\' ) . '/elementor/css';
		wp_mkdir_p( $this->css_dir );
		$this->remove_css_assets();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_css_new_time'] );
		$GLOBALS['stonewright_test_actions'] = [];
		unset( $GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] );
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
		self::assertSame( 'verified', $result['generation_status'] ?? null );
		self::assertSame( 'verified', $result['delivery_status'] ?? null );
		self::assertSame( 'not_checked', $result['frontend_verification_status'] ?? null );
		self::assertNotSame( '', $result['backup']['snapshot_id'] ?? '' );
		self::assertArrayNotHasKey( 'path', $result );
		self::assertArrayNotHasKey( 'url', $result );
		self::assertStringNotContainsString( $this->css_dir, (string) wp_json_encode( $result ) );

		$events = $GLOBALS['stonewright_test_css_regenerate_events'];
		self::assertSame(
			[ 'permission', 'confirmation', 'backup', 'post_lock', 'css_lease', 'update_css', 'health', 'audit' ],
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

	public function test_does_not_publish_private_or_draft_posts(): void {
		foreach ( [ 'private', 'draft' ] as $status ) {
			$GLOBALS['stonewright_test_posts'][ 301 ]->post_status = $status;
			$GLOBALS['stonewright_test_wp_update_post_calls']      = [];
			$this->write_css( 'post-301.css', 'old-post' );
			$this->configure_update_file( 301 );

			$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

			self::assertIsArray( $result, $status );
			self::assertSame( $status, get_post_status( 301 ) );
			foreach ( $GLOBALS['stonewright_test_wp_update_post_calls'] as $payload ) {
				self::assertNotSame( 'publish', $payload['post_status'] ?? null, $status );
			}
			self::assertSame( 'post-css', $this->read_css( 'post-301.css' ) );
		}
	}

	public function test_login_protected_delivery_keeps_the_write_and_warns_without_rollback(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->write_css( 'post-999.css', 'sibling' );
		$this->configure_update_file( 301 );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-301.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static function (): array {
			return [
				'response' => [ 'code' => 302 ],
				'headers'  => [ 'location' => 'https://example.test/wp-login.php' ],
				'body'     => '<html>Secret private page title XYZ</html>',
			];
		};

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 'verified', $result['generation_status'] ?? null );
		self::assertSame( 'blocked', $result['delivery_status'] ?? null );
		self::assertSame( 'not_checked', $result['frontend_verification_status'] ?? null );
		self::assertArrayNotHasKey( 'root_error_code', $result );
		self::assertArrayNotHasKey( 'failed_check', $result );
		self::assertFalse( $result['retryable'] );
		self::assertSame( 'not_needed', $result['rollback_status'] ?? null );
		self::assertSame( 'stonewright_elementor_css_delivery_protected', $result['warnings'][0]['code'] ?? null );
		self::assertStringContainsString( 'Do not rebuild', (string) ( $result['repair'] ?? '' ) );
		self::assertStringContainsString( 'version', (string) ( $result['repair'] ?? '' ) );
		self::assertSame( 'post-css', $this->read_css( 'post-301.css' ) );
		self::assertSame( 'sibling', $this->read_css( 'post-999.css' ) );
		self::assertStringNotContainsString( 'Secret private page title XYZ', (string) wp_json_encode( $result ) );
	}

	public function test_redirect_to_a_page_that_is_not_css_is_a_written_file_with_a_changed_version(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		$GLOBALS['stonewright_test_css_new_time'] = 500;
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-301.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static fn(): array => [
			'response' => [ 'code' => 301 ],
			'headers'  => [ 'location' => 'https://example.test/' ],
			'body'     => '',
		];
		$GLOBALS['stonewright_test_asset_responses']['https://example.test/'] = static fn(): array => [
			'response' => [ 'code' => 200 ],
			'headers'  => [ 'content-type' => 'text/html' ],
			'body'     => '<html><body>Home page</body></html>',
		];

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'blocked', $result['delivery_status'] ?? null );
		self::assertSame( 'not_needed', $result['rollback_status'] ?? null );
		self::assertSame( 'post-css', $this->read_css( 'post-301.css' ) );
		self::assertSame( 1700000500, $result['css_version'] ?? null );
		self::assertSame( 1, $result['css_version_before'] ?? null );
		self::assertTrue( $result['css_version_changed'] ?? null );
		self::assertSame( 1700000500, get_post_meta( 301, '_elementor_css', true )['time'] ?? null );
		self::assertStringNotContainsString( 'Home page', (string) wp_json_encode( $result ) );
	}

	public function test_verified_delivery_reports_the_changed_css_version(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		$GLOBALS['stonewright_test_css_new_time'] = 800;

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'verified', $result['delivery_status'] ?? null );
		self::assertSame( 1700000800, $result['css_version'] ?? null );
		self::assertTrue( $result['css_version_changed'] ?? null );
		self::assertArrayNotHasKey( 'warnings', $result );
		self::assertArrayNotHasKey( 'repair', $result );
	}

	public function test_a_failed_delivery_check_that_is_not_access_control_keeps_the_probe_failed_code(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		$url = 'https://example.test/wp-content/uploads/elementor/css/post-301.css';
		$GLOBALS['stonewright_test_asset_responses'][ $url ] = static fn(): array => [ 'response' => [ 'code' => 503 ], 'headers' => [], 'body' => '' ];

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertSame( 'not_checked', $result['delivery_status'] ?? null );
		self::assertSame( 'stonewright_elementor_css_probe_failed', $result['root_error_code'] ?? null );
		self::assertStringContainsString( 'Do not rebuild', (string) ( $result['repair'] ?? '' ) );
	}

	public function test_page_without_post_css_regenerates_with_a_truthful_result(): void {
		$this->write_css( 'post-999.css', 'sibling' );
		Post::$factory = function ( int $id ): object {
			return new class( $id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update_file(): void {
				}

				public function get_content(): string {
					return '';
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

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 'verified', $result['generation_status'] );
		self::assertSame( 'not_applicable', $result['delivery_status'] );
		self::assertSame( 'not_produced', $result['css_file_status'] ?? null );
		self::assertSame( 'empty_css', $result['css_file_reason'] ?? null );
		self::assertSame( 'not_needed', $result['rollback_status'] );
		self::assertArrayNotHasKey( 'root_error_code', $result );
		self::assertSame( 'sibling', $this->read_css( 'post-999.css' ) );
		self::assertFalse( is_file( $this->css_dir . '/post-301.css' ) );
	}

	public function test_page_with_post_css_reports_the_file_as_present(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertSame( 'present', $result['css_file_status'] ?? null );
		self::assertArrayNotHasKey( 'css_file_reason', $result );
	}

	public function test_without_a_page_cache_plugin_only_core_is_purged_and_the_result_says_so(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [ 'wordpress_post_cache' ], $result['cache_purge']['ran'] ?? null );
		self::assertSame( 'no_page_cache_plugin', $result['cache_purge']['skipped_reason'] ?? null );
		self::assertArrayNotHasKey( 'repair', $result );
		self::assertArrayNotHasKey( 'warnings', $result );
	}

	public function test_a_present_page_cache_is_purged_once_after_the_file_and_version_changed(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		$GLOBALS['stonewright_test_css_new_time'] = 500;
		$seen                                     = [];
		add_action(
			'litespeed_purge_post',
			function ( ...$args ) use ( &$seen ): void {
				$seen[] = [
					'args' => $args,
					'css'  => $this->read_css( 'post-301.css' ),
					'time' => get_post_meta( 301, '_elementor_css', true )['time'] ?? null,
				];
			}
		);

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( [ [ 'args' => [ 301 ], 'css' => 'post-css', 'time' => 1700000500 ] ], $seen );
		self::assertSame( [ 'litespeed_cache', 'wordpress_post_cache' ], $result['cache_purge']['ran'] ?? null );
		self::assertArrayNotHasKey( 'skipped_reason', $result['cache_purge'] );
	}

	public function test_the_purge_filter_turns_the_purge_off_without_touching_the_write(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		$calls = 0;
		add_action(
			'litespeed_purge_post',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
		$GLOBALS['stonewright_test_filters']['stonewright_css_regenerate_purge'] = '__return_false';

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 0, $calls );
		self::assertSame( [], $result['cache_purge']['ran'] ?? null );
		self::assertSame( 'disabled_by_filter', $result['cache_purge']['skipped_reason'] ?? null );
		self::assertSame( 'post-css', $this->read_css( 'post-301.css' ) );
	}

	public function test_a_rolled_back_write_never_purges(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$calls = 0;
		add_action(
			'litespeed_purge_post',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
		Post::$factory = function ( int $post_id ): object {
			return new class( $post_id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update_file(): void {
					file_put_contents( $this->get_path(), '' );
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
		self::assertSame( 0, $calls );
	}

	public function test_a_result_without_a_moved_version_does_not_purge(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$calls = 0;
		add_action(
			'litespeed_purge_post',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
		Post::$factory = function ( int $post_id ): object {
			return new class( $post_id, $this->css_dir ) {
				public function __construct( private int $post_id, private string $css_dir ) {
				}

				public function update_file(): void {
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

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'css_version_changed', $result );
		self::assertSame( 0, $calls );
		self::assertSame( [], $result['cache_purge']['ran'] ?? null );
		self::assertSame( 'css_version_not_changed', $result['cache_purge']['skipped_reason'] ?? null );
	}

	public function test_a_failing_purger_is_a_warning_and_the_write_still_counts(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		add_action(
			'litespeed_purge_post',
			static function (): void {
				throw new \RuntimeException( 'Purge socket /var/run/secret.sock refused' );
			}
		);

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'post-css', $this->read_css( 'post-301.css' ) );
		self::assertSame( [ 'wordpress_post_cache' ], $result['cache_purge']['ran'] ?? null );
		self::assertSame( [ [ 'purger' => 'litespeed_cache', 'error_class' => 'RuntimeException' ] ], $result['cache_purge']['failed'] ?? null );
		self::assertSame( 'stonewright_css_cache_purge_failed', $result['warnings'][0]['code'] ?? null );
		self::assertStringContainsString( 'LiteSpeed Cache', (string) ( $result['warnings'][0]['message'] ?? '' ) );
		self::assertStringContainsString( 'by hand', (string) ( $result['warnings'][0]['message'] ?? '' ) );
		self::assertStringNotContainsString( 'secret.sock', (string) wp_json_encode( $result ) );
	}

	public function test_a_blocked_delivery_repair_says_what_was_purged_before_asking_for_a_manual_purge(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		add_action(
			'litespeed_purge_post',
			static function (): void {
			}
		);
		$GLOBALS['stonewright_test_asset_responses']['https://example.test/wp-content/uploads/elementor/css/post-301.css'] = static fn(): array => [
			'response' => [ 'code' => 302 ],
			'headers'  => [ 'location' => 'https://example.test/wp-login.php' ],
			'body'     => '',
		];

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		$repair = (string) ( $result['repair'] ?? '' );
		self::assertStringContainsString( 'Do not rebuild', $repair );
		self::assertStringContainsString( 'already purged', $repair );
		self::assertStringContainsString( 'LiteSpeed Cache', $repair );
		self::assertStringNotContainsString( 'WordPress post cache', $repair );
		self::assertGreaterThan( strpos( $repair, 'already purged' ), strpos( $repair, 'by hand' ) );
		self::assertSame( 'stonewright_elementor_css_delivery_protected', $result['warnings'][0]['code'] ?? null );
	}

	public function test_a_blocked_delivery_repair_keeps_the_manual_purge_advice_when_nothing_was_purged(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		$GLOBALS['stonewright_test_asset_responses']['https://example.test/wp-content/uploads/elementor/css/post-301.css'] = static fn(): array => [
			'response' => [ 'code' => 302 ],
			'headers'  => [ 'location' => 'https://example.test/wp-login.php' ],
			'body'     => '',
		];

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		$repair = (string) ( $result['repair'] ?? '' );
		self::assertStringContainsString( 'Do not rebuild', $repair );
		self::assertStringContainsString( 'purge the host or page cache', $repair );
		self::assertStringNotContainsString( 'already purged', $repair );
	}

	public function test_an_unchecked_delivery_still_purges_and_the_repair_names_what_ran(): void {
		$this->write_css( 'post-301.css', 'old-post' );
		$this->configure_update_file( 301 );
		add_action(
			'litespeed_purge_post',
			static function (): void {
			}
		);
		$GLOBALS['stonewright_test_asset_responses']['https://example.test/wp-content/uploads/elementor/css/post-301.css'] = static fn(): array => [ 'response' => [ 'code' => 503 ], 'headers' => [], 'body' => '' ];

		$result = ( new CssRegenerate() )->execute( [ 'post_id' => 301 ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertSame( [ 'litespeed_cache', 'wordpress_post_cache' ], $result['cache_purge']['ran'] ?? null );
		self::assertStringContainsString( 'LiteSpeed Cache', (string) ( $result['repair'] ?? '' ) );
	}

	public function test_output_schema_declares_the_cache_purge_report(): void {
		$schema = ( new CssRegenerate() )->output_schema()['properties']['cache_purge'] ?? [];
		self::assertSame( 'object', $schema['type'] ?? null );
		self::assertSame( 'array', $schema['properties']['ran']['type'] ?? null );
		self::assertSame( 'string', $schema['properties']['skipped_reason']['type'] ?? null );
		self::assertSame( 'array', $schema['properties']['failed']['type'] ?? null );
		self::assertSame( [ 'ran' ], $schema['required'] ?? null );
	}

	public function test_output_schema_declares_generation_and_delivery_status(): void {
		$properties = ( new CssRegenerate() )->output_schema()['properties'];
		self::assertSame( 'integer', $properties['css_version']['type'] );
		self::assertSame( 'boolean', $properties['css_version_changed']['type'] );
		self::assertSame( 'array', $properties['warnings']['type'] );
		self::assertSame( 'string', $properties['repair']['type'] );
		self::assertSame( [ 'verified', 'blocked', 'failed', 'not_checked' ], $properties['generation_status']['enum'] );
		self::assertSame( [ 'verified', 'blocked', 'failed', 'not_checked', 'not_applicable' ], $properties['delivery_status']['enum'] );
		self::assertSame( [ 'present', 'not_produced' ], $properties['css_file_status']['enum'] );
		self::assertSame( [ 'verified', 'blocked', 'failed', 'not_checked' ], $properties['frontend_verification_status']['enum'] );
		self::assertArrayHasKey( 'root_error_code', $properties );
		self::assertArrayHasKey( 'failed_check', $properties );
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
					$this->update_file();
					$meta         = (array) get_post_meta( $this->post_id, '_elementor_css', true );
					$meta['time'] = 1700000000 + (int) ( $GLOBALS['stonewright_test_css_new_time'] ?? 0 );
					$meta['status'] = 'file';
					update_post_meta( $this->post_id, '_elementor_css', $meta );
				}

				/** @return mixed */
				public function get_meta( ?string $property = null ) {
					$meta = (array) get_post_meta( $this->post_id, '_elementor_css', true );
					return null === $property ? $meta : ( $meta[ $property ] ?? null );
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
