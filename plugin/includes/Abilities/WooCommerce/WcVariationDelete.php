<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcVariationDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-variation-delete';
	}

	public function label(): string {
		return __( 'WooCommerce: Delete variation', 'stonewright' );
	}

	public function description(): string {
		return __( 'Previews by default and deletes a variation only after parent ownership and confirmation gates pass.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'parent_id'          => [ 'type' => 'integer', 'minimum' => 1 ],
				'id'                 => [ 'type' => 'integer', 'minimum' => 1 ],
				'force'              => [ 'type' => 'boolean', 'default' => false ],
				'dry_run'            => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'parent_id', 'id' ],
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
				$variation_id = (int) $args['id'];
				$variation    = WooRuntime::get_product( $variation_id );
				if (
					! is_object( $variation )
					|| ! method_exists( $variation, 'get_type' )
					|| 'variation' !== (string) $variation->get_type()
				) {
					return new \WP_Error( 'stonewright_wc_variation_not_found', 'WooCommerce variation not found.', [ 'status' => 404 ] );
				}
				if (
					! method_exists( $variation, 'get_parent_id' )
					|| (int) $args['parent_id'] !== (int) $variation->get_parent_id()
				) {
					return new \WP_Error(
						'stonewright_wc_variation_parent_mismatch',
						'Variation does not belong to the requested parent.',
						[ 'status' => 409 ]
					);
				}
				$force   = ! empty( $args['force'] );
				$dry_run = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'id'               => $variation_id,
						'action'           => $force ? 'permanent_delete' : 'trash',
					];
				}
				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				if ( ! method_exists( $variation, 'delete' ) ) {
					return new \WP_Error( 'stonewright_wc_variation_delete_unavailable', 'WooCommerce variation delete API is unavailable.' );
				}
				try {
					$variation->delete( $force );
				} catch ( \Throwable ) {
					return new \WP_Error( 'stonewright_wc_variation_delete_failed', 'WooCommerce rejected the variation deletion.', [ 'status' => 400 ] );
				}
				$readback = WooRuntime::get_product( $variation_id );
				$verified = $force
					? ! is_object( $readback )
					: is_object( $readback ) && method_exists( $readback, 'get_status' ) && 'trash' === $readback->get_status();
				if ( ! $verified ) {
					return new \WP_Error(
						'stonewright_wc_variation_delete_readback_failed',
						'WooCommerce variation deletion did not pass readback.',
						[ 'status' => 500, 'verification_status' => 'failed' ]
					);
				}
				return [
					'supported'           => true,
					'dry_run'             => false,
					'id'                  => $variation_id,
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
