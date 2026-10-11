<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Content;

/**
 * Which post types the generic content abilities may create, and which ids they refuse.
 *
 * Attachments, revisions, menu items and the other internal types have their own abilities and their own
 * invariants; writing them as plain posts would break those.
 */
final class PostTypeGuard {

	private const INTERNAL = [
		'attachment',
		'revision',
		'nav_menu_item',
		'customize_changeset',
		'custom_css',
		'oembed_cache',
		'user_request',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	];

	/**
	 * An error when a post type cannot be written through the generic content abilities.
	 */
	public static function error( string $post_type ): ?\WP_Error {
		$post_type = sanitize_key( $post_type );
		$allowed   = '' !== $post_type
			&& ! in_array( $post_type, self::INTERNAL, true )
			&& ( in_array( $post_type, [ 'post', 'page' ], true ) || null !== get_post_type_object( $post_type ) );
		if ( $allowed ) {
			return null;
		}
		return new \WP_Error(
			'stonewright_invalid_post_type',
			__( 'This post type cannot be written through the generic content abilities. Use a registered content post type.', 'stonewright' ),
			[ 'status' => 400, 'post_type' => $post_type ]
		);
	}

	/**
	 * A revision is a saved copy of a post, not a post. Writing to one changes nothing a visitor can see
	 * and leaves a change that cannot be checked, so the id of the post itself is required.
	 */
	public static function revision_error( object $post ): ?\WP_Error {
		if ( 'revision' !== (string) ( $post->post_type ?? '' ) ) {
			return null;
		}
		return new \WP_Error(
			'stonewright_invalid_post_type',
			__( 'This id is a revision, not a post. Pass the id of the post itself.', 'stonewright' ),
			[ 'status' => 400 ]
		);
	}
}
