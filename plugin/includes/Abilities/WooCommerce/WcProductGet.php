<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Catalog;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcProductGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/wc-product-get';
	}

	public function label(): string {
		return __( 'WooCommerce: Get product', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reads one WooCommerce product or variation through the native product object API.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'id' ],
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
				$product = WooRuntime::get_product( (int) $args['id'] );
				if ( ! is_object( $product ) ) {
					return new \WP_Error(
						'stonewright_wc_product_not_found',
						'WooCommerce product not found.',
						[ 'status' => 404 ]
					);
				}
				return [ 'supported' => true, 'product' => Catalog::product_payload( $product ) ];
			}
		);
	}
}
