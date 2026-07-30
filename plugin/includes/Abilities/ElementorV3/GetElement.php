<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetElement extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-get-element';
	}

	public function label(): string {
		return __( 'Get Elementor element', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns a single element from an Elementor page by element id.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'element_id' => [ 'type' => 'string' ],
			],
			'required'             => [ 'post_id', 'element_id' ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		$post_id = (int) $args['post_id'];
		$tree    = ElementorData::read( $post_id );
		$path    = ElementorData::find_path( $tree, (string) $args['element_id'] );
		if ( null === $path ) {
			return $this->error( 'not_found', __( 'Element not found in page data.', 'stonewright' ) );
		}

		$element = $tree;
		foreach ( $path as $index ) {
			$element = $element[ $index ];
			if ( ! empty( $element['elements'] ) && $index !== end( $path ) ) {
				$element = $element['elements'];
			}
		}

		// Walk again cleanly to fetch a final reference.
		$current = $tree;
		foreach ( $path as $pos => $index ) {
			$current = $current[ $index ];
			if ( $pos !== array_key_last( $path ) ) {
				$current = $current['elements'] ?? [];
			}
		}

		return [
			'post_id' => $post_id,
			'path'    => $path,
			'element' => $current,
		];
	}
}
