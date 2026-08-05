<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Diagnostics\ThirdPartyControlRiskMap;
use Stonewright\WpMcp\Security\Permissions;

/** Read-only preservation map for third-party widget/form controls. */
final class ThirdPartyRiskMap extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-third-party-risk-map';
	}

	public function label(): string {
		return __( 'Map third-party control risk', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reports owned, unknown, plugin-registered, action-referenced, and safe patch keys before a native replace or patch.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'before', 'patch' ],
			'properties'           => [ 'before' => [ 'type' => 'object' ], 'patch' => [ 'type' => 'object' ], 'context' => [ 'type' => 'object' ] ],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true, 'properties' => [ 'owned_controls' => [ 'type' => 'array' ], 'unknown_controls' => [ 'type' => 'array' ], 'preservation_hash' => [ 'type' => 'string' ] ] ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		return ThirdPartyControlRiskMap::analyze(
			is_array( $args['before'] ?? null ) ? $args['before'] : [],
			is_array( $args['patch'] ?? null ) ? $args['patch'] : [],
			is_array( $args['context'] ?? null ) ? $args['context'] : []
		);
	}
}
