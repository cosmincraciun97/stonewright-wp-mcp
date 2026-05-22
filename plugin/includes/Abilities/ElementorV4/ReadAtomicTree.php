<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
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

	/** @var list<string> Known atomic elType values. */
	private const ATOMIC_EL_TYPES = [ 'e-element', 'e-flexbox' ];

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
				'non_atomic_count' => [ 'type' => 'integer' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! get_option( 'stonewright_elementor_v4_atomic', false ) ) {
			return new \WP_Error( 'feature_disabled', __( 'Elementor V4 atomic features are disabled.', 'stonewright' ) );
		}
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

				$tree           = ElementorData::read( $post_id );
				$atomic         = [];
				$non_atomic_count = 0;

				foreach ( $tree as $element ) {
					if ( $this->is_atomic( (array) $element ) ) {
						$atomic[] = $element;
					} else {
						++$non_atomic_count;
					}
				}

				return [
					'atomic_tree'      => $atomic,
					'non_atomic_count' => $non_atomic_count,
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $element
	 */
	private function is_atomic( array $element ): bool {
		$el_type    = (string) ( $element['elType'] ?? '' );
		$widget_type = (string) ( $element['widgetType'] ?? '' );

		if ( in_array( $el_type, self::ATOMIC_EL_TYPES, true ) ) {
			return true;
		}
		// Atomic widgets use an 'e-' prefix by convention.
		if ( str_starts_with( $widget_type, 'e-' ) ) {
			return true;
		}
		return false;
	}
}
