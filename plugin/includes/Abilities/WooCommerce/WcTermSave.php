<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\Taxonomies;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcTermSave extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-term-save';
	}

	public function label(): string {
		return __( 'WooCommerce: Save catalog term', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry-runs by default, then creates or updates a WooCommerce catalog or global attribute term and verifies readback.', 'stonewright' );
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
				'name'              => [ 'type' => 'string', 'minLength' => 1 ],
				'slug'              => [ 'type' => 'string' ],
				'description'       => [ 'type' => 'string' ],
				'parent'            => [ 'type' => 'integer', 'minimum' => 0 ],
				'dry_run'           => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token'=> [ 'type' => 'string' ],
			],
			'required'             => [ 'taxonomy', 'name' ],
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
				$term_id  = (int) ( $args['id'] ?? 0 );
				$existing = null;
				if ( $term_id > 0 ) {
					$existing = get_term( $term_id, $taxonomy );
					if ( ! $existing instanceof \WP_Term ) {
						return new \WP_Error( 'stonewright_wc_term_not_found', 'WooCommerce catalog term not found.', [ 'status' => 404 ] );
					}
				}
				$term_args = [];
				if ( array_key_exists( 'slug', $args ) ) {
					$term_args['slug'] = sanitize_title( (string) $args['slug'] );
				}
				if ( array_key_exists( 'description', $args ) ) {
					$term_args['description'] = sanitize_textarea_field( (string) $args['description'] );
				}
				if ( 'product_cat' === $taxonomy && array_key_exists( 'parent', $args ) ) {
					$term_args['parent'] = max( 0, (int) $args['parent'] );
				}
				$dry_run = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					$preview = [
						'id'          => $term_id,
						'taxonomy'    => $taxonomy,
						'name'        => $existing instanceof \WP_Term ? (string) $existing->name : '',
						'slug'        => $existing instanceof \WP_Term ? (string) $existing->slug : sanitize_title( (string) $args['name'] ),
						'description' => $existing instanceof \WP_Term ? (string) $existing->description : '',
						'parent'      => $existing instanceof \WP_Term ? (int) $existing->parent : 0,
					];
					$preview['name'] = sanitize_text_field( (string) $args['name'] );
					foreach ( $term_args as $field => $value ) {
						$preview[ $field ] = $value;
					}
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'term'             => $preview,
					];
				}
				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$result = $term_id > 0
					? wp_update_term( $term_id, $taxonomy, [ 'name' => sanitize_text_field( (string) $args['name'] ) ] + $term_args )
					: wp_insert_term( sanitize_text_field( (string) $args['name'] ), $taxonomy, $term_args );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				$saved_id = (int) ( $result['term_id'] ?? 0 );
				$readback = get_term( $saved_id, $taxonomy );
				if ( ! $readback instanceof \WP_Term ) {
					return new \WP_Error(
						'stonewright_wc_term_readback_failed',
						'WooCommerce catalog term save did not pass readback.',
						[
							'status'              => 500,
							'execution_status'    => 'partial',
							'verification_status' => 'failed',
							'effect_verified'     => false,
							'term_id'             => $saved_id,
						]
					);
				}
				$mismatches = [];
				$expected   = [ 'name' => sanitize_text_field( (string) $args['name'] ) ] + $term_args;
				foreach ( $expected as $field => $value ) {
					$received = match ( $field ) {
						'parent' => (int) $readback->parent,
						default  => (string) $readback->{$field},
					};
					if ( $value !== $received ) {
						$mismatches[] = $field;
					}
				}
				if ( [] !== $mismatches ) {
					return new \WP_Error(
						'stonewright_wc_term_readback_mismatch',
						'WooCommerce catalog term readback did not match every requested field.',
						[
							'status'              => 500,
							'execution_status'    => 'partial',
							'verification_status' => 'failed',
							'effect_verified'     => false,
							'term_id'             => $saved_id,
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
					'term'                => WcTermList::payload( $readback ),
				];
			}
		);
	}
}
