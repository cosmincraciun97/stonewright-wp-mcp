<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Design\Manifest\SectionManifest as SectionManifestContract;
use Stonewright\WpMcp\Design\Planning\NativeRendererDecision;
use Stonewright\WpMcp\Security\Permissions;

/** Validates and plans one compact vendor-neutral design section manifest. */
final class SectionManifest extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-section-manifest';
	}

	public function label(): string {
		return __( 'Validate section manifest', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a compact vendor-neutral design manifest, decomposes sections deterministically, and reports native renderer gaps without writing.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'manifest' ],
			'properties'           => [
				'action'      => [ 'type' => 'string', 'enum' => [ 'validate', 'plan', 'decompose' ], 'default' => 'validate' ],
				'manifest'    => [ 'type' => 'object' ],
				'live_schema' => [ 'type' => 'object' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'          => [ 'type' => 'boolean' ],
				'manifest'    => [ 'type' => 'object' ],
				'digest_hash' => [ 'type' => 'string' ],
				'decision'   => [ 'type' => 'object' ],
				'sections'   => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_view_design();
	}

	public function execute( array $args ): array|\WP_Error {
		$manifest = is_array( $args['manifest'] ?? null ) ? $args['manifest'] : [];
		$validated = SectionManifestContract::validate( $manifest );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		$action = (string) ( $args['action'] ?? 'validate' );
		if ( 'decompose' === $action ) {
			return [
				'ok'          => true,
				'digest_hash' => $validated['digest_hash'],
				'sections'    => SectionManifestContract::decompose( $validated['manifest'] ),
			];
		}
		if ( 'plan' === $action ) {
			$decision = NativeRendererDecision::choose( $validated['manifest'], is_array( $args['live_schema'] ?? null ) ? $args['live_schema'] : [] );
			if ( $decision instanceof \WP_Error ) {
				return $decision;
			}
			return [ 'ok' => (bool) $decision['ok'], 'manifest' => $validated['manifest'], 'digest_hash' => $validated['digest_hash'], 'decision' => $decision ];
		}
		return [
			'ok'          => true,
			'manifest'    => $validated['manifest'],
			'digest_hash' => $validated['digest_hash'],
			'asset_count' => count( (array) $validated['manifest']['assets'] ),
		];
	}
}
