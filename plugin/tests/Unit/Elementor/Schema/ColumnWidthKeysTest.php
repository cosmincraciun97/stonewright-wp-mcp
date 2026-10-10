<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\BuildTree;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;

/**
 * @covers \Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BuildTree
 */
final class ColumnWidthKeysTest extends TestCase {

	private object $original_elementor;

	/**
	 * Serves the structural controls the way a booted Elementor does: the column width is a number control and
	 * `_column_size` is not a declared control at all.
	 */
	private function use_live_structural_controls(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$base                     = $this->original_elementor;
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager'  => $base->widgets_manager,
			'elements_manager' => new class() {
				public function get_element_types( ?string $name = null ): array|object {
					$element = new class() {
						/** @return array<string, array<string, mixed>> */
						public function get_controls(): array {
							return [
								'_inline_size'     => [ 'type' => 'number', 'tab' => 'layout', 'section' => 'layout', 'min' => 2, 'max' => 98, 'responsive' => [] ],
								'content_position' => [ 'type' => 'select', 'tab' => 'layout', 'section' => 'layout', 'responsive' => [] ],
							];
						}
					};
					return null === $name ? [ 'column' => $element ] : $element;
				}
			},
		];
	}

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			951 => (object) [
				'ID'           => 951,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Columns target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => '[]',
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
		if ( isset( $this->original_elementor ) ) {
			\Elementor\Plugin::$instance = $this->original_elementor;
		}
		$GLOBALS['stonewright_test_posts']           = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
	}

	/** @return array<int, array<string, mixed>> */
	private function stored_tree(): array {
		$decoded = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][951]->meta['_elementor_data'] ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param list<array<string, mixed>> $columns
	 * @return array<int, array<string, mixed>>
	 */
	private static function section_tree( array $columns ): array {
		return [
			[
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => [],
				'elements' => $columns,
			],
		];
	}

	public function test_column_schema_lists_the_width_keys_elementor_saves(): void {
		$schema = ContainerSchemaRepository::get( 'column' );

		self::assertIsArray( $schema );
		self::assertArrayHasKey( '_column_size', $schema['controls'] );
		self::assertArrayHasKey( '_inline_size', $schema['controls'] );
	}

	public function test_column_settings_with_the_saved_width_keys_validate(): void {
		$result = SettingsValidator::validate_container( [ '_column_size' => 50, '_inline_size' => null ], 'column' );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( 50, $result['settings']['_column_size'] );
		self::assertArrayHasKey( '_inline_size', $result['settings'] );

		$numeric = SettingsValidator::validate_container( [ '_column_size' => 50, '_inline_size' => 50 ], 'column' );
		self::assertIsArray( $numeric );
	}

	public function test_build_tree_accepts_columns_with_both_width_keys(): void {
		$tree = self::section_tree(
			[
				[ 'id' => 'col0001', 'elType' => 'column', 'settings' => [ '_column_size' => 50, '_inline_size' => 50 ], 'elements' => [] ],
				[ 'id' => 'col0002', 'elType' => 'column', 'settings' => [ '_column_size' => 50, '_inline_size' => null ], 'elements' => [] ],
			]
		);

		$dry = ( new BuildTree() )->execute( [ 'post_id' => 951, 'dry_run' => true, 'tree' => $tree ] );
		self::assertIsArray( $dry, is_wp_error( $dry ) ? $dry->get_error_message() : '' );

		$applied = ( new BuildTree() )->execute( [ 'post_id' => 951, 'tree' => $tree ] );
		self::assertIsArray( $applied, is_wp_error( $applied ) ? $applied->get_error_message() : '' );
		$stored = $this->stored_tree();
		self::assertSame( 50, $stored[0]['elements'][0]['settings']['_column_size'] );
		self::assertSame( 50, $stored[0]['elements'][0]['settings']['_inline_size'] );
	}

	public function test_build_tree_supplies_a_column_size_when_a_column_has_none(): void {
		$tree = self::section_tree(
			[
				[ 'id' => 'col0001', 'elType' => 'column', 'settings' => [], 'elements' => [] ],
				[ 'id' => 'col0002', 'elType' => 'column', 'settings' => [ '_column_size' => 70 ], 'elements' => [] ],
				[ 'id' => 'col0003', 'elType' => 'column', 'settings' => [], 'elements' => [] ],
			]
		);

		$result = ( new BuildTree() )->execute( [ 'post_id' => 951, 'tree' => $tree ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$columns = $this->stored_tree()[0]['elements'];
		self::assertSame( 33, $columns[0]['settings']['_column_size'] );
		self::assertSame( 70, $columns[1]['settings']['_column_size'], 'A width the caller set must not change.' );
		self::assertSame( 33, $columns[2]['settings']['_column_size'] );
	}

	public function test_build_tree_dry_run_and_apply_agree_for_columns_without_widths(): void {
		$tree = self::section_tree( [ [ 'id' => 'col0001', 'elType' => 'column', 'settings' => [], 'elements' => [] ] ] );

		$dry     = ( new BuildTree() )->execute( [ 'post_id' => 951, 'dry_run' => true, 'tree' => $tree ] );
		$applied = ( new BuildTree() )->execute( [ 'post_id' => 951, 'tree' => $tree ] );

		self::assertIsArray( $dry );
		self::assertIsArray( $applied );
		self::assertSame( $dry['element_count'], $applied['element_count'] );
		self::assertSame( 100, $this->stored_tree()[0]['elements'][0]['settings']['_column_size'] );
	}

	public function test_a_cleared_column_width_validates_against_the_live_number_control(): void {
		$this->use_live_structural_controls();

		foreach ( [ null, '' ] as $cleared ) {
			$result = SettingsValidator::validate_container( [ '_column_size' => 50, '_inline_size' => $cleared ], 'column' );
			self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
			self::assertSame( $cleared, $result['settings']['_inline_size'] );
		}

		$tablet = SettingsValidator::validate_container( [ '_column_size' => 50, '_inline_size_tablet' => '' ], 'column' );
		self::assertIsArray( $tablet, is_wp_error( $tablet ) ? $tablet->get_error_message() : '' );

		$text = SettingsValidator::validate_container( [ '_column_size' => 50, '_inline_size' => 'wide' ], 'column' );
		self::assertInstanceOf( \WP_Error::class, $text, 'Only the cleared state is accepted; other non-numeric text is still refused.' );
	}

	public function test_build_tree_accepts_the_width_keys_elementor_writes_for_a_new_column(): void {
		$this->use_live_structural_controls();

		foreach ( [ null, '' ] as $cleared ) {
			$tree = self::section_tree(
				[
					[ 'id' => 'col0001', 'elType' => 'column', 'settings' => [ '_column_size' => 50, '_inline_size' => $cleared ], 'elements' => [] ],
					[ 'id' => 'col0002', 'elType' => 'column', 'settings' => [ '_column_size' => 50, '_inline_size' => $cleared ], 'elements' => [] ],
				]
			);

			$dry = ( new BuildTree() )->execute( [ 'post_id' => 951, 'dry_run' => true, 'tree' => $tree ] );
			self::assertIsArray( $dry, is_wp_error( $dry ) ? $dry->get_error_message() : '' );

			$applied = ( new BuildTree() )->execute( [ 'post_id' => 951, 'tree' => $tree ] );
			self::assertIsArray( $applied, is_wp_error( $applied ) ? $applied->get_error_message() : '' );
			$stored = $this->stored_tree()[0]['elements'];
			self::assertSame( 50, $stored[0]['settings']['_column_size'] );
			self::assertSame( $cleared, $stored[0]['settings']['_inline_size'] );
		}
	}

	public function test_a_single_column_with_only_a_custom_width_dry_runs_and_applies_alike_over_a_stored_column(): void {
		// The first build stores the width keys as `_inline_size`, `_column_size`; the second names them the other way round.
		$first  = self::section_tree( [ [ 'id' => 'col0001', 'elType' => 'column', 'settings' => [ '_inline_size' => 100 ], 'elements' => [] ] ] );
		$second = self::section_tree( [ [ 'id' => 'col0001', 'elType' => 'column', 'settings' => [ '_column_size' => 100, '_inline_size' => 100 ], 'elements' => [] ] ] );

		foreach ( [ $first, $second ] as $tree ) {
			$dry     = ( new BuildTree() )->execute( [ 'post_id' => 951, 'dry_run' => true, 'tree' => $tree ] );
			$applied = ( new BuildTree() )->execute( [ 'post_id' => 951, 'tree' => $tree ] );

			self::assertIsArray( $applied, is_wp_error( $applied ) ? $applied->get_error_message() : '' );
			self::assertIsArray( $dry, is_wp_error( $dry ) ? $dry->get_error_message() : '' );
		}
		self::assertSame( 100, $this->stored_tree()[0]['elements'][0]['settings']['_inline_size'] );
	}

	public function test_build_tree_dry_run_reports_the_settings_delta_error_the_apply_returns(): void {
		$GLOBALS['stonewright_test_posts'][951]->meta['_elementor_data'] = (string) wp_json_encode(
			self::section_tree( [ [ 'id' => 'col0001', 'elType' => 'column', 'settings' => [ '_column_size' => 100, 'third_party_option' => 'keep' ], 'elements' => [] ] ] )
		);
		$tree = self::section_tree( [ [ 'id' => 'col0001', 'elType' => 'column', 'settings' => [ '_column_size' => 100 ], 'elements' => [] ] ] );

		$dry     = ( new BuildTree() )->execute( [ 'post_id' => 951, 'dry_run' => true, 'tree' => $tree ] );
		$applied = ( new BuildTree() )->execute( [ 'post_id' => 951, 'tree' => $tree ] );

		self::assertInstanceOf( \WP_Error::class, $applied );
		self::assertInstanceOf( \WP_Error::class, $dry );
		self::assertSame( $applied->get_error_code(), $dry->get_error_code() );
		self::assertSame( $applied->get_error_message(), $dry->get_error_message() );
	}
}
