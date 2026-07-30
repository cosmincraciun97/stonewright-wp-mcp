<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Catalog;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcVariationSave extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-variation-save';
	}

	public function label(): string {
		return __( 'WooCommerce: Save variation', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry-runs by default, then creates or updates a variation under a verified variable parent and reads it back.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		$string = [ 'type' => 'string' ];
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'parent_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'status'             => [ 'type' => 'string', 'enum' => [ 'draft', 'private', 'publish' ] ],
				'sku'                => $string,
				'regular_price'      => $string,
				'sale_price'         => $string,
				'stock_status'       => [ 'type' => 'string', 'enum' => [ 'instock', 'outofstock', 'onbackorder' ] ],
				'manage_stock'       => [ 'type' => 'boolean' ],
				'stock_quantity'     => [ 'type' => [ 'integer', 'null' ], 'minimum' => 0 ],
				'attributes'         => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ],
				'image_id'           => [ 'type' => 'integer', 'minimum' => 0 ],
				'virtual'            => [ 'type' => 'boolean' ],
				'downloadable'       => [ 'type' => 'boolean' ],
				'tax_status'         => [ 'type' => 'string', 'enum' => [ 'taxable', 'shipping', 'none' ] ],
				'tax_class'          => $string,
				'weight'             => $string,
				'length'             => $string,
				'width'              => $string,
				'height'             => $string,
				'dry_run'            => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'parent_id', 'attributes' ],
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

				$variation_id = (int) ( $args['id'] ?? 0 );
				$variation    = $variation_id > 0
					? WooRuntime::get_product( $variation_id )
					: WooRuntime::new_variation();
				if ( $variation instanceof \WP_Error ) {
					return $variation;
				}
				if ( ! is_object( $variation ) ) {
					return new \WP_Error( 'stonewright_wc_variation_not_found', 'WooCommerce variation not found.', [ 'status' => 404 ] );
				}
				if (
					$variation_id > 0
					&& method_exists( $variation, 'get_parent_id' )
					&& $parent_id !== (int) $variation->get_parent_id()
				) {
					return new \WP_Error(
						'stonewright_wc_variation_parent_mismatch',
						'Variation does not belong to the requested parent.',
						[ 'status' => 409 ]
					);
				}
				if ( method_exists( $variation, 'set_parent_id' ) ) {
					$variation->set_parent_id( $parent_id );
				}
				$apply_error = Catalog::apply_product_input( $variation, $args, true );
				if ( null !== $apply_error ) {
					return $apply_error;
				}

				$dry_run = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'variation'        => Catalog::product_payload( $variation ),
					];
				}
				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				if ( ! method_exists( $variation, 'save' ) ) {
					return new \WP_Error( 'stonewright_wc_variation_save_unavailable', 'WooCommerce variation save API is unavailable.' );
				}
				try {
					$saved_id = (int) $variation->save();
				} catch ( \Throwable ) {
					return new \WP_Error( 'stonewright_wc_variation_save_failed', 'WooCommerce rejected the variation save.', [ 'status' => 400 ] );
				}
				$readback = WooRuntime::get_product( $saved_id );
				if ( $saved_id < 1 || ! is_object( $readback ) ) {
					return new \WP_Error(
						'stonewright_wc_variation_readback_failed',
						'WooCommerce saved the variation but readback failed.',
						[
							'status'               => 500,
							'execution_status'     => 'partial',
							'verification_status'  => 'failed',
							'effect_verified'      => false,
							'variation_id'         => $saved_id,
						]
					);
				}
				$mismatches = Catalog::mismatch_fields( $readback, $args, true );
				if (
					method_exists( $readback, 'get_parent_id' )
					&& $parent_id !== (int) $readback->get_parent_id()
				) {
					$mismatches[] = 'parent_id';
				}
				$mismatches = array_values( array_unique( $mismatches ) );
				if ( [] !== $mismatches ) {
					return new \WP_Error(
						'stonewright_wc_variation_readback_mismatch',
						'WooCommerce variation readback did not match every requested field.',
						[
							'status'              => 500,
							'execution_status'    => 'partial',
							'verification_status' => 'failed',
							'effect_verified'     => false,
							'variation_id'        => $saved_id,
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
					'variation'           => Catalog::product_payload( $readback ),
				];
			}
		);
	}
}
