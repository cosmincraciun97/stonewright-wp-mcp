<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Media;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class SetAlt extends AbilityKernel {

	public function name(): string {
		return 'stonewright/media-set-alt';
	}

	public function label(): string {
		return __( 'Set media alt text', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates the alt text for an attachment.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'id'  => [ 'type' => 'integer', 'minimum' => 1 ],
				'alt' => [ 'type' => 'string', 'maxLength' => 500 ],
			],
			'required'             => [ 'id', 'alt' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'  => [ 'type' => 'integer' ],
				'alt' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$id  = (int) $args['id'];
				$alt = sanitize_text_field( (string) $args['alt'] );

				if ( 'attachment' !== get_post_type( $id ) ) {
					return $this->error( 'not_found', __( 'Attachment not found.', 'stonewright' ) );
				}

				update_post_meta( $id, '_wp_attachment_image_alt', $alt );

				return [ 'id' => $id, 'alt' => $alt ];
			}
		);
	}
}
