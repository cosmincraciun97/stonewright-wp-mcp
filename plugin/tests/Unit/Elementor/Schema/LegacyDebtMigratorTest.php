<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\LegacyDebtMigrate;
use Stonewright\WpMcp\Elementor\Schema\LegacyDebtMigrator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Support\ElementorData;

/** @covers \Stonewright\WpMcp\Elementor\Schema\LegacyDebtMigrator */
final class LegacyDebtMigratorTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps'] = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts'] = [ 78 => $this->legacy_post() ];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [ 'legacy-widget' => new LegacyMigrationWidget() ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts'] = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_plans_only_explicit_live_schema_mappings_and_preserves_unknown_data(): void {
		$tree = [
			[
				'id'         => 'legacy-1',
				'elType'     => 'widget',
				'widgetType' => 'legacy-widget',
				'settings'   => [
					'image'             => null,
					'button_icon_align' => 'right',
					'width_mobile'      => 'legacy-wide',
					'plugin_control'    => 'preserve-me',
					'form_fields'       => [
						[
							'_id'                => 'field-a',
							'file_sizes'         => '',
							'newsman_list_id'     => 'preserve-secretless-fixture',
						],
					],
				],
				'elements'   => [],
			],
		];

		$plan = LegacyDebtMigrator::plan(
			$tree,
			'legacy-1',
			[ 'image', 'button_icon_align', 'form_fields[0].file_sizes', 'plugin_control', 'width_mobile' ]
		);

		self::assertIsArray( $plan );
		self::assertCount( 5, $plan['issues'] );
		self::assertCount( 2, $plan['operations'] );
		self::assertSame( [ 'url' => '', 'id' => 0 ], $plan['operations'][0]['settings']['image'] );
		self::assertSame( 'row-reverse', $plan['operations'][0]['settings']['button_icon_align'] );
		self::assertSame( [ '_id' => 'field-a' ], $plan['operations'][1]['selector'] );
		self::assertSame( [ 'file_sizes' => 2 ], $plan['operations'][1]['row_patch'] );
		self::assertSame( 2, $plan['unavailable_count'] );
		self::assertNotContains( 'plugin_control', array_keys( $plan['operations'][0]['settings'] ) );
		self::assertNotContains( 'width_mobile', array_keys( $plan['operations'][0]['settings'] ) );
		self::assertStringNotContainsString( 'preserve-me', (string) wp_json_encode( $plan['issues'] ) );
		self::assertStringNotContainsString( 'newsman_list_id', (string) wp_json_encode( $plan ) );
	}

	public function test_migration_ability_binds_apply_to_reviewed_plan_and_preserves_third_party_data(): void {
		$paths = [ 'image', 'button_icon_align', 'form_fields[0].file_sizes', 'plugin_control' ];
		$dry = ( new LegacyDebtMigrate() )->execute( [ 'post_id' => 78, 'element_id' => 'legacy-1', 'paths' => $paths ] );

		self::assertIsArray( $dry );
		self::assertSame( 'dry_run', $dry['action'] );
		self::assertSame( 2, $dry['operations_count'] );
		self::assertFalse( $dry['write_performed'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );

		$applied = ( new LegacyDebtMigrate() )->execute(
			[
				'post_id' => 78,
				'element_id' => 'legacy-1',
				'paths' => $paths,
				'action' => 'apply',
				'expected_tree_hash' => $dry['before_tree_hash'],
				'approved_plan_hash' => $dry['plan_hash'],
				'idempotency_key' => 'legacy-plan-78',
			]
		);

		self::assertIsArray( $applied );
		self::assertTrue( $applied['write_performed'] );
		self::assertSame( 'verified', $applied['verification_status'] );
		$stored = ElementorData::read( 78 )[0]['settings'];
		self::assertSame( [ 'url' => '', 'id' => 0 ], $stored['image'] );
		self::assertSame( 'row-reverse', $stored['button_icon_align'] );
		self::assertSame( 2, $stored['form_fields'][0]['file_sizes'] );
		self::assertSame( 'preserve-me', $stored['plugin_control'] );
		self::assertSame( 'preserve-fixture', $stored['form_fields'][0]['integration_mapping'] );
	}

	public function test_production_safe_migration_apply_requires_confirmation(): void {
		$paths = [ 'button_icon_align' ];
		$dry = ( new LegacyDebtMigrate() )->execute( [ 'post_id' => 78, 'element_id' => 'legacy-1', 'paths' => $paths ] );
		self::assertIsArray( $dry );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new LegacyDebtMigrate() )->execute(
			[
				'post_id' => 78, 'element_id' => 'legacy-1', 'paths' => $paths, 'action' => 'apply',
				'expected_tree_hash' => $dry['before_tree_hash'], 'approved_plan_hash' => $dry['plan_hash'],
				'idempotency_key' => 'legacy-plan-78',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	private function legacy_post(): object {
		return (object) [
			'ID' => 78, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Legacy fixture', 'post_content' => '', 'post_excerpt' => '',
			'meta' => [
				'_elementor_data' => wp_json_encode( [ [
					'id' => 'legacy-1', 'elType' => 'widget', 'widgetType' => 'legacy-widget', 'elements' => [],
					'settings' => [
						'image' => null, 'button_icon_align' => 'right', 'plugin_control' => 'preserve-me',
						'form_fields' => [ [ '_id' => 'field-a', 'file_sizes' => '', 'integration_mapping' => 'preserve-fixture' ] ],
					],
				] ] ),
				'_elementor_edit_mode' => 'builder',
			],
		];
	}
}

final class LegacyMigrationWidget {
	public function get_title(): string {
		return 'Legacy migration fixture';
	}

	/** @return array<string,array<string,mixed>> */
	public function get_controls(): array {
		return [
			'image' => [
				'type'    => 'media',
				'default' => [ 'url' => '', 'id' => 0 ],
			],
			'button_icon_align' => [
				'type'    => 'choose',
				'options' => [ 'row' => 'Start', 'row-reverse' => 'End' ],
				'default' => 'row',
				'selectors_dictionary' => [ 'left' => 'row', 'right' => 'row-reverse' ],
			],
			'width' => [
				'type'       => 'number',
				'default'    => 100,
				'responsive' => true,
			],
			'form_fields' => [
				'type'   => 'repeater',
				'fields' => [
					'file_sizes' => [ 'type' => 'number', 'default' => 2 ],
				],
			],
		];
	}
}
