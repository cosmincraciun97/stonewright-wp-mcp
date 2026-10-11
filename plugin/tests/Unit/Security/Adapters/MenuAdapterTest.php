<?php
/**
 * The menu family in the change ledger: the image of a menu with its items, and the restore that rebuilds it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Menu\MenuAddItem;
use Stonewright\WpMcp\Abilities\Menu\MenuAssignLocation;
use Stonewright\WpMcp\Abilities\Menu\MenuCreate;
use Stonewright\WpMcp\Abilities\Menu\MenuDelete;
use Stonewright\WpMcp\Security\Adapters\MenuAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\MenuAdapter
 * @covers \Stonewright\WpMcp\Menu\MenuStore
 */
final class MenuAdapterTest extends FamilyLedgerTestCase {

	/**
	 * A menu of five items: Home, About with two children (Team, History), Contact and a link to a page.
	 *
	 * @return array{menu:int,items:array<string,int>}
	 */
	private function standard_menu(): array {
		$built = $this->make_menu(
			'Main',
			[
				[ 'home', 'Home', 'https://example.test/', '' ],
				[ 'about', 'About', 'https://example.test/about/', '' ],
				[ 'team', 'Team', 'https://example.test/about/team/', 'about' ],
				[ 'history', 'History', 'https://example.test/about/history/', 'about' ],
				[ 'contact', 'Contact', 'https://example.test/contact/', '' ],
			]
		);
		wp_update_nav_menu_item(
			$built['menu'],
			$built['items']['contact'],
			[
				'menu-item-title'     => 'Contact',
				'menu-item-url'       => 'https://example.test/contact/',
				'menu-item-status'    => 'publish',
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => 42,
				'menu-item-target'    => '_blank',
				'menu-item-attr-title' => 'Write to us',
				'menu-item-description' => 'Contact page',
				'menu-item-xfn'       => 'nofollow',
				'menu-item-classes'   => 'cta highlighted',
				'menu-item-parent-id' => 0,
				'menu-item-position'  => 5,
			]
		);
		return $built;
	}

	/**
	 * @return list<string>
	 */
	private function titles( int $menu_id ): array {
		return array_map( static fn ( array $row ): string => $row[0], $this->menu_shape( $menu_id ) );
	}

	// -----------------------------------------------------------------------
	// Image.
	// -----------------------------------------------------------------------

	public function test_the_image_holds_the_menu_its_items_in_order_with_parents_links_classes_and_locations(): void {
		$menu = $this->standard_menu();
		set_theme_mod( 'nav_menu_locations', [ 'primary' => $menu['menu'], 'footer' => 9999 ] );

		$image = MenuAdapter::image( $menu['menu'] );

		self::assertIsArray( $image );
		self::assertSame( $menu['menu'], $image['menu']['term_id'] );
		self::assertSame( 'Main', $image['menu']['name'] );
		self::assertSame( [ 'Home', 'About', 'Team', 'History', 'Contact' ], array_column( $image['items'], 'title' ) );
		self::assertSame( [ 0, 0, $menu['items']['about'], $menu['items']['about'], 0 ], array_column( $image['items'], 'parent' ) );
		self::assertSame( $menu['items']['team'], $image['items'][2]['id'] );
		$contact = $image['items'][4];
		self::assertSame( 'https://example.test/contact/', $contact['url'] );
		self::assertSame( 'post_type', $contact['type'] );
		self::assertSame( 'page', $contact['object'] );
		self::assertSame( 42, $contact['object_id'] );
		self::assertSame( [ 'cta', 'highlighted' ], $contact['classes'] );
		self::assertSame( '_blank', $contact['target'] );
		self::assertSame( 'Write to us', $contact['attr_title'] );
		self::assertSame( 'nofollow', $contact['xfn'] );
		self::assertSame( [ 'primary' ], $image['locations'], 'Only the locations that point at this menu.' );
	}

	public function test_a_menu_that_does_not_exist_has_no_image(): void {
		self::assertNull( MenuAdapter::image( 424242 ) );
	}

	// -----------------------------------------------------------------------
	// Restore.
	// -----------------------------------------------------------------------

	public function test_restore_rebuilds_item_order_and_parents_after_items_were_moved_removed_and_added(): void {
		$menu   = $this->standard_menu();
		$id     = $menu['menu'];
		$items  = $menu['items'];
		$before = MenuAdapter::image( $id );
		$shape  = $this->menu_shape( $id );
		self::assertIsArray( $before );

		// Move Contact to the front, make Team a top-level item and History a child of Contact, drop About,
		// and add a new item.
		wp_update_nav_menu_item( $id, $items['contact'], [ 'menu-item-title' => 'Contact', 'menu-item-url' => 'https://example.test/contact/', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-parent-id' => 0, 'menu-item-position' => 1 ] );
		wp_update_nav_menu_item( $id, $items['team'], [ 'menu-item-title' => 'Team', 'menu-item-url' => 'https://example.test/about/team/', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-parent-id' => 0, 'menu-item-position' => 2 ] );
		wp_update_nav_menu_item( $id, $items['history'], [ 'menu-item-title' => 'History', 'menu-item-url' => 'https://example.test/about/history/', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-parent-id' => $items['contact'], 'menu-item-position' => 3 ] );
		wp_delete_post( $items['about'], true );
		wp_update_nav_menu_item( $id, 0, [ 'menu-item-title' => 'Extra', 'menu-item-url' => 'https://example.test/extra/', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-position' => 9 ] );
		self::assertNotSame( $shape, $this->menu_shape( $id ), 'The menu was changed.' );

		$result = MenuAdapter::restore( $before );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'], implode( ',', (array) ( $result['differences'] ?? [] ) ) );
		self::assertSame( [], $result['differences'] );
		self::assertSame( $id, $result['menu_id'] );
		self::assertFalse( $result['recreated'] );
		self::assertSame( $shape, $this->menu_shape( $id ), 'Order, titles, urls and parents are as they were.' );
		$after = MenuAdapter::image( $id );
		self::assertSame( [ 'cta', 'highlighted' ], array_column( $after['items'], 'classes' )[4], 'Fields other than the title survive the rebuild.' );
		self::assertSame( 42, $after['items'][4]['object_id'] );
	}

	public function test_restore_handles_a_child_that_comes_before_its_parent_in_menu_order(): void {
		$menu = $this->make_menu(
			'Odd',
			[
				[ 'a', 'Parent', 'https://example.test/p/', '' ],
				[ 'b', 'Child', 'https://example.test/c/', 'a' ],
			]
		);
		$id = $menu['menu'];
		// The child is first in the order although it hangs under the parent.
		wp_update_nav_menu_item( $id, $menu['items']['b'], [ 'menu-item-title' => 'Child', 'menu-item-url' => 'https://example.test/c/', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-parent-id' => $menu['items']['a'], 'menu-item-position' => 1 ] );
		wp_update_nav_menu_item( $id, $menu['items']['a'], [ 'menu-item-title' => 'Parent', 'menu-item-url' => 'https://example.test/p/', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom', 'menu-item-parent-id' => 0, 'menu-item-position' => 2 ] );
		$before = MenuAdapter::image( $id );
		$shape  = $this->menu_shape( $id );
		self::assertSame( [ 'Child', 'Parent' ], $this->titles( $id ) );
		// Remove both and rebuild from the image: the new parent does not exist yet when the child is made.
		wp_delete_post( $menu['items']['a'], true );
		wp_delete_post( $menu['items']['b'], true );

		$result = MenuAdapter::restore( $before );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( $shape, $this->menu_shape( $id ) );
	}

	public function test_restore_recreates_a_deleted_menu_with_its_items_and_locations(): void {
		$menu = $this->standard_menu();
		set_theme_mod( 'nav_menu_locations', [ 'primary' => $menu['menu'] ] );
		$before = MenuAdapter::image( $menu['menu'] );
		$shape  = $this->menu_shape( $menu['menu'] );
		wp_delete_nav_menu( $menu['menu'] );
		self::assertNull( MenuAdapter::image( $menu['menu'] ) );

		$result = MenuAdapter::restore( (array) $before );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['recreated'] );
		self::assertSame( $menu['menu'], $result['previous_menu_id'] );
		self::assertNotSame( $menu['menu'], $result['menu_id'], 'The term id of a deleted menu is not reused.' );
		self::assertSame( $shape, $this->menu_shape( $result['menu_id'] ) );
		self::assertSame( $result['menu_id'], get_nav_menu_locations()['primary'] );
		self::assertSame( 'Main', wp_get_nav_menu_object( $result['menu_id'] )->name );
	}

	public function test_restore_writes_nothing_when_the_name_of_a_deleted_menu_is_taken(): void {
		$menu   = $this->make_menu( 'Main', [ [ 'a', 'One', 'https://example.test/1/', '' ] ] );
		$before = MenuAdapter::image( $menu['menu'] );
		wp_delete_nav_menu( $menu['menu'] );
		wp_create_nav_menu( 'Main' );
		$menus_before = count( wp_get_nav_menus() );

		$result = MenuAdapter::restore( (array) $before );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $menus_before, count( wp_get_nav_menus() ) );
	}

	public function test_restore_refuses_an_image_that_is_not_a_menu_image(): void {
		$result = MenuAdapter::restore( [ 'v' => 1, 'kind' => 'options' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_image_invalid', $result->get_error_code() );
	}

	public function test_restore_refuses_an_image_with_a_masked_value(): void {
		$menu   = $this->make_menu( 'Main', [ [ 'a', 'One', 'https://example.test/1/', '' ] ] );
		$before = (array) MenuAdapter::image( $menu['menu'] );
		$before['items'][0]['url'] = '[masked line 1]';

		$result = MenuAdapter::restore( $before );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_image_masked', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Through the abilities.
	// -----------------------------------------------------------------------

	public function test_deleting_a_menu_records_the_full_image_and_the_delete_can_be_undone(): void {
		$menu = $this->standard_menu();
		set_theme_mod( 'nav_menu_locations', [ 'primary' => $menu['menu'] ] );
		$shape = $this->menu_shape( $menu['menu'] );

		$result = ( new MenuDelete() )->execute( [ 'menu_id' => $menu['menu'] ] );

		self::assertSame( [ 'deleted' => true ], $result );
		self::assertFalse( is_nav_menu( $menu['menu'] ) );
		$row = $this->only_row();
		self::assertSame( 'menu', $row['family'] );
		self::assertSame( 'menu', $row['resource_type'] );
		self::assertSame( (string) $menu['menu'], $row['resource_id'] );
		self::assertSame( 'stonewright/menu-delete', $row['ability'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['after_ref'], 'There is nothing left to image after a delete.' );
		$image = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertSame( [ 'Home', 'About', 'Team', 'History', 'Contact' ], array_column( $image['items'], 'title' ) );
		self::assertSame( [ 'primary' ], $image['locations'] );

		$undo = MenuAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertTrue( $undo['recreated'] );
		self::assertSame( $shape, $this->menu_shape( $undo['menu_id'] ) );
		self::assertSame( $undo['menu_id'], get_nav_menu_locations()['primary'] );
	}

	public function test_adding_an_item_is_recorded_with_both_images_and_undone_by_restore(): void {
		$menu = $this->standard_menu();
		$shape = $this->menu_shape( $menu['menu'] );

		$result = ( new MenuAddItem() )->execute( [ 'menu_id' => $menu['menu'], 'title' => 'Blog', 'url' => 'https://example.test/blog/' ] );

		self::assertIsArray( $result );
		$row = $this->only_row();
		self::assertSame( 'stonewright/menu-add-item', $row['ability'] );
		self::assertSame( 'menu', $row['family'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertCount( 5, ChangeLedger::read_image( $row['change_id'], 'before' )['items'] );
		self::assertCount( 6, ChangeLedger::read_image( $row['change_id'], 'after' )['items'] );

		$undo = MenuAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( $shape, $this->menu_shape( $menu['menu'] ) );
	}

	public function test_creating_a_menu_with_items_is_one_create_row_whose_undo_deletes_the_menu(): void {
		$result = ( new MenuCreate() )->execute(
			[
				'name'  => 'Footer links',
				'items' => [ [ 'title' => 'One', 'url' => 'https://example.test/1/' ], [ 'title' => 'Two', 'url' => 'https://example.test/2/' ] ],
			]
		);

		self::assertIsArray( $result );
		$id  = $result['menu_id'];
		$row = $this->only_row();
		self::assertSame( 'stonewright/menu-create', $row['ability'] );
		self::assertSame( (string) $id, $row['resource_id'] );
		self::assertSame( '', $row['before_ref'], 'There is no before image of a menu that did not exist.' );
		self::assertTrue( $row['restorable'] );
		self::assertTrue( MenuAdapter::is_created_row( $row ) );
		self::assertSame( [ 'One', 'Two' ], array_column( ChangeLedger::read_image( $row['change_id'], 'after' )['items'], 'title' ) );

		$undo = MenuAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertFalse( is_nav_menu( $id ) );
	}

	public function test_assigning_a_location_records_the_location_and_the_undo_gives_the_slot_back_to_the_menu_it_displaced(): void {
		$one = $this->make_menu( 'One', [] );
		$two = $this->make_menu( 'Two', [] );
		set_theme_mod( 'nav_menu_locations', [ 'primary' => $one['menu'], 'footer' => $one['menu'] ] );

		$result = ( new MenuAssignLocation() )->execute( [ 'location' => 'primary', 'menu_id' => $two['menu'] ] );

		self::assertIsArray( $result );
		self::assertSame( $two['menu'], get_nav_menu_locations()['primary'] );
		$row = $this->only_row();
		self::assertSame( 'stonewright/menu-assign-location', $row['ability'] );
		self::assertSame( 'menu', $row['family'] );
		self::assertSame( 'menu_location', $row['resource_type'] );
		self::assertSame( 'primary', $row['resource_id'] );
		self::assertSame( $one['menu'], ChangeLedger::read_image( $row['change_id'], 'before' )['menu_id'] );
		self::assertSame( $two['menu'], ChangeLedger::read_image( $row['change_id'], 'after' )['menu_id'] );

		$undo = MenuAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( $one['menu'], get_nav_menu_locations()['primary'] );
		self::assertSame( $one['menu'], get_nav_menu_locations()['footer'], 'Other slots are not touched.' );
	}

	public function test_the_undo_of_an_assignment_to_an_empty_slot_empties_it_again(): void {
		$one = $this->make_menu( 'One', [] );

		( new MenuAssignLocation() )->execute( [ 'location' => 'footer', 'menu_id' => $one['menu'] ] );
		$row  = $this->only_row();
		$undo = MenuAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertArrayNotHasKey( 'footer', get_nav_menu_locations() );
	}

	public function test_a_call_that_changes_no_menu_records_nothing(): void {
		$menu = $this->standard_menu();

		$result = ( new MenuAddItem() )->execute( [ 'menu_id' => 99999, 'title' => 'Blog', 'url' => 'https://example.test/blog/' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 0, $this->ledger_count() );
		self::assertCount( 5, MenuAdapter::image( $menu['menu'] )['items'] );
	}
}
