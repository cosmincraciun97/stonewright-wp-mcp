<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Media;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Media\StockImageClient;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Import a stock image into the media library with license attribution.
 *
 * @stonewright-status stable
 */
final class StockImageImport extends AbilityKernel {

	public function name(): string {
		return 'stonewright/stock-image-import';
	}

	public function label(): string {
		return __( 'Import stock image', 'stonewright' );
	}

	public function description(): string {
		return __( 'Sideloads a stock photo into the media library, writes license attribution to the caption, and records an audit entry. Requires upload_files.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'url' ],
			'properties'           => [
				'url'           => [
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'Direct image URL from stock-image-search results.',
				],
				'provider'      => [
					'type'    => 'string',
					'enum'    => [ 'openverse', 'unsplash', 'pexels' ],
					'default' => 'openverse',
				],
				'id'            => [
					'type'        => 'string',
					'maxLength'   => 120,
					'description' => 'Provider image id from search results.',
				],
				'title'         => [
					'type'      => 'string',
					'maxLength' => 200,
				],
				'alt'           => [
					'type'      => 'string',
					'maxLength' => 500,
				],
				'attribution'   => [
					'type'        => 'string',
					'maxLength'   => 1000,
					'description' => 'License attribution string written into the attachment caption.',
				],
				'creator'       => [ 'type' => 'string', 'maxLength' => 200 ],
				'license'       => [ 'type' => 'string', 'maxLength' => 120 ],
				'license_url'   => [ 'type' => 'string', 'maxLength' => 500 ],
				'landing_url'   => [ 'type' => 'string', 'maxLength' => 500 ],
				'parent_post_id'=> [
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'          => [ 'type' => 'boolean' ],
				'id'          => [ 'type' => 'integer' ],
				'url'         => [ 'type' => 'string' ],
				'mime'        => [ 'type' => 'string' ],
				'caption'     => [ 'type' => 'string' ],
				'provider'    => [ 'type' => 'string' ],
				'attribution' => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'id', 'url', 'caption', 'provider', 'attribution' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::upload_files();
	}

	/**
	 * @param array<string, mixed> $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, scalar|null>
	 */
	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		$meta = [
			'elapsed_ms' => $elapsed_ms,
			'provider'   => isset( $args['provider'] ) ? (string) $args['provider'] : StockImageClient::PROVIDER_OPENVERSE,
		];
		if ( is_array( $result ) && isset( $result['id'] ) ) {
			$meta['attachment_id'] = (int) $result['id'];
		}

		return $meta;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$image_url = isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : '';
				if ( '' === $image_url || ! preg_match( '#^https?://#i', $image_url ) ) {
					return $this->error( 'invalid_url', __( 'A valid stock image URL is required.', 'stonewright' ) );
				}

				$provider    = isset( $args['provider'] ) ? sanitize_key( (string) $args['provider'] ) : StockImageClient::PROVIDER_OPENVERSE;
				$parent      = isset( $args['parent_post_id'] ) ? max( 0, (int) $args['parent_post_id'] ) : 0;
				$title       = isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : '';
				$alt         = isset( $args['alt'] ) ? sanitize_text_field( (string) $args['alt'] ) : $title;
				$creator     = isset( $args['creator'] ) ? sanitize_text_field( (string) $args['creator'] ) : '';
				$license     = isset( $args['license'] ) ? sanitize_text_field( (string) $args['license'] ) : '';
				$license_url = isset( $args['license_url'] ) ? esc_url_raw( (string) $args['license_url'] ) : '';
				$landing_url = isset( $args['landing_url'] ) ? esc_url_raw( (string) $args['landing_url'] ) : '';
				$provider_id = isset( $args['id'] ) ? sanitize_text_field( (string) $args['id'] ) : '';
				$attribution = isset( $args['attribution'] ) ? sanitize_text_field( (string) $args['attribution'] ) : '';

				if ( '' === $attribution ) {
					$parts = [];
					if ( '' !== $creator ) {
						$parts[] = 'Photo by ' . $creator;
					}
					if ( '' !== $provider ) {
						$parts[] = 'via ' . $provider;
					}
					if ( '' !== $license ) {
						$parts[] = '(' . $license . ')';
					}
					if ( '' !== $landing_url ) {
						$parts[] = $landing_url;
					}
					$attribution = trim( implode( ' ', $parts ) );
				}

				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';

				$desc = '' !== $title ? $title : basename( (string) wp_parse_url( $image_url, PHP_URL_PATH ) );
				$attachment_id = media_sideload_image( $image_url, $parent, $desc, 'id' );
				if ( is_wp_error( $attachment_id ) ) {
					return $attachment_id;
				}

				$attachment_id = (int) $attachment_id;
				if ( $attachment_id <= 0 ) {
					return $this->error( 'sideload_failed', __( 'Stock image sideload did not return an attachment id.', 'stonewright' ) );
				}

				$update = [
					'ID'           => $attachment_id,
					'post_excerpt' => $attribution,
				];
				if ( '' !== $title ) {
					$update['post_title'] = $title;
				}
				wp_update_post( $update );

				if ( '' !== $alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
				}

				update_post_meta( $attachment_id, '_stonewright_stock_provider', $provider );
				if ( '' !== $provider_id ) {
					update_post_meta( $attachment_id, '_stonewright_stock_id', $provider_id );
				}
				if ( '' !== $license ) {
					update_post_meta( $attachment_id, '_stonewright_stock_license', $license );
				}
				if ( '' !== $license_url ) {
					update_post_meta( $attachment_id, '_stonewright_stock_license_url', $license_url );
				}
				if ( '' !== $landing_url ) {
					update_post_meta( $attachment_id, '_stonewright_stock_landing_url', $landing_url );
				}
				if ( '' !== $creator ) {
					update_post_meta( $attachment_id, '_stonewright_stock_creator', $creator );
				}
				update_post_meta( $attachment_id, '_stonewright_stock_attribution', $attribution );

				return [
					'ok'          => true,
					'id'          => $attachment_id,
					'url'         => (string) wp_get_attachment_url( $attachment_id ),
					'mime'        => (string) get_post_mime_type( $attachment_id ),
					'caption'     => $attribution,
					'provider'    => $provider,
					'attribution' => $attribution,
				];
			}
		);
	}
}
