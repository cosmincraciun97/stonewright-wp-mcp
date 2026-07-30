<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Catalog;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcProductSave extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-product-save';
	}

	public function label(): string {
		return __( 'WooCommerce: Save product', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry-runs by default, then creates or updates an allowlisted WooCommerce product through native product objects and verifies readback.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		$string = [ 'type' => 'string' ];
		$ids    = [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'uniqueItems' => true ];
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'type'               => [ 'type' => 'string', 'enum' => [ 'simple', 'variable', 'grouped', 'external' ], 'default' => 'simple' ],
				'name'               => $string,
				'slug'               => $string,
				'status'             => [ 'type' => 'string', 'enum' => [ 'draft', 'pending', 'private', 'publish' ] ],
				'description'        => $string,
				'short_description'  => $string,
				'sku'                => $string,
				'regular_price'      => $string,
				'sale_price'         => $string,
				'stock_status'       => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
				'manage_stock'       => [ 'type' => 'boolean' ],
				'stock_quantity'     => [ 'type' => [ 'integer', 'null' ], 'minimum' => 0 ],
				'catalog_visibility' => [ 'type' => 'string', 'enum' => [ 'visible', 'catalog', 'search', 'hidden' ] ],
				'category_ids'       => $ids,
				'tag_ids'            => $ids,
				'image_id'           => [ 'type' => 'integer', 'minimum' => 0 ],
				'gallery_image_ids'  => $ids,
				'children'           => $ids,
				'external_url'       => [ 'type' => 'string', 'format' => 'uri' ],
				'button_text'        => $string,
				'attributes'         => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'        => [ 'type' => 'integer', 'minimum' => 0 ],
							'name'      => $string,
							'options'   => [ 'type' => 'array', 'items' => [ 'type' => [ 'string', 'integer' ] ] ],
							'position'  => [ 'type' => 'integer', 'minimum' => 0 ],
							'visible'   => [ 'type' => 'boolean', 'default' => true ],
							'variation' => [ 'type' => 'boolean', 'default' => false ],
						],
						'required'             => [ 'name', 'options' ],
					],
				],
				'default_attributes' => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ],
				'virtual'            => [ 'type' => 'boolean' ],
				'downloadable'       => [ 'type' => 'boolean' ],
				'featured'           => [ 'type' => 'boolean' ],
				'sold_individually'  => [ 'type' => 'boolean' ],
				'tax_status'         => [ 'type' => 'string', 'enum' => [ 'taxable', 'shipping', 'none' ] ],
				'tax_class'          => $string,
				'weight'             => $string,
				'length'             => $string,
				'width'              => $string,
				'height'             => $string,
				'purchase_note'      => $string,
				'menu_order'         => [ 'type' => 'integer' ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'anyOf'                => [
				[ 'required' => [ 'id' ] ],
				[ 'required' => [ 'name' ] ],
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
			function ( array $args ): array|\WP_Error {
				if ( ! WooRuntime::available() ) {
					return [ 'supported' => false, 'hint' => 'WooCommerce is not active on this site.' ];
				}

				$product_id = (int) ( $args['id'] ?? 0 );
				$product    = $product_id > 0
					? WooRuntime::get_product( $product_id )
					: WooRuntime::new_product( sanitize_key( (string) ( $args['type'] ?? 'simple' ) ) );
				if ( $product instanceof \WP_Error ) {
					return $product;
				}
				if ( ! is_object( $product ) ) {
					return new \WP_Error( 'stonewright_wc_product_not_found', 'WooCommerce product not found.', [ 'status' => 404 ] );
				}
				if (
					$product_id > 0
					&& isset( $args['type'] )
					&& method_exists( $product, 'get_type' )
					&& (string) $product->get_type() !== (string) $args['type']
				) {
					return new \WP_Error(
						'stonewright_wc_product_type_change_blocked',
						'Changing an existing product type is blocked; create the intended type or use WooCommerce migration tooling.',
						[ 'status' => 400 ]
					);
				}

				$apply_error = Catalog::apply_product_input( $product, $args );
				if ( null !== $apply_error ) {
					return $apply_error;
				}

				$dry_run = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'product'          => Catalog::product_payload( $product ),
					];
				}

				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				if ( ! method_exists( $product, 'save' ) ) {
					return new \WP_Error( 'stonewright_wc_product_save_unavailable', 'WooCommerce product save API is unavailable.' );
				}
				try {
					$saved_id = (int) $product->save();
				} catch ( \Throwable ) {
					return new \WP_Error( 'stonewright_wc_product_save_failed', 'WooCommerce rejected the product save.', [ 'status' => 400 ] );
				}
				$readback = WooRuntime::get_product( $saved_id );
				if ( $saved_id < 1 || ! is_object( $readback ) ) {
					return new \WP_Error(
						'stonewright_wc_product_readback_failed',
						'WooCommerce saved the product but readback failed.',
						[
							'status'              => 500,
							'execution_status'    => 'partial',
							'verification_status' => 'failed',
							'effect_verified'     => false,
							'product_id'          => $saved_id,
						]
					);
				}
				$mismatches = Catalog::mismatch_fields( $readback, $args );
				if ( [] !== $mismatches ) {
					return new \WP_Error(
						'stonewright_wc_product_readback_mismatch',
						'WooCommerce product readback did not match every requested field.',
						[
							'status'              => 500,
							'execution_status'    => 'partial',
							'verification_status' => 'failed',
							'effect_verified'     => false,
							'product_id'          => $saved_id,
							'mismatch_fields'     => $mismatches,
						]
					);
				}
				return [
					'supported'           => true,
					'dry_run'             => false,
					'execution_status'    => 'applied',
					'verification_status' => 'passed',
					'effect_verified'     => true,
					'product'             => Catalog::product_payload( $readback ),
				];
			}
		);
	}
}
