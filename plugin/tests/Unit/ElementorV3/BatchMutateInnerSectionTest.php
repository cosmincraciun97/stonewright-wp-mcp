<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorV3;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddInnerSection;

/**
 * elementor-v3-batch-mutate adds an inner section, so the deprecated elementor-add-inner-section has a real
 * replacement before the 2.0.0 sunset.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddWidget
 * @covers \Stonewright\WpMcp\Elementor\Write\V3MutationCompiler
 */
final class BatchMutateInnerSectionTest extends TestCase {

	private const POST = 601;

	protected function setUp(): void {
		$tree = [
			[ 'id' => 'root', 'elType' => 'container', 'settings' => [], 'elements' => [] ],
			[ 'id' => 'sec1', 'elType' => 'section', 'settings' => [], 'elements' => [ [ 'id' => 'col1', 'elType' => 'column', 'settings' => [ '_column_size' => 100 ], 'elements' => [] ] ] ],
		];
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Inner section target',
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
		$decoded = json_decode( stripslashes( (string) $GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] ), true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/** @param array<string, mixed> $operation */
	private function batch( array $operation, bool $dry_run = false ): array|\WP_Error {
		return ( new BatchMutate() )->execute( [ 'post_id' => self::POST, 'dry_run' => $dry_run, 'operations' => [ $operation ] ] );
	}

	public function test_the_batch_action_list_offers_add_inner_section(): void {
		$enum = ( new BatchMutate() )->input_schema()['properties']['operations']['items']['properties']['action']['enum'];

		self::assertContains( 'add_inner_section', $enum );
	}

	public function test_an_inner_section_in_a_column_is_a_real_inner_section_with_a_column(): void {
		$result = $this->batch( [ 'action' => 'add_inner_section', 'parent_id' => 'col1', 'op_id' => 'inner' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$inner = $this->stored_tree()[1]['elements'][0]['elements'][0];
		self::assertSame( $result['items'][0]['element_id'], $inner['id'] );
		self::assertSame( 'section', $inner['elType'] );
		self::assertTrue( $inner['isInner'] );
		self::assertCount( 1, $inner['elements'] );
		self::assertSame( 'column', $inner['elements'][0]['elType'] );
		self::assertSame( 100, $inner['elements'][0]['settings']['_column_size'] );
		self::assertSame( $inner['id'], $result['refs']['inner'] );
		self::assertSame( $inner['elements'][0]['id'], $result['refs']['inner.column'], 'a later operation can add into the new column' );
	}

	public function test_an_inner_section_in_a_container_is_an_inner_container(): void {
		$result = $this->batch( [ 'action' => 'add_inner_section', 'parent_id' => 'root' ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$inner = $this->stored_tree()[0]['elements'][0];
		self::assertSame( 'container', $inner['elType'] );
		self::assertTrue( $inner['isInner'] );
		self::assertSame( [], $inner['elements'] );
	}

	public function test_an_inner_section_directly_inside_a_section_is_refused_and_nothing_is_written(): void {
		$result = $this->batch( [ 'action' => 'add_inner_section', 'parent_id' => 'sec1' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_data()['items'][0]['error']['code'] );
	}

	public function test_an_inner_section_needs_a_parent(): void {
		$result = $this->batch( [ 'action' => 'add_inner_section' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_inner_section_needs_parent', $result->get_error_data()['items'][0]['error']['code'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_a_dry_run_plans_the_inner_section_without_writing(): void {
		$result = $this->batch( [ 'action' => 'add_inner_section', 'parent_id' => 'col1' ], true );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( 'add_inner_section', $result['items'][0]['action'] );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_a_widget_can_be_added_into_the_new_inner_column_in_the_same_batch(): void {
		$result = ( new BatchMutate() )->execute(
			[
				'post_id'    => self::POST,
				'operations' => [
					[ 'action' => 'add_inner_section', 'parent_id' => 'col1', 'op_id' => 'inner' ],
					[ 'action' => 'add_widget', 'parent_ref' => 'inner.column', 'widget_type' => 'heading', 'settings' => [ 'title' => 'Inside' ] ],
				],
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$column = $this->stored_tree()[1]['elements'][0]['elements'][0]['elements'][0];
		self::assertSame( 'Inside', $column['elements'][0]['settings']['title'] );
	}

	public function test_the_deprecated_ability_and_the_add_widget_refusal_point_at_the_batch_action(): void {
		self::assertSame( 'stonewright/elementor-v3-batch-mutate', ( new AddInnerSection() )->meta()['replacement'] );

		$refusal = ( new AddWidget() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'root', 'widget_type' => 'inner-section', 'settings' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $refusal );
		self::assertSame( 'stonewright_inner_section_is_layout', $refusal->get_error_code() );
		self::assertStringContainsString( 'add_inner_section', $refusal->get_error_message() );
		self::assertStringNotContainsString( 'elementor-add-inner-section', $refusal->get_error_message() );
	}
}
