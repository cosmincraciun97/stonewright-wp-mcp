<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Media;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact media-library search for asset reuse before uploads.
 *
 * @stonewright-status stable
 */
final class ListMedia extends AbilityKernel {

	/** Most matching ids a search reads before it pages them. Ids are cheap; this only bounds a very large library. */
	private const SEARCH_ID_LIMIT = 2000;

	public function name(): string {
		return 'stonewright/media-list';
	}

	public function label(): string {
		return __( 'List media', 'stonewright' );
	}

	public function description(): string {
		return __( 'Searches the whole media library by title, caption, description, alt text, and file name, with a mime filter, before uploading duplicates.', 'stonewright' );
	}

	public function category(): string {
		return 'media';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'search'    => [
					'type'        => 'string',
					'default'     => '',
					'maxLength'   => 120,
					'description' => 'Optional text matched against attachment title, caption, description, alt text, and file name, across the whole library.',
				],
				'mime_type' => [
					'type'        => 'string',
					'default'     => 'image',
					'maxLength'   => 80,
					'description' => 'Optional mime filter such as image, image/jpeg, image/png, application/pdf, or empty for all attachments.',
				],
				'per_page'  => [
					'type'        => 'integer',
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => 'Maximum compact rows to return.',
				],
				'page'      => [
					'type'        => 'integer',
					'default'     => 1,
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Result page.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'    => [ 'type' => 'boolean' ],
				'count' => [ 'type' => 'integer' ],
				'query' => [ 'type' => 'object' ],
				'items' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'      => [ 'type' => 'integer' ],
							'title'   => [ 'type' => 'string' ],
							'url'     => [ 'type' => 'string' ],
							'mime'    => [ 'type' => 'string' ],
							'alt'     => [ 'type' => 'string' ],
							'caption' => [ 'type' => 'string' ],
							'parent'  => [ 'type' => 'integer' ],
							'width'   => [ 'type' => 'integer' ],
							'height'  => [ 'type' => 'integer' ],
						],
						'required'   => [ 'id', 'title', 'url', 'mime', 'alt', 'caption', 'parent', 'width', 'height' ],
					],
				],
			],
			'required'   => [ 'ok', 'count', 'query', 'items' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		$search    = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( sanitize_text_field( $args['search'] ) ) : '';
		$mime_type = isset( $args['mime_type'] ) && is_string( $args['mime_type'] ) ? trim( sanitize_text_field( $args['mime_type'] ) ) : 'image';
		$per_page  = isset( $args['per_page'] ) ? max( 1, min( 50, (int) $args['per_page'] ) ) : 20;
		$page      = isset( $args['page'] ) ? max( 1, min( 100, (int) $args['page'] ) ) : 1;
		$base = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => '' !== $mime_type ? $mime_type : null,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$page_args = $base + [
			'posts_per_page' => $per_page,
			'offset'         => ( $page - 1 ) * $per_page,
		];
		if ( '' !== $search ) {
			// Look at the whole library, not at its newest items: WordPress search covers the title, caption and description,
			// and a meta query covers the alt text and the file name. The matching ids are joined, then the page is read
			// from them in date order.
			$ids = array_values(
				array_unique(
					array_merge(
						array_map( 'intval', (array) get_posts( $base + [ 's' => $search, 'fields' => 'ids', 'posts_per_page' => self::SEARCH_ID_LIMIT, 'no_found_rows' => true ] ) ),
						array_map(
							'intval',
							(array) get_posts(
								$base + [
									'fields'         => 'ids',
									'posts_per_page' => self::SEARCH_ID_LIMIT,
									'no_found_rows'  => true,
									'meta_query'     => [
										'relation' => 'OR',
										[ 'key' => '_wp_attachment_image_alt', 'value' => $search, 'compare' => 'LIKE' ],
										[ 'key' => '_wp_attached_file', 'value' => $search, 'compare' => 'LIKE' ],
									],
								]
							)
						)
					)
				)
			);
			$page_args['post__in'] = [] === $ids ? [ 0 ] : $ids;
		}

		$items = [];
		foreach ( get_posts( $page_args ) as $post ) {
			if ( is_object( $post ) && Permissions::read_post( (int) $post->ID ) ) {
				$items[] = self::attachment_row( $post );
			}
		}

		return [
			'ok'    => true,
			'count' => count( $items ),
			'query' => [
				'search'    => $search,
				'mime_type' => $mime_type,
				'per_page'  => $per_page,
				'page'      => $page,
			],
			'items' => $items,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function attachment_row( object $post ): array {
		$id   = (int) $post->ID;
		$meta = wp_get_attachment_metadata( $id );

		return [
			'id'      => $id,
			'title'   => (string) ( $post->post_title ?? '' ),
			'url'     => (string) wp_get_attachment_url( $id ),
			'mime'    => (string) get_post_mime_type( $id ),
			'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption' => (string) ( $post->post_excerpt ?? '' ),
			'parent'  => (int) ( $post->post_parent ?? 0 ),
			'width'   => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'  => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		];
	}
}
