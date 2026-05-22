<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class RemoveElement extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-remove-element';
	}

	public function label(): string {
		return __( 'Remove Elementor element', 'stonewright' );
	}

	public function description(): string {
		return __( 'Removes an element from an Elementor page by id. Snapshots before write.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id'         => [ 'type' => 'string' ],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'post_id', 'element_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'     => [ 'type' => 'integer' ],
				'snapshot_id' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id     = (int) $args['post_id'];
				$verify_args = [
					'post_id'    => $post_id,
					'element_id' => (string) $args['element_id'],
				];

				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$snapshot_id = Backup::snapshot_post( $post_id );
				$tree        = ElementorData::read( $post_id );

				$path = ElementorData::find_path( $tree, (string) $args['element_id'] );
				if ( null === $path ) {
					return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ) );
				}

				$tree = ElementorData::set( $tree, $path, null );
				if ( ! ElementorData::write( $post_id, $tree ) ) {
					return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
				}

				return [
					'post_id'     => $post_id,
					'snapshot_id' => $snapshot_id,
				];
			}
		);
	}
}
