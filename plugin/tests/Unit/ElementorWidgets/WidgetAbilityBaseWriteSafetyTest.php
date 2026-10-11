<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddHeading;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddInnerSection;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddToggle;
use Stonewright\WpMcp\Core\AbilityAnnotations;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;

/**
 * The per-widget abilities read the page under its write lock, look up the parent before the snapshot, and
 * state that adding a widget is not destructive.
 *
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidgets\WidgetAbilityBase
 */
final class WidgetAbilityBaseWriteSafetyTest extends TestCase {

	private const POST = 321;

	/** @var list<array<string, mixed>> */
	private array $tree;

	protected function setUp(): void {
		$this->tree = [
			[
				'id'       => 'root',
				'elType'   => 'container',
				'settings' => [],
				'elements' => [
					[ 'id' => 'w1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Existing' ], 'elements' => [] ],
				],
			],
		];
		$GLOBALS['stonewright_test_posts'] = [
			self::POST => (object) [
				'ID'           => self::POST,
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '',
				'post_excerpt' => '',
				'meta'         => [
					'_elementor_data'      => (string) wp_json_encode( $this->tree ),
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
		unset( $GLOBALS['stonewright_test_after_add_option'] );
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

	public function test_a_busy_page_takes_no_snapshot_and_writes_nothing(): void {
		self::assertIsArray( PostWriteLock::acquire( self::POST, 'other-writer', 30 ) );

		$result = ( new AddHeading() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'root', 'settings' => [ 'title' => 'Busy' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_write_busy', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'no snapshot is stored for a call that cannot write' );
	}

	public function test_the_page_is_read_after_the_lock_is_taken_so_a_write_in_between_is_kept(): void {
		$GLOBALS['stonewright_test_after_add_option'] = function (): void {
			$tree = $this->tree;
			$tree[0]['elements'][] = [ 'id' => 'external', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Written meanwhile' ], 'elements' => [] ];
			$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $tree );
		};

		$result = ( new AddHeading() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'root', 'settings' => [ 'title' => 'Mine' ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		$ids = array_column( $this->stored_tree()[0]['elements'], 'id' );
		self::assertContains( 'external', $ids, 'the write that landed after the plan is not overwritten' );
		self::assertContains( $result['element_id'], $ids );
	}

	public function test_the_lock_is_released_after_the_write(): void {
		$result = ( new AddHeading() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'root', 'settings' => [ 'title' => 'Done' ] ] );
		self::assertIsArray( $result );

		self::assertIsArray( PostWriteLock::acquire( self::POST, 'next-writer', 30 ) );
	}

	public function test_a_missing_parent_takes_no_snapshot(): void {
		$result = ( new AddHeading() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'nope', 'settings' => [ 'title' => 'Orphan' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_found', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'], 'a failed parent lookup stores no snapshot' );
		self::assertIsArray( PostWriteLock::acquire( self::POST, 'next-writer', 30 ), 'and leaves no lock behind' );
	}

	public function test_a_parent_that_cannot_hold_children_takes_no_snapshot(): void {
		$result = ( new AddHeading() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'w1', 'settings' => [ 'title' => 'Inside a widget' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_an_inner_section_in_a_wrong_parent_takes_no_snapshot(): void {
		$tree = $this->tree;
		$tree[] = [ 'id' => 'sec1', 'elType' => 'section', 'settings' => [], 'elements' => [ [ 'id' => 'col1', 'elType' => 'column', 'settings' => [], 'elements' => [] ] ] ];
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = wp_json_encode( $tree );

		$result = ( new AddInnerSection() )->execute( [ 'post_id' => self::POST, 'parent_id' => 'sec1' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_container', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['stonewright_test_post_meta_calls'] );
	}

	public function test_adding_a_widget_is_stated_not_destructive_whatever_the_verb_of_its_name(): void {
		$meta = ( new AddToggle() )->meta();

		self::assertFalse( $meta['annotations']['destructive'] );
		self::assertFalse( AbilityAnnotations::for_ability( new AddToggle() )['destructive'] );
		self::assertFalse( AbilityAnnotations::for_ability( new AddHeading() )['destructive'] );
	}
}
