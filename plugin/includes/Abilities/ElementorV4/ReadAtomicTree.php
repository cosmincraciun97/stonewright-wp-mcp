<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicTreeInspector;
use Stonewright\WpMcp\Elementor\V4\V4FeatureGate;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Reads _elementor_data for a post and returns only atomic-aware elements.
 * An element is considered atomic if its elType is 'e-element' or 'e-flexbox',
 * or its widgetType begins with 'e-' (the V4 atomic widget prefix convention).
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status experimental
 */
final class ReadAtomicTree extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-read-atomic-tree';
	}

	public function label(): string {
		return __( 'Read Elementor V4 atomic tree', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the atomic-aware subset of _elementor_data for a post, plus a count of non-atomic elements that were filtered out.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'post_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'atomic_tree'      => [ 'type' => 'array' ],
				'atomic_count'     => [ 'type' => 'integer' ],
				'non_atomic_count' => [ 'type' => 'integer' ],
				'unknown_atomic'   => [ 'type' => 'array' ],
				'architecture'     => [ 'type' => 'string', 'enum' => [ 'empty', 'v3', 'v4', 'mixed' ] ],
				'schema_fingerprint'  => [ 'type' => 'string' ],
				'implicit_conversion' => [ 'type' => 'boolean' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$gate = V4FeatureGate::check();
		if ( is_wp_error( $gate ) ) {
return $gate; }
		$post_id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $post_id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_id = (int) $args['post_id'];
				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ) );
				}

				return AtomicTreeInspector::inspect( ElementorData::read( $post_id ) );
			}
		);
	}
}
