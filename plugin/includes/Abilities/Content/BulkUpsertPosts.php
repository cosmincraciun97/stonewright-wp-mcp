<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Fast structured content writer for repeated MCP page-build workflows.
 *
 * This intentionally does not execute user-supplied PHP. It is a typed
 * WordPress operation that upserts posts and post meta through core APIs.
 *
 * @stonewright-status experimental
 */
final class BulkUpsertPosts extends AbilityKernel {

	public function name(): string {
		return 'stonewright/content-bulk-upsert-posts';
	}

	public function label(): string {
		return __( 'Bulk upsert posts', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates or updates many posts of one post type by slug, including post meta, in one guarded call. Use this instead of many WP-CLI post/meta commands during fast page builds.', 'stonewright' );
	}

	public function category(): string {
		return 'content';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_type' => [ 'type' => 'string', 'default' => 'post' ],
				'items'     => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 100,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'         => [ 'type' => 'integer', 'minimum' => 1 ],
							'slug'       => [ 'type' => 'string', 'maxLength' => 200 ],
							'title'      => [ 'type' => 'string', 'maxLength' => 255 ],
							'content'    => [ 'type' => 'string' ],
							'excerpt'    => [ 'type' => 'string' ],
							'status'     => [ 'type' => 'string', 'enum' => [ 'draft', 'publish', 'private', 'pending', 'future' ] ],
							'post_status' => [
								'type'        => 'string',
								'enum'        => [ 'draft', 'publish', 'private', 'pending', 'future' ],
								'description' => 'Alias for status; accepted for WordPress payload alignment.',
							],
							'parent'     => [ 'type' => 'integer', 'minimum' => 0 ],
							'menu_order' => [ 'type' => 'integer' ],
							'meta'       => [ 'type' => 'object' ],
						],
						'required'             => [ 'slug', 'title' ],
					],
				],
			],
			'required'             => [ 'post_type', 'items' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'      => [ 'type' => 'boolean' ],
				'created' => [ 'type' => 'integer' ],
				'updated' => [ 'type' => 'integer' ],
				'failed'  => [ 'type' => 'integer' ],
				'items'   => [ 'type' => 'array' ],
			],
			'required'   => [ 'ok', 'created', 'updated', 'failed', 'items' ],
		];
	}

	/**
	 * @stonewright-cap create_posts_for($post_type) for new rows, edit_post for existing rows, edit_post_meta per meta key, plus publish cap when status is publish/private/future.
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		if ( ! self::post_type_is_available( $post_type ) ) {
			return new \WP_Error(
				'stonewright_invalid_post_type',
				__( 'Unknown post_type.', 'stonewright' ),
				[ 'status' => 400, 'post_type' => $post_type ]
			);
		}

		foreach ( (array) ( $args['items'] ?? [] ) as $index => $item ) {
			$item = is_array( $item ) ? $item : [];
			$id   = $this->target_post_id( $item, $post_type );
			if ( $id > 0 ) {
				if ( ! Permissions::edit_post( $id ) ) {
					return new \WP_Error(
						'stonewright_forbidden',
						__( 'Insufficient capability to edit an existing post.', 'stonewright' ),
						[ 'status' => 403, 'failed_index' => $index, 'post_id' => $id ]
					);
				}
			} elseif ( ! Permissions::can_create_post_type( $post_type ) ) {
				return new \WP_Error(
					'stonewright_forbidden',
					__( 'Insufficient capability to create posts of this type.', 'stonewright' ),
					[ 'status' => 403, 'failed_index' => $index, 'post_type' => $post_type ]
				);
			}

			$status      = self::item_status( $item );
			$publish_cap = Permissions::publish_cap_for_status( $post_type, $status );
			if ( null !== $publish_cap && ! current_user_can( $publish_cap ) ) {
				return new \WP_Error(
					'stonewright_forbidden',
					__( 'Insufficient capability to publish posts of this type.', 'stonewright' ),
					[ 'status' => 403, 'failed_index' => $index, 'post_type' => $post_type ]
				);
			}
		}

		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
				if ( ! self::post_type_is_available( $post_type ) ) {
					return $this->error( 'invalid_post_type', __( 'Unknown post_type.', 'stonewright' ), [ 'status' => 400, 'post_type' => $post_type ] );
				}

				$created = 0;
				$updated = 0;
				$failed  = 0;
				$items   = [];

				foreach ( (array) ( $args['items'] ?? [] ) as $index => $item ) {
					$item = is_array( $item ) ? $item : [];
					$validation = self::validate_item( $item, $index );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
					$id   = $this->target_post_id( $item, $post_type );
					$slug = sanitize_title( (string) ( $item['slug'] ?? '' ) );
					$status = self::item_status( $item );

					$payload = [
						'post_type'  => $post_type,
						'post_name'  => $slug,
						'post_title' => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
					];

					if ( array_key_exists( 'content', $item ) ) {
						$payload['post_content'] = wp_kses_post( (string) $item['content'] );
					}
					if ( array_key_exists( 'excerpt', $item ) ) {
						$payload['post_excerpt'] = sanitize_text_field( (string) $item['excerpt'] );
					}
					if ( '' !== $status ) {
						$payload['post_status'] = $status;
					} elseif ( 0 === $id ) {
						$payload['post_status'] = 'draft';
					}
					if ( array_key_exists( 'parent', $item ) ) {
						$payload['post_parent'] = max( 0, (int) $item['parent'] );
					}
					if ( array_key_exists( 'menu_order', $item ) ) {
						$payload['menu_order'] = (int) $item['menu_order'];
					}

					$action = 'created';
					if ( $id > 0 ) {
						$payload['ID'] = $id;
						$result        = wp_update_post( $payload, true );
						$action        = 'updated';
					} else {
						$result = wp_insert_post( $payload, true );
					}

					if ( is_wp_error( $result ) ) {
						++$failed;
						$items[] = [
							'index'   => $index,
							'ok'      => false,
							'code'    => $result->get_error_code(),
							'message' => $result->get_error_message(),
						];
						continue;
					}

					$post_id = (int) $result;
					if ( 'created' === $action ) {
						++$created;
					} else {
						++$updated;
					}

					[ $meta_written, $meta_skipped ] = $this->write_meta( $post_id, (array) ( $item['meta'] ?? [] ) );
					$items[] = [
						'index'        => $index,
						'ok'           => true,
						'action'       => $action,
						'id'           => $post_id,
						'slug'         => $slug,
						'meta_written' => $meta_written,
						'meta_skipped' => $meta_skipped,
					];
				}

				return [
					'ok'      => 0 === $failed,
					'created' => $created,
					'updated' => $updated,
					'failed'  => $failed,
					'items'   => $items,
				];
			}
		);
	}

	/**
	 * @param array<string, mixed> $item
	 * @return true|\WP_Error
	 */
	private static function validate_item( array $item, int $index ): bool|\WP_Error {
		$slug  = sanitize_title( (string) ( $item['slug'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
		if ( '' === $slug || '' === $title ) {
			return new \WP_Error(
				'stonewright_invalid_content_item',
				__( 'Each bulk upsert item requires a non-empty slug and title.', 'stonewright' ),
				[ 'status' => 400, 'failed_index' => $index ]
			);
		}

		$status = self::item_status( $item );
		if ( '' !== $status && ! in_array( $status, self::allowed_statuses(), true ) ) {
			return new \WP_Error(
				'stonewright_invalid_post_status',
				__( 'Invalid post status for bulk upsert item.', 'stonewright' ),
				[ 'status' => 400, 'failed_index' => $index, 'post_status' => $status ]
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function item_status( array $item ): string {
		return sanitize_key( (string) ( $item['post_status'] ?? $item['status'] ?? '' ) );
	}

	/**
	 * @return list<string>
	 */
	private static function allowed_statuses(): array {
		return [ 'draft', 'publish', 'private', 'pending', 'future' ];
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function target_post_id( array $item, string $post_type ): int {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		if ( $id > 0 ) {
			$post = get_post( $id );
			return $post && $post_type === (string) $post->post_type ? $id : 0;
		}

		$slug = sanitize_title( (string) ( $item['slug'] ?? '' ) );
		return '' === $slug ? 0 : self::find_post_by_slug( $slug, $post_type );
	}

	private static function find_post_by_slug( string $slug, string $post_type ): int {
		if ( function_exists( 'get_page_by_path' ) ) {
			$post = get_page_by_path( $slug, OBJECT, $post_type );
			if ( null !== $post ) {
				return (int) $post->ID;
			}
		}

		if ( isset( $GLOBALS['stonewright_test_posts'] ) && is_array( $GLOBALS['stonewright_test_posts'] ) ) {
			foreach ( $GLOBALS['stonewright_test_posts'] as $post ) {
				if (
					is_object( $post )
					&& $post_type === (string) ( $post->post_type ?? '' )
					&& $slug === (string) ( $post->post_name ?? '' )
				) {
					return (int) ( $post->ID ?? 0 );
				}
			}
		}

		if ( ! class_exists( '\WP_Query' ) ) {
			return 0;
		}

		$query = new \WP_Query(
			[
				'name'           => $slug,
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			]
		);
		$posts = is_array( $query->posts ) ? $query->posts : [];
		$first = $posts[0] ?? null;
		if ( is_object( $first ) ) {
			return (int) $first->ID;
		}
		return null !== $first ? (int) $first : 0;
	}

	private static function post_type_is_available( string $post_type ): bool {
		if ( '' === $post_type ) {
			return false;
		}
		if ( in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return true;
		}
		return (bool) get_post_type_object( $post_type );
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array{0:int,1:array<int,string>}
	 */
	private function write_meta( int $post_id, array $meta ): array {
		$written = 0;
		$skipped = [];

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( ! Permissions::can_edit_post_meta( $post_id, $key ) ) {
				$skipped[] = $key;
				continue;
			}
			update_post_meta( $post_id, $key, $value );
			++$written;
		}

		return [ $written, $skipped ];
	}
}
