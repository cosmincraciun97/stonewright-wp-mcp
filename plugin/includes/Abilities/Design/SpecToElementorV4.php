<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SpecToElementorV4 extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-spec-to-elementor-v4';
	}

	public function label(): string {
		return __( 'Render spec to Elementor V4 (atomic)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Renders a Stonewright Design Spec into Elementor V4 atomic structure. Gated behind elementor_v4_atomic flag.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'    => [ 'type' => 'object' ],
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'dry_run' => [ 'type' => 'boolean', 'default' => true ],
			],
			'required'             => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'rendered'    => [ 'type' => 'array' ],
				'dry_run'     => [ 'type' => 'boolean' ],
				'diagnostics' => [ 'type' => 'array' ],
			],
			'required'   => [ 'rendered', 'dry_run', 'diagnostics' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! get_option( 'stonewright_elementor_v4_atomic', false ) ) {
			return new \WP_Error( 'feature_disabled', __( 'Elementor V4 atomic renderer is disabled. Toggle stonewright_elementor_v4_atomic to enable.', 'stonewright' ) );
		}
		if ( ! empty( $args['post_id'] ) ) {
			return Permissions::edit_post( (int) $args['post_id'] );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				if ( ! class_exists( '\\Stonewright\\WpMcp\\Renderers\\ElementorV4SpecRenderer' ) ) {
					return $this->error( 'renderer_missing', __( 'Elementor V4 renderer is not bundled in this build.', 'stonewright' ) );
				}
				$normalized = Validator::validate( (array) $args['spec'] );
				if ( is_wp_error( $normalized ) ) {
					return $normalized;
				}
				$diagnostics = [];
				$rendered    = \Stonewright\WpMcp\Renderers\ElementorV4SpecRenderer::render( $normalized, $diagnostics );
				if ( is_wp_error( $rendered ) ) {
					return $rendered;
				}
				return [
					'rendered'    => $rendered,
					'dry_run'     => true,
					'diagnostics' => $diagnostics,
				];
			}
		);
	}
}
