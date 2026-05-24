<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\FigmaToSpec as FigmaToSpecAdapter;
use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Ability: stonewright/design-figma-to-spec
 *
 * Adapts a raw Figma node JSON (Figma REST API shape) into a validated
 * Stonewright DesignSpec — purely PHP-side, no companion call. Useful when a
 * client already has the Figma node payload in hand (e.g. captured offline,
 * received via the companion's `/figma-ingest` endpoint, etc.) and wants to
 * deterministically produce a DesignSpec it can hand to V3/V4 renderers.
 *
 * @stonewright-status stable
 */
final class FigmaToSpec extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-figma-to-spec';
	}

	public function label(): string {
		return __( 'Figma node to DesignSpec', 'stonewright' );
	}

	public function description(): string {
		return __( 'Adapts a raw Figma node JSON payload into a validated Stonewright DesignSpec without calling the companion.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'figma_node'      => [
					'type'        => 'object',
					'description' => 'A Figma REST API node (the `document` payload), shape returned by /v1/files/{key}/nodes.',
				],
				'viewport_label'  => [
					'type'        => 'string',
					'description' => 'Optional viewport label hint (e.g. "desktop" or "mobile"). Accepted but not persisted into the spec; useful for caller bookkeeping.',
				],
			],
			'required'             => [ 'figma_node' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'version'  => [ 'type' => 'string' ],
				'source'   => [ 'type' => 'object' ],
				'page'     => [ 'type' => 'object' ],
				'tokens'   => [ 'type' => 'object' ],
				'sections' => [ 'type' => 'array' ],
			],
			'required'   => [ 'version', 'page', 'sections' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ): array|\WP_Error {
				$node = $args['figma_node'] ?? null;
				if ( ! is_array( $node ) || [] === $node ) {
					return $this->error(
						'missing_figma_node',
						__( 'figma_node must be a non-empty Figma node object.', 'stonewright' )
					);
				}

				$spec = FigmaToSpecAdapter::to_spec( $node );

				// `viewport_label` is accepted as a caller hint but is NOT
				// persisted into the spec — the bundled DesignSpec schema's
				// `source` block uses `additionalProperties: false`, so adding
				// extra keys would fail validation. Callers can pair the label
				// with the returned spec out-of-band (e.g. their own metadata).

				$validated = Validator::validate( $spec );
				if ( is_wp_error( $validated ) ) {
					return $validated;
				}
				return $validated;
			}
		);
	}
}
