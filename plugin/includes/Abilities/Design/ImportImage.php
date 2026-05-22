<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ImportImage extends AbilityKernel {

	public function name(): string {
		return 'stonewright/design-import-image';
	}

	public function label(): string {
		return __( 'Import image as spec stub', 'stonewright' );
	}

	public function description(): string {
		return __( 'Accepts a reference image (url, base64, or attachment id) and returns a minimal spec stub that the agent fills out via the vision pipeline.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'url'             => [ 'type' => 'string', 'format' => 'uri' ],
				'attachment_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
				'base64'          => [ 'type' => 'string' ],
				'title'           => [ 'type' => 'string', 'maxLength' => 255 ],
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
		$url = (string) ( $args['url'] ?? '' );
		if ( ! empty( $args['attachment_id'] ) ) {
			$resolved = wp_get_attachment_url( (int) $args['attachment_id'] );
			if ( $resolved ) {
				$url = $resolved;
			}
		}
		if ( '' === $url && ! empty( $args['base64'] ) ) {
			$url = 'data:image/png;base64,' . preg_replace( '/^data:[^,]+,/', '', (string) $args['base64'] );
		}

		return [
			'version' => '1.0.0',
			'source'  => [
				'type' => 'image',
				'url'  => $url,
			],
			'page'    => [ 'title' => (string) ( $args['title'] ?? __( 'Image-derived page', 'stonewright' ) ) ],
			'tokens'  => new \stdClass(),
			'sections'=> [
				[
					'id'     => 'image_ref',
					'width'  => 'boxed',
					'layout' => 'stack',
					'blocks' => [
						[
							'type' => 'image',
							'url'  => $url,
							'alt'  => __( 'Reference image', 'stonewright' ),
						],
					],
				],
			],
		];
	}
}
