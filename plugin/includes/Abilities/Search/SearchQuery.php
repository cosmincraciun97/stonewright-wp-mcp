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
			],
			'required'=>[ 'search' ],
		];
	}
	public function output_schema(): array {
 return [ 'additionalProperties' => true, 'type'=>'object', 'properties'=>[ 'items'=>[ 'type'=>'array' ] ], 'required'=>[ 'items' ] ]; }
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::read(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				// Subscribers (read only) must not enumerate drafts/private posts.
				$can_read_private = current_user_can( 'read_private_posts' )
					|| current_user_can( 'edit_others_posts' )
					|| current_user_can( 'edit_posts' );

				$q = new \WP_Query(
					[
						's'              => (string) $args['search'],
						'post_type'      => isset( $args['post_type'] ) ? (string) $args['post_type'] : 'any',
						'posts_per_page' => min( (int) ( $args['per_page'] ?? 20 ), 50 ),
						'post_status'    => $can_read_private ? 'any' : 'publish',
					]
				);
				$items = [];
				foreach ( $q->posts as $p ) {
					$status = (string) $p->post_status;
					if ( 'publish' === $status ) {
						// Public content is fine for any caller with the read ability gate.
					} elseif ( ! $can_read_private ) {
						continue;
					} elseif (
						! current_user_can( 'edit_post', (int) $p->ID )
						&& ! current_user_can( 'read_post', (int) $p->ID )
						&& ! current_user_can( 'edit_posts' )
					) {
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
				return [ 'items' => $items, 'total' => count( $items ) ];
			}
		);
	}
}
