<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\DesignSpec\FigmaImporter;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ImportFigmaNode extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-import-figma-node';
	}

	public function label(): string {
		return __( 'Import Figma node', 'stonewright' );
	}

	public function description(): string {
		return __( 'Fetches a Figma node via REST API and converts it into a Stonewright Design Spec.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'file_key' => [ 'type' => 'string' ],
				'node_id'  => [ 'type' => 'string' ],
				'token'    => [ 'type' => 'string' ],
				'figma_url'=> [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$file_key = (string) ( $args['file_key'] ?? '' );
				$node_id  = (string) ( $args['node_id'] ?? '' );

				if ( ! empty( $args['figma_url'] ) && ( '' === $file_key || '' === $node_id ) ) {
					$parsed = FigmaImporter::parse_url( (string) $args['figma_url'] );
					if ( null === $parsed ) {
						return $this->error( 'invalid_figma_url', __( 'Could not parse Figma url.', 'stonewright' ) );
					}
					$file_key = '' !== $file_key ? $file_key : $parsed['file_key'];
					$node_id  = '' !== $node_id ? $node_id : $parsed['node_id'];
				}

				if ( '' === $file_key || '' === $node_id ) {
					return $this->error( 'missing_target', __( 'file_key and node_id (or figma_url) are required.', 'stonewright' ) );
				}

				$token = (string) ( $args['token'] ?? get_option( 'stonewright_figma_token', '' ) );
				if ( '' === $token ) {
					return $this->error( 'missing_token', __( 'A Figma personal access token is required.', 'stonewright' ) );
				}

				return FigmaImporter::fetch( $file_key, $node_id, $token );
			}
		);
	}
}
