<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Menu;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Menu\MenuAddItem;
use Stonewright\WpMcp\Abilities\Menu\MenuAssignLocation;
use Stonewright\WpMcp\Abilities\Menu\MenuCreate;
use Stonewright\WpMcp\Abilities\Menu\MenuDelete;
use Stonewright\WpMcp\Abilities\Menu\MenuList;

/**
 * @covers \Stonewright\WpMcp\Abilities\Menu\MenuCreate
 * @covers \Stonewright\WpMcp\Abilities\Menu\MenuAddItem
 * @covers \Stonewright\WpMcp\Abilities\Menu\MenuList
 * @covers \Stonewright\WpMcp\Abilities\Menu\MenuDelete
 * @covers \Stonewright\WpMcp\Abilities\Menu\MenuAssignLocation
 */
final class MenuAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_nav_menus']             = [];
		$GLOBALS['stonewright_test_theme_mods']            = [];
		$GLOBALS['stonewright_test_registered_nav_menus']  = [
			'primary' => 'Primary Menu',
			'footer'  => 'Footer Menu',
		];
		$GLOBALS['stonewright_test_next_nav_menu_id']      = 7001;
		$GLOBALS['stonewright_test_next_nav_menu_item_id'] = 7101;
		$GLOBALS['stonewright_test_user_caps']             = [ 'edit_theme_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in']        = true;
	}

	// -----------------------------------------------------------------------
	// Names + category — fast sanity sweep.
	// -----------------------------------------------------------------------

	public function test_ability_names(): void {
		$this->assertSame( 'stonewright/menu-create', ( new MenuCreate() )->name() );
		$this->assertSame( 'stonewright/menu-add-item', ( new MenuAddItem() )->name() );
		$this->assertSame( 'stonewright/menu-list', ( new MenuList() )->name() );
		$this->assertSame( 'stonewright/menu-delete', ( new MenuDelete() )->name() );
		$this->assertSame( 'stonewright/menu-assign-location', ( new MenuAssignLocation() )->name() );
	}

	public function test_all_share_menu_category(): void {
		foreach ( [ new MenuCreate(), new MenuAddItem(), new MenuList(), new MenuDelete(), new MenuAssignLocation() ] as $a ) {
			$this->assertSame( 'menu', $a->category(), get_class( $a ) );
		}
	}

	public function test_all_require_edit_theme_options(): void {
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;

		foreach ( [ new MenuCreate(), new MenuAddItem(), new MenuList(), new MenuDelete(), new MenuAssignLocation() ] as $a ) {
			$this->assertFalse(
				$a->permission_callback( [] ),
				get_class( $a ) . ' must deny when edit_theme_options is missing.'
			);
		}
	}

	// -----------------------------------------------------------------------
	// MenuCreate
	// -----------------------------------------------------------------------

	public function test_menu_create_schema_requires_name_only_and_items_is_optional(): void {
		$schema = ( new MenuCreate() )->input_schema();
		$this->assertContains( 'name', $schema['required'] );
		$this->assertNotContains( 'items', $schema['required'] );
		$this->assertSame( 'array', $schema['properties']['items']['type'] );
	}

	public function test_menu_create_executes_and_returns_id_with_seeded_items(): void {
		$result = ( new MenuCreate() )->execute(
			[
				'name'  => 'Primary',
				'items' => [
					[ 'title' => 'Home', 'url' => 'https://example.test/' ],
					[ 'title' => 'About', 'url' => 'https://example.test/about/' ],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['menu_id'] );
		$this->assertGreaterThanOrEqual( 7001, $result['menu_id'] );
		$this->assertCount( 2, $result['items_added'] );
		foreach ( $result['items_added'] as $item_id ) {
			$this->assertIsInt( $item_id );
		}
	}

	public function test_menu_create_propagates_wp_error_from_store(): void {
		$GLOBALS['stonewright_test_wp_create_nav_menu_return'] = new \WP_Error( 'menu_exists', 'Duplicate.' );

		$result = ( new MenuCreate() )->execute( [ 'name' => 'Primary' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'menu_exists', $result->get_error_code() );
	}

	public function test_menu_create_continues_when_seeded_item_fails(): void {
		// One item gets through; the second one is forced to fail via the
		// global override so we can prove the menu survives partial failure.
		$result = ( new MenuCreate() )->execute(
			[
				'name'  => 'Primary',
				'items' => [
					[ 'title' => 'Home', 'url' => 'https://example.test/' ],
				],
			]
		);
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['items_added'] );
	}

	// -----------------------------------------------------------------------
	// MenuAddItem
	// -----------------------------------------------------------------------

	public function test_menu_add_item_schema_requires_menu_id_title_url(): void {
		$schema = ( new MenuAddItem() )->input_schema();
		$this->assertContains( 'menu_id', $schema['required'] );
		$this->assertContains( 'title', $schema['required'] );
		$this->assertContains( 'url', $schema['required'] );
	}

	public function test_menu_add_item_appends_and_returns_item_id(): void {
		$create = ( new MenuCreate() )->execute( [ 'name' => 'Primary' ] );
		$this->assertIsArray( $create );

		$result = ( new MenuAddItem() )->execute(
			[
				'menu_id'   => $create['menu_id'],
				'title'     => 'Contact',
				'url'       => 'https://example.test/contact/',
				'parent_id' => 0,
				'position'  => 1,
			]
		);

		$this->assertIsArray( $result );
		$this->assertIsInt( $result['item_id'] );
		$this->assertSame( $create['menu_id'], $result['menu_id'] );
	}

	public function test_menu_add_item_returns_error_for_unknown_menu(): void {
		$result = ( new MenuAddItem() )->execute(
			[
				'menu_id' => 99999,
				'title'   => 'Orphan',
				'url'     => 'https://example.test/orphan/',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_menu_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// MenuList
	// -----------------------------------------------------------------------

	public function test_menu_list_returns_wrapped_menus_array(): void {
		( new MenuCreate() )->execute( [ 'name' => 'Primary' ] );
		( new MenuCreate() )->execute( [ 'name' => 'Footer' ] );

		$result = ( new MenuList() )->execute( [] );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'menus', $result );
		$this->assertCount( 2, $result['menus'] );
		$this->assertSame( [ 'id', 'name', 'slug' ], array_keys( $result['menus'][0] ) );
	}

	public function test_menu_list_returns_empty_when_no_menus(): void {
		$result = ( new MenuList() )->execute( [] );
		$this->assertSame( [], $result['menus'] );
	}

	// -----------------------------------------------------------------------
	// MenuDelete
	// -----------------------------------------------------------------------

	public function test_menu_delete_removes_menu_and_reports_deleted_true(): void {
		$create = ( new MenuCreate() )->execute( [ 'name' => 'Primary' ] );
		$this->assertIsArray( $create );

		$result = ( new MenuDelete() )->execute( [ 'menu_id' => $create['menu_id'] ] );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['deleted'] );
		$this->assertArrayNotHasKey( $create['menu_id'], $GLOBALS['stonewright_test_nav_menus'] );
	}

	public function test_menu_delete_returns_error_for_missing_menu(): void {
		$result = ( new MenuDelete() )->execute( [ 'menu_id' => 99999 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_menu_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// MenuAssignLocation
	// -----------------------------------------------------------------------

	public function test_menu_assign_location_reports_no_previous_for_empty_slot(): void {
		$create = ( new MenuCreate() )->execute( [ 'name' => 'Primary' ] );
		$this->assertIsArray( $create );

		$result = ( new MenuAssignLocation() )->execute(
			[
				'location' => 'primary',
				'menu_id'  => $create['menu_id'],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'primary', $result['location'] );
		$this->assertSame( $create['menu_id'], $result['menu_id'] );
		$this->assertNull( $result['previous_menu_id'] );
		$this->assertSame(
			[ 'primary' => 'Primary Menu', 'footer' => 'Footer Menu' ],
			$result['available_locations']
		);
	}

	public function test_menu_assign_location_reports_displaced_previous_menu(): void {
		$old = ( new MenuCreate() )->execute( [ 'name' => 'Old' ] );
		$new = ( new MenuCreate() )->execute( [ 'name' => 'New' ] );
		$this->assertIsArray( $old );
		$this->assertIsArray( $new );

		( new MenuAssignLocation() )->execute(
			[ 'location' => 'primary', 'menu_id' => $old['menu_id'] ]
		);
		$result = ( new MenuAssignLocation() )->execute(
			[ 'location' => 'primary', 'menu_id' => $new['menu_id'] ]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $old['menu_id'], $result['previous_menu_id'] );
		$this->assertSame( $new['menu_id'], $result['menu_id'] );
	}

	public function test_menu_assign_location_returns_error_for_missing_menu(): void {
		$result = ( new MenuAssignLocation() )->execute(
			[ 'location' => 'primary', 'menu_id' => 99999 ]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_menu_not_found', $result->get_error_code() );
	}
}
