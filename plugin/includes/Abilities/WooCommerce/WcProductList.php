<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Catalog;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcProductList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-product-list';
	}

	public function label(): string {
		return __( 'WooCommerce: Products', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists and filters WooCommerce products with native pagination and compact catalog fields.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'search'       => [ 'type' => 'string' ],
				'status'       => [ 'type' => 'string', 'enum' => [ 'any', 'draft', 'pending', 'private', 'publish', 'trash' ] ],
				'type'         => [ 'type' => 'string', 'enum' => [ 'simple', 'variable', 'grouped', 'external', 'variation' ] ],
				'sku'          => [ 'type' => 'string' ],
				'category'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'tag'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'stock_status' => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
				'on_sale'      => [ 'type' => 'boolean' ],
				'featured'     => [ 'type' => 'boolean' ],
				'per_page'     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ],
				'page'         => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
				'orderby'      => [ 'type' => 'string', 'enum' => [ 'date', 'id', 'include', 'menu_order', 'modified', 'name', 'price', 'rand', 'title' ] ],
				'order'        => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ] ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_woocommerce();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ): array|\WP_Error {
				if ( ! WooRuntime::available() ) {
					return [ 'supported' => false, 'hint' => 'WooCommerce is not active on this site.' ];
				}
				$query = [
					'limit'    => min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) ),
					'page'     => max( 1, (int) ( $args['page'] ?? 1 ) ),
					'paginate' => true,
					'return'   => 'objects',
				];
				$mapping = [
					'search'       => 's',
					'status'       => 'status',
					'type'         => 'type',
					'sku'          => 'sku',
					'category'     => 'category',
					'tag'          => 'tag',
					'stock_status' => 'stock_status',
					'on_sale'      => 'on_sale',
					'featured'     => 'featured',
					'orderby'      => 'orderby',
					'order'        => 'order',
				];
				foreach ( $mapping as $input => $query_key ) {
					if ( array_key_exists( $input, $args ) ) {
						$query[ $query_key ] = $args[ $input ];
					}
				}
				$result   = WooRuntime::get_products( $query );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				if ( ! is_array( $result ) && ! is_object( $result ) ) {
					return new \WP_Error( 'stonewright_wc_product_query_failed', 'WooCommerce product query failed.', [ 'status' => 500 ] );
				}
				$products = is_object( $result ) && isset( $result->products )
					? (array) $result->products
					: ( is_array( $result ) ? $result : [] );
				$items = [];
				foreach ( $products as $product ) {
					if ( is_object( $product ) ) {
						$items[] = Catalog::product_summary( $product );
					}
				}
				return [
					'supported'   => true,
					'items'       => $items,
					'count'       => count( $items ),
					'total'       => is_object( $result ) && isset( $result->total ) ? (int) $result->total : count( $items ),
					'total_pages' => is_object( $result ) && isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1,
					'page'        => (int) $query['page'],
				];
			}
		);
	}
}
