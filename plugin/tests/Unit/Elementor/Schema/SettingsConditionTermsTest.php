<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * Control conditions in the `relation` / `terms` form.
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 */
final class SettingsConditionTermsTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']    = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_transients'] = [];
		WidgetSchemaRepository::reset_request_cache();
		$base                        = $this->original_elementor;
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class( $base ) {
				public function __construct( private object $base ) {
				}

				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					if ( 'condition-probe' === $name ) {
						return new ConditionProbeWidget();
					}
					return $this->base->widgets_manager->get_widget_types( $name );
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	/** @param array<string, mixed> $settings */
	private static function text_editor( array $settings ): array|\WP_Error {
		return SettingsValidator::validate( 'text-editor', array_merge( [ 'editor' => '<p>Body</p>' ], $settings ) );
	}

	/** @param array<string, mixed> $settings */
	private static function probe( array $settings ): array|\WP_Error {
		return SettingsValidator::validate( 'condition-probe', $settings, false );
	}

	public function test_text_editor_column_gap_is_accepted_for_an_empty_column_count(): void {
		$result = self::text_editor( [ 'text_columns' => '', 'column_gap' => [ 'unit' => 'px', 'size' => 20 ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( [ 'unit' => 'px', 'size' => 20 ], $result['settings']['column_gap'] );
	}

	public function test_text_editor_column_gap_is_accepted_for_two_columns(): void {
		foreach ( [ '2', 2 ] as $columns ) {
			$result = self::text_editor( [ 'text_columns' => $columns, 'column_gap' => [ 'unit' => 'px', 'size' => 20 ] ] );
			self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		}
	}

	public function test_text_editor_column_gap_is_accepted_when_the_column_count_is_not_sent(): void {
		$result = self::text_editor( [ 'column_gap' => [ 'unit' => 'px', 'size' => 20 ] ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public function test_text_editor_column_gap_is_refused_for_one_column(): void {
		foreach ( [ '1', 1 ] as $columns ) {
			$result = self::text_editor( [ 'text_columns' => $columns, 'column_gap' => [ 'unit' => 'px', 'size' => 20 ] ] );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'inactive_condition', $result->get_error_data()['violations'][0]['code'] );
			self::assertSame( 'settings.column_gap', $result->get_error_data()['violations'][0]['path'] );
		}
	}

	public function test_and_relation_needs_every_term(): void {
		self::assertIsArray( self::probe( [ 'mode' => 'custom', 'level' => 5, 'and_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'custom', 'level' => 0, 'and_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'plain', 'level' => 5, 'and_target' => 'x' ] ) );
	}

	public function test_a_terms_list_without_a_relation_is_an_and(): void {
		self::assertIsArray( self::probe( [ 'mode' => 'custom', 'level' => 5, 'default_relation_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'custom', 'level' => 0, 'default_relation_target' => 'x' ] ) );
	}

	public function test_nested_groups_are_evaluated(): void {
		// (mode === custom) AND ( level > 3 OR flag === yes )
		self::assertIsArray( self::probe( [ 'mode' => 'custom', 'level' => 9, 'nested_target' => 'x' ] ) );
		self::assertIsArray( self::probe( [ 'mode' => 'custom', 'level' => 1, 'flag' => 'yes', 'nested_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'custom', 'level' => 1, 'flag' => '', 'nested_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'plain', 'level' => 9, 'nested_target' => 'x' ] ) );
	}

	public function test_set_and_comparison_operators(): void {
		self::assertIsArray( self::probe( [ 'mode' => 'custom', 'in_target' => 'x' ] ) );
		self::assertIsArray( self::probe( [ 'mode' => 'other', 'in_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'plain', 'in_target' => 'x' ] ) );

		self::assertIsArray( self::probe( [ 'mode' => 'plain', 'not_in_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'custom', 'not_in_target' => 'x' ] ) );

		self::assertIsArray( self::probe( [ 'level' => 3, 'range_target' => 'x' ] ) );
		self::assertIsArray( self::probe( [ 'level' => 7, 'range_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'level' => 8, 'range_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'level' => 2, 'range_target' => 'x' ] ) );

		self::assertIsArray( self::probe( [ 'mode' => 'plain', 'ne_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'custom', 'ne_target' => 'x' ] ) );
	}

	public function test_a_term_can_name_a_sub_value(): void {
		self::assertIsArray( self::probe( [ 'link' => [ 'url' => 'https://example.com/a' ], 'sub_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'link' => [ 'url' => '' ], 'sub_target' => 'x' ] ) );
	}

	public function test_an_unknown_operator_keeps_the_control_inactive_and_says_so(): void {
		$result = self::probe( [ 'mode' => 'custom', 'unknown_op_target' => 'x' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		$violation = $result->get_error_data()['violations'][0];
		self::assertSame( 'unsupported_condition_operator', $violation['code'] );
		self::assertSame( 'settings.unknown_op_target', $violation['path'] );
		self::assertStringContainsString( '~=', $violation['expected'] );
		self::assertStringContainsString( 'mode', $violation['expected'] );
	}

	public function test_an_unknown_operator_in_a_satisfied_or_branch_warns_but_stays_active(): void {
		$result = self::probe( [ 'mode' => 'custom', 'level' => 9, 'unknown_or_target' => 'x' ] );

		self::assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$codes = array_column( $result['warnings'], 'code' );
		self::assertContains( 'unsupported_condition_operator', $codes );
	}

	public function test_a_flat_condition_is_met_by_a_multiple_value_that_contains_it(): void {
		self::assertIsArray( self::probe( [ 'actions' => [ 'message', 'hide' ], 'multi_target' => 'x' ] ) );
		self::assertIsArray( self::probe( [ 'actions' => [ 'message' ], 'multi_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'actions' => [ 'hide' ], 'multi_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'actions' => [], 'multi_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'multi_target' => 'x' ] ) );
	}

	public function test_the_flat_condition_form_is_unchanged(): void {
		self::assertIsArray( self::probe( [ 'mode' => 'custom', 'flat_target' => 'x' ] ) );
		self::assertInstanceOf( \WP_Error::class, self::probe( [ 'mode' => 'plain', 'flat_target' => 'x' ] ) );
	}
}

final class ConditionProbeWidget {
	public function get_title(): string {
		return 'Condition probe';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'basic' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		$term = static fn( string $name, string $operator, mixed $value ): array => [ 'name' => $name, 'operator' => $operator, 'value' => $value ];
		$c    = static fn( array $condition ): array => [ 'type' => 'text', 'tab' => 'content', 'section' => 'content', 'condition' => $condition ];
		return [
			'mode'                    => [ 'type' => 'select', 'tab' => 'content', 'section' => 'content', 'default' => '', 'options' => [ '' => 'Default', 'plain' => 'Plain', 'custom' => 'Custom', 'other' => 'Other' ] ],
			'level'                   => [ 'type' => 'number', 'tab' => 'content', 'section' => 'content' ],
			'flag'                    => [ 'type' => 'switcher', 'tab' => 'content', 'section' => 'content', 'default' => '' ],
			'link'                    => [ 'type' => 'url', 'tab' => 'content', 'section' => 'content' ],
			'actions'                 => [ 'type' => 'select2', 'tab' => 'content', 'section' => 'content', 'multiple' => true, 'default' => [], 'options' => [ 'message' => 'Message', 'hide' => 'Hide' ] ],
			'multi_target'            => $c( [ 'actions' => 'message' ] ),
			'flat_target'             => $c( [ 'mode' => 'custom' ] ),
			'and_target'              => $c( [ 'relation' => 'and', 'terms' => [ $term( 'mode', '===', 'custom' ), $term( 'level', '>', 0 ) ] ] ),
			'default_relation_target' => $c( [ 'terms' => [ $term( 'mode', '===', 'custom' ), $term( 'level', '>', 0 ) ] ] ),
			'nested_target'           => $c(
				[
					'relation' => 'and',
					'terms'    => [
						$term( 'mode', '===', 'custom' ),
						[ 'relation' => 'or', 'terms' => [ $term( 'level', '>', 3 ), $term( 'flag', '===', 'yes' ) ] ],
					],
				]
			),
			'in_target'               => $c( [ 'terms' => [ $term( 'mode', 'in', [ 'custom', 'other' ] ) ] ] ),
			'not_in_target'           => $c( [ 'terms' => [ $term( 'mode', '!in', [ 'custom', 'other' ] ) ] ] ),
			'range_target'            => $c( [ 'terms' => [ $term( 'level', '>=', 3 ), $term( 'level', '<=', 7 ) ] ] ),
			'ne_target'               => $c( [ 'terms' => [ $term( 'mode', '!==', 'custom' ) ] ] ),
			'sub_target'              => $c( [ 'terms' => [ $term( 'link[url]', '!==', '' ) ] ] ),
			'unknown_op_target'       => $c( [ 'terms' => [ $term( 'mode', '~=', 'custom' ) ] ] ),
			'unknown_or_target'       => $c( [ 'relation' => 'or', 'terms' => [ $term( 'mode', '~=', 'custom' ), $term( 'level', '>', 3 ) ] ] ),
		];
	}
}
