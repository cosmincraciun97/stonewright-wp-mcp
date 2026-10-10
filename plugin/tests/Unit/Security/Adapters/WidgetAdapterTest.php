<?php
/**
 * The widget family in the change ledger: sidebar assignments and widget instances, and the restore.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\Widgets\WidgetDelete;
use Stonewright\WpMcp\Abilities\Widgets\WidgetSave;
use Stonewright\WpMcp\Security\Adapters\WidgetAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\WidgetAdapter
 */
final class WidgetAdapterTest extends FamilyLedgerTestCase {

	private const MARKER = 'SYNTHETIC-KEY-9d4e10';

	protected function setUp(): void {
		parent::setUp();
		update_option( 'widget_text', [ 1 => [ 'title' => 'Hello', 'text' => 'Synthetic text' ], 3 => [ 'title' => 'Other', 'text' => 'More' ], '_multiwidget' => 1 ] );
		update_option( 'widget_search', [ 2 => [ 'title' => 'Find' ], '_multiwidget' => 1 ] );
		$GLOBALS['stonewright_test_sidebars'] = [ 'sidebar-1' => [ 'text-1', 'search-2' ], 'footer-1' => [ 'text-3' ] ];
	}

	/** @return list<string> */
	private function sidebar( string $id ): array {
		return (array) ( wp_get_sidebars_widgets()[ $id ] ?? [] );
	}

	public function test_the_image_holds_the_sidebar_list_and_the_instances_of_its_widgets(): void {
		$image = WidgetAdapter::image( 'sidebar-1', [ 'text-1', 'search-2', 'text-9' ] );

		self::assertSame( 'sidebar-1', $image['sidebar'] );
		self::assertSame( [ 'text-1', 'search-2' ], $image['widgets'] );
		self::assertSame( [ 'exists' => true, 'value' => [ 'title' => 'Hello', 'text' => 'Synthetic text' ] ], $image['instances']['text-1'] );
		self::assertSame( [ 'exists' => true, 'value' => [ 'title' => 'Find' ] ], $image['instances']['search-2'] );
		self::assertFalse( $image['instances']['text-9']['exists'] );
		self::assertArrayNotHasKey( 'text-3', $image['instances'], 'A widget of another sidebar is not part of this image.' );
	}

	public function test_a_sidebar_that_does_not_exist_is_imaged_as_absent(): void {
		$image = WidgetAdapter::image( 'sidebar-9', [] );

		self::assertNull( $image['widgets'] );
	}

	public function test_widget_save_is_recorded_with_both_images_and_the_undo_restores_the_list_and_nothing_else(): void {
		$result = ( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'search-2', 'text-3' ] ] );

		self::assertSame( [ 'ok' => true, 'sidebar_id' => 'sidebar-1' ], $result );
		$row = $this->only_row();
		self::assertSame( 'widget', $row['family'] );
		self::assertSame( 'sidebar', $row['resource_type'] );
		self::assertSame( 'sidebar-1', $row['resource_id'] );
		self::assertSame( 'stonewright/widget-save', $row['ability'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( [ 'text-1', 'search-2' ], ChangeLedger::read_image( $row['change_id'], 'before' )['widgets'] );
		self::assertSame( [ 'search-2', 'text-3' ], ChangeLedger::read_image( $row['change_id'], 'after' )['widgets'] );
		$footer = $this->sidebar( 'footer-1' );
		$GLOBALS['stonewright_test_sidebars']['footer-1'] = [ 'text-3', 'search-2' ];

		$undo = WidgetAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'] );
		self::assertSame( [ 'text-1', 'search-2' ], $this->sidebar( 'sidebar-1' ) );
		self::assertSame( [ 'text-3', 'search-2' ], $this->sidebar( 'footer-1' ), 'Another sidebar is left as it is.' );
		self::assertSame( [ 'text-3' ], $footer );
	}

	public function test_widget_delete_is_recorded_and_the_undo_puts_the_widget_back_with_its_settings(): void {
		$result = ( new WidgetDelete() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widget_id' => 'text-1' ] );

		self::assertIsArray( $result );
		self::assertSame( [ 'search-2' ], $this->sidebar( 'sidebar-1' ) );
		$row = $this->only_row();
		self::assertSame( 'stonewright/widget-delete', $row['ability'] );
		self::assertSame( 'verified', $row['status'] );
		self::assertSame( 'Synthetic text', ChangeLedger::read_image( $row['change_id'], 'before' )['instances']['text-1']['value']['text'] );
		// The widget settings were removed as well, as the admin screen does when it deletes a widget.
		update_option( 'widget_text', [ 3 => [ 'title' => 'Other', 'text' => 'More' ], '_multiwidget' => 1 ] );

		$undo = WidgetAdapter::undo( $row['change_id'] );

		self::assertIsArray( $undo );
		self::assertTrue( $undo['ok'], implode( ',', $undo['differences'] ) );
		self::assertSame( [ 'text-1', 'search-2' ], $this->sidebar( 'sidebar-1' ) );
		self::assertSame( 'Synthetic text', get_option( 'widget_text' )[1]['text'] );
		self::assertSame( 'Other', get_option( 'widget_text' )[3]['title'], 'The settings of other widgets stay.' );
		self::assertSame( 1, get_option( 'widget_text' )['_multiwidget'] );
	}

	public function test_restore_removes_the_instance_of_a_widget_that_did_not_exist(): void {
		$before = WidgetAdapter::image( 'sidebar-1', [ 'text-1', 'text-7' ] );
		update_option( 'widget_text', [ 1 => [ 'title' => 'Hello', 'text' => 'Synthetic text' ], 3 => [ 'title' => 'Other', 'text' => 'More' ], 7 => [ 'title' => 'New' ], '_multiwidget' => 1 ] );
		$GLOBALS['stonewright_test_sidebars']['sidebar-1'][] = 'text-7';

		$result = WidgetAdapter::restore( $before );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertArrayNotHasKey( 7, get_option( 'widget_text' ) );
		self::assertSame( [ 'text-1', 'search-2' ], $this->sidebar( 'sidebar-1' ) );
	}

	public function test_restore_of_a_sidebar_that_did_not_exist_removes_it(): void {
		$before = WidgetAdapter::image( 'sidebar-9', [] );
		$GLOBALS['stonewright_test_sidebars']['sidebar-9'] = [ 'search-2' ];

		$result = WidgetAdapter::restore( $before );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertArrayNotHasKey( 'sidebar-9', wp_get_sidebars_widgets() );
	}

	public function test_a_secret_inside_a_widget_masks_the_image_and_is_never_stored(): void {
		update_option( 'widget_text', [ 1 => [ 'title' => 'Hello', 'api_key' => self::MARKER ], '_multiwidget' => 1 ] );

		( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'search-2' ] ] );

		$row = $this->only_row();
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertStringNotContainsString( self::MARKER, $this->everything_stored() );
		$undo = WidgetAdapter::undo( $row['change_id'] );
		self::assertInstanceOf( \WP_Error::class, $undo );
		self::assertSame( 'stonewright_change_not_restorable', $undo->get_error_code() );
		self::assertSame( [ 'search-2' ], $this->sidebar( 'sidebar-1' ), 'Nothing was written.' );
	}

	public function test_a_widget_whose_settings_option_has_a_secret_name_is_vetoed_and_not_imaged(): void {
		update_option( 'widget_payment_token', [ 1 => [ 'title' => 'Pay', 'note' => 'synthetic-token-value-5555' ], '_multiwidget' => 1 ] );
		$GLOBALS['stonewright_test_sidebars']['sidebar-1'] = [ 'text-1', 'payment_token-1' ];

		( new WidgetSave() )->execute( [ 'sidebar_id' => 'sidebar-1', 'widgets' => [ 'text-1' ] ] );

		$row = $this->only_row();
		self::assertFalse( $row['restorable'] );
		self::assertSame( \Stonewright\WpMcp\Security\Adapters\OptionsAdapter::SKIPPED_REASON, $row['restorable_reason'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertArrayNotHasKey( 'payment_token-1', $before['instances'] );
		self::assertSame( [ 'widget_payment_token' ], $before['vetoed'] );
		self::assertSame( [ 'text-1', 'payment_token-1' ], $before['widgets'], 'The id of the widget is kept; only its settings are not.' );
		self::assertStringNotContainsString( 'synthetic-token-value-5555', $this->everything_stored() );
	}

	public function test_restore_refuses_a_malformed_image_and_one_for_a_sidebar_name_it_cannot_use(): void {
		$bad  = WidgetAdapter::restore( [ 'v' => 1, 'kind' => 'menu' ] );
		$weird = WidgetAdapter::restore( [ 'v' => 1, 'kind' => 'widget', 'sidebar' => "bad\nid", 'widgets' => [ 'text-1' ], 'instances' => [], 'vetoed' => [] ] );

		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'stonewright_image_invalid', $bad->get_error_code() );
		self::assertInstanceOf( \WP_Error::class, $weird );
		self::assertSame( 'stonewright_image_invalid', $weird->get_error_code() );
	}
}
