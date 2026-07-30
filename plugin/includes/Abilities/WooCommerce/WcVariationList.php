<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Catalog;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcVariationList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-variation-list';
	}

	public function label(): string {
		return __( 'WooCommerce: Variations', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists a variable product parent and its variation objects with prices, stock, and attributes.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'parent_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ],
				'page'      => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			],
			'required'             => [ 'parent_id' ],
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
				$parent_id = (int) $args['parent_id'];
				$parent    = WooRuntime::get_product( $parent_id );
				if (
					! is_object( $parent )
					|| ! method_exists( $parent, 'get_type' )
					|| 'variable' !== (string) $parent->get_type()
				) {
					return new \WP_Error(
						'stonewright_wc_variable_parent_required',
						'A variable WooCommerce parent product is required.',
						[ 'status' => 400 ]
					);
				}
				$products = WooRuntime::get_products(
					[
						'type'   => 'variation',
						'parent' => $parent_id,
						'limit'  => min( 100, max( 1, (int) ( $args['per_page'] ?? 50 ) ) ),
						'page'   => max( 1, (int) ( $args['page'] ?? 1 ) ),
						'return' => 'objects',
					]
				);
				if ( $products instanceof \WP_Error ) {
					return $products;
				}
				if ( ! is_array( $products ) ) {
					return new \WP_Error( 'stonewright_wc_variation_query_failed', 'WooCommerce variation query failed.', [ 'status' => 500 ] );
				}
				$items = [];
				foreach ( $products as $product ) {
					if ( is_object( $product ) ) {
						$items[] = Catalog::product_payload( $product );
					}
				}
				return [
					'supported' => true,
					'parent'    => Catalog::product_payload( $parent ),
					'items'     => $items,
					'count'     => count( $items ),
				];
			}
		);
	}
}
