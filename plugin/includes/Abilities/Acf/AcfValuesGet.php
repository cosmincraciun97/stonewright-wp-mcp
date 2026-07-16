<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfValuesGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/acf-values-get';
	}

	public function label(): string {
		return __( 'ACF: Get values', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reads all ACF field values for a post as { field_name: value }.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'format_value' => [ 'type' => 'boolean', 'default' => true ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'post_id' => [ 'type' => 'integer' ],
				'fields'  => [ 'type' => 'object' ],
			],
			'required'             => [ 'post_id', 'fields' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				if ( ! AcfRuntime::is_active() || ! function_exists( 'get_fields' ) ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}
				$post_id = (int) $args['post_id'];
				$fields  = get_fields( $post_id, (bool) ( $args['format_value'] ?? true ) );
				return [
					'post_id' => $post_id,
					'fields'  => is_array( $fields ) ? $fields : [],
				];
			}
		);
	}
}
