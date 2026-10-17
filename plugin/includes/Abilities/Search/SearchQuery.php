<?php
declare( strict_types=1 );
namespace Stonewright\WpMcp\Abilities\Search;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;
/** @stonewright-status stable */
final class SearchQuery extends AbilityKernel {
	public function name(): string {
 return 'stonewright/search-query'; }
	public function label(): string {
 return __( 'Search: Query', 'stonewright' ); }
	public function description(): string {
 return __( 'Universal search across posts/types via WP_Query.', 'stonewright' ); }
	public function category(): string {
 return 'search'; }
	public function input_schema(): array {
		return [
			'type'=>'object',
			'additionalProperties'=>false,
			'properties'=>[
				'search'=>[ 'type'=>'string' ],
				'post_type'=>[ 'type'=>'string' ],
				'per_page'=>[ 'type'=>'integer', 'minimum'=>1, 'maximum'=>50, 'default'=>20 ],
				'page'=>[ 'type'=>'integer', 'minimum'=>1, 'default'=>1, 'description'=>'Result page, starting at 1.' ],
			],
			'required'=>[ 'search' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'items'=>[ 'type'=>'array' ], 'total'=>[ 'type'=>'integer' ], 'page'=>[ 'type'=>'integer' ], 'per_page'=>[ 'type'=>'integer' ], 'total_pages'=>[ 'type'=>'integer' ] ], 'required'=>[ 'items' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::edit_posts(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit_read(
			$args,
			static function ( array $args ) {
				$post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : 'any';
				// A type the caller cannot edit is listed only when the site shows it. WP_Query lists the published
				// items of any type it is given, which would hand out the entries of a private type (coupon codes).
				if ( 'any' !== $post_type && ! self::may_list( $post_type ) ) {
					return new \WP_Error(
						'stonewright_search_post_type_not_allowed',
						__( 'This post type is not public and you cannot edit it, so it cannot be searched.', 'stonewright' ),
						[
							'status'    => 403,
							'post_type' => $post_type,
						]
					);
				}

				$per_page = max( 1, min( (int) ( $args['per_page'] ?? 20 ), 50 ) );
				$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

				$q = new \WP_Query(
					[
						's'              => (string) $args['search'],
						'post_type'      => $post_type,
						'posts_per_page' => $per_page,
						'paged'          => $page,
						'post_status'    => 'any',
					]
				);
				$items   = [];
				$skipped = 0;
				foreach ( $q->posts as $p ) {
					$status = (string) $p->post_status;
					if ( 'publish' === $status ) {
						// Published content of a type the caller may list is fine for any caller with this gate.
					} elseif (
						! current_user_can( 'edit_post', (int) $p->ID )
						&& ! current_user_can( 'read_post', (int) $p->ID )
					) {
						// Every caller holds edit_posts, so the capability for this one post decides.
						++$skipped;
						continue;
					}
					$items[] = [
						'id'       => (int) $p->ID,
						'title'    => (string) $p->post_title,
						'type'     => (string) $p->post_type,
						'status'   => $status,
						'modified' => (string) $p->post_modified,
					];
				}
				// Matches across all pages, without the ones this caller may not read.
				$total = max( count( $items ), (int) $q->found_posts - $skipped );

				return [
					'items'       => $items,
					'total'       => $total,
					'page'        => $page,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total / $per_page ),
				];
			}
		);
	}

	/**
	 * Whether the caller may search a post type: they can edit it, or the site shows it to visitors.
	 */
	private static function may_list( string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		if ( ! is_object( $object ) ) {
			return false;
		}
		$edit_cap = isset( $object->cap->edit_posts ) ? (string) $object->cap->edit_posts : '';
		if ( '' !== $edit_cap && current_user_can( $edit_cap ) ) {
			return true;
		}

		return is_post_type_viewable( $object );
	}
}
