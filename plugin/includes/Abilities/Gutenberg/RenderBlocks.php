<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Gutenberg;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Gutenberg\Renderer;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/gutenberg.render_blocks
 *
 * Validates a DesignSpec and renders it to Gutenberg block markup.
 * Read-only — no writes, no backup required.
 *
 * Permission: can_view_design().
 *
 * @stonewright-status stable
 */
final class RenderBlocks extends AbilityKernel {

	public function name(): string {
		return 'stonewright/gutenberg-render-blocks';
	}

	public function label(): string {
		return __( 'Render design spec to Gutenberg block markup', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a Stonewright Design Spec and renders it to Gutenberg block markup. Read-only — does not write to any post.', 'stonewright' );
	}

	public function category(): string {
		return 'gutenberg';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec' => [
					'type'        => 'object',
					'description' => 'Stonewright Design Spec to render.',
				],
			],
			'required' => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'block_markup' => [ 'type' => 'string' ],
				'diagnostics'  => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
				'spec_sha8'    => [ 'type' => 'string' ],
			],
			'required' => [ 'block_markup', 'diagnostics', 'spec_sha8' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		$raw_spec = $args['spec'] ?? null;
		if ( ! is_array( $raw_spec ) ) {
			return $this->error( 'missing_spec', __( 'The spec parameter is required and must be an object.', 'stonewright' ) );
		}

		// ── Validate spec ────────────────────────────────────────────────────
		$validated = Validator::validate( $raw_spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// ── Render to block dicts ─────────────────────────────────────────────
		$diagnostics  = [];
		$block_dicts  = Renderer::render( $validated, $diagnostics );

		// ── Serialize to markup ───────────────────────────────────────────────
		$markup = '';
		foreach ( $block_dicts as $block ) {
			$markup .= serialize_block( $block );
		}

		// ── Fingerprint ───────────────────────────────────────────────────────
		$spec_json = (string) wp_json_encode( $validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$spec_sha8 = substr( sha1( $spec_json ), 0, 8 );

		return [
			'block_markup' => $markup,
			'diagnostics'  => $diagnostics,
			'spec_sha8'    => $spec_sha8,
		];
	}
}
