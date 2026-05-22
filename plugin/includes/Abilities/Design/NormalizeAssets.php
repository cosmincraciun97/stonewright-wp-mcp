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
				$replaced = 0;
				$attach   = [];

				if ( $sideload ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
				}

				$walker = function ( &$node ) use ( &$walker, $sideload, &$replaced, &$attach ) {
					if ( ! is_array( $node ) ) {
						return;
					}
					if ( isset( $node['type'] ) && 'image' === $node['type'] && ! empty( $node['url'] ) && empty( $node['id'] ) ) {
						$url = (string) $node['url'];
						if ( $sideload && preg_match( '#^https?://#i', $url ) ) {
							$tmp = download_url( $url, 60 );
							if ( ! is_wp_error( $tmp ) ) {
								$file_array = [
									'name'     => sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'image.bin' ) ),
									'tmp_name' => $tmp,
								];
								$id = media_handle_sideload( $file_array, 0 );
								if ( ! is_wp_error( $id ) ) {
									$node['id']  = (int) $id;
									$node['url'] = wp_get_attachment_url( (int) $id );
									$attach[]    = (int) $id;
									$replaced++;
								} else {
									@unlink( $tmp );
								}
							}
						}
					}
					foreach ( $node as &$child ) {
						if ( is_array( $child ) ) {
							$walker( $child );
						}
					}
				};

				$walker( $spec );

				return [
					'spec'        => $spec,
					'replaced'    => $replaced,
					'attachments' => $attach,
				];
			}
		);
	}
}
