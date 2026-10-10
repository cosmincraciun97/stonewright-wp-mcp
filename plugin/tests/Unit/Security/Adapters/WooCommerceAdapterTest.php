<?php
/**
 * The WooCommerce family in the change ledger: products, variations, terms and global attributes, through
 * the catalog objects and functions.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Adapters;

use Stonewright\WpMcp\Abilities\WooCommerce\WcProductDelete;
use Stonewright\WpMcp\Abilities\WooCommerce\WcProductSave;
use Stonewright\WpMcp\Security\Adapters\OtherFamilies;
use Stonewright\WpMcp\Security\Adapters\WooCommerceAdapter;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Tests\Unit\Security\Fixtures\FakeWooProduct;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/**
 * @covers \Stonewright\WpMcp\Security\Adapters\WooCommerceAdapter
 */
final class WooCommerceAdapterTest extends FamilyLedgerTestCase {

	protected function setUp(): void {
		parent::setUp();
		FakeWooProduct::reset();
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		WooRuntime::set_test_overrides(
			[
				'available'                => static fn (): bool => true,
				'get_product'              => static fn ( int $id ): mixed => isset( FakeWooProduct::$store[ $id ] ) ? clone FakeWooProduct::$store[ $id ] : null,
				'new_product'              => static fn ( string $type ): object => new FakeWooProduct( 0, $type ),
				'new_variation'            => static fn (): object => new FakeWooProduct( 0, 'variation' ),
				'get_attribute_taxonomies' => static fn (): array => array_values( $GLOBALS['stonewright_test_wc_attributes'] ),
			]
		);
	}

	protected function tearDown(): void {
		WooRuntime::reset_test_overrides();
		FakeWooProduct::reset();
		parent::tearDown();
	}

	private function product( int $id = 12 ): FakeWooProduct {
		return FakeWooProduct::add( $id, 'simple', [ 'name' => 'Old name', 'slug' => 'old-name', 'sku' => 'SKU-12', 'regular_price' => '10', 'category_ids' => [ 4, 5 ], 'description' => 'Stone mug.' ] );
	}

	public function test_a_product_update_is_recorded_and_restored_through_the_catalog_objects(): void {
		$this->product();

		$result = ( new WcProductSave() )->execute( [ 'id' => 12, 'name' => 'New name', 'regular_price' => '12', 'dry_run' => false ] );

		self::assertTrue( $result['effect_verified'] );
		$row = $this->row_of( 'woocommerce' );
		self::assertSame( [ 'wc_product', '12', 'stonewright/wc-product-save', 'verified', true ], [ $row['resource_type'], $row['resource_id'], $row['ability'], $row['status'], $row['restorable'] ] );
		self::assertStringStartsWith( 'Updated product', $row['summary'] );
		$before = ChangeLedger::read_image( $row['change_id'], 'before' );
		self::assertSame( [ 'Old name', '10', [ 4, 5 ] ], [ $before['fields']['name'], $before['fields']['regular_price'], $before['fields']['category_ids'] ] );
		self::assertSame( 'New name', ChangeLedger::read_image( $row['change_id'], 'after' )['fields']['name'] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( [ 'Old name', '10', 'SKU-12', [ 4, 5 ] ], [ FakeWooProduct::$store[12]->get_name(), FakeWooProduct::$store[12]->get_regular_price(), FakeWooProduct::$store[12]->get_sku(), FakeWooProduct::$store[12]->get_category_ids() ] );
		self::assertCount( 1, $this->rows_of_kind( 'rollback' ) );
		self::assertSame( 'noop', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_a_dry_run_and_a_failed_save_record_nothing(): void {
		$this->product();

		( new WcProductSave() )->execute( [ 'id' => 12, 'name' => 'Preview only' ] );
		( new WcProductDelete() )->execute( [ 'id' => 12, 'force' => true ] );
		WooRuntime::set_test_overrides( [ 'available' => static fn (): bool => false ] );
		( new WcProductSave() )->execute( [ 'id' => 12, 'name' => 'Not active', 'dry_run' => false ] );

		self::assertSame( [], $this->ledger_rows() );
		self::assertSame( 'Old name', FakeWooProduct::$store[12]->get_name() );
	}

	public function test_a_created_product_is_recorded_and_its_undo_moves_it_to_the_trash(): void {
		$result = ( new WcProductSave() )->execute( [ 'name' => 'Fresh product', 'regular_price' => '5', 'dry_run' => false ] );

		self::assertSame( 300, $result['product']['id'] );
		$row = $this->row_of( 'woocommerce' );
		self::assertSame( '300', $row['resource_id'] );
		self::assertStringStartsWith( 'Created product', $row['summary'] );
		self::assertTrue( $row['restorable'] );
		self::assertSame( '', $row['before_ref'] );

		$undo = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $undo['status'], (string) $undo['detail'] );
		self::assertSame( 'trash', FakeWooProduct::$store[300]->get_status(), 'The product is trashed, not deleted.' );
		self::assertSame( 'noop', OtherFamilies::restore( $row['change_id'] )['status'] );
	}

	public function test_a_product_deleted_for_good_keeps_its_full_image_and_restore_creates_it_again(): void {
		$this->product();

		( new WcProductDelete() )->execute( [ 'id' => 12, 'force' => true, 'dry_run' => false ] );

		self::assertArrayNotHasKey( 12, FakeWooProduct::$store );
		$row = $this->row_of( 'woocommerce' );
		self::assertStringStartsWith( 'Deleted product', $row['summary'] );
		self::assertStringContainsString( 'new id', $row['summary'] );
		self::assertSame( '', $row['after_ref'] );
		self::assertSame( 'SKU-12', ChangeLedger::read_image( $row['change_id'], 'before' )['fields']['sku'] );

		$restore = OtherFamilies::restore( $row['change_id'] );

		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		$new = FakeWooProduct::$store[300];
		self::assertSame( [ 'Old name', 'SKU-12', '10', [ 4, 5 ], 'Stone mug.', 'simple' ], [ $new->get_name(), $new->get_sku(), $new->get_regular_price(), $new->get_category_ids(), $new->get_description(), $new->get_type() ] );
		self::assertSame( '300', $this->rows_of_kind( 'rollback' )[0]['resource_id'] );
	}

	public function test_a_trashed_product_is_a_status_change_and_restore_puts_it_back(): void {
		$this->product();

		( new WcProductDelete() )->execute( [ 'id' => 12, 'dry_run' => false ] );

		self::assertSame( 'trash', FakeWooProduct::$store[12]->get_status() );
		$row = $this->row_of( 'woocommerce' );
		self::assertStringStartsWith( 'Updated product', $row['summary'] );
		OtherFamilies::restore( $row['change_id'] );
		self::assertSame( 'publish', FakeWooProduct::$store[12]->get_status() );
	}

	public function test_a_deleted_variable_product_says_its_variations_are_not_restored(): void {
		FakeWooProduct::add( 20, 'variable', [ 'name' => 'Mug set', 'children' => [ 21, 22 ] ] );

		( new WcProductDelete() )->execute( [ 'id' => 20, 'force' => true, 'dry_run' => false ] );

		$row = $this->row_of( 'woocommerce' );
		self::assertStringContainsString( 'no variations', $row['summary'] );
		$restore = OtherFamilies::restore( $row['change_id'] );
		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertStringContainsString( 'variations', implode( ' ', $restore['limits'] ) );
		self::assertSame( 'variable', FakeWooProduct::$store[300]->get_type() );
	}

	public function test_a_variation_update_is_recorded_and_restored(): void {
		FakeWooProduct::add( 20, 'variable', [ 'name' => 'Mug set' ] );
		FakeWooProduct::add( 21, 'variation', [ 'regular_price' => '8', 'parent_id' => 20, 'attributes' => [ 'pa_size' => 'small' ] ] );

		$this->call(
			'stonewright/wc-variation-save',
			[ 'parent_id' => 20, 'id' => 21, 'regular_price' => '9', 'attributes' => [ 'pa_size' => 'small' ], 'dry_run' => false ],
			static function (): void {
				FakeWooProduct::$store[21]->data['regular_price'] = '9';
			},
			[ 'supported' => true, 'dry_run' => false, 'variation' => [ 'id' => 21 ] ]
		);
		$row = $this->row_of( 'woocommerce' );
		self::assertSame( [ 'wc_variation', '21' ], [ $row['resource_type'], $row['resource_id'] ] );
		self::assertSame( '8', ChangeLedger::read_image( $row['change_id'], 'before' )['fields']['regular_price'] );
		OtherFamilies::restore( $row['change_id'] );
		self::assertSame( '8', FakeWooProduct::$store[21]->get_regular_price() );
		self::assertSame( 20, FakeWooProduct::$store[21]->get_parent_id() );
	}

	public function test_a_term_update_and_delete_are_recorded_and_restored(): void {
		$GLOBALS['stonewright_test_terms']['product_cat']['shoes'] = (object) [ 'term_id' => 31, 'name' => 'Shoes', 'slug' => 'shoes', 'description' => '', 'parent' => 0 ];

		$this->call(
			'stonewright/wc-term-save',
			[ 'taxonomy' => 'product_cat', 'id' => 31, 'name' => 'Boots', 'dry_run' => false ],
			static function (): void {
				$GLOBALS['stonewright_test_terms']['product_cat']['shoes']->name = 'Boots';
			},
			[ 'supported' => true, 'dry_run' => false, 'term' => [ 'id' => 31 ] ]
		);

		$update = $this->row_of( 'woocommerce' );
		self::assertSame( [ 'wc_term', 'product_cat:31' ], [ $update['resource_type'], $update['resource_id'] ] );
		self::assertSame( 'Shoes', ChangeLedger::read_image( $update['change_id'], 'before' )['fields']['name'] );
		self::assertSame( 'succeeded', OtherFamilies::restore( $update['change_id'] )['status'] );
		self::assertSame( 'Shoes', $GLOBALS['stonewright_test_terms']['product_cat']['shoes']->name );

		$this->call(
			'stonewright/wc-term-delete',
			[ 'taxonomy' => 'product_cat', 'id' => 31, 'dry_run' => false ],
			static function (): void {
				unset( $GLOBALS['stonewright_test_terms']['product_cat']['shoes'] );
			},
			[ 'supported' => true, 'dry_run' => false, 'id' => 31, 'deleted' => true ]
		);

		$delete = array_values( array_filter( $this->rows_of_kind( 'change' ), static fn ( array $row ): bool => 'stonewright/wc-term-delete' === $row['ability'] ) )[0];
		self::assertStringContainsString( 'products lose the term', $delete['summary'] );
		$restore = OtherFamilies::restore( $delete['change_id'] );
		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 'Shoes', $GLOBALS['stonewright_test_terms']['product_cat']['shoes']->name );
		self::assertStringContainsString( 'products', implode( ' ', $restore['limits'] ) );
	}

	public function test_a_created_term_is_recorded_and_its_undo_deletes_an_unused_term_only(): void {
		$this->call(
			'stonewright/wc-term-save',
			[ 'taxonomy' => 'product_tag', 'name' => 'Sale', 'dry_run' => false ],
			static function (): void {
				$GLOBALS['stonewright_test_terms']['product_tag']['sale'] = (object) [ 'term_id' => 61, 'name' => 'Sale', 'slug' => 'sale', 'count' => 2 ];
			},
			[ 'supported' => true, 'dry_run' => false, 'term' => [ 'id' => 61, 'taxonomy' => 'product_tag' ] ]
		);
		$row = $this->row_of( 'woocommerce' );
		self::assertSame( 'product_tag:61', $row['resource_id'] );

		self::assertSame( [ 'failed', 'term_in_use' ], [ OtherFamilies::restore( $row['change_id'] )['status'], OtherFamilies::restore( $row['change_id'] )['detail'] ] );

		$GLOBALS['stonewright_test_terms']['product_tag']['sale']->count = 0;
		self::assertSame( 'succeeded', OtherFamilies::restore( $row['change_id'] )['status'] );
		self::assertArrayNotHasKey( 'sale', $GLOBALS['stonewright_test_terms']['product_tag'] );
	}

	public function test_a_global_attribute_save_and_delete_are_recorded_and_restored(): void {
		$GLOBALS['stonewright_test_wc_attributes'][40] = (object) [ 'attribute_id' => 40, 'attribute_label' => 'Size', 'attribute_name' => 'size', 'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0 ];

		$this->call(
			'stonewright/wc-attribute-save',
			[ 'id' => 40, 'name' => 'Sizes', 'dry_run' => false ],
			static function (): void {
				$GLOBALS['stonewright_test_wc_attributes'][40]->attribute_label = 'Sizes';
			},
			[ 'supported' => true, 'dry_run' => false, 'attribute' => [ 'id' => 40 ] ]
		);
		$update = $this->row_of( 'woocommerce' );
		self::assertSame( [ 'wc_attribute', '40' ], [ $update['resource_type'], $update['resource_id'] ] );
		self::assertSame( 'succeeded', OtherFamilies::restore( $update['change_id'] )['status'] );
		self::assertSame( 'Size', $GLOBALS['stonewright_test_wc_attributes'][40]->attribute_label );

		$this->call(
			'stonewright/wc-attribute-delete',
			[ 'id' => 40, 'dry_run' => false ],
			static function (): void {
				unset( $GLOBALS['stonewright_test_wc_attributes'][40] );
			},
			[ 'supported' => true, 'dry_run' => false, 'id' => 40, 'deleted' => true ]
		);
		$delete = array_values( array_filter( $this->rows_of_kind( 'change' ), static fn ( array $row ): bool => 'stonewright/wc-attribute-delete' === $row['ability'] ) )[0];
		self::assertStringContainsString( 'no terms', $delete['summary'] );
		$restore = OtherFamilies::restore( $delete['change_id'] );
		self::assertSame( 'succeeded', $restore['status'], (string) $restore['detail'] );
		self::assertSame( 'Size', $GLOBALS['stonewright_test_wc_attributes'][40]->attribute_label ?? $GLOBALS['stonewright_test_wc_attributes'][41]->attribute_label );
	}

	public function test_restore_needs_the_shop_capability_and_a_woocommerce_taxonomy(): void {
		$this->product();
		( new WcProductSave() )->execute( [ 'id' => 12, 'name' => 'New name', 'dry_run' => false ] );
		$row                                           = $this->row_of( 'woocommerce' );
		$GLOBALS['stonewright_test_user_can_callback'] = static fn ( string $cap ): bool => ! in_array( $cap, [ 'manage_woocommerce', 'manage_options' ], true );

		self::assertSame( [ 'failed', 'permission_denied' ], [ OtherFamilies::restore( $row['change_id'] )['status'], OtherFamilies::restore( $row['change_id'] )['detail'] ] );
		self::assertSame( 'New name', FakeWooProduct::$store[12]->get_name() );
		self::assertNull( WooCommerceAdapter::image( 'wc_term', 'category:31' ), 'Only the catalog taxonomies are imaged.' );
		self::assertNull( WooCommerceAdapter::image( 'wc_term', 'nav_menu:31' ) );
	}

	public function test_a_product_text_with_a_credential_is_masked_and_not_restorable(): void {
		$this->product();
		FakeWooProduct::$store[12]->data['purchase_note'] = "Thanks\napi_key: sk_live_abcd1234efgh5678";

		( new WcProductSave() )->execute( [ 'id' => 12, 'name' => 'New name', 'dry_run' => false ] );

		$row = $this->row_of( 'woocommerce' );
		self::assertFalse( $row['restorable'] );
		self::assertSame( 'masked_secret', $row['restorable_reason'] );
		self::assertStringNotContainsString( 'sk_live_abcd1234efgh5678', $this->stored_text() );
	}
}
