<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class MoveElement extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-move-element';
	}

	public function label(): string {
		return __( 'Move Elementor element', 'stonewright' );
	}

	public function description(): string {
		return __( 'Moves an element to a new parent and position within the same page.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id'    => [ 'type' => 'string' ],
				'new_parent_id' => [ 'type' => 'string' ],
				'position'      => [ 'type' => 'integer' ],
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
				$snapshot_id = Backup::snapshot_post( $post_id );
				$tree        = ElementorData::read( $post_id );

				$src_path = ElementorData::find_path( $tree, (string) $args['element_id'] );
				if ( null === $src_path ) {
					return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ) );
				}

				$element = $this->resolve( $tree, $src_path );
				if ( null === $element ) {
					return $this->error( 'element_not_found', __( 'Element not found.', 'stonewright' ) );
				}

				$tree = ElementorData::set( $tree, $src_path, null );

				$parent_path = [];
				if ( ! empty( $args['new_parent_id'] ) ) {
					$parent_path = ElementorData::find_path( $tree, (string) $args['new_parent_id'] );
					if ( null === $parent_path ) {
						return $this->error( 'parent_not_found', __( 'New parent not found.', 'stonewright' ) );
					}
				}

				$position = isset( $args['position'] ) ? (int) $args['position'] : PHP_INT_MAX;
				$tree     = ElementorData::insert( $tree, $parent_path, $position, $element );

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

	private function resolve( array $tree, array $path ): ?array {
		$current = null;
		foreach ( $path as $index ) {
			if ( ! isset( $tree[ $index ] ) ) {
				return null;
			}
			$current = $tree[ $index ];
			$tree    = isset( $current['elements'] ) && is_array( $current['elements'] ) ? $current['elements'] : [];
		}
		return $current;
	}
}
