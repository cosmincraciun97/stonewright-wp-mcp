<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ChooseRenderer extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-choose-renderer';
	}

	public function label(): string {
		return __( 'Choose renderer', 'stonewright' );
	}

	public function description(): string {
		return __( 'Picks the best target renderer (gutenberg, elementor_v3, elementor_v4) based on site state and spec hints.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'              => [ 'type' => 'object' ],
				'prefer'            => [ 'type' => 'string', 'enum' => [ 'auto', 'gutenberg', 'elementor_v3', 'elementor_v4' ], 'default' => 'auto' ],
				'post_id'           => [ 'type' => 'integer' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'renderer' => [ 'type' => 'string' ],
				'reason'   => [ 'type' => 'string' ],
				'options'  => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$prefer  = isset( $args['prefer'] ) ? (string) $args['prefer'] : 'auto';
		$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		$has_elementor    = defined( 'ELEMENTOR_VERSION' );
		$has_elementor_v4 = (bool) get_option( 'stonewright_elementor_v4_atomic', false ) && $has_elementor;
		$post_uses_elementor = $post_id > 0 && 'builder' === (string) get_post_meta( $post_id, '_elementor_edit_mode', true );

		$options = array_filter(
			[
				'gutenberg',
				$has_elementor ? 'elementor_v3' : null,
				$has_elementor_v4 ? 'elementor_v4' : null,
			]
		);

		if ( 'auto' !== $prefer ) {
			if ( ! in_array( $prefer, $options, true ) ) {
				return $this->error( 'renderer_unavailable', __( 'Requested renderer is not available on this site.', 'stonewright' ) );
			}
			return [ 'renderer' => $prefer, 'reason' => 'explicit', 'options' => array_values( $options ) ];
		}

		if ( $post_uses_elementor ) {
			return [ 'renderer' => 'elementor_v3', 'reason' => 'post already uses Elementor', 'options' => array_values( $options ) ];
		}
		if ( $has_elementor_v4 ) {
			return [ 'renderer' => 'elementor_v4', 'reason' => 'v4 atomic enabled', 'options' => array_values( $options ) ];
		}
		return [ 'renderer' => 'gutenberg', 'reason' => 'default native renderer', 'options' => array_values( $options ) ];
	}
}
