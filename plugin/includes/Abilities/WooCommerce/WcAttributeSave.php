<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WooCommerce;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\WooCommerce\WooRuntime;

/** @stonewright-status stable */
final class WcAttributeSave extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wc-attribute-save';
	}

	public function label(): string {
		return __( 'WooCommerce: Save global attribute', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry-runs by default, then creates or updates a WooCommerce global product attribute and verifies readback.', 'stonewright' );
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
				'name'              => [ 'type' => 'string', 'minLength' => 1 ],
				'slug'              => [ 'type' => 'string' ],
				'type'              => [ 'type' => 'string', 'enum' => [ 'select', 'text' ], 'default' => 'select' ],
				'order_by'          => [ 'type' => 'string', 'enum' => [ 'menu_order', 'name', 'name_num', 'id' ], 'default' => 'menu_order' ],
				'has_archives'      => [ 'type' => 'boolean', 'default' => false ],
				'dry_run'           => [ 'type' => 'boolean', 'default' => true ],
				'confirmation_token'=> [ 'type' => 'string' ],
			],
			'required'             => [ 'name' ],
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
					|| ! function_exists( 'wc_create_attribute' )
					|| ! function_exists( 'wc_update_attribute' )
					|| ! function_exists( 'wc_get_attribute_taxonomies' )
				) {
					return [ 'supported' => false, 'hint' => 'WooCommerce global attribute APIs are unavailable.' ];
				}
				$attribute_id = (int) ( $args['id'] ?? 0 );
				$existing     = self::find_attribute( $attribute_id );
				if ( $attribute_id > 0 && ! is_object( $existing ) ) {
					return new \WP_Error(
						'stonewright_wc_attribute_not_found',
						'WooCommerce global attribute not found.',
						[ 'status' => 404 ]
					);
				}
				$payload = [ 'name' => sanitize_text_field( (string) $args['name'] ) ];
				if ( array_key_exists( 'slug', $args ) ) {
					$payload['slug'] = sanitize_title( (string) $args['slug'] );
				}
				if ( array_key_exists( 'type', $args ) || 0 === $attribute_id ) {
					$payload['type'] = sanitize_key( (string) ( $args['type'] ?? 'select' ) );
				}
				if ( array_key_exists( 'order_by', $args ) || 0 === $attribute_id ) {
					$payload['order_by'] = sanitize_key( (string) ( $args['order_by'] ?? 'menu_order' ) );
				}
				if ( array_key_exists( 'has_archives', $args ) || 0 === $attribute_id ) {
					$payload['has_archives'] = ! empty( $args['has_archives'] );
				}
				$dry_run      = ! isset( $args['dry_run'] ) || true === $args['dry_run'];
				if ( $dry_run ) {
					$preview = [
						'id'           => $attribute_id,
						'name'         => is_object( $existing ) ? (string) ( $existing->attribute_label ?? '' ) : '',
						'slug'         => is_object( $existing ) ? (string) ( $existing->attribute_name ?? '' ) : sanitize_title( (string) $args['name'] ),
						'type'         => is_object( $existing ) ? (string) ( $existing->attribute_type ?? 'select' ) : 'select',
						'order_by'     => is_object( $existing ) ? (string) ( $existing->attribute_orderby ?? 'menu_order' ) : 'menu_order',
						'has_archives' => is_object( $existing ) && ! empty( $existing->attribute_public ),
					];
					foreach ( $payload as $field => $value ) {
						$preview[ $field ] = $value;
					}
					return [
						'supported'        => true,
						'dry_run'          => true,
						'execution_status' => 'preview',
						'attribute'        => $preview,
					];
				}
				$verify_args = $args;
				unset( $verify_args['confirmation_token'] );
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}
				$result = $attribute_id > 0
					? wc_update_attribute( $attribute_id, $payload )
					: wc_create_attribute( $payload );
				if ( $result instanceof \WP_Error ) {
					return $result;
				}
				$saved_id = $attribute_id > 0 ? $attribute_id : (int) $result;
				$found = self::find_attribute( $saved_id );
				if ( ! is_object( $found ) ) {
					return new \WP_Error(
						'stonewright_wc_attribute_readback_failed',
						'WooCommerce global attribute save did not pass readback.',
						[
							'status'              => 500,
							'execution_status'    => 'partial',
							'verification_status' => 'failed',
							'effect_verified'     => false,
							'attribute_id'        => $saved_id,
						]
					);
				}
				$mismatches = [];
				$field_map  = [
					'name'         => (string) ( $found->attribute_label ?? '' ),
					'slug'         => (string) ( $found->attribute_name ?? '' ),
					'type'         => (string) ( $found->attribute_type ?? '' ),
					'order_by'     => (string) ( $found->attribute_orderby ?? '' ),
					'has_archives' => ! empty( $found->attribute_public ),
				];
				foreach ( $payload as $field => $expected ) {
					if ( $expected !== $field_map[ $field ] ) {
						$mismatches[] = $field;
					}
				}
				if ( [] !== $mismatches ) {
					return new \WP_Error(
						'stonewright_wc_attribute_readback_mismatch',
						'WooCommerce global attribute readback did not match every requested field.',
						[
							'status'              => 500,
							'execution_status'    => 'partial',
							'verification_status' => 'failed',
							'effect_verified'     => false,
							'attribute_id'        => $saved_id,
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
					'attribute'           => [
						'id'           => $saved_id,
						'name'         => (string) ( $found->attribute_label ?? '' ),
						'slug'         => (string) ( $found->attribute_name ?? '' ),
						'type'         => (string) ( $found->attribute_type ?? '' ),
						'order_by'     => (string) ( $found->attribute_orderby ?? '' ),
						'has_archives' => ! empty( $found->attribute_public ),
					],
				];
			}
		);
	}

	private static function find_attribute( int $attribute_id ): ?object {
		if ( $attribute_id < 1 ) {
			return null;
		}
		foreach ( (array) WooRuntime::get_attribute_taxonomies() as $attribute ) {
			if ( is_object( $attribute ) && $attribute_id === (int) ( $attribute->attribute_id ?? 0 ) ) {
				return $attribute;
			}
		}
		return null;
	}
}
