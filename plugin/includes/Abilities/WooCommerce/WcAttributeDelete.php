<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcAttributeDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-attribute-delete';
	}

	public function label(): string {
		return __( 'WooCommerce: Delete global attribute', 'stonewright' );
	}

	public function description(): string {
		return __( 'Previews by default, then permanently deletes a WooCommerce global product attribute after confirmation.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'                => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run'           => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token'=> [ 'type' => 'string' ],
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
				if (
					! WooRuntime::available()
					|| ! function_exists( 'wc_delete_attribute' )
					|| ! function_exists( 'wc_get_attribute_taxonomies' )
				) {
					return [ 'supported' => false, 'hint' => 'WooCommerce global attribute APIs are unavailable.' ];
				}
				$attribute_id = (int) $args['id'];
				$exists       = false;
				foreach ( (array) WooRuntime::get_attribute_taxonomies() as $attribute ) {
					if ( is_object( $attribute ) && $attribute_id === (int) ( $attribute->attribute_id ?? 0 ) ) {
						$exists = true;
						break;
					}
				}
				if ( ! $exists ) {
					return new \WP_Error( 'stonewright_wc_attribute_not_found', 'WooCommerce global attribute not found.', [ 'status' => 404 ] );
				}
				$dry_run = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'id'               => $attribute_id,
					];
				}
				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$result = wc_delete_attribute( $attribute_id );
				if ( $result instanceof \WP_Error || false === $result ) {
					return $result instanceof \WP_Error
						? $result
						: new \WP_Error( 'stonewright_wc_attribute_delete_failed', 'WooCommerce global attribute deletion failed.' );
				}
				foreach ( (array) WooRuntime::get_attribute_taxonomies() as $attribute ) {
					if ( is_object( $attribute ) && $attribute_id === (int) ( $attribute->attribute_id ?? 0 ) ) {
						return new \WP_Error(
							'stonewright_wc_attribute_delete_readback_failed',
							'WooCommerce global attribute deletion did not pass readback.',
							[ 'status' => 500, 'verification_status' => 'failed' ]
						);
					}
				}
				return [
					'supported'           => true,
					'dry_run'             => false,
					'id'                  => $attribute_id,
					'deleted'             => true,
					'execution_status'    => 'applied',
					'verification_status' => 'passed',
					'effect_verified'     => true,
				];
			}
		);
	}
}
