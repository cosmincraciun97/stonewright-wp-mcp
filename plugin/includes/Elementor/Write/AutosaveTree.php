<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/**
 * The Elementor tree of the current user's autosave of a post.
 *
 * Elementor stages an edit of a published document in an autosave of the current user and leaves the published
 * tree alone, so a reader that only looks at the document's own meta never sees such an edit.
 */
final class AutosaveTree {

	/** @return array<int,mixed>|null Null when the user has no autosave of the post. */
	public static function of( int $post_id ): ?array {
		if ( $post_id < 1 || ! function_exists( 'wp_get_post_autosave' ) ) {
			return null;
		}
		$autosave = wp_get_post_autosave( $post_id, get_current_user_id() );
		if ( ! is_object( $autosave ) ) {
			return null;
		}
		return ElementTreeDiff::tree_from_meta( get_post_meta( (int) $autosave->ID, '_elementor_data', true ) );
	}
}
