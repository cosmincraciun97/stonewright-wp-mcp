<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Evidence\Validator;
use Stonewright\WpMcp\Design\Planning\NativePlanner;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Validates vendor-neutral design evidence and returns a deterministic native-first dry plan.
 *
 * @stonewright-status stable
 */
final class NativePlan extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-native-plan';
	}

	public function label(): string {
		return __( 'Validate evidence and build native design plan', 'stonewright' );
	}

	public function description(): string {
		return __( 'Normalizes Figma/image/brief evidence, blocks unresolved semantics or unproven styles, and returns a deterministic native-first plan. It never writes or applies custom code.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action'   => [ 'type' => 'string', 'enum' => [ 'validate', 'plan' ], 'default' => 'plan' ],
				'target'   => [
					'type'        => 'string',
					// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Machine-readable target ids.
					'enum'        => [ 'elementor', 'elementor-v3', 'elementor-v4', 'gutenberg', 'fse', 'wordpress' ],
					'default'     => 'elementor-v3',
					'description' => 'Render engine. Aliases: elementor→elementor-v3. fse uses constrained FSE template blocks.',
				],
				'engine'   => [
					'type'        => 'string',
					'description' => 'Alias for target (elementor|gutenberg|fse).',
				],
				'evidence' => [
					'type'        => 'object',
					'description' => 'DesignEvidence 1.0: sources, viewports, semantic nodes, layout intent, measured_targets (px per breakpoint), figma_token_table / spacing_scale / typography_ramp, per-style provenance. Raw Figma trees are rejected by normalization.',
				],
			],
			'required'             => [ 'evidence' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'                      => [ 'type' => 'boolean' ],
				'status'                  => [ 'type' => 'string' ],
				'evidence_schema_version' => [ 'type' => 'string' ],
				'evidence_hash'           => [ 'type' => 'string' ],
				'native_phase'             => [ 'type' => 'object' ],
				'blockers'                 => [ 'type' => 'array' ],
				'customization_proposal'   => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$evidence = isset( $args['evidence'] ) && is_array( $args['evidence'] ) ? $args['evidence'] : [];
		$action   = (string) ( $args['action'] ?? 'plan' );
		if ( 'validate' === $action ) {
			$result = Validator::validate( $evidence );
			if ( $result instanceof \WP_Error ) {
				return $result;
			}
			return [
				'ok'                      => true,
				'status'                  => 'evidence_valid',
				'evidence_schema_version' => Validator::VERSION,
				'evidence_hash'           => $result['evidence_hash'],
				'node_count'              => $result['node_count'],
				'source_count'            => $result['source_count'],
				'viewport_count'          => $result['viewport_count'],
				'normalized_evidence'     => $result['evidence'],
			];
		}

		$target = (string) ( $args['target'] ?? $args['engine'] ?? 'elementor-v3' );
		return NativePlanner::plan( $evidence, $target );
	}
}
