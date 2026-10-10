<?php
/**
 * The rollback engine against the post, option, menu and widget families: a run restores, a redo restores the after image.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Rollback;

use Stonewright\WpMcp\Abilities\Menu\MenuAddItem;
use Stonewright\WpMcp\Abilities\Menu\MenuDelete;
use Stonewright\WpMcp\Abilities\Settings\SettingsUpdate;
use Stonewright\WpMcp\Abilities\Widgets\WidgetSave;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\ChangeRollback;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeRollback
 * @covers \Stonewright\WpMcp\Security\Rollback\PostRollbackFamily
 * @covers \Stonewright\WpMcp\Security\Rollback\OptionsRollbackFamily
 * @covers \Stonewright\WpMcp\Security\Rollback\MenuRollbackFamily
 * @covers \Stonewright\WpMcp\Security\Rollback\WidgetRollbackFamily
 */
final class ChangeRollbackFamiliesTest extends RollbackTestCase {

	private const ELEMENTOR_BEFORE = '[{"id":"a1b2c3","elType":"widget","widgetType":"heading","settings":{"title":"Before"}}]';

	private const ELEMENTOR_AFTER = '[{"id":"a1b2c3","elType":"widget","widgetType":"heading","settings":{"title":"After"}}]';

	/**
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, mixed>
	 */
	private function ok( array|\WP_Error $result ): array {
		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_code() . ': ' . $result->get_error_message() : '' );
		self::assertTrue( $result['ok'] );
		return $result;
	}

	// ---- posts --------------------------------------------------------------------------------------------

	public function test_a_post_is_restored_with_its_terms_slug_and_meta_and_a_redo_returns_the_change(): void {
		$GLOBALS['stonewright_test_object_taxonomies']['page'] = [ 'category' ];
		$this->make_post( self::POST, [ 'post_title' => 'Before title', 'post_name' => 'before-slug', 'post_content' => 'Before body' ] );
		$this->set_terms( self::POST, 'category', [ [ 11, 'news', 'News' ] ] );
		$this->set_meta( self::POST, '_wp_page_template', 'full-width.php' );
		$id = $this->post_change(
			function (): void {
				$this->edit_post( 'post_title', 'After title' );
				$this->edit_post( 'post_name', 'after-slug' );
				$this->edit_post( 'post_content', 'After body' );
				$this->set_meta( self::POST, '_wp_page_template', 'default' );
				$this->set_terms( self::POST, 'category', [ [ 12, 'blog', 'Blog' ] ] );
			}
		);

		$undo = $this->ok( ChangeRollback::run( $id ) );

		self::assertSame( 'Before title', $this->post_field( 'post_title' ) );
		self::assertSame( 'before-slug', $this->post_field( 'post_name' ) );
		self::assertSame( 'Before body', $this->post_field( 'post_content' ) );
		self::assertSame( 'full-width.php', $this->meta( self::POST, '_wp_page_template' ) );
		self::assertSame( [ 'news' ], $GLOBALS['stonewright_test_object_terms'][ self::POST ]['category'] );

		$this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );

		self::assertSame( 'After title', $this->post_field( 'post_title' ) );
		self::assertSame( 'after-slug', $this->post_field( 'post_name' ) );
		self::assertSame( 'After body', $this->post_field( 'post_content' ) );
		self::assertSame( 'default', $this->meta( self::POST, '_wp_page_template' ) );
		self::assertSame( [ 'blog' ], $GLOBALS['stonewright_test_object_terms'][ self::POST ]['category'] );
	}

	public function test_an_elementor_post_is_restored_by_its_element_data_and_the_redo_returns_the_new_tree(): void {
		$this->make_post( self::POST, [ 'post_content' => '' ] );
		$this->set_meta( self::POST, '_elementor_data', self::ELEMENTOR_BEFORE );
		$this->set_meta( self::POST, '_elementor_edit_mode', 'builder' );
		$id = $this->post_change( fn () => $this->set_meta( self::POST, '_elementor_data', self::ELEMENTOR_AFTER ), self::POST, 'stonewright/elementor-v3-update-element' );
		self::assertSame( 'elementor', $this->row( $id )['family'] );

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertTrue( $plan['diff']['changed'], 'The undo is shown as an element diff.' );

		$undo = $this->ok( ChangeRollback::run( $id ) );

		self::assertSame( self::ELEMENTOR_BEFORE, $this->meta( self::POST, '_elementor_data' ) );
		self::assertSame( 'elementor', $this->row( $undo['rollback_change_id'] )['family'] );

		$this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );

		self::assertSame( self::ELEMENTOR_AFTER, $this->meta( self::POST, '_elementor_data' ) );
	}

	// ---- options --------------------------------------------------------------------------------------------

	public function test_options_are_restored_through_the_ability_that_wrote_them_and_a_redo_writes_them_again(): void {
		update_option( 'blogname', 'Before name' );
		update_option( 'posts_per_page', 10 );
		( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After name', 'posts_per_page' => 5 ] ] );
		$id = (string) $this->only_row_of( 'option' )['change_id'];
		$this->forget_journal();

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertSame( 'ledger', $plan['path'] );
		self::assertTrue( $plan['diff']['changed'] );
		self::assertStringContainsString( 'Before name', (string) wp_json_encode( $plan['diff'] ) );

		$undo = $this->ok( ChangeRollback::run( $id ) );

		self::assertSame( 'Before name', get_option( 'blogname' ) );
		self::assertSame( 10, get_option( 'posts_per_page' ) );
		$rollback = $this->row( $undo['rollback_change_id'] );
		self::assertSame( 'option', $rollback['family'] );
		self::assertSame( 'blogname,posts_per_page', $rollback['resource_id'] );

		$this->ok( ChangeRollback::run( $rollback['change_id'] ) );

		self::assertSame( 'After name', get_option( 'blogname' ) );
		self::assertSame( 5, get_option( 'posts_per_page' ) );
	}

	public function test_an_option_edited_by_hand_since_is_drift_and_the_value_is_listed_nowhere_in_the_plan(): void {
		update_option( 'blogname', 'Before name' );
		( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After name' ] ] );
		$id = (string) $this->only_row_of( 'option' )['change_id'];
		$this->forget_journal();
		update_option( 'blogname', 'Edited by hand' );

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );

		self::assertIsArray( $plan );
		self::assertTrue( $plan['drift'] );
		$this->assert_refused( ChangeRollback::run( $id ), 'stonewright_change_drift' );
		self::assertSame( 'Edited by hand', get_option( 'blogname' ) );
	}

	public function test_an_option_that_did_not_exist_before_is_removed_by_the_rollback(): void {
		$this->make_post( 12, [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'show_on_front', 'posts' );
		( new \Stonewright\WpMcp\Abilities\Site\SetFrontPage() )->execute( [ 'page_id' => 12 ] );
		$id = (string) $this->only_row_of( 'option' )['change_id'];
		$this->forget_journal();

		$this->ok( ChangeRollback::run( $id ) );

		self::assertSame( 'posts', get_option( 'show_on_front' ) );
		self::assertArrayNotHasKey( 'page_on_front', $GLOBALS['stonewright_test_options'] );
	}

	// ---- menus ----------------------------------------------------------------------------------------------

	public function test_a_deleted_menu_is_recreated_by_the_rollback_and_the_redo_deletes_the_recreated_menu(): void {
		$menu = $this->make_menu(
			'Main',
			[
				[ 'home', 'Home', 'https://example.test/', '' ],
				[ 'about', 'About', 'https://example.test/about/', '' ],
				[ 'team', 'Team', 'https://example.test/about/team/', 'about' ],
			]
		);
		$shape = $this->menu_shape( $menu['menu'] );
		( new MenuDelete() )->execute( [ 'menu_id' => $menu['menu'] ] );
		$id = (string) $this->only_row_of( 'menu' )['change_id'];
		$this->forget_journal();
		self::assertFalse( wp_get_nav_menu_object( $menu['menu'] ) );

		$plan = ChangeRollback::run( $id, [ 'dry_run' => true ] );
		self::assertIsArray( $plan, $plan instanceof \WP_Error ? $plan->get_error_message() : '' );
		self::assertFalse( $plan['drift'], 'A menu that is gone is what the change left.' );

		$undo   = $this->ok( ChangeRollback::run( $id ) );
		$new_id = (int) $undo['resource_id'];

		self::assertNotSame( 0, $new_id );
		self::assertSame( $shape, $this->menu_shape( $new_id ), 'The items come back in their order and under their parents.' );
		$rollback = $this->row( $undo['rollback_change_id'] );
		self::assertSame( (string) $new_id, $rollback['resource_id'] );
		self::assertTrue( $rollback['restorable'], 'Undoing the rollback deletes the recreated menu.' );

		$this->ok( ChangeRollback::run( $rollback['change_id'] ) );

		self::assertFalse( wp_get_nav_menu_object( $new_id ), 'The redo deletes the menu again.' );
	}

	public function test_undo_and_redo_of_a_menu_delete_succeed_with_custom_items_and_leave_no_orphan_menu(): void {
		// A custom link keeps its own id as its object id, and a recreated menu has new item ids.
		$menu = $this->make_menu(
			'Main',
			[
				[ 'home', 'Home', 'https://example.test/', '' ],
				[ 'about', 'About', 'https://example.test/about/', '' ],
			]
		);
		( new MenuDelete() )->execute( [ 'menu_id' => $menu['menu'] ] );
		$id = (string) $this->only_row_of( 'menu' )['change_id'];
		$this->forget_journal();

		$undo = ChangeRollback::run( $id );

		self::assertIsArray( $undo, $undo instanceof \WP_Error ? $undo->get_error_code() . ': ' . $undo->get_error_message() : '' );
		self::assertTrue( $undo['ok'] );
		self::assertSame( 'verified', $this->row( $undo['rollback_change_id'] )['status'], 'The rollback row succeeded.' );
		self::assertSame( 'rolled_back_by', $this->row( $id )['status'], 'The delete row is rolled back, so a redo is offered.' );
		self::assertSame( $undo['rollback_change_id'], ChangeRollback::redo_target( $this->row( $id ) )['change_id'] ?? '' );
		self::assertCount( 1, wp_get_nav_menus(), 'The menu exists once.' );

		$again = ChangeRollback::run( $id );
		$this->assert_refused( $again, 'stonewright_change_already_rolled_back' );
		self::assertCount( 1, wp_get_nav_menus(), 'A second undo leaves no orphan menu.' );

		$this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );
		self::assertCount( 0, wp_get_nav_menus(), 'The redo deletes the recreated menu.' );
	}

	public function test_a_menu_item_added_is_removed_by_the_rollback_and_added_again_by_the_redo(): void {
		$menu = $this->make_menu( 'Main', [ [ 'home', 'Home', 'https://example.test/', '' ] ] );
		( new MenuAddItem() )->execute( [ 'menu_id' => $menu['menu'], 'title' => 'Two', 'url' => 'https://example.test/2/' ] );
		$id = (string) $this->only_row_of( 'menu' )['change_id'];
		$this->forget_journal();

		$undo = $this->ok( ChangeRollback::run( $id ) );
		self::assertSame( [ 'Home' ], array_column( $this->menu_shape( $menu['menu'] ), 0 ) );

		$this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );
		self::assertSame( [ 'Home', 'Two' ], array_column( $this->menu_shape( $menu['menu'] ), 0 ) );
	}

	// ---- widgets --------------------------------------------------------------------------------------------

	public function test_a_sidebar_is_restored_and_the_redo_puts_the_change_back(): void {
		( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'search-2' ] ] );
		$id = (string) $this->only_row_of( 'widget' )['change_id'];
		$this->forget_journal();
		self::assertSame( [ 'search-2' ], wp_get_sidebars_widgets()['sidebar-1'] );

		$undo = $this->ok( ChangeRollback::run( $id ) );

		self::assertSame( [ 'text-1', 'search-2' ], wp_get_sidebars_widgets()['sidebar-1'] );

		$this->ok( ChangeRollback::run( $undo['rollback_change_id'] ) );

		self::assertSame( [ 'search-2' ], wp_get_sidebars_widgets()['sidebar-1'] );
	}

	// ---- what a row says about itself ------------------------------------------------------------------------

	public function test_every_rollback_row_names_the_engine_as_its_ability_and_keeps_the_family_of_the_change(): void {
		update_option( 'blogname', 'Before name' );
		( new SettingsUpdate() )->execute( [ 'settings' => [ 'blogname' => 'After name' ] ] );
		$id = (string) $this->only_row_of( 'option' )['change_id'];
		$this->forget_journal();

		$undo = $this->ok( ChangeRollback::run( $id ) );

		self::assertSame( 'stonewright/change-rollback', $this->row( $undo['rollback_change_id'] )['ability'] );
		self::assertSame( 2, ChangeLedger::count() );
	}
}
