<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Taxonomies;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcTermDelete extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-term-delete';
	}

	public function label(): string {
		return __( 'WooCommerce: Delete catalog term', 'stonewright' );
	}

	public function description(): string {
		return __( 'Previews by default, then permanently deletes one allowlisted WooCommerce catalog or global attribute term.', 'stonewright' );
	}

	public function category(): string {
		return 'woocommerce';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'taxonomy'          => [ 'type' => 'string', 'pattern' => '^(product_cat|product_tag|product_shipping_class|pa_[a-z0-9_-]+)$' ],
				'id'                => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run'           => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token'=> [ 'type' => 'string' ],
			],
			'required'             => [ 'taxonomy', 'id' ],
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
				$taxonomy = (string) $args['taxonomy'];
				if ( ! Taxonomies::allowed( $taxonomy ) ) {
					return Taxonomies::error();
				}
				$term_id  = (int) $args['id'];
				$term     = get_term( $term_id, $taxonomy );
				if ( ! $term instanceof \WP_Term ) {
					return new \WP_Error( 'stonewright_wc_term_not_found', 'WooCommerce catalog term not found.', [ 'status' => 404 ] );
				}
				$dry_run = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'term'             => WcTermList::payload( $term ),
					];
				}
				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$result = wp_delete_term( $term_id, $taxonomy );
				if ( $result instanceof \WP_Error || false === $result ) {
					return $result instanceof \WP_Error
						? $result
						: new \WP_Error( 'stonewright_wc_term_delete_failed', 'WooCommerce catalog term deletion failed.' );
				}
				$readback = get_term( $term_id, $taxonomy );
				if ( $readback instanceof \WP_Term ) {
					return new \WP_Error(
						'stonewright_wc_term_delete_readback_failed',
						'WooCommerce catalog term deletion did not pass readback.',
						[ 'status' => 500, 'verification_status' => 'failed' ]
					);
				}
				return [
					'supported'           => true,
					'dry_run'             => false,
					'id'                  => $term_id,
					'deleted'             => true,
					'execution_status'    => 'applied',
					'verification_status' => 'passed',
					'effect_verified'     => true,
				];
			}
		);
	}
}
