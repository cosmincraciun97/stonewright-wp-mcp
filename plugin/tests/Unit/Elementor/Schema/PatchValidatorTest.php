<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\PatchValidator
 */
final class PatchValidatorTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [ 'patch-widget' => new PatchWidgetForTest() ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_preserves_unknown_runtime_settings_recursively_when_existing(): void {
		$result = PatchValidator::widget(
			'patch-widget',
			[
				'title'       => 'Before',
				'plugin_data' => [ 'keep' => [ 'old' => true ], 'untouched' => 'yes' ],
			],
			[
				'plugin_data' => [ 'keep' => [ 'new' => true ] ],
				'title'       => 'After',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( [ 'old' => true, 'new' => true ], $result['settings']['plugin_data']['keep'] );
		self::assertSame( 'yes', $result['settings']['plugin_data']['untouched'] );
		self::assertContains( 'plugin_data.keep.new', $result['changed_paths'] );
		self::assertNotContains( 'plugin_data.untouched', $result['changed_paths'] );
	}

	public function test_rejects_new_unknown_setting_instead_of_silently_creating_debt(): void {
		$result = PatchValidator::widget( 'patch-widget', [ 'title' => 'Before' ], [ 'invented_runtime_key' => 'x' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( 'unknown_setting_preserved', $result->get_error_data()['violations'][0]['code'] );
		self::assertTrue( $result->get_error_data()['patch_validation'] );
	}

	public function test_touched_known_control_shape_is_rejected(): void {
		$result = PatchValidator::widget( 'patch-widget', [ 'title' => 'Before' ], [ 'title' => [ 'not' => 'text' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( 'invalid_shape', $result->get_error_data()['violations'][0]['code'] );
	}

	public function test_untouched_legacy_repeater_violation_is_preserved_as_a_warning(): void {
		$result = PatchValidator::widget(
			'patch-widget',
			[ 'items' => [ [ '_id' => 'item-a', 'label' => 'Old', 'legacy_plugin_field' => 'keep' ] ] ],
			[ 'title' => 'New' ]
		);

		self::assertIsArray( $result );
		self::assertSame( 'keep', $result['settings']['items'][0]['legacy_plugin_field'] );
		self::assertSame( 'untouched_legacy_violation', $result['warnings'][0]['code'] );
		self::assertSame( 'settings.items.0.legacy_plugin_field', $result['warnings'][0]['path'] );
	}

	public function test_sibling_legacy_field_in_touched_repeater_is_not_mistaken_for_the_changed_path(): void {
		$result = PatchValidator::widget(
			'patch-widget',
			[ 'items' => [ [ '_id' => 'item-a', 'label' => 'Old', 'legacy_plugin_field' => 'keep' ] ] ],
			[ 'items' => [ [ '_id' => 'item-a', 'label' => 'New', 'legacy_plugin_field' => 'keep' ] ] ]
		);

		self::assertIsArray( $result );
		self::assertSame( 'New', $result['settings']['items'][0]['label'] );
		self::assertSame( 'keep', $result['settings']['items'][0]['legacy_plugin_field'] );
		self::assertContains( 'items.0.label', $result['changed_paths'] );
		self::assertContains( 'untouched_legacy_violation', array_column( $result['warnings'], 'code' ) );
	}

	public function test_replace_persists_the_normalized_canonical_alias(): void {
		$result = PatchValidator::widget(
			'patch-widget',
			[ 'title' => 'Before' ],
			[ 'title' => 'After', 'font_size' => [ 'size' => 18, 'unit' => 'px' ] ],
			'replace'
		);

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'font_size', $result['settings'] );
		self::assertSame( [ 'size' => 18, 'unit' => 'px' ], $result['settings']['typography_font_size'] );
	}

	public function test_repeater_identity_must_be_unique_for_both_elementor_keys(): void {
		$underscore = PatchValidator::widget( 'patch-widget', [], [ 'items' => [ [ '_id' => 'duplicate' ], [ '_id' => 'duplicate' ] ] ] );
		$custom     = PatchValidator::widget( 'patch-widget', [], [ 'items' => [ [ 'custom_id' => 'duplicate' ], [ 'custom_id' => 'duplicate' ] ] ] );

		self::assertInstanceOf( \WP_Error::class, $underscore );
		self::assertInstanceOf( \WP_Error::class, $custom );
		self::assertSame( 'stonewright_elementor_repeater_identity_invalid', $underscore->get_error_code() );
		self::assertSame( 'stonewright_elementor_repeater_identity_invalid', $custom->get_error_code() );
	}
}

final class PatchWidgetForTest {
	public function get_title(): string {
		return 'Patch Widget';
	}

	/** @return array<string,array<string,mixed>> */
	public function get_controls(): array {
		return [
			'title' => [ 'type' => 'text', 'label' => 'Title' ],
			'typography_font_size' => [ 'type' => 'slider', 'label' => 'Font size' ],
			'items' => [
				'type'   => 'repeater',
				'fields' => [ 'label' => [ 'type' => 'text', 'label' => 'Label' ] ],
			],
		];
	}
}
