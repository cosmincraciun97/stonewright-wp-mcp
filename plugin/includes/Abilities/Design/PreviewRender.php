<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\Renderer;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design.preview_render
 *
 * Validates a DesignSpec and renders it to an Elementor element array WITHOUT
 * writing to any post. Intended for preview/dry-run workflows.
 *
 * Security envelope:
 *   - Permission: Permissions::can_view_design() (manage_options).
 *   - NO post write — no backup required.
 *   - Validator is called before Renderer (AGENTS.md rule 4).
 *
 * @stonewright-status stable
 */
final class PreviewRender extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-preview-render';
	}

	public function label(): string {
		return __( 'Preview render design spec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a Stonewright Design Spec and renders it to an Elementor element array without writing to any post. Use this for dry-run previews.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec' => [ 'type' => 'object', 'description' => 'Stonewright Design Spec to render.' ],
			],
			'required' => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'elementor_data' => [ 'type' => 'array', 'description' => 'Rendered Elementor element array (not yet written to a post).' ],
				'diagnostics'    => [ 'type' => 'array', 'items' => [ 'type' => 'object' ], 'description' => 'Per-block render diagnostics.' ],
				'spec_sha8'      => [ 'type' => 'string', 'description' => 'First 8 chars of sha1 of the canonical validated spec JSON.' ],
			],
			'required' => [ 'elementor_data', 'diagnostics', 'spec_sha8' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$raw_spec = $args['spec'] ?? null;

				if ( ! is_array( $raw_spec ) ) {
					return $this->error(
						'missing_spec',
						__( 'The spec parameter is required and must be an object.', 'stonewright' )
					);
				}

				// Validator before Renderer — AGENTS.md rule 4.
				$validated = Validator::validate( $raw_spec );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}

				$diagnostics   = [];
				$elementor_data = Renderer::render( $validated, $diagnostics );

				$spec_json = (string) wp_json_encode( $validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$spec_sha8 = substr( sha1( $spec_json ), 0, 8 );

				return [
					'elementor_data' => $elementor_data,
					'diagnostics'    => $diagnostics,
					'spec_sha8'      => $spec_sha8,
				];
			}
		);
	}
}
