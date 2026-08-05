<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Quality\VisualComparator;
use Stonewright\WpMcp\Security\Permissions;

/** Compares measured browser evidence to manifest anchors without inventing observations. */
final class VisualCompare extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-visual-compare';
	}

	public function label(): string {
		return __( 'Compare visual evidence', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns deterministic desktop/tablet/mobile box, typography, color, missing-element, and tolerance findings from supplied measurements.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'expected', 'observed' ],
			'properties'           => [ 'expected' => [ 'type' => 'object' ], 'observed' => [ 'type' => 'object' ] ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true, 'properties' => [ 'ok' => [ 'type' => 'boolean' ], 'findings' => [ 'type' => 'array' ], 'comparison_hash' => [ 'type' => 'string' ] ] ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		$result = VisualComparator::compare( is_array( $args['expected'] ?? null ) ? $args['expected'] : [], is_array( $args['observed'] ?? null ) ? $args['observed'] : [] );
		return $result instanceof \WP_Error ? $result : array_merge( [ 'ok' => (bool) $result['ok'] ], $result );
	}
}
