<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\AssetReferences;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class NormalizeAssets extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-normalize-assets';
	}

	public function label(): string {
		return __( 'Normalize spec assets', 'stonewright' );
	}

	public function description(): string {
		return __( 'Resolves remote/asset urls inside a spec to media library attachments, sideloading missing files.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'spec'      => [ 'type' => 'object' ],
				'sideload'  => [ 'type' => 'boolean', 'default' => true ],
			],
			'required'             => [ 'spec' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'spec'       => [ 'type' => 'object' ],
				'replaced'   => [ 'type' => 'integer' ],
				'attachments'=> [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::upload_files();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$spec     = (array) $args['spec'];
				$sideload = ! isset( $args['sideload'] ) || (bool) $args['sideload'];
				$resolved = AssetReferences::resolve( $spec, $sideload );

				return [
					'spec'        => $resolved['spec'],
					'replaced'    => count( $resolved['sideloaded_assets'] ),
					'attachments' => $resolved['sideloaded_assets'],
				];
			}
		);
	}
}
