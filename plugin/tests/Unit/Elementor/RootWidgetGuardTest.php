<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * A widget is never a root element of an Elementor document: it sits inside a container, a section or a column.
 * A write that would put one at the root is refused; a document that already has one can still be edited.
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator::validate_tree
 * @covers \Stonewright\WpMcp\Support\ElementorData
 */
final class RootWidgetGuardTest extends TestCase {

	private const POST = 8810;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		$GLOBALS['stonewright_test_posts'][ self::POST ] = (object) [
			'ID'           => self::POST,
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Fixture',
			'post_content' => '',
			'post_excerpt' => '',
			'post_parent'  => 0,
			'post_name'    => 'fixture',
			'meta'         => [
				'_elementor_data'      => '[]',
				'_elementor_edit_mode' => 'builder',
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_post_meta_calls'] = [];
		unset( $GLOBALS['stonewright_test_posts'][ self::POST ] );
	}

	/** @return array<string, mixed> */
	private static function widget( string $id = 'w1' ): array {
		return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => 'html', 'settings' => [ 'html' => '<p>x</p>' ], 'elements' => [] ];
	}

	/** @return array<string, mixed> */
	private static function container( string $id, array $children = [] ): array {
		return [ 'id' => $id, 'elType' => 'container', 'settings' => [], 'elements' => $children ];
	}

	public function test_validate_tree_refuses_a_widget_at_the_root(): void {
		self::assertFalse( SettingsValidator::validate_tree( [ self::widget() ] ) );
		self::assertSame( 'widget_at_root', SettingsValidator::last_error()?->get_error_data()['violations'][0]['code'] );
		self::assertSame( 'root.0', SettingsValidator::last_error()?->get_error_data()['violations'][0]['path'] );
	}

	public function test_validate_tree_refuses_a_root_widget_even_when_the_content_scope_is_empty(): void {
		self::assertFalse( SettingsValidator::validate_tree( [ self::widget() ], [] ) );
		self::assertSame( 'widget_at_root', SettingsValidator::last_error()?->get_error_data()['violations'][0]['code'] );
	}

	public function test_validate_tree_accepts_a_widget_inside_a_container(): void {
		self::assertTrue( SettingsValidator::validate_tree( [ self::container( 'c1', [ self::widget() ] ) ] ) );
	}

	public function test_validate_tree_tolerates_a_root_widget_the_document_already_had(): void {
		self::assertTrue( SettingsValidator::validate_tree( [ self::widget( 'legacy' ) ], [], [ 'legacy' ] ) );
		self::assertFalse( SettingsValidator::validate_tree( [ self::widget( 'legacy' ), self::widget( 'fresh' ) ], [], [ 'legacy' ] ) );
	}

	public function test_write_refuses_a_new_root_widget_and_stores_nothing(): void {
		$stored = ElementorData::read( self::POST );

		self::assertFalse( ElementorData::write( self::POST, [ self::container( 'c1' ), self::widget() ] ) );

		self::assertSame( 'widget_at_root', ElementorData::last_write_error()?->get_error_data()['violations'][0]['code'] );
		self::assertSame( $stored, ElementorData::read( self::POST ) );
	}

	public function test_dry_run_preflight_reports_the_same_refusal(): void {
		$error = ElementorData::preflight( [], [ self::widget() ] );

		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'widget_at_root', $error->get_error_data()['violations'][0]['code'] );
	}

	public function test_a_document_that_already_has_a_root_widget_can_still_be_edited(): void {
		$GLOBALS['stonewright_test_posts'][ self::POST ]->meta['_elementor_data'] = (string) wp_json_encode( [ self::widget( 'legacy' ) ] );

		$next = [ self::widget( 'legacy' ), self::container( 'added' ) ];

		self::assertNull( ElementorData::preflight( ElementorData::read( self::POST ), $next ) );
		self::assertTrue( ElementorData::write( self::POST, $next ) );
		self::assertSame( 'legacy', ElementorData::read( self::POST )[0]['id'] );
	}

	/**
	 * @return iterable<string, array{0:int,1:int,2:list<int>,3:int}>
	 */
	public static function insertion_cases(): iterable {
		yield 'no position goes to the end of the root' => [ PHP_INT_MAX, 2, [], 2 ];
		yield 'a position inside the list stays' => [ 1, 2, [], 1 ];
		yield 'a negative position goes first' => [ -3, 2, [], 0 ];
		yield 'no position goes to the end of a parent' => [ PHP_INT_MAX, 3, [ 1 ], 3 ];
	}

	/**
	 * @param list<int> $parent_path
	 * @dataProvider insertion_cases
	 */
	public function test_insertion_index_is_the_index_the_element_gets( int $position, int $children, array $parent_path, int $expected ): void {
		$siblings = [];
		for ( $i = 0; $i < $children; $i++ ) {
			$siblings[] = self::container( 'k' . $i );
		}
		$tree = [] === $parent_path
			? $siblings
			: [ self::container( 'first' ), self::container( 'parent', $siblings ) ];

		self::assertSame( $expected, ElementorData::insertion_index( $tree, $parent_path, $position ) );
		$inserted = ElementorData::insert( $tree, $parent_path, $position, self::container( 'new' ) );
		$level    = [] === $parent_path ? $inserted : $inserted[1]['elements'];
		self::assertSame( 'new', $level[ $expected ]['id'] );
	}
}
