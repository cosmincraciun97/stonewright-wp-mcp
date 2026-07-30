<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Seo;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class SeoMetaUpdate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/seo-meta-update';
	}

	public function label(): string {
		return __( 'SEO: Update meta', 'stonewright' );
	}

	public function description(): string {
		return __( 'Updates a subset of normalized SEO meta after snapshotting the post.', 'stonewright' );
	}

	public function category(): string {
		return 'seo';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'       => [ 'type' => 'integer', 'minimum' => 1 ],
				'title'         => [ 'type' => 'string' ],
				'description'   => [ 'type' => 'string' ],
				'focus_keyword' => [ 'type' => 'string' ],
				'canonical'     => [ 'type' => 'string' ],
				'noindex'       => [ 'type' => 'boolean' ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::can_edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				$post_id = (int) $args['post_id'];
				Backup::snapshot_post( $post_id );
				$patch = [];
				foreach ( [ 'title', 'description', 'focus_keyword', 'canonical', 'noindex' ] as $field ) {
					if ( array_key_exists( $field, $args ) ) {
						$patch[ $field ] = $args[ $field ];
					}
				}
				$meta = SeoAdapter::update_meta( $post_id, $patch );
				if ( is_wp_error( $meta ) ) {
					return $meta;
				}
				return array_merge( [ 'post_id' => $post_id, 'ok' => true ], $meta );
			}
		);
	}
}
