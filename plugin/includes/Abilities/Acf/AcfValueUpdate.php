<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Acf;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class AcfValueUpdate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/acf-value-update';
	}

	public function label(): string {
		return __( 'ACF: Update value', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates one ACF field on a post after snapshotting the post.', 'stonewright' );
	}

	public function category(): string {
		return 'acf';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'selector' ],
			'properties'           => [
				'post_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'selector' => [ 'type' => 'string', 'minLength' => 1 ],
				'value'    => [],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'required'             => [ 'post_id', 'selector', 'ok' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				if ( ! AcfRuntime::is_active() || ! function_exists( 'update_field' ) ) {
					return new \WP_Error(
						'stonewright_plugin_missing',
						__( 'ACF is not active on this site.', 'stonewright' ),
						[ 'status' => 409 ]
					);
				}
				$post_id  = (int) $args['post_id'];
				$selector = (string) $args['selector'];
				Backup::snapshot_post( $post_id );
				$ok       = update_field( $selector, $args['value'] ?? null, $post_id );
				$readback = function_exists( 'get_field' ) ? get_field( $selector, $post_id ) : null;
				return [
					'post_id'  => $post_id,
					'selector' => $selector,
					'ok'       => (bool) $ok,
					'value'    => $readback,
				];
			}
		);
	}
}
