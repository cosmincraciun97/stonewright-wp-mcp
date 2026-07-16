<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Seo;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class SeoMetaGet extends AbilityKernel {

	public function name(): string {
		return 'stonewright/seo-meta-get';
	}

	public function label(): string {
		return __( 'SEO: Get meta', 'stonewright' );
	}

	public function description(): string {
		return __( 'Reads normalized SEO meta for a post (Yoast, Rank Math, AIOSEO, or SEOPress).', 'stonewright' );
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
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
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
				$meta    = SeoAdapter::get_meta( $post_id );
				if ( is_wp_error( $meta ) ) {
					return $meta;
				}
				return array_merge( [ 'post_id' => $post_id ], $meta );
			}
		);
	}
}
