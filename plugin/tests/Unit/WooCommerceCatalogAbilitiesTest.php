<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\WooCommerce\WcCatalogAudit;
use Stonewright\WpMcp\Abilities\WooCommerce\WcProductDelete;
use Stonewright\WpMcp\Abilities\WooCommerce\WcProductSave;
use Stonewright\WpMcp\Abilities\WooCommerce\WcStatus;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @covers \Stonewright\WpMcp\WooCommerce\Catalog */
final class WooCommerceCatalogAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_woocommerce' => true,
			'manage_options'     => true,
		];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		WooRuntime::reset_test_overrides();
	}

	protected function tearDown(): void {
		WooRuntime::reset_test_overrides();
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
	}

	public function test_registry_contains_complete_native_woocommerce_surface(): void {
		$classes = AbilityRegistry::list();
		foreach (
			[
				\Stonewright\WpMcp\Abilities\WooCommerce\WcStatus::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcProductGet::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcProductSave::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcProductDelete::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcVariationList::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcVariationSave::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcVariationDelete::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcTermList::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcTermSave::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcTermDelete::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcAttributeList::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcAttributeSave::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcAttributeDelete::class,
				\Stonewright\WpMcp\Abilities\WooCommerce\WcCatalogAudit::class,
			] as $class
		) {
			self::assertContains( $class, $classes );
		}
	}

	public function test_status_is_explicit_when_woocommerce_is_unavailable(): void {
		WooRuntime::set_test_overrides( [ 'available' => static fn(): bool => false ] );
		$result = ( new WcStatus() )->execute( [] );
		self::assertIsArray( $result );
		self::assertFalse( $result['supported'] );
	}

	public function test_product_save_dry_runs_by_default_without_persisting(): void {
		$product = self::fake_product( 12, 'simple' );
		WooRuntime::set_test_overrides(
			[
				'available'   => static fn(): bool => true,
				'get_product' => static fn( int $id ): object => $product,
			]
		);
		$result = ( new WcProductSave() )->execute( [ 'id' => 12, 'name' => '<b>Safe name</b>' ] );
		self::assertIsArray( $result );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'Safe name', $result['product']['name'] );
		self::assertSame( 0, $product->save_count );
	}

	public function test_product_save_writes_and_verifies_readback(): void {
		$product = self::fake_product( 12, 'simple' );
		WooRuntime::set_test_overrides(
			[
				'available'   => static fn(): bool => true,
				'get_product' => static fn( int $id ): object => $product,
			]
		);
		$result = ( new WcProductSave() )->execute(
			[
				'id'      => 12,
				'name'    => 'Updated product',
				'dry_run' => false,
			]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 1, $product->save_count );
		self::assertSame( 'Updated product', $result['product']['name'] );
	}

	public function test_product_save_rejects_mismatched_readback(): void {
		$product  = self::fake_product( 12, 'simple' );
		$stale    = self::fake_product( 12, 'simple' );
		$readback = 0;
		WooRuntime::set_test_overrides(
			[
				'available'   => static fn(): bool => true,
				'get_product' => static function ( int $id ) use ( $product, $stale, &$readback ): object {
					unset( $id );
					return 0 === $readback++ ? $product : $stale;
				},
			]
		);
		$result = ( new WcProductSave() )->execute(
			[
				'id'      => 12,
				'name'    => 'Updated product',
				'dry_run' => false,
			]
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_wc_product_readback_mismatch', $result->get_error_code() );
		self::assertSame( [ 'name' ], $result->get_error_data()['mismatch_fields'] );
	}

	public function test_product_delete_previews_and_requires_confirmation_in_production_safe(): void {
		$product = self::fake_product( 12, 'simple' );
		WooRuntime::set_test_overrides(
			[
				'available'   => static fn(): bool => true,
				'get_product' => static fn( int $id ): object => $product,
			]
		);
		$preview = ( new WcProductDelete() )->execute( [ 'id' => 12 ] );
		self::assertIsArray( $preview );
		self::assertTrue( $preview['dry_run'] );
		self::assertSame( 0, $product->delete_count );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$blocked = ( new WcProductDelete() )->execute( [ 'id' => 12, 'dry_run' => false ] );
		self::assertInstanceOf( \WP_Error::class, $blocked );
		self::assertSame( 'stonewright_confirmation_required', $blocked->get_error_code() );
		self::assertSame( 0, $product->delete_count );
	}

	public function test_catalog_audit_reports_duplicate_skus_and_quality_gaps(): void {
		$first = self::fake_product( 21, 'simple' );
		$first->sku = 'DUP-1';
		$first->regular_price = '';
		$first->image_id = 0;
		$first->category_ids = [];
		$second = self::fake_product( 22, 'simple' );
		$second->sku = 'DUP-1';
		WooRuntime::set_test_overrides(
			[
				'available'    => static fn(): bool => true,
				'get_products' => static fn( array $query ): array => [ $first, $second ],
			]
		);
		$result = ( new WcCatalogAudit() )->execute( [] );
		self::assertIsArray( $result );
		$codes = array_column( $result['issues'], 'code' );
		self::assertContains( 'duplicate_sku', $codes );
		self::assertContains( 'missing_regular_price', $codes );
		self::assertContains( 'missing_image', $codes );
		self::assertContains( 'missing_category', $codes );
	}

	public function test_catalog_audit_reports_variable_product_without_variations(): void {
		$product = self::fake_product( 23, 'variable' );
		WooRuntime::set_test_overrides(
			[
				'available'    => static fn(): bool => true,
				'get_products' => static fn( array $query ): array => [ $product ],
			]
		);
		$result = ( new WcCatalogAudit() )->execute( [] );
		self::assertIsArray( $result );
		self::assertContains( 'variable_without_variations', array_column( $result['issues'], 'code' ) );
	}

	private static function fake_product( int $id, string $type ): object {
		return new class( $id, $type ) {
			public int $save_count = 0;
			public int $delete_count = 0;
			public string $name = 'Product';
			public string $sku = 'SKU';
			public string $regular_price = '10';
			public string $status = 'publish';
			public int $image_id = 1;
			/** @var list<int> */
			public array $category_ids = [ 1 ];
			/** @var array<string, string> */
			public array $attributes = [];

			public function __construct( private int $id, private string $type ) {}
			public function get_id(): int { return $this->id; }
			public function get_type(): string { return $this->type; }
			public function get_name(): string { return $this->name; }
			public function set_name( string $name ): void { $this->name = $name; }
			public function get_slug(): string { return 'product'; }
			public function get_status(): string { return $this->status; }
			public function get_sku(): string { return $this->sku; }
			public function get_price(): string { return $this->regular_price; }
			public function get_regular_price(): string { return $this->regular_price; }
			public function get_sale_price(): string { return ''; }
			public function get_stock_status(): string { return 'instock'; }
			public function get_manage_stock(): bool { return false; }
			public function get_stock_quantity(): ?int { return null; }
			public function get_catalog_visibility(): string { return 'visible'; }
			public function get_permalink(): string { return 'https://example.com/product'; }
			public function get_parent_id(): int { return 0; }
			/** @return list<int> */
			public function get_category_ids(): array { return $this->category_ids; }
			/** @return list<int> */
			public function get_tag_ids(): array { return []; }
			public function get_image_id(): int { return $this->image_id; }
			/** @return list<int> */
			public function get_gallery_image_ids(): array { return []; }
			public function get_virtual(): bool { return false; }
			public function get_downloadable(): bool { return false; }
			public function get_featured(): bool { return false; }
			public function get_date_modified(): mixed { return null; }
			/** @return list<int> */
			public function get_children(): array { return []; }
			/** @return array<string, string> */
			public function get_attributes(): array { return $this->attributes; }
			public function save(): int { ++$this->save_count; return $this->id; }
			public function delete( bool $force ): void {
				++$this->delete_count;
				$this->status = $force ? 'deleted' : 'trash';
			}
		};
	}
}
