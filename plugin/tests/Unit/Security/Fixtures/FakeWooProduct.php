<?php
/**
 * A stand-in for a WooCommerce product or variation, with the getters and setters the catalog mapping uses.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security\Fixtures;

/**
 * Saved products live in self::$store, which a test hands to WooRuntime through its overrides. delete( false )
 * moves a product to the trash status; delete( true ) removes it.
 */
final class FakeWooProduct {

	/** @var array<int, FakeWooProduct> */
	public static array $store = [];

	public static int $next_id = 300;

	public int $save_count = 0;

	/** @var array<string, mixed> */
	public array $data = [
		'name'               => '',
		'slug'               => '',
		'status'             => 'publish',
		'sku'                => '',
		'regular_price'      => '',
		'sale_price'         => '',
		'stock_status'       => 'instock',
		'manage_stock'       => false,
		'stock_quantity'     => null,
		'catalog_visibility' => 'visible',
		'tax_status'         => 'taxable',
		'tax_class'          => '',
		'weight'             => '',
		'length'             => '',
		'width'              => '',
		'height'             => '',
		'purchase_note'      => '',
		'description'        => '',
		'short_description'  => '',
		'sold_individually'  => false,
		'virtual'            => false,
		'downloadable'       => false,
		'featured'           => false,
		'menu_order'         => 0,
		'image_id'           => 0,
		'category_ids'       => [],
		'tag_ids'            => [],
		'gallery_image_ids'  => [],
		'children'           => [],
		'attributes'         => [],
		'default_attributes' => [],
		'parent_id'          => 0,
		'product_url'        => '',
		'button_text'        => '',
	];

	public function __construct( private int $id, private string $type = 'simple' ) {
	}

	public static function reset(): void {
		self::$store   = [];
		self::$next_id = 300;
	}

	public static function add( int $id, string $type = 'simple', array $data = [] ): self {
		$product                   = new self( $id, $type );
		$product->data             = array_merge( $product->data, $data );
		self::$store[ $id ]        = $product;
		return $product;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function get_name(): string {
		return (string) $this->data['name'];
	}

	public function set_name( string $value ): void {
		$this->data['name'] = $value;
	}

	public function get_slug(): string {
		return (string) $this->data['slug'];
	}

	public function set_slug( string $value ): void {
		$this->data['slug'] = $value;
	}

	public function get_status(): string {
		return (string) $this->data['status'];
	}

	public function set_status( string $value ): void {
		$this->data['status'] = $value;
	}

	public function get_sku(): string {
		return (string) $this->data['sku'];
	}

	public function set_sku( string $value ): void {
		$this->data['sku'] = $value;
	}

	public function get_price(): string {
		return '' !== $this->data['sale_price'] ? (string) $this->data['sale_price'] : (string) $this->data['regular_price'];
	}

	public function get_regular_price(): string {
		return (string) $this->data['regular_price'];
	}

	public function set_regular_price( string $value ): void {
		$this->data['regular_price'] = $value;
	}

	public function get_sale_price(): string {
		return (string) $this->data['sale_price'];
	}

	public function set_sale_price( string $value ): void {
		$this->data['sale_price'] = $value;
	}

	public function get_stock_status(): string {
		return (string) $this->data['stock_status'];
	}

	public function set_stock_status( string $value ): void {
		$this->data['stock_status'] = $value;
	}

	public function get_manage_stock(): bool {
		return (bool) $this->data['manage_stock'];
	}

	public function set_manage_stock( bool $value ): void {
		$this->data['manage_stock'] = $value;
	}

	public function get_stock_quantity(): ?int {
		return null === $this->data['stock_quantity'] ? null : (int) $this->data['stock_quantity'];
	}

	public function set_stock_quantity( ?int $value ): void {
		$this->data['stock_quantity'] = $value;
	}

	public function get_catalog_visibility(): string {
		return (string) $this->data['catalog_visibility'];
	}

	public function set_catalog_visibility( string $value ): void {
		$this->data['catalog_visibility'] = $value;
	}

	public function get_tax_status(): string {
		return (string) $this->data['tax_status'];
	}

	public function set_tax_status( string $value ): void {
		$this->data['tax_status'] = $value;
	}

	public function get_tax_class(): string {
		return (string) $this->data['tax_class'];
	}

	public function set_tax_class( string $value ): void {
		$this->data['tax_class'] = $value;
	}

	public function get_weight(): string {
		return (string) $this->data['weight'];
	}

	public function set_weight( string $value ): void {
		$this->data['weight'] = $value;
	}

	public function get_length(): string {
		return (string) $this->data['length'];
	}

	public function set_length( string $value ): void {
		$this->data['length'] = $value;
	}

	public function get_width(): string {
		return (string) $this->data['width'];
	}

	public function set_width( string $value ): void {
		$this->data['width'] = $value;
	}

	public function get_height(): string {
		return (string) $this->data['height'];
	}

	public function set_height( string $value ): void {
		$this->data['height'] = $value;
	}

	public function get_purchase_note(): string {
		return (string) $this->data['purchase_note'];
	}

	public function set_purchase_note( string $value ): void {
		$this->data['purchase_note'] = $value;
	}

	public function get_description(): string {
		return (string) $this->data['description'];
	}

	public function set_description( string $value ): void {
		$this->data['description'] = $value;
	}

	public function get_short_description(): string {
		return (string) $this->data['short_description'];
	}

	public function set_short_description( string $value ): void {
		$this->data['short_description'] = $value;
	}

	public function get_sold_individually(): bool {
		return (bool) $this->data['sold_individually'];
	}

	public function set_sold_individually( bool $value ): void {
		$this->data['sold_individually'] = $value;
	}

	public function get_virtual(): bool {
		return (bool) $this->data['virtual'];
	}

	public function set_virtual( bool $value ): void {
		$this->data['virtual'] = $value;
	}

	public function get_downloadable(): bool {
		return (bool) $this->data['downloadable'];
	}

	public function set_downloadable( bool $value ): void {
		$this->data['downloadable'] = $value;
	}

	public function get_featured(): bool {
		return (bool) $this->data['featured'];
	}

	public function set_featured( bool $value ): void {
		$this->data['featured'] = $value;
	}

	public function get_menu_order(): int {
		return (int) $this->data['menu_order'];
	}

	public function set_menu_order( int $value ): void {
		$this->data['menu_order'] = $value;
	}

	public function get_image_id(): int {
		return (int) $this->data['image_id'];
	}

	public function set_image_id( int $value ): void {
		$this->data['image_id'] = $value;
	}

	/** @return list<int> */
	public function get_category_ids(): array {
		return $this->data['category_ids'];
	}

	/** @param list<int> $value */
	public function set_category_ids( array $value ): void {
		$this->data['category_ids'] = $value;
	}

	/** @return list<int> */
	public function get_tag_ids(): array {
		return $this->data['tag_ids'];
	}

	/** @param list<int> $value */
	public function set_tag_ids( array $value ): void {
		$this->data['tag_ids'] = $value;
	}

	/** @return list<int> */
	public function get_gallery_image_ids(): array {
		return $this->data['gallery_image_ids'];
	}

	/** @param list<int> $value */
	public function set_gallery_image_ids( array $value ): void {
		$this->data['gallery_image_ids'] = $value;
	}

	/** @return list<int> */
	public function get_children(): array {
		return $this->data['children'];
	}

	/** @param list<int> $value */
	public function set_children( array $value ): void {
		$this->data['children'] = $value;
	}

	/** @return array<mixed> */
	public function get_attributes(): array {
		return $this->data['attributes'];
	}

	/** @param array<mixed> $value */
	public function set_attributes( array $value ): void {
		$this->data['attributes'] = $value;
	}

	/** @return array<string, string> */
	public function get_default_attributes(): array {
		return $this->data['default_attributes'];
	}

	/** @param array<string, string> $value */
	public function set_default_attributes( array $value ): void {
		$this->data['default_attributes'] = $value;
	}

	public function get_parent_id(): int {
		return (int) $this->data['parent_id'];
	}

	public function set_parent_id( int $value ): void {
		$this->data['parent_id'] = $value;
	}

	public function get_permalink(): string {
		return 'https://example.test/?product=' . $this->id;
	}

	public function get_date_modified(): mixed {
		return null;
	}

	public function get_product_url(): string {
		return (string) $this->data['product_url'];
	}

	public function get_button_text(): string {
		return (string) $this->data['button_text'];
	}

	public function save(): int {
		++$this->save_count;
		if ( $this->id < 1 ) {
			$this->id = self::$next_id++;
		}
		self::$store[ $this->id ] = $this;
		return $this->id;
	}

	public function delete( bool $force = false ): bool {
		if ( $force ) {
			unset( self::$store[ $this->id ] );
			return true;
		}
		$this->data['status']     = 'trash';
		self::$store[ $this->id ] = $this;
		return true;
	}
}
