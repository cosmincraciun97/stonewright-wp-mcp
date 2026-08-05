<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Regression coverage for the read-back end-state verification in
 * ElementorData::write().
 *
 * Background — update_post_meta() returns `false` BOTH on true failure AND
 * on a successful no-op (the new value equals the existing one). The pre-fix
 * write() required `false !== $mode_result && false !== $version_result`,
 * which exploded on every BuildPageFromSpec into a freshly-created Theme
 * Builder template — TemplateStore::create() sets `_elementor_edit_mode =
 * 'builder'` at creation time, so the write()'s identical re-set returned
 * false → write() returned false → BuildPageFromSpec returned WP_Error
 * `stonewright_write_failed` even though `_elementor_data` was persisted
 * correctly.
 *
 * @covers \Stonewright\WpMcp\Support\ElementorData
 */
final class ElementorDataWriteTest extends TestCase {

	private object $elementor_instance;

	protected function setUp(): void {
		$this->elementor_instance = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_transients']       = [];
		$GLOBALS['stonewright_test_post_meta_calls']  = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_posts'][ 8800 ]    = (object) [
			'ID'           => 8800,
			'post_type'    => 'elementor_library',
			'post_status'  => 'publish',
			'post_title'   => 'fixture template',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'fixture',
			'meta'         => [
				// Simulating the post that TemplateStore::create() just set up:
				// edit_mode already 'builder', empty data, no version yet.
				'_elementor_data'      => '[]',
				'_elementor_edit_mode' => 'builder',
				'_elementor_element_cache' => '<div>stale builder html</div>',
			],
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->elementor_instance;
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		unset( $GLOBALS['stonewright_test_update_post_meta_return'] );
		unset( $GLOBALS['stonewright_test_update_post_meta_returns'] );
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_write_succeeds_when_edit_mode_is_already_builder(): void {
		$tree = [
			[
				'id'         => 'abc1234',
				'elType'     => 'section',
				'settings'   => [],
				'elements'   => [],
			],
		];

		$result = ElementorData::write( 8800, $tree );

		$this->assertTrue(
			$result,
			"write() must succeed even when _elementor_edit_mode is unchanged. "
			. "update_post_meta() returning false for a no-op no longer poisons the overall result."
		);

		// And the data must actually be persisted to the fake post.
		$post = $GLOBALS['stonewright_test_posts'][ 8800 ];
		$this->assertArrayHasKey( '_elementor_data', $post->meta );
	}

	public function test_write_persists_data_into_post_meta(): void {
		$tree = [
			[
				'id'       => 'def5678',
				'elType'   => 'section',
				'settings' => [],
				'elements' => [],
			],
		];

		$this->assertTrue( ElementorData::write( 8800, $tree ) );

		// Confirm the slashed JSON landed in the post's meta. The test stub
		// of update_post_meta persists addslashed strings as-is (real WP
		// auto-unslashes on get_post_meta read; the stub does not — so a
		// round-trip ElementorData::read() comparison would be testing the
		// stub more than the production code; covered by the smoke-test on
		// mcp-test.local instead).
		$post = $GLOBALS['stonewright_test_posts'][ 8800 ];
		$this->assertArrayHasKey( '_elementor_data', $post->meta );
		$this->assertStringContainsString( 'def5678', stripslashes( (string) $post->meta['_elementor_data'] ) );
	}

	public function test_write_returns_false_when_json_encoding_fails(): void {
		// Resource handles are unencodable by json_encode → wp_json_encode returns false.
		$tree = [ [ 'id' => 'x', 'res' => fopen( 'php://memory', 'r' ) ] ];
		$result = ElementorData::write( 8800, $tree );
		// PHP 8.4 + JSON_INVALID_UTF8_IGNORE may swap behaviour; either way,
		// when wp_json_encode returns false write() must return false.
		// (If json_encode happens to succeed on the resource — it won't, but
		// hedging — read-back equality will still gate the success path.)
		$this->assertFalse( $result );
	}

	public function test_write_persists_elementor_version_to_meta(): void {
		ElementorData::write( 8800, [] );
		$post = $GLOBALS['stonewright_test_posts'][ 8800 ];
		$this->assertArrayHasKey( '_elementor_version', $post->meta );
		$this->assertNotSame( '', (string) $post->meta['_elementor_version'] );
	}

	public function test_write_invalidates_only_the_edited_post_css_cache(): void {
		$files_manager = new class() {
			public int $calls = 0;

			public function clear_cache(): void {
				++$this->calls;
			}
		};
		$posts_css_manager = new class() {
			/** @var list<int> */
			public array $post_ids = [];

			public function clear_cache_post( int $post_id ): void {
				$this->post_ids[] = $post_id;
			}
		};
		\Elementor\Plugin::$instance = (object) [
			'files_manager'     => $files_manager,
			'posts_css_manager' => $posts_css_manager,
		];

		$result = ElementorData::write(
			8800,
			[
				[
					'id'       => 'cache01',
					'elType'   => 'section',
					'settings' => [],
					'elements' => [],
				],
			]
		);

		$this->assertTrue( $result );
		$this->assertSame( [ 8800 ], $posts_css_manager->post_ids );
		$this->assertSame( 0, $files_manager->calls, 'Normal writes must never clear every Elementor CSS file.' );
		$this->assertArrayNotHasKey( '_elementor_element_cache', $GLOBALS['stonewright_test_posts'][8800]->meta );
		$this->assertTrue( (bool) ( ElementorData::last_write_receipt()['element_cache']['deleted'] ?? false ) );
	}

	public function test_standalone_write_receipt_records_the_acquired_lock_and_verified_hashes(): void {
		$result = ElementorData::write(
			8800,
			[
				[
					'id'       => 'locked1',
					'elType'   => 'section',
					'settings' => [],
					'elements' => [],
				],
			]
		);

		$receipt = ElementorData::last_elementor_write_receipt();
		self::assertTrue( $result );
		self::assertSame( 8800, $receipt['post_id'] );
		self::assertSame( 'acquired', $receipt['lock_status'] );
		self::assertSame( 'acquired', $receipt['lock']['status'] );
		self::assertNotSame( '', $receipt['lock']['owner'] );
		self::assertGreaterThan( time(), $receipt['lock']['expires_at'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $receipt['before_hash'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $receipt['planned_hash'] );
		self::assertSame( $receipt['planned_hash'], $receipt['after_hash'] );
		self::assertSame( $receipt['after_hash'], $receipt['readback_hash'] );
		self::assertSame( 'verified', $receipt['verification_status'] );
	}

	public function test_standalone_busy_lock_returns_a_complete_failure_receipt(): void {
		self::assertIsArray( PostWriteLock::acquire( 8800, 'other-transaction', 30 ) );

		try {
			$result = ElementorData::write(
				8800,
				[
					[
						'id'       => 'blocked',
						'elType'   => 'section',
						'settings' => [],
						'elements' => [],
					],
				]
			);
		} finally {
			PostWriteLock::release( 8800, 'other-transaction' );
		}

		$receipt = ElementorData::last_elementor_write_receipt();
		self::assertFalse( $result );
		self::assertSame( 'stonewright_elementor_write_busy', ElementorData::last_write_error()?->get_error_code() );
		self::assertSame( 8800, $receipt['post_id'] );
		self::assertNotSame( '', $receipt['transaction_id'] );
		self::assertNotSame( '', $receipt['change_set_id'] );
		self::assertSame( 'busy', $receipt['lock_status'] );
		self::assertSame( 'busy', $receipt['lock']['status'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $receipt['lock']['fingerprint'] );
		self::assertGreaterThan( 0, $receipt['lock']['retry_after'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $receipt['before_hash'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $receipt['planned_hash'] );
		self::assertSame( 'stonewright_elementor_write_busy', $receipt['root_error_code'] );
		self::assertSame( 'lock.acquire', $receipt['root_error_path'] );
		self::assertTrue( $receipt['retryable'] );
	}

	public function test_first_write_failure_restores_an_initially_absent_elementor_document(): void {
		$GLOBALS['stonewright_test_posts'][ 8800 ]->meta = [];
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$result = ElementorData::write(
			8800,
			[
				[
					'id'       => 'first01',
					'elType'   => 'section',
					'settings' => [],
					'elements' => [],
				],
			],
			[ 'skip_integrity' => true ]
		);

		$meta    = (array) $GLOBALS['stonewright_test_posts'][ 8800 ]->meta;
		$receipt = ElementorData::last_elementor_write_receipt();
		self::assertFalse( $result );
		self::assertArrayNotHasKey( '_elementor_data', $meta );
		self::assertArrayNotHasKey( '_elementor_edit_mode', $meta );
		self::assertArrayNotHasKey( '_elementor_version', $meta );
		self::assertSame( 'stonewright_elementor_readback_failed_restored', ElementorData::last_write_error()?->get_error_code() );
		self::assertTrue( $receipt['rollback_attempted'] );
		self::assertSame( 'succeeded', $receipt['rollback_status'] );
		self::assertSame( $receipt['before_hash'], $receipt['after_hash'] );
		self::assertSame( $receipt['after_hash'], $receipt['readback_hash'] );
		self::assertSame( 'failed', $receipt['verification_status'] );
	}

	public function test_first_write_failure_preserves_a_stored_empty_document_and_absent_companion_meta(): void {
		$GLOBALS['stonewright_test_posts'][ 8800 ]->meta = [ '_elementor_data' => '[]' ];
		$GLOBALS['stonewright_test_update_post_meta_return'] = false;

		$result = ElementorData::write(
			8800,
			[
				[
					'id'       => 'first02',
					'elType'   => 'section',
					'settings' => [],
					'elements' => [],
				],
			],
			[ 'skip_integrity' => true ]
		);

		$meta = (array) $GLOBALS['stonewright_test_posts'][ 8800 ]->meta;
		self::assertFalse( $result );
		self::assertSame( '[]', $meta['_elementor_data'] );
		self::assertArrayNotHasKey( '_elementor_edit_mode', $meta );
		self::assertArrayNotHasKey( '_elementor_version', $meta );
		self::assertSame( 'stonewright_elementor_readback_failed_restored', ElementorData::last_write_error()?->get_error_code() );
		self::assertSame( 'succeeded', ElementorData::last_elementor_write_receipt()['rollback_status'] );
	}

	public function test_delta_guard_allows_unchanged_legacy_null_media_during_a_sibling_patch(): void {
		$this->install_delta_widget_schema();
		$before = $this->delta_widget_tree(
			[
				'title' => 'Before',
				'image' => null,
			]
		);
		$after  = $this->delta_widget_tree(
			[
				'title' => 'After',
				'image' => null,
			]
		);
		$this->seed_tree( $before );

		self::assertTrue( ElementorData::write( 8800, $after ) );
		self::assertSame( $after, ElementorData::read( 8800 ) );
		self::assertNull( ElementorData::read( 8800 )[0]['settings']['image'] );
	}

	public function test_delta_guard_allows_unchanged_invalid_known_siblings(): void {
		$this->install_delta_widget_schema();
		$legacy = [
			'title'             => 'Before',
			'button_icon_align' => 'right',
			'form_fields'       => [
				[
					'custom_id' => 'email',
					'file_sizes' => '',
				],
			],
		];
		$this->seed_tree( $this->delta_widget_tree( $legacy ) );
		$legacy['title'] = 'After';

		self::assertTrue( ElementorData::write( 8800, $this->delta_widget_tree( $legacy ) ) );
		$settings = ElementorData::read( 8800 )[0]['settings'];
		self::assertSame( 'right', $settings['button_icon_align'] );
		self::assertSame( '', $settings['form_fields'][0]['file_sizes'] );
	}

	public function test_delta_guard_preserves_unknown_top_level_and_repeater_fields_byte_for_byte(): void {
		$this->install_delta_widget_schema();
		$before = [
			'title'          => 'Before',
			'plugin_control' => [ 'opaque' => [ 'enabled' => true, 'token' => '01' ] ],
			'form_fields'    => [
				[
					'custom_id'       => 'email',
					'file_sizes'      => 2,
					'newsman_mapping' => [ 'list' => 'alpha', 'enabled' => true ],
				],
			],
		];
		$this->seed_tree( $this->delta_widget_tree( $before ) );
		$after          = $before;
		$after['title'] = 'After';

		self::assertTrue( ElementorData::write( 8800, $this->delta_widget_tree( $after ) ) );
		$stored = ElementorData::read( 8800 )[0]['settings'];
		self::assertSame( $before['plugin_control'], $stored['plugin_control'] );
		self::assertSame( $before['form_fields'][0]['newsman_mapping'], $stored['form_fields'][0]['newsman_mapping'] );
	}

	public function test_delta_guard_blocks_unknown_repeater_field_removal_by_default(): void {
		$this->install_delta_widget_schema();
		$before = [
			'title'       => 'Before',
			'form_fields' => [
				[ 'custom_id' => 'email', 'file_sizes' => 2, 'newsman_mapping' => 'keep' ],
			],
		];
		$after = [
			'title'       => 'After',
			'form_fields' => [
				[ 'custom_id' => 'email', 'file_sizes' => 2 ],
			],
		];
		$this->seed_tree( $this->delta_widget_tree( $before ) );

		self::assertFalse( ElementorData::write( 8800, $this->delta_widget_tree( $after ) ) );
		$error = ElementorData::last_write_error();
		self::assertSame( 'stonewright_elementor_settings_invalid', $error?->get_error_code() );
		self::assertSame( 'unknown_repeater_field_not_preserved', $error?->get_error_data()['violations'][0]['code'] );
		self::assertSame( $this->delta_widget_tree( $before ), ElementorData::read( 8800 ) );
	}

	public function test_explicit_unknown_removal_signal_allows_only_removal_delta(): void {
		$this->install_delta_widget_schema();
		$before = [
			'title'          => 'Before',
			'plugin_control' => 'remove-me',
			'form_fields'    => [
				[ 'custom_id' => 'email', 'file_sizes' => 2, 'newsman_mapping' => 'remove-me-too' ],
			],
		];
		$after = [
			'title'       => 'After',
			'form_fields' => [
				[ 'custom_id' => 'email', 'file_sizes' => 2 ],
			],
		];
		$this->seed_tree( $this->delta_widget_tree( $before ) );

		self::assertFalse( ElementorData::write( 8800, $this->delta_widget_tree( $after ) ) );
		self::assertTrue(
			ElementorData::write(
				8800,
				$this->delta_widget_tree( $after ),
				[ 'allow_unknown_setting_removal' => true ]
			)
		);
		self::assertSame( $this->delta_widget_tree( $after ), ElementorData::read( 8800 ) );

		$aggravated          = $after;
		$aggravated['title'] = [ 'invalid' => 'shape' ];
		self::assertFalse(
			ElementorData::write(
				8800,
				$this->delta_widget_tree( $aggravated ),
				[ 'allow_unknown_setting_removal' => true ]
			)
		);
		self::assertSame( 'invalid_shape', ElementorData::last_write_error()?->get_error_data()['violations'][0]['code'] );
	}

	private function install_delta_widget_schema(): void {
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [ 'delta-aware-widget' => new DeltaAwareWriteWidget() ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
	}

	/** @param array<string, mixed> $settings @return array<int, array<string, mixed>> */
	private function delta_widget_tree( array $settings ): array {
		return [
			[
				'id'         => 'delta01',
				'elType'     => 'widget',
				'widgetType' => 'delta-aware-widget',
				'settings'   => $settings,
				'elements'   => [],
			],
		];
	}

	/** @param array<int, array<string, mixed>> $tree */
	private function seed_tree( array $tree ): void {
		$GLOBALS['stonewright_test_posts'][ 8800 ]->meta['_elementor_data'] = (string) wp_json_encode(
			$tree,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}
}

final class DeltaAwareWriteWidget {
	public function get_title(): string {
		return 'Delta-aware widget';
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'title'             => [ 'type' => 'text', 'label' => 'Title' ],
			'image'             => [ 'type' => 'media', 'label' => 'Image' ],
			'button_icon_align' => [
				'type'    => 'choose',
				'label'   => 'Icon alignment',
				'options' => [ 'row' => 'Start', 'row-reverse' => 'End' ],
			],
			'form_fields'       => [
				'type'   => 'repeater',
				'label'  => 'Fields',
				'fields' => [
					'custom_id'  => [ 'type' => 'text', 'label' => 'ID' ],
					'file_sizes' => [ 'type' => 'number', 'label' => 'File sizes' ],
				],
			],
		];
	}
}
