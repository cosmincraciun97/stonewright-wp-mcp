<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Comments;

use Stonewright\WpMcp\Security\Permissions;

/**
 * The per-comment capability check of the comment abilities: edit_comment, which core maps to the
 * comment's post. The generic edit_posts and moderate_comments capabilities say nothing about one comment.
 */
final class CommentAccess {

	/**
	 * The refusal for a comment the user may not work with, or null when the user may edit it.
	 */
	public static function refusal( int $comment_id ): ?\WP_Error {
		if ( $comment_id <= 0 || null === get_comment( $comment_id ) ) {
			return new \WP_Error( 'stonewright_comment_not_found', __( 'Comment not found.', 'stonewright' ), [ 'status' => 404, 'id' => $comment_id ] );
		}
		if ( ! Permissions::edit_comment( $comment_id ) ) {
			return new \WP_Error( 'stonewright_forbidden', __( 'Insufficient capability to work with this comment.', 'stonewright' ), [ 'status' => 403, 'id' => $comment_id ] );
		}
		return null;
	}
}
