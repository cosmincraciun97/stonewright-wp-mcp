<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\DesignSpec\AssetReferences;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class NormalizeAssets extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/design-normalize-assets';
	}

	public function label(): string {
		return __( 'Normalize spec assets', 'stonewright' );
	}

	public function description(): string {
		return __( 'Resolves remote/asset urls inside a spec to media library attachments, sideloading missing files. In production-safe mode a call that sideloads needs a confirmation_token issued for its arguments; sideload=false needs none.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	/**
	 * Sideloads missing files into the media library and adds attachments; nothing that exists is overwritten.
	 *
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return [
			'annotations' => [
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			],
		];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'spec'      => [ 'type' => 'object' ],
				'sideload'  => [ 'type' => 'boolean', 'default' => true, 'description' => 'false resolves the spec without fetching or storing any file.' ],
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
				// Fetching a file and adding it to the media library is a write; resolving without it is not.
				if ( $sideload ) {
					$token_error = $this->confirmation_token_error( $args, $args );
					if ( $token_error instanceof \WP_Error ) {
						return $token_error;
					}
				}
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
