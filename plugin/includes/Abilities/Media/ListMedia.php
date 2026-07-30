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

	public function name(): string {
		return 'stonewright/media-list';
	}

	public function label(): string {
		return __( 'List media', 'stonewright' );
	}

	public function description(): string {
		return __( 'Searches existing media by title, alt text, caption, mime, and filename before uploading duplicates.', 'stonewright' );
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
					'description' => 'Optional text matched against attachment title, filename slug, caption, description, and alt text.',
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
		$candidate_limit = '' === $search ? $per_page : 200;

		$posts = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => '' !== $mime_type ? $mime_type : null,
				'posts_per_page' => $candidate_limit,
				'offset'         => '' === $search ? ( $page - 1 ) * $per_page : 0,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		$items = [];
		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) ) {
				continue;
			}

			$row = self::attachment_row( $post );
			if ( '' !== $search && ! self::matches_search( $row, $post, $search ) ) {
				continue;
			}
			$items[] = $row;
		}

		if ( '' !== $search ) {
			$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );
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

	/**
	 * @param array<string, mixed> $row
	 */
	private static function matches_search( array $row, object $post, string $search ): bool {
		$needle   = strtolower( $search );
		$haystack = strtolower(
			implode(
				' ',
				[
					(string) $row['title'],
					(string) $row['alt'],
					(string) $row['caption'],
					(string) ( $post->post_name ?? '' ),
					(string) ( $post->post_content ?? '' ),
				]
			)
		);

		return str_contains( $haystack, $needle );
	}
}
