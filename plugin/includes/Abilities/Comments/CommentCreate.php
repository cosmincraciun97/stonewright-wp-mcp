<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class CommentCreate extends AbilityKernel {
	public function name(): string {
 return 'stonewright/comment-create'; }
	public function label(): string {
 return __( 'Comment: Create', 'stonewright' ); }
	public function description(): string {
 return __( 'Creates a comment on a post.', 'stonewright' ); }
	public function category(): string {
 return 'comments'; }
	public function input_schema(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => [
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'content' => [ 'type' => 'string' ],
				'parent'  => [ 'type' => 'integer', 'minimum' => 0 ],
			],
			'required' => [ 'post_id', 'content' ],
		];
	}
	public function output_schema(): array {
		return [ 'additionalProperties' => true, 'type' => 'object', 'properties' => [ 'id' => [ 'type' => 'integer' ] ], 'required' => [ 'id' ] ];
	}
	public function permission_callback( array $args ): bool|\WP_Error {
 return Permissions::edit_posts(); }
	public function execute( array $args ): array|\WP_Error {
		return $this->audit( $args, static function ( array $args ) {
			$id = wp_insert_comment( [
				'comment_post_ID' => (int) $args['post_id'],
				'comment_content' => (string) $args['content'],
				'comment_parent'  => (int) ( $args['parent'] ?? 0 ),
				'user_id'         => get_current_user_id(),
			] );
			if ( ! $id || $id instanceof \WP_Error ) {
				return $id instanceof \WP_Error ? $id : new \WP_Error( 'stonewright_comment_create_failed', 'Could not create comment.' );
			}
			return [ 'id' => (int) $id ];
		} );
	}
}
