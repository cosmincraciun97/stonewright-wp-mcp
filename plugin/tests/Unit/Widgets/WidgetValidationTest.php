<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Widgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Widgets\WidgetDelete;
use Stonewright\WpMcp\Abilities\Widgets\WidgetSave;

/**
 * widget-save and widget-delete check the ids they are given.
 *
 * @covers \Stonewright\WpMcp\Abilities\Widgets\WidgetSave
 * @covers \Stonewright\WpMcp\Abilities\Widgets\WidgetDelete
 */
final class WidgetValidationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_sidebars']        = [ 'sidebar-1' => [ 'text-1', 'search-2' ], 'footer-1' => [ 'text-3' ], 'wp_inactive_widgets' => [] ];
		update_option( 'widget_text', [ 1 => [ 'title' => 'Hello' ], 3 => [ 'title' => 'Other' ], 5 => [ 'title' => 'Unplaced' ], '_multiwidget' => 1 ] );
		update_option( 'widget_search', [ 2 => [ 'title' => 'Find' ], '_multiwidget' => 1 ] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_sidebars'] );
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	/** @return list<string> */
	private function sidebar( string $id ): array {
		return (array) ( wp_get_sidebars_widgets()[ $id ] ?? [] );
	}

	public function test_save_refuses_a_sidebar_that_does_not_exist_and_lists_the_ones_that_do(): void {
		$result = ( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-9', 'widgets' => [ 'text-1' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_sidebar_not_found', $result->get_error_code() );
		self::assertSame( [ 'sidebar-1', 'footer-1', 'wp_inactive_widgets' ], $result->get_error_data()['available_sidebars'] );
		self::assertArrayNotHasKey( 'sidebar-9', wp_get_sidebars_widgets() );
	}

	public function test_save_refuses_widget_ids_that_do_not_exist_and_changes_nothing(): void {
		$result = ( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'text-1', 'ghost-7', 'not a widget id!' ] ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_widget_not_found', $result->get_error_code() );
		self::assertSame( [ 'ghost-7', 'not a widget id!' ], $result->get_error_data()['unknown_widgets'] );
		self::assertSame( [ 'text-1', 'search-2' ], $this->sidebar( 'sidebar-1' ) );
	}

	public function test_save_accepts_widgets_that_are_placed_elsewhere_or_exist_as_instances(): void {
		// text-3 sits in another sidebar, text-5 is a saved instance that no sidebar holds yet.
		$result = ( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'search-2', 'text-3', 'text-5' ] ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() : '' );
		self::assertSame( [ 'search-2', 'text-3', 'text-5' ], $this->sidebar( 'sidebar-1' ) );
	}

	public function test_save_with_an_empty_list_still_clears_a_sidebar(): void {
		$result = ( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [] ] );

		self::assertIsArray( $result );
		self::assertSame( [], $this->sidebar( 'sidebar-1' ) );
	}

	public function test_delete_refuses_a_sidebar_that_does_not_exist(): void {
		$result = ( new WidgetDelete() )->execute( [ 'sidebar_id' => 'sidebar-9', 'widget_id' => 'text-1' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_sidebar_not_found', $result->get_error_code() );
		self::assertArrayNotHasKey( 'sidebar-9', wp_get_sidebars_widgets() );
	}

	public function test_delete_refuses_a_widget_that_is_not_in_the_sidebar(): void {
		$result = ( new WidgetDelete() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widget_id' => 'text-3' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_widget_not_found', $result->get_error_code() );
		self::assertSame( [ 'text-1', 'search-2' ], $this->sidebar( 'sidebar-1' ) );
		self::assertSame( [ 'text-3' ], $this->sidebar( 'footer-1' ) );
	}

	public function test_delete_removes_a_widget_that_is_in_the_sidebar(): void {
		$result = ( new WidgetDelete() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widget_id' => 'text-1' ] );

		self::assertSame( [ 'ok' => true, 'sidebar_id' => 'sidebar-1', 'widget_id' => 'text-1' ], $result );
		self::assertSame( [ 'search-2' ], $this->sidebar( 'sidebar-1' ) );
	}
}
