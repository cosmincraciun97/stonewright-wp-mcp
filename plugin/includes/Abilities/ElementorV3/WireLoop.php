<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\Loop\LoopTransaction;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Plans or transactionally wires one native Elementor Pro loop widget.
 *
 * @stonewright-status stable
 */
final class WireLoop extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-wire-loop';
	}

	public function label(): string {
		return __( 'Wire Elementor loop', 'stonewright' );
	}

	public function description(): string {
		return __( 'Plan or transactionally add a native Elementor Pro Loop Carousel or Loop Grid using an existing loop-item template or a validated template spec.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		$id_list = [
			'type'     => 'array',
			'maxItems' => 100,
			'items'    => [ 'type' => 'integer', 'minimum' => 1 ],
		];

		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'             => [ 'type' => 'integer', 'minimum' => 1 ],
				'parent_id'           => [ 'type' => 'string', 'minLength' => 1 ],
				'display'             => [ 'type' => 'string', 'enum' => [ 'carousel', 'grid' ] ],
				'post_type'           => [ 'type' => 'string', 'minLength' => 1 ],
				'template_id'         => [ 'type' => 'integer', 'minimum' => 1 ],
				'template_spec'       => [ 'type' => 'object' ],
				'query'               => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'posts_per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
						'post__in'       => $id_list,
						'post__not_in'   => $id_list,
						'tax_query'      => [ 'type' => 'array' ],
						'meta_query'     => [ 'type' => 'array' ],
						'orderby'        => [ 'type' => 'string' ],
						'order'          => [ 'type' => 'string', 'enum' => [ 'ASC', 'DESC' ] ],
						'offset'         => [ 'type' => 'integer', 'minimum' => 0 ],
					],
				],
				'responsive'          => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'desktop' => [ 'type' => 'integer', 'minimum' => 1 ],
						'tablet'  => [ 'type' => 'integer', 'minimum' => 1 ],
						'mobile'  => [ 'type' => 'integer', 'minimum' => 1 ],
					],
				],
				'slides_to_scroll'     => [ 'type' => 'integer', 'minimum' => 1 ],
				'arrows'               => [ 'type' => 'boolean' ],
				'pagination'           => [ 'type' => 'boolean' ],
				'require_results'      => [ 'type' => 'boolean', 'default' => false ],
				'expected_tree_hash'   => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'idempotency_key'      => [ 'type' => 'string', 'minLength' => 8, 'maxLength' => 128 ],
				'dry_run'              => [ 'type' => 'boolean' ],
				'confirmation_token'   => [ 'type' => 'string' ],
			],
			'required'             => [ 'post_id', 'parent_id', 'display', 'post_type', 'idempotency_key', 'dry_run' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'status'              => [ 'type' => 'string' ],
				'post_id'             => [ 'type' => 'integer' ],
				'parent_id'           => [ 'type' => 'string' ],
				'template_id'         => [ 'type' => 'integer' ],
				'template_id_source'  => [ 'type' => 'string' ],
				'created_template'    => [ 'type' => 'boolean' ],
				'widget_id'           => [ 'type' => 'string' ],
				'widget_type'         => [ 'type' => 'string' ],
				'snapshot_id'         => [ 'type' => 'string' ],
				'before_hash'         => [ 'type' => 'string' ],
				'after_hash'          => [ 'type' => 'string' ],
				'readback_hash'       => [ 'type' => 'string' ],
				'schema_hash'         => [ 'type' => 'string' ],
				'query_probe'         => [ 'type' => 'object' ],
				'resolved_controls'   => [ 'type' => 'object' ],
				'resolved_settings'   => [ 'type' => 'object' ],
				'warnings'            => [ 'type' => 'array', 'items' => [] ],
				'diff'                => [ 'type' => 'object' ],
				'execution_status'    => [ 'type' => 'string' ],
				'verification_status' => [ 'type' => 'string' ],
				'rollback_status'     => [ 'type' => 'string' ],
				'effect_verified'     => [ 'type' => 'boolean' ],
				'idempotent_replay'   => [ 'type' => 'boolean' ],
				'learning'            => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
			'required'   => [ 'ok' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) ) ) {
			return false;
		}
		if ( (int) ( $args['template_id'] ?? 0 ) > 0 ) {
			return Permissions::edit_post( (int) $args['template_id'] );
		}
		return Permissions::can_create_post_type( 'elementor_library' )
			&& Permissions::can_publish_post_type( 'elementor_library' );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$has_id   = (int) ( $args['template_id'] ?? 0 ) > 0;
				$has_spec = is_array( $args['template_spec'] ?? null );
				if ( $has_id === $has_spec ) {
					return $this->error(
						'loop_template_source_invalid',
						__( 'Provide exactly one of template_id or template_spec.', 'stonewright' ),
						[ 'status' => 400 ]
					);
				}

				if ( empty( $args['dry_run'] ) && $has_spec ) {
					$verify_args = array_filter(
						$args,
						static fn( string $key ): bool => 'confirmation_token' !== $key,
						ARRAY_FILTER_USE_KEY
					);
					$token_error = $this->confirmation_token_error( $args, $verify_args );
					if ( null !== $token_error ) {
						return $token_error;
					}
				}

				return LoopTransaction::run( $args );
			}
		);
	}
}
