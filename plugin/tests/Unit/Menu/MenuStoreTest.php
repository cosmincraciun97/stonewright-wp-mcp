<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Menu;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Menu\MenuStore;

/**
 * @covers \Stonewright\WpMcp\Menu\MenuStore
 */
final class MenuStoreTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_nav_menus']             = [];
		$GLOBALS['stonewright_test_theme_mods']            = [];
		$GLOBALS['stonewright_test_registered_nav_menus']  = [];
		$GLOBALS['stonewright_test_next_nav_menu_id']      = 8001;
		$GLOBALS['stonewright_test_next_nav_menu_item_id'] = 8101;
	}

	public function test_create_returns_term_id_on_success(): void {
		$id = MenuStore::create( 'Primary' );
		$this->assertIsInt( $id );
		$this->assertGreaterThanOrEqual( 8001, $id );

		$this->assertArrayHasKey( $id, $GLOBALS['stonewright_test_nav_menus'] );
		$this->assertSame( 'Primary', $GLOBALS['stonewright_test_nav_menus'][ $id ]->name );
	}

	public function test_create_returns_wp_error_for_empty_name(): void {
		$result = MenuStore::create( '   ' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_create_returns_wp_error_for_duplicate_name(): void {
		MenuStore::create( 'Primary' );
		$result = MenuStore::create( 'Primary' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_add_item_returns_item_id_and_defaults_status_to_publish_and_type_to_custom(): void {
		$menu_id = MenuStore::create( 'Primary' );
		$this->assertIsInt( $menu_id );

		$item_id = MenuStore::add_item(
			$menu_id,
			[
				'menu-item-title' => 'Home',
				'menu-item-url'   => 'https://example.test/',
			]
		);
		$this->assertIsInt( $item_id );
		$this->assertGreaterThanOrEqual( 8101, $item_id );

		// The defaults must have been merged in even though the caller did
		// not pass them — this is exactly the contract MenuCreate/MenuAddItem
		// rely on to avoid silently-draft items.
		$stored = $GLOBALS['stonewright_test_nav_menus'][ $menu_id ]->items[ $item_id ]['data'];
		$this->assertSame( 'publish', $stored['menu-item-status'] );
		$this->assertSame( 'custom', $stored['menu-item-type'] );
	}

	public function test_add_item_returns_wp_error_for_invalid_menu_id(): void {
		$result = MenuStore::add_item(
			99999,
			[
				'menu-item-title' => 'Home',
				'menu-item-url'   => 'https://example.test/',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_add_item_surfaces_zero_return_as_wp_error(): void {
		// Real WP can return 0 on weird invalid-input paths that don't raise
		// a WP_Error — our facade must convert that to an explicit error so
		// callers don't get a misleading "ok" with no id.
		$menu_id = MenuStore::create( 'Primary' );
		$GLOBALS['stonewright_test_wp_update_nav_menu_item_return'] = 0;

		$result = MenuStore::add_item(
			$menu_id,
			[ 'menu-item-title' => 'Home', 'menu-item-url' => 'https://example.test/' ]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_menu_item_insert_failed', $result->get_error_code() );
	}

	public function test_list_menus_projects_term_objects_to_id_name_slug_dto(): void {
		MenuStore::create( 'Primary' );
		MenuStore::create( 'Footer' );

		$out = MenuStore::list_menus();
		$this->assertCount( 2, $out );
		$this->assertSame( [ 'id', 'name', 'slug' ], array_keys( $out[0] ) );
		$this->assertSame( 'Primary', $out[0]['name'] );
		$this->assertSame( 'primary', $out[0]['slug'] );
	}

	public function test_delete_removes_term_and_clears_associated_location(): void {
		$menu_id = MenuStore::create( 'Primary' );
		$this->assertIsInt( $menu_id );

		MenuStore::assign_location( 'primary', $menu_id );

		$result = MenuStore::delete( $menu_id );
		$this->assertTrue( $result );
		$this->assertArrayNotHasKey( $menu_id, $GLOBALS['stonewright_test_nav_menus'] );

		// Cascade: the location assignment is gone too.
		$locations = MenuStore::get_locations();
		$this->assertArrayNotHasKey( 'primary', $locations );
	}

	public function test_delete_returns_wp_error_when_menu_missing(): void {
		$result = MenuStore::delete( 12345 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_menu_not_found', $result->get_error_code() );
	}

	public function test_assign_location_merges_into_existing_theme_mod_without_clobbering(): void {
		$primary_id = MenuStore::create( 'Primary' );
		$footer_id  = MenuStore::create( 'Footer' );
		$this->assertIsInt( $primary_id );
		$this->assertIsInt( $footer_id );

		// Pre-existing assignment that must survive the new write.
		$GLOBALS['stonewright_test_theme_mods']['nav_menu_locations'] = [
			'footer' => $footer_id,
		];

		$ok = MenuStore::assign_location( 'primary', $primary_id );
		$this->assertTrue( $ok );

		$locations = MenuStore::get_locations();
		$this->assertSame( $primary_id, $locations['primary'] );
		$this->assertSame( $footer_id, $locations['footer'], 'Other location must not be clobbered.' );
	}

	public function test_get_registered_locations_returns_theme_declared_slugs(): void {
		$GLOBALS['stonewright_test_registered_nav_menus'] = [
			'primary' => 'Primary Menu',
			'footer'  => 'Footer Menu',
		];

		$registered = MenuStore::get_registered_locations();
		$this->assertSame( [ 'primary' => 'Primary Menu', 'footer' => 'Footer Menu' ], $registered );
	}

	public function test_get_locations_returns_empty_array_when_theme_mod_unset(): void {
		$this->assertSame( [], MenuStore::get_locations() );
	}
}
