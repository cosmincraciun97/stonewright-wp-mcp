<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\ElementorSchema;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\RuntimeFingerprint
 * @covers \Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository
 * @covers \Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\ElementorSchema
 */
final class WidgetSchemaRepositoryTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options'] = [
			'active_plugins'                         => [],
			'elementor_experiment-container'         => 'active',
			'elementor_experiment-e_atomic_elements' => 'inactive',
		];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [
						'third-party-card' => new LiveThirdPartyWidget(),
						'wide-widget'       => new LiveWideWidget(),
					];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
			'elements_manager' => new class() {
				public function get_element_types( string $name ): ?object {
					return 'container' === $name ? new LiveContainerElement() : null;
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_user_caps']  = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_live_third_party_schema_is_fingerprinted_cached_and_path_free(): void {
		$schema = WidgetSchemaRepository::get( 'third-party-card' );

		self::assertIsArray( $schema );
		self::assertSame( 'Third Party Card', $schema['title'] );
		self::assertArrayHasKey( 'link', $schema['controls'] );
		self::assertArrayHasKey( 'items', $schema['controls'] );
		self::assertContains( 'link', $schema['link_capable_controls'] );
		self::assertSame( 'live_elementor_runtime', $schema['controls']['link']['provenance'] );
		self::assertSame( 'live_elementor_runtime', $schema['provenance']['controls'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $schema['schema_hash'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $schema['runtime_fingerprint'] );
		self::assertStringNotContainsString( '/Users/', (string) wp_json_encode( $schema ) );
		self::assertNotEmpty( $GLOBALS['stonewright_test_transients'] );
	}

	public function test_unknown_setting_is_rejected_with_exact_repair_hints(): void {
		$result = SettingsValidator::validate( 'third-party-card', [ 'titel' => 'Wrong key' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'unknown_setting', $data['violations'][0]['code'] );
		self::assertContains( 'title', $data['violations'][0]['suggestions'] );
		self::assertTrue( $data['retryable'] );
		self::assertSame( 'stonewright/elementor-schema', $data['schema_request']['ability'] );
		self::assertSame( 'titel', $data['schema_request']['input']['query'] );
	}

	public function test_live_container_schema_accepts_injected_controls_and_rejects_unknown_keys(): void {
		$schema = ContainerSchemaRepository::get();
		self::assertIsArray( $schema );
		self::assertSame( 'elementor_live_controls', $schema['source'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $schema['schema_hash'] );
		self::assertTrue( $schema['controls']['flex_direction']['responsive'] );

		$valid = SettingsValidator::validate_container(
			[
				'flex_direction_mobile'      => 'column',
				'plugin_layout_token_tablet' => 'wide',
			]
		);
		self::assertIsArray( $valid );

		$invalid = SettingsValidator::validate_container( [ 'invented_layout_key' => true ] );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'unknown_setting', $invalid->get_error_data()['violations'][0]['code'] );
	}

	public function test_responsive_url_and_recursive_repeater_shapes_are_validated(): void {
		$valid = SettingsValidator::validate(
			'third-party-card',
			[
				'title'          => 'Card',
				'link'           => [ 'url' => '/contact' ],
				'spacing_tablet' => [ 'top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12, 'unit' => 'px', 'isLinked' => true ],
				'items'          => [ [ '_id' => 'a1', 'label' => 'One' ] ],
			]
		);
		self::assertIsArray( $valid );

		$invalid = SettingsValidator::validate( 'third-party-card', [ 'items' => [ [ 'made_up' => 'x' ] ] ] );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'unknown_repeater_field', $invalid->get_error_data()['violations'][0]['code'] );
	}

	public function test_control_conditions_require_the_live_activator(): void {
		$invalid = SettingsValidator::validate( 'third-party-card', [ 'subtitle' => 'Hidden' ] );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'inactive_condition', $invalid->get_error_data()['violations'][0]['code'] );

		$valid = SettingsValidator::validate( 'third-party-card', [ 'show_subtitle' => 'yes', 'subtitle' => 'Visible' ] );
		self::assertIsArray( $valid );
	}

	public function test_final_tree_guard_preserves_inactive_existing_controls(): void {
		$valid = SettingsValidator::validate_tree(
			[
				[
					'id'         => 'card1',
					'elType'     => 'widget',
					'widgetType' => 'third-party-card',
					'settings'   => [ 'subtitle' => 'Preserved while hidden' ],
					'elements'   => [],
				],
			]
		);

		self::assertTrue( $valid );
		self::assertNull( SettingsValidator::last_error() );
	}

	public function test_feature_change_creates_a_new_runtime_fingerprint(): void {
		$before = RuntimeFingerprint::describe();
		$GLOBALS['stonewright_test_options']['elementor_experiment-container'] = 'inactive';
		$after = RuntimeFingerprint::describe();

		self::assertNotSame( $before['hash'], $after['hash'] );
	}

	public function test_explicit_invalidation_deletes_tracked_schema_shards(): void {
		self::assertIsArray( WidgetSchemaRepository::get( 'third-party-card' ) );
		self::assertNotEmpty( $GLOBALS['stonewright_test_transients'] );

		WidgetSchemaRepository::invalidate();

		self::assertSame( [], $GLOBALS['stonewright_test_transients'] );
		self::assertSame( [], get_option( 'stonewright_elementor_schema_cache_keys' ) );
	}

	public function test_unified_summary_stays_compact_and_list_finds_live_widget(): void {
		$ability = new ElementorSchema();
		$list    = $ability->execute( [ 'mode' => 'search', 'query' => 'card' ] );
		self::assertIsArray( $list );
		self::assertSame( 'third-party-card', $list['items'][0]['widget_type'] );

		$summary = $ability->execute( [ 'mode' => 'summary', 'widget_type' => 'third-party-card' ] );
		self::assertIsArray( $summary );
		self::assertLessThan( 3200, strlen( (string) wp_json_encode( $summary ) ) );
		self::assertArrayNotHasKey( 'default', $summary['controls']['title'] );
		self::assertFalse( str_starts_with( (string) array_key_first( $summary['controls'] ), '_' ) );

		$filtered = $ability->execute( [ 'mode' => 'summary', 'widget_type' => 'third-party-card', 'query' => 'spacing' ] );
		self::assertIsArray( $filtered );
		self::assertSame( [ 'spacing' ], array_keys( $filtered['controls'] ) );
		self::assertSame( 1, $filtered['total'] );

		$wide = $ability->execute( [ 'mode' => 'summary', 'widget_type' => 'wide-widget' ] );
		self::assertIsArray( $wide );
		self::assertCount( 18, $wide['controls'] );
		self::assertLessThan( 3200, strlen( (string) wp_json_encode( $wide ) ) );
	}

	public function test_final_write_guard_preserves_unknown_settings_on_write(): void {
		// P0 integrity: unknown Pro/runtime keys must not be stripped to "pass".
		$GLOBALS['stonewright_test_posts'][99] = (object) [
			'ID'   => 99,
			'meta' => [
				'_elementor_data'      => '[]',
				'_elementor_edit_mode' => 'builder',
			],
		];
		$tree = [
			[
				'id'         => 'card1',
				'elType'     => 'widget',
				'widgetType' => 'third-party-card',
				'settings'   => [ 'invented_color' => '#fff' ],
				'elements'   => [],
			],
		];
		self::assertTrue( SettingsValidator::validate_tree( $tree ) );
		$written = ElementorData::write( 99, $tree );

		self::assertTrue( $written, (string) ( ElementorData::last_write_error()?->get_error_message() ?? '' ) );
		self::assertNotSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		$meta = (string) ( $GLOBALS['stonewright_test_posts'][99]->meta['_elementor_data'] ?? '' );
		self::assertStringContainsString( 'invented_color', stripslashes( $meta ) );
	}

	public function test_final_write_guard_preserves_unknown_container_settings_on_write(): void {
		$GLOBALS['stonewright_test_posts'][99] = (object) [
			'ID'   => 99,
			'meta' => [
				'_elementor_data'      => '[]',
				'_elementor_edit_mode' => 'builder',
			],
		];
		$tree = [
			[
				'id'       => 'container1',
				'elType'   => 'container',
				'settings' => [ 'invented_layout_key' => 'bad' ],
				'elements' => [],
			],
		];
		self::assertTrue( SettingsValidator::validate_tree( $tree ) );
		$written = ElementorData::write( 99, $tree );

		self::assertTrue( $written, (string) ( ElementorData::last_write_error()?->get_error_message() ?? '' ) );
		self::assertNotSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		$meta = (string) ( $GLOBALS['stonewright_test_posts'][99]->meta['_elementor_data'] ?? '' );
		self::assertStringContainsString( 'invented_layout_key', stripslashes( $meta ) );
	}

	public function test_final_write_guard_blocks_nodes_without_ids_before_post_meta(): void {
		$written = ElementorData::write(
			99,
			[
				[
					'elType'   => 'container',
					'settings' => [],
					'elements' => [],
				],
			]
		);

		self::assertFalse( $written );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		// Integrity gate runs before SettingsValidator on write.
		self::assertSame(
			'stonewright_elementor_integrity_missing_id',
			ElementorData::last_write_error()?->get_error_code()
		);
	}

	public function test_final_tree_guard_blocks_duplicate_ids(): void {
		$valid = SettingsValidator::validate_tree(
			[
				[ 'id' => 'same-id', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
				[ 'id' => 'same-id', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
			]
		);

		self::assertFalse( $valid );
		self::assertSame( 'duplicate_id', SettingsValidator::last_error()?->get_error_data()['violations'][0]['code'] );
	}

	public function test_final_tree_guard_allows_atomic_widgets_in_mixed_tree(): void {
		// P0: coexisting e-* nodes are structure-only; never force convert to text-editor.
		$valid = SettingsValidator::validate_tree(
			[
				[ 'id' => 'atomic1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ],
			]
		);

		self::assertTrue( $valid );
	}

	public function test_final_tree_guard_blocks_stripped_unicode_escapes(): void {
		$valid = SettingsValidator::validate_tree(
			[
				[
					'id'         => 'card1',
					'elType'     => 'widget',
					'widgetType' => 'third-party-card',
					'settings'   => [ 'title' => 'Cum pou021bi creu0219te u00een echipu0103?' ],
					'elements'   => [],
				],
			]
		);

		self::assertFalse( $valid );
		self::assertSame( 'stripped_unicode_escape', SettingsValidator::last_error()?->get_error_data()['violations'][0]['code'] );
	}

	public function test_final_tree_guard_preserves_real_romanian_diacritics(): void {
		$valid = SettingsValidator::validate_tree(
			[
				[
					'id'         => 'card1',
					'elType'     => 'widget',
					'widgetType' => 'third-party-card',
					'settings'   => [ 'title' => 'Cum poți crește în echipă?' ],
					'elements'   => [],
				],
			]
		);

		self::assertTrue( $valid );
	}
}

final class LiveContainerElement {
	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'container_type'     => [ 'type' => 'select', 'options' => [ 'flex' => 'Flex', 'grid' => 'Grid' ] ],
			// Elementor's live control array omits responsive metadata for core
			// layout controls even though breakpoint-suffixed values are native.
			'flex_direction'     => [ 'type' => 'select' ],
			'plugin_layout_token' => [ 'type' => 'select', 'responsive' => true, 'options' => [ 'wide' => 'Wide' ] ],
		];
	}
}

final class LiveThirdPartyWidget {
	public function get_title(): string {
		return 'Third Party Card';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'third-party' ];
	}

	/** @return list<string> */
	public function get_keywords(): array {
		return [ 'card', 'cta' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return [
			'title'   => [ 'type' => 'text', 'label' => 'Title', 'tab' => 'content', 'section' => 'content', 'default' => 'Card' ],
			'show_subtitle' => [ 'type' => 'switcher', 'label' => 'Show subtitle', 'tab' => 'content', 'section' => 'content', 'return_value' => 'yes' ],
			'subtitle' => [ 'type' => 'text', 'label' => 'Subtitle', 'tab' => 'content', 'section' => 'content', 'condition' => [ 'show_subtitle' => 'yes' ] ],
			'link'    => [ 'type' => 'url', 'label' => 'Link', 'tab' => 'content', 'section' => 'content' ],
			'spacing' => [ 'type' => 'dimensions', 'label' => 'Spacing', 'tab' => 'advanced', 'section' => 'layout', 'responsive' => true ],
			'items'   => [
				'type'    => 'repeater',
				'label'   => 'Items',
				'tab'     => 'content',
				'section' => 'content',
				'fields'  => [
					'label' => [ 'type' => 'text', 'label' => 'Label' ],
				],
			],
		];
	}
}

final class LiveWideWidget {
	public function get_title(): string {
		return 'Wide Widget';
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		$controls = [];
		for ( $index = 1; $index <= 30; ++$index ) {
			$controls[ 'control_' . $index ] = [
				'type'    => 'text',
				'label'   => 'Control ' . $index,
				'tab'     => 'content',
				'section' => 'content',
			];
		}
		return $controls;
	}
}
