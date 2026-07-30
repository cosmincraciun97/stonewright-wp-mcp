<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcProductDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-product-delete';
	}

	public function label(): string {
		return __( 'WooCommerce: Delete product', 'stonewright' );
	}

	public function description(): string {
		return __( 'Previews by default and moves a product to trash unless force=true explicitly requests permanent deletion.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'force'              => [ 'type' => 'boolean', 'default' => false ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token' => [ 'type' => 'string' ],
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
			function ( array $args ): array|\WP_Error {
				if ( ! WooRuntime::available() ) {
					return [ 'supported' => false, 'hint' => 'WooCommerce is not active on this site.' ];
				}
				$product_id = (int) $args['id'];
				$product    = WooRuntime::get_product( $product_id );
				if ( ! is_object( $product ) ) {
					return new \WP_Error( 'stonewright_wc_product_not_found', 'WooCommerce product not found.', [ 'status' => 404 ] );
				}
				$force   = ! empty( $args['force'] );
				$dry_run = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'id'               => $product_id,
						'action'           => $force ? 'permanent_delete' : 'trash',
					];
				}
				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				if ( ! method_exists( $product, 'delete' ) ) {
					return new \WP_Error( 'stonewright_wc_product_delete_unavailable', 'WooCommerce product delete API is unavailable.' );
				}
				try {
					$product->delete( $force );
				} catch ( \Throwable ) {
					return new \WP_Error( 'stonewright_wc_product_delete_failed', 'WooCommerce rejected the product deletion.', [ 'status' => 400 ] );
				}
				$readback = WooRuntime::get_product( $product_id );
				$verified = $force
					? ! is_object( $readback )
					: is_object( $readback ) && method_exists( $readback, 'get_status' ) && 'trash' === $readback->get_status();
				if ( ! $verified ) {
					return new \WP_Error(
						'stonewright_wc_product_delete_readback_failed',
						'WooCommerce product deletion did not pass readback.',
						[ 'status' => 500, 'verification_status' => 'failed' ]
					);
				}
				return [
					'supported'           => true,
					'dry_run'             => false,
					'id'                  => $product_id,
					'deleted'             => $force,
					'trashed'             => ! $force,
					'execution_status'    => 'applied',
					'verification_status' => 'passed',
					'effect_verified'     => true,
				];
			}
		);
	}
}
