<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\MoveElement;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddArchivePosts;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddHeading;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddInnerSection;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddWcAddToCart;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidgets\WidgetAbilityBase
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddWidget
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddContainer
 */
final class WidgetStructureGuardsTest extends TestCase {

	protected function setUp(): void {
		$tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[
						'id'         => 'w1',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => [ 'title' => 'Existing' ],
						'elements'   => [],
					],
				],
			],
			[
				'id'       => 'sec1',
				'elType'   => 'section',
				'settings' => [],
				'elements' => [
					[
						'id'       => 'col1',
						'elType'   => 'column',
						'settings' => [ '_column_size' => 100 ],
						'elements' => [],
					],
				],
			],
		];

		$GLOBALS['stonewright_test_posts'] = [
			321 => (object) [
				'ID'           => 321,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => (string) wp_json_encode( $tree ),
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
		$decoded = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][321]->meta['_elementor_data'] ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** @return list<array<string, mixed>> */
	private function document_writes(): array {
		return array_values(
			array_filter(
				$GLOBALS['stonewright_test_post_meta_calls'],
				static fn( array $call ): bool => '_elementor_data' === $call['meta_key']
			)
		);
	}

	// ----- QV3-04 inner section --------------------------------------

	public function test_inner_section_in_a_column_stores_a_real_inner_section_with_a_column(): void {
		$result = ( new AddInnerSection() )->execute( [ 'post_id' => 321, 'parent_id' => 'col1' ] );

		self::assertIsArray( $result );
		$column = $this->stored_tree()[1]['elements'][0];
		self::assertCount( 1, $column['elements'] );
		$inner = $column['elements'][0];
		self::assertSame( $result['element_id'], $inner['id'] );
		self::assertSame( 'section', $inner['elType'] );
		self::assertTrue( $inner['isInner'] );
		self::assertArrayNotHasKey( 'widgetType', $inner );
		self::assertCount( 1, $inner['elements'] );
		self::assertSame( 'column', $inner['elements'][0]['elType'] );
		self::assertSame( 100, $inner['elements'][0]['settings']['_column_size'] );
		self::assertTrue( $inner['elements'][0]['isInner'] );
		self::assertSame( [], $inner['elements'][0]['elements'] );
	}

	public function test_inner_section_in_a_container_stores_an_inner_container(): void {
		$result = ( new AddInnerSection() )->execute( [ 'post_id' => 321, 'parent_id' => 'root' ] );

		self::assertIsArray( $result );
		$inner = $this->stored_tree()[0]['elements'][1];
		self::assertSame( $result['element_id'], $inner['id'] );
		self::assertSame( 'container', $inner['elType'] );
		self::assertTrue( $inner['isInner'] );
		self::assertArrayNotHasKey( 'widgetType', $inner );
		self::assertSame( [], $inner['elements'] );
	}

	public function test_inner_section_is_refused_directly_inside_a_section(): void {
		$result = ( new AddInnerSection() )->execute( [ 'post_id' => 321, 'parent_id' => 'sec1' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_code() );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_inner_section_cannot_be_stored_as_a_widget_through_the_raw_or_batch_paths(): void {
		$raw = ( new AddWidget() )->execute(
			[
				'post_id'                => 321,
				'parent_id'              => 'root',
				'widget_type'            => 'inner-section',
				'allow_raw_known_widget' => true,
				'settings'               => [],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $raw );
		self::assertSame( 'stonewright_inner_section_is_layout', $raw->get_error_code() );

		$batch = ( new BatchMutate() )->execute(
			[
				'post_id'    => 321,
				'operations' => [ [ 'action' => 'add_widget', 'parent_id' => 'root', 'widget_type' => 'inner-section', 'settings' => [] ] ],
			]
		);
		self::assertInstanceOf( \WP_Error::class, $batch );
		self::assertStringContainsString( 'inner_section_is_layout', (string) wp_json_encode( $batch->get_error_data() ) . $batch->get_error_code() );
		self::assertSame( [], $this->document_writes() );
	}

	// ----- QV3-05 widget parents -------------------------------------

	public function test_dedicated_widget_cannot_be_added_under_another_widget(): void {
		$result = ( new AddHeading() )->execute(
			[ 'post_id' => 321, 'parent_id' => 'w1', 'settings' => [ 'title' => 'Child' ] ]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_code() );
		self::assertSame( 'w1', $result->get_error_data()['parent_id'] );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_raw_widget_cannot_be_added_under_another_widget(): void {
		$result = ( new AddWidget() )->execute(
			[
				'post_id'                => 321,
				'parent_id'              => 'w1',
				'widget_type'            => 'divider',
				'allow_raw_known_widget' => true,
				'settings'               => [],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_code() );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_batch_add_widget_cannot_target_a_widget_parent(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 321,
				'operations' => [
					[
						'action'      => 'add_widget',
						'parent_id'   => 'w1',
						'widget_type' => 'heading',
						'settings'    => [ 'title' => 'Child' ],
					],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->document_writes() );
		self::assertStringContainsString( 'parent_not_container', (string) wp_json_encode( $result->get_error_data() ) . $result->get_error_code() );
	}

	public function test_add_container_cannot_target_a_widget_parent(): void {
		$result = ( new AddContainer() )->execute( [ 'post_id' => 321, 'parent_id' => 'w1', 'settings' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_code() );
		self::assertSame( 'w1', $result->get_error_data()['parent_id'] );
		self::assertSame( 'widget', $result->get_error_data()['parent_el_type'] );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_move_element_cannot_target_a_widget_parent(): void {
		$result = ( new MoveElement() )->execute( [ 'post_id' => 321, 'element_id' => 'col1', 'new_parent_id' => 'w1' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_code() );
		self::assertSame( 'w1', $result->get_error_data()['parent_id'] );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_batch_add_container_cannot_target_a_widget_parent(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 321,
				'operations' => [ [ 'action' => 'add_container', 'parent_id' => 'w1', 'settings' => [] ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->document_writes() );
		self::assertStringContainsString( 'parent_not_container', (string) wp_json_encode( $result->get_error_data() ) . $result->get_error_code() );
	}

	public function test_batch_move_element_cannot_target_a_widget_parent(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => 321,
				'operations' => [ [ 'action' => 'move_element', 'element_id' => 'col1', 'new_parent_id' => 'w1' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $this->document_writes() );
		self::assertStringContainsString( 'parent_not_container', (string) wp_json_encode( $result->get_error_data() ) . $result->get_error_code() );
	}

	public function test_containers_and_moves_are_still_accepted_by_containers_columns_and_sections(): void {
		$added = ( new AddContainer() )->execute( [ 'post_id' => 321, 'parent_id' => 'root', 'settings' => [] ] );
		self::assertIsArray( $added );

		$moved = ( new MoveElement() )->execute( [ 'post_id' => 321, 'element_id' => 'w1', 'new_parent_id' => 'col1' ] );
		self::assertIsArray( $moved );
		self::assertSame( 'w1', $this->stored_tree()[1]['elements'][0]['elements'][0]['id'] );

		$batch = ( new BatchMutate() )->execute(
			[
				'post_id'    => 321,
				'operations' => [
					[ 'action' => 'add_container', 'parent_id' => 'root', 'settings' => [] ],
					[ 'action' => 'move_element', 'element_id' => 'w1', 'new_parent_id' => 'root' ],
				],
			]
		);
		self::assertIsArray( $batch, is_wp_error( $batch ) ? $batch->get_error_message() : '' );
	}

	public function test_widgets_are_still_accepted_by_containers_columns_and_sections(): void {
		foreach ( [ 'root', 'col1', 'sec1' ] as $parent ) {
			$result = ( new AddHeading() )->execute( [ 'post_id' => 321, 'parent_id' => $parent, 'settings' => [ 'title' => 'Ok' ] ] );
			self::assertIsArray( $result, $parent );
		}
	}

	// ----- QV3-08 busy lock ------------------------------------------

	public function test_concurrent_writes_report_the_busy_error_on_every_add_path(): void {
		self::assertIsArray( PostWriteLock::acquire( 321, 'other-writer', 30 ) );
		$before = $GLOBALS['stonewright_test_posts'][321]->meta['_elementor_data'];

		$attempts = [
			'dedicated' => ( new AddHeading() )->execute( [ 'post_id' => 321, 'parent_id' => 'root', 'settings' => [ 'title' => 'Busy' ] ] ),
			'raw'       => ( new AddWidget() )->execute( [ 'post_id' => 321, 'parent_id' => 'root', 'widget_type' => 'divider', 'allow_raw_known_widget' => true, 'settings' => [] ] ),
			'container' => ( new AddContainer() )->execute( [ 'post_id' => 321, 'settings' => [] ] ),
		];
		foreach ( $attempts as $name => $result ) {
			self::assertInstanceOf( \WP_Error::class, $result, $name );
			self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code(), $name );
			$data = $result->get_error_data();
			self::assertTrue( $data['retryable'], $name );
			self::assertGreaterThan( 0, $data['retry_after'], $name );
		}
		self::assertSame( $before, $GLOBALS['stonewright_test_posts'][321]->meta['_elementor_data'] );
	}

	// ----- QV3-15 unavailable widgets --------------------------------

	public function test_pro_widget_is_refused_when_elementor_pro_is_missing(): void {
		$result = ( new AddArchivePosts() )->execute( [ 'post_id' => 321, 'parent_id' => 'root', 'settings' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_widget_unavailable', $result->get_error_code() );
		$data = $result->get_error_data();
		self::assertSame( 'archive-posts', $data['widget_type'] );
		self::assertSame( 'elementor-pro', $data['requires'] );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_woocommerce_widget_is_refused_without_the_required_plugins(): void {
		$result = ( new AddWcAddToCart() )->execute( [ 'post_id' => 321, 'parent_id' => 'root', 'settings' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_widget_unavailable', $result->get_error_code() );
		self::assertContains( $result->get_error_data()['requires'], [ 'elementor-pro', 'woocommerce' ] );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_raw_add_refuses_a_pro_widget_on_a_free_site(): void {
		$result = ( new AddWidget() )->execute(
			[
				'post_id'                => 321,
				'parent_id'              => 'root',
				'widget_type'            => 'archive-posts',
				'allow_raw_known_widget' => true,
				'settings'               => [],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_widget_unavailable', $result->get_error_code() );
		self::assertSame( [], $this->document_writes() );
	}

	public function test_free_widgets_are_not_affected_by_the_availability_check(): void {
		$result = ( new AddHeading() )->execute( [ 'post_id' => 321, 'parent_id' => 'root', 'settings' => [ 'title' => 'Free' ] ] );

		self::assertIsArray( $result );
	}
}
