<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildTree;
use Stonewright\WpMcp\Abilities\ElementorV3\UpdateElement;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddAlert;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddDivider;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddSocialIcons;
use Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 */
final class SettingsKeyAliasesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			931 => (object) [
				'ID'           => 931,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Alias target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => (string) wp_json_encode(
						[
							[
								'id'       => 'root',
								'elType'   => 'container',
								'settings' => [],
								'elements' => [
									[
										'id'         => 'alert1',
										'elType'     => 'widget',
										'widgetType' => 'alert',
										'settings'   => [ 'alert_title' => 'Notice' ],
										'elements'   => [],
									],
								],
							],
						]
					),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_post' => true, 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/** @return array<int, array<string, mixed>> */
	private function stored_tree(): array {
		$raw = (string) $GLOBALS['stonewright_test_posts'][931]->meta['_elementor_data'];
		$decoded = json_decode( stripslashes( $raw ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	public function test_normalize_without_controls_keeps_the_legacy_container_mapping(): void {
		$result = SettingsKeyAliases::normalize( [ 'gap' => '12', 'background' => '#fff' ] );

		self::assertSame( '12', $result['settings']['flex_gap'] );
		self::assertSame( '#fff', $result['settings']['background_color'] );
		self::assertArrayNotHasKey( 'gap', $result['settings'] );
	}

	public function test_normalize_keeps_a_key_that_is_a_real_control(): void {
		$controls = [
			'gap'        => [ 'type' => 'slider' ],
			'column_gap' => [ 'type' => 'slider' ],
		];
		$result   = SettingsKeyAliases::normalize( [ 'gap' => [ 'size' => 4 ], 'column_gap' => [ 'size' => 8 ], 'row_gap' => [ 'size' => 2 ] ], $controls );

		self::assertSame( [ 'size' => 4 ], $result['settings']['gap'] );
		self::assertSame( [ 'size' => 8 ], $result['settings']['column_gap'] );
		// No `row_gap` control here, so the alias still applies.
		self::assertSame( [ 'size' => 2 ], $result['settings']['flex_row_gap'] );
		self::assertCount( 1, $result['applied'] );
	}

	public function test_validator_keeps_alert_background_control(): void {
		$result = SettingsValidator::validate( 'alert', [ 'alert_title' => 'Notice', 'background' => '#112233' ] );

		self::assertIsArray( $result );
		self::assertSame( '#112233', $result['settings']['background'] );
		self::assertArrayNotHasKey( 'background_color', $result['settings'] );
	}

	public function test_validator_keeps_divider_gap_control(): void {
		$result = SettingsValidator::validate( 'divider', [ 'gap' => [ 'size' => 20, 'unit' => 'px' ] ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'size' => 20, 'unit' => 'px' ], $result['settings']['gap'] );
		self::assertArrayNotHasKey( 'flex_gap', $result['settings'] );
	}

	public function test_validator_keeps_text_editor_column_gap_and_social_icons_row_gap(): void {
		// The bundled catalog describes the column_gap condition as a terms list; conditions are not the subject here.
		$text = SettingsValidator::validate( 'text-editor', [ 'editor' => '<p>Body</p>', 'text_columns' => '2', 'column_gap' => [ 'size' => 24, 'unit' => 'px' ] ], true, false );
		self::assertIsArray( $text );
		self::assertSame( [ 'size' => 24, 'unit' => 'px' ], $text['settings']['column_gap'] );
		self::assertArrayNotHasKey( 'flex_column_gap', $text['settings'] );

		$social = SettingsValidator::validate( 'social-icons', [ 'row_gap' => [ 'size' => 6, 'unit' => 'px' ] ], false );
		self::assertIsArray( $social );
		self::assertSame( [ 'size' => 6, 'unit' => 'px' ], $social['settings']['row_gap'] );
		self::assertArrayNotHasKey( 'flex_row_gap', $social['settings'] );
	}

	public function test_validator_still_aliases_container_keys(): void {
		$result = SettingsValidator::validate_container( [ 'gap' => [ 'size' => 10, 'unit' => 'px' ], 'justify_content' => 'center' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'size' => 10, 'unit' => 'px' ], $result['settings']['flex_gap'] );
		self::assertSame( 'center', $result['settings']['flex_justify_content'] );
		self::assertArrayNotHasKey( 'gap', $result['settings'] );
	}

	public function test_widget_without_the_control_still_gets_the_alias(): void {
		// `heading` has no `font_size` control; the short form still resolves.
		$result = SettingsValidator::validate( 'heading', [ 'title' => 'Hi', 'typography_typography' => 'custom', 'font_size' => [ 'size' => 20, 'unit' => 'px' ] ], false );

		self::assertIsArray( $result );
		self::assertSame( [ 'size' => 20, 'unit' => 'px' ], $result['settings']['typography_font_size'] );
		self::assertArrayNotHasKey( 'font_size', $result['settings'] );
	}

	public function test_dedicated_add_tools_store_the_real_control(): void {
		$cases = [
			[ new AddAlert(), [ 'alert_title' => 'Notice', 'background' => '#112233' ], 'background' ],
			[ new AddDivider(), [ 'gap' => [ 'size' => 20, 'unit' => 'px' ] ], 'gap' ],
			[ new AddSocialIcons(), [ 'row_gap' => [ 'size' => 6, 'unit' => 'px' ], 'social_icon_list' => [ [ 'social_icon' => [ 'value' => 'fab fa-twitter', 'library' => 'fa-brands' ] ] ] ], 'row_gap' ],
		];
		foreach ( $cases as [ $ability, $settings, $key ] ) {
			$result = $ability->execute( [ 'post_id' => 931, 'parent_id' => 'root', 'settings' => $settings ] );
			self::assertIsArray( $result, $key . ' must be accepted' );
			$tree   = $this->stored_tree();
			$widget = null;
			foreach ( $tree[0]['elements'] as $element ) {
				if ( $element['id'] === $result['element_id'] ) {
					$widget = $element;
				}
			}
			self::assertNotNull( $widget );
			self::assertArrayHasKey( $key, $widget['settings'], $key . ' must be stored as written' );
			self::assertSame( $settings[ $key ], $widget['settings'][ $key ] );
		}
	}

	public function test_update_element_keeps_the_real_widget_control(): void {
		$result = ( new UpdateElement() )->execute(
			[
				'post_id'    => 931,
				'element_id' => 'alert1',
				'settings'   => [ 'background' => '#112233' ],
			]
		);

		self::assertIsArray( $result );
		$tree = $this->stored_tree();
		self::assertSame( '#112233', $tree[0]['elements'][0]['settings']['background'] );
		self::assertArrayNotHasKey( 'background_color', $tree[0]['elements'][0]['settings'] );
	}

	public function test_raw_add_widget_keeps_the_real_widget_control(): void {
		$result = ( new AddWidget() )->execute(
			[
				'post_id'                => 931,
				'parent_id'              => 'root',
				'widget_type'            => 'divider',
				'allow_raw_known_widget' => true,
				'settings'               => [ 'gap' => [ 'size' => 20, 'unit' => 'px' ] ],
			]
		);

		self::assertIsArray( $result );
		$tree = $this->stored_tree();
		self::assertSame( [ 'size' => 20, 'unit' => 'px' ], $tree[0]['elements'][1]['settings']['gap'] );
	}

	public function test_build_tree_keeps_widget_controls_and_still_aliases_containers(): void {
		$result = ( new BuildTree() )->execute(
			[
				'post_id' => 931,
				'tree'    => [
					[
						'id'       => 'bt0root',
						'elType'   => 'container',
						'settings' => [ 'gap' => [ 'size' => 10, 'unit' => 'px' ] ],
						'elements' => [
							[
								'id'         => 'bt0alrt',
								'elType'     => 'widget',
								'widgetType' => 'alert',
								'settings'   => [ 'alert_title' => 'Notice', 'background' => '#112233' ],
								'elements'   => [],
							],
						],
					],
				],
			]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$stored = $this->stored_tree();
		self::assertArrayHasKey( 'flex_gap', $stored[0]['settings'] );
		self::assertSame( '#112233', $stored[0]['elements'][0]['settings']['background'] );
		self::assertArrayNotHasKey( 'background_color', $stored[0]['elements'][0]['settings'] );
	}

	public function test_batch_mutate_keeps_the_real_widget_control_for_add_and_update(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 931,
				'operations' => [
					[
						'action'      => 'add_widget',
						'op_id'       => 'divider',
						'parent_id'   => 'root',
						'widget_type' => 'divider',
						'settings'    => [ 'gap' => [ 'size' => 20, 'unit' => 'px' ] ],
					],
					[
						'action'     => 'update_element',
						'element_id' => 'alert1',
						'settings'   => [ 'background' => '#112233' ],
					],
				],
			]
		);

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$tree = $this->stored_tree();
		self::assertSame( '#112233', $tree[0]['elements'][0]['settings']['background'] );
		self::assertSame( [ 'size' => 20, 'unit' => 'px' ], $tree[0]['elements'][1]['settings']['gap'] );
		self::assertArrayNotHasKey( 'flex_gap', $tree[0]['elements'][1]['settings'] );
	}
}
