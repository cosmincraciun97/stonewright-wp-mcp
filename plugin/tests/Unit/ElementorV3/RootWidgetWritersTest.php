<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddContainer;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorV3\MoveElement;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * No single-element writer puts a widget at the document root, and add-container reports what it wrote.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddContainer
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\MoveElement
 * @covers \Stonewright\WpMcp\Elementor\Write\V3MutationCompiler
 */
final class RootWidgetWritersTest extends TestCase {

	private const POST = 740;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'post_name'    => 'target',
				'meta'         => [
					'_elementor_data'      => (string) wp_json_encode(
						[
							[
								'id'       => 'row',
								'elType'   => 'container',
								'settings' => [],
								'elements' => [
									[ 'id' => 'a', 'elType' => 'container', 'isInner' => true, 'settings' => [], 'elements' => [] ],
									[ 'id' => 'b', 'elType' => 'container', 'isInner' => true, 'settings' => [], 'elements' => [] ],
									[ 'id' => 'h', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>x</p>' ], 'elements' => [] ],
								],
							],
							[ 'id' => 'last', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
						]
					),
					'_elementor_edit_mode' => 'builder',
					'_elementor_version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0',
				],
			],
		];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development', 'stonewright_allow_html_widgets' => true ];
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

	/** @return list<array<string, mixed>> */
	private static function stored(): array {
		return ElementorData::read( self::POST );
	}

	public function test_batch_add_widget_without_a_parent_is_refused(): void {
		$before = self::stored();

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => self::POST,
				'operations' => [ [ 'action' => 'add_widget', 'widget_type' => 'html', 'allow_html_widget' => true, 'settings' => [ 'html' => '<p>y</p>' ] ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		self::assertSame( 'stonewright_widget_at_root', $result->get_error_data()['root_error_code'] ?? $result->get_error_data()['items'][0]['error']['code'] ?? '' );
		self::assertSame( $before, self::stored() );
	}

	public function test_batch_move_of_a_widget_to_the_root_is_refused(): void {
		$before = self::stored();

		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => self::POST,
				'operations' => [ [ 'action' => 'move_element', 'element_id' => 'h' ] ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_batch_operation_failed', $result->get_error_code() );
		self::assertSame( $before, self::stored() );
	}

	public function test_batch_move_of_a_container_to_the_root_still_works(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => self::POST,
				'operations' => [ [ 'action' => 'move_element', 'element_id' => 'a' ] ],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 'a', self::stored()[2]['id'] );
	}

	public function test_move_element_of_a_widget_to_the_root_is_refused(): void {
		$before = self::stored();

		$result = ( new MoveElement() )->execute( [ 'post_id' => self::POST, 'element_id' => 'h' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_widget_at_root', $result->get_error_code() );
		self::assertSame( $before, self::stored(), 'The widget stays where it was.' );
	}

	public function test_move_element_of_a_widget_into_a_container_still_works(): void {
		$result = ( new MoveElement() )->execute( [ 'post_id' => self::POST, 'element_id' => 'h', 'new_parent_id' => 'last' ] );

		self::assertIsArray( $result );
		self::assertSame( 'h', self::stored()[1]['elements'][0]['id'] );
	}

	public function test_move_element_of_a_container_to_the_root_clears_is_inner(): void {
		$result = ( new MoveElement() )->execute( [ 'post_id' => self::POST, 'element_id' => 'a' ] );

		self::assertIsArray( $result );
		$moved = self::stored()[2];
		self::assertSame( 'a', $moved['id'] );
		self::assertFalse( $moved['isInner'], 'A container at the root is not an inner container.' );
	}

	public function test_move_element_of_a_root_container_into_a_parent_sets_is_inner(): void {
		$result = ( new MoveElement() )->execute( [ 'post_id' => self::POST, 'element_id' => 'last', 'new_parent_id' => 'row' ] );

		self::assertIsArray( $result );
		$tree = self::stored();
		self::assertSame( [ 'row' ], array_column( $tree, 'id' ) );
		$moved = $tree[0]['elements'][3];
		self::assertSame( 'last', $moved['id'] );
		self::assertTrue( $moved['isInner'], 'A container inside another element is an inner container.' );
	}

	public function test_move_element_of_a_container_between_two_parents_keeps_is_inner(): void {
		$result = ( new MoveElement() )->execute( [ 'post_id' => self::POST, 'element_id' => 'a', 'new_parent_id' => 'b' ] );

		self::assertIsArray( $result );
		$moved = self::stored()[0]['elements'][0]['elements'][0];
		self::assertSame( 'a', $moved['id'] );
		self::assertTrue( $moved['isInner'] );
	}

	public function test_move_element_of_a_widget_gets_no_is_inner(): void {
		$result = ( new MoveElement() )->execute( [ 'post_id' => self::POST, 'element_id' => 'h', 'new_parent_id' => 'last' ] );

		self::assertIsArray( $result );
		self::assertArrayNotHasKey( 'isInner', self::stored()[1]['elements'][0] );
	}

	public function test_add_container_inside_a_container_is_marked_inner_and_reports_its_index(): void {
		$result = ( new AddContainer() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'row' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 0, 3 ], $result['path'], 'The path ends in the index the container got, not in the unset position.' );
		$added = self::stored()[0]['elements'][3];
		self::assertSame( $result['element_id'], $added['id'] );
		self::assertTrue( $added['isInner'] );
	}

	public function test_add_container_at_the_root_is_not_inner_and_reports_its_index(): void {
		$result = ( new AddContainer() )->execute( [ 'post_id' => self::POST ] );

		self::assertIsArray( $result );
		self::assertSame( [ 2 ], $result['path'] );
		self::assertFalse( self::stored()[2]['isInner'] );
	}

	public function test_add_container_at_a_position_reports_that_index(): void {
		$result = ( new AddContainer() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'row', 'position' => 1 ] );

		self::assertIsArray( $result );
		self::assertSame( [ 0, 1 ], $result['path'] );
		self::assertSame( $result['element_id'], self::stored()[0]['elements'][1]['id'] );
	}
}
