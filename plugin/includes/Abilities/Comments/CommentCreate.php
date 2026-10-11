<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Creates a comment through core's own comment pipeline (wp_new_comment), so the content is filtered the way
 * core filters it (kses for a user without unfiltered_html), duplicate and flood checks run, and the
 * moderation rules decide the status.
 *
 * Capability: edit_post on the target post. That is the capability core checks when an editor replies to a
 * comment in wp-admin, and edit_comment maps to edit_post of the comment's post. edit_posts alone would let any
 * contributor comment on any post, including a private post of someone else. moderate_comments is a site-wide
 * capability and is not a per-post grant, so it is used only to allow a reply on a post that is closed to
 * comments, as the admin reply screen allows.
 *
 * @stonewright-status stable
 */
final class CommentCreate extends AbilityKernel {
	public function name(): string {
 return 'stonewright/comment-create'; }
	public function label(): string {
 return __( 'Comment: Create', 'stonewright' ); }
	public function description(): string {
 return __( 'Creates a comment on a post as the current user, through the same filtering and moderation rules core applies. The post must exist, must not be a draft, pending or trashed post, and must accept comments unless the user can moderate comments; a parent comment must belong to the same post. Requires edit access to the post.', 'stonewright' ); }
	public function category(): string {
 return 'comments'; }
	public function input_schema(): array {
		return [
			'type' => 'object',
			'additionalProperties' => false,
			'properties' => [
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'content' => [ 'type' => 'string', 'minLength' => 1 ],
				'parent'  => [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Optional parent comment id. It must belong to the same post.' ],
			],
			'required' => [ 'post_id', 'content' ],
		];
	}
	public function output_schema(): array {
		return [
			'additionalProperties' => true,
			'type'                 => 'object',
			'properties'           => [
				'id'      => [ 'type' => 'integer' ],
				'post_id' => [ 'type' => 'integer' ],
				'parent'  => [ 'type' => 'integer' ],
				'status'  => [ 'type' => 'string', 'description' => 'approve or hold, as the moderation rules decided.' ],
			],
			'required'             => [ 'id' ],
		];
	}

	/**
	 * @stonewright-cap edit_post($args['post_id'])
	 */
	public function permission_callback( array $args ): bool|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return new \WP_Error( 'stonewright_not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
		}
		if ( ! Permissions::edit_post( $post_id ) ) {
			return new \WP_Error(
				'stonewright_forbidden',
				__( 'Insufficient capability to comment on this post.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write( $args, function ( array $args ) {
			$post_id = (int) $args['post_id'];
			$post    = get_post( $post_id );
			if ( ! $post ) {
				return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
			}
			// Core refuses a comment on a post nobody can read yet, as the REST comments endpoint and the admin reply do.
			if ( in_array( (string) $post->post_status, [ 'draft', 'pending', 'auto-draft', 'trash' ], true ) ) {
				return $this->error( 'comment_post_unavailable', __( 'This post is not published, so it cannot take comments.', 'stonewright' ), [ 'status' => 409 ] );
			}
			// A closed post takes no comments, except a reply from a user who can moderate (the admin reply screen allows it).
			if ( ! comments_open( $post_id ) && ! Permissions::moderate_comments() ) {
				return $this->error( 'comments_closed', __( 'Comments are closed on this post.', 'stonewright' ), [ 'status' => 409 ] );
			}

			$content = trim( (string) $args['content'] );
			if ( '' === $content ) {
				return $this->error( 'comment_content_required', __( 'The comment cannot be empty.', 'stonewright' ), [ 'status' => 400 ] );
			}

			$parent = (int) ( $args['parent'] ?? 0 );
			if ( $parent > 0 ) {
				$parent_comment = get_comment( $parent );
				if ( null === $parent_comment || (int) $parent_comment->comment_post_ID !== $post_id ) {
					return $this->error( 'comment_parent_invalid', __( 'The parent comment does not exist on this post.', 'stonewright' ), [ 'status' => 400, 'parent' => $parent ] );
				}
			}

			$user = wp_get_current_user();
			$data = [
				'comment_post_ID'      => $post_id,
				'comment_content'      => $content,
				'comment_parent'       => $parent,
				'comment_type'         => 'comment',
				'user_id'              => get_current_user_id(),
				'comment_author'       => (string) ( $user->display_name ?? '' ),
				'comment_author_email' => (string) ( $user->user_email ?? '' ),
				'comment_author_url'   => (string) ( $user->user_url ?? '' ),
			];

			// wp_new_comment takes slashed data, filters the content (pre_comment_content, which is kses for a user
			// without unfiltered_html), runs the duplicate and flood checks, and decides the status.
			$id = wp_new_comment( wp_slash( $data ), true );
			if ( $id instanceof \WP_Error ) {
				return $id;
			}
			if ( ! $id ) {
				return $this->error( 'comment_create_failed', __( 'Could not create comment.', 'stonewright' ), [ 'status' => 500 ] );
			}

			return [
				'id'      => (int) $id,
				'post_id' => $post_id,
				'parent'  => $parent,
				'status'  => CommentStatus::of( get_comment( (int) $id ) ),
			];
		} );
	}
}
