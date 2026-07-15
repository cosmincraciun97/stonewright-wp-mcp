<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Reusable permission callbacks. Abilities should call into these helpers
 * rather than declaring inline closures so that the policy lives in one file.
 */
final class Permissions {

	public static function read(): bool {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	public static function edit_posts(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function edit_pages(): bool {
		return current_user_can( 'edit_pages' );
	}

	public static function edit_post( int $post_id ): bool {
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	public static function upload_files(): bool {
		return current_user_can( 'upload_files' );
	}

	public static function edit_theme_options(): bool {
		return current_user_can( 'edit_theme_options' );
	}

	public static function manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission gate for sandbox abilities.
	 *
	 * Sandbox abilities execute or stage PHP on the production server, so they
	 * require BOTH `edit_plugins` (the WordPress capability that guards plugin
	 * code editing) AND `manage_options` (site administration). The conjunction
	 * is intentional: on multisite, `edit_plugins` is granted to super admins
	 * only, and `manage_options` is the per-site administrator gate.
	 */
	public static function can_manage_sandbox(): bool {
		return current_user_can( 'edit_plugins' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Read-only gate for sandbox/widget listing. Requires manage_options so that
	 * subscriber/editor-level users cannot enumerate staged PHP files.
	 */
	public static function can_view_sandbox(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns false when WordPress' `DISALLOW_FILE_MODS` constant is set, which
	 * means the host operator has explicitly disabled plugin/theme file mods.
	 * Sandbox mutating abilities must short-circuit when this returns false.
	 */
	public static function file_mods_allowed(): bool {
		return ! ( defined( 'DISALLOW_FILE_MODS' ) && true === constant( 'DISALLOW_FILE_MODS' ) );
	}

	public static function destructive(): bool {
		if ( ! self::manage_options() ) {
			return false;
		}
		return 'production-safe' !== get_option( 'stonewright_mode', 'development' );
	}

	public static function not_production_safe(): bool {
		return 'production-safe' !== get_option( 'stonewright_mode', 'development' );
	}

	public static function is_production_safe(): bool {
		return 'production-safe' === get_option( 'stonewright_mode', 'development' );
	}

	/**
	 * Returns the capability required to create posts of the given post type.
	 * Defaults to `edit_posts` when the post type is unknown (so callers can still
	 * fail closed with a generic 403).
	 */
	public static function create_cap_for_post_type( string $post_type ): string {
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || ! isset( $obj->cap->create_posts ) ) {
			return 'edit_posts';
		}
		return (string) $obj->cap->create_posts;
	}

	/**
	 * Returns the capability required to publish (or set publish-equivalent
	 * status) on the given post type. Returns null if no publish gate is needed
	 * for the supplied status.
	 */
	public static function publish_cap_for_status( string $post_type, string $status ): ?string {
		if ( ! in_array( $status, [ 'publish', 'private', 'future' ], true ) ) {
			return null;
		}
		$obj = get_post_type_object( $post_type );
		if ( ! $obj ) {
			return 'publish_posts';
		}
		// WordPress core has no distinct private_posts cap; publish_posts gates private content too.
		if ( isset( $obj->cap->publish_posts ) ) {
			return (string) $obj->cap->publish_posts;
		}
		return 'publish_posts';
	}

	/**
	 * Returns true if the current user can create posts of the given type.
	 */
	public static function can_create_post_type( string $post_type ): bool {
		return current_user_can( self::create_cap_for_post_type( $post_type ) );
	}

	/**
	 * Permission gate for design management abilities (validate and apply specs).
	 *
	 * Requires manage_options + edit_pages — design writes affect live page content
	 * so we gate at the same level as theme/global-styles mutations.
	 */
	public static function can_manage_design(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_pages' );
	}

	/**
	 * Permission gate for read-only design abilities (preview render, spec validation).
	 *
	 * Requires manage_options only — no content write occurs.
	 */
	public static function can_view_design(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns true if the current user can edit the given post.
	 */
	public static function can_edit_post( int $post_id ): bool {
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Permission gate for FSE write abilities (write_template, write_template_part,
	 * write_global_styles). Requires manage_options + edit_theme_options.
	 */
	public static function can_manage_fse(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_theme_options' );
	}

	/**
	 * Returns true if the current user can write meta key $meta_key on $post_id.
	 * Falls back to edit_post when the meta-specific cap is unmapped.
	 */
	public static function can_edit_post_meta( int $post_id, string $meta_key ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		return current_user_can( 'edit_post_meta', $post_id, $meta_key );
	}

	public static function moderate_comments(): bool {
		return current_user_can( 'moderate_comments' );
	}

	public static function list_users(): bool {
		return current_user_can( 'list_users' );
	}

	public static function create_users(): bool {
		return current_user_can( 'create_users' );
	}

	public static function edit_users(): bool {
		return current_user_can( 'edit_users' );
	}

	public static function delete_users(): bool {
		return current_user_can( 'delete_users' );
	}

	public static function switch_themes(): bool {
		return current_user_can( 'switch_themes' );
	}

	public static function edit_css(): bool {
		return current_user_can( 'edit_css' ) || current_user_can( 'edit_theme_options' );
	}

	public static function activate_plugins(): bool {
		return current_user_can( 'activate_plugins' );
	}

	public static function delete_plugins(): bool {
		return current_user_can( 'delete_plugins' );
	}

	public static function manage_woocommerce(): bool {
		// Prefer WooCommerce's manage_woocommerce when the plugin is present; fall back to manage_options.
		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- custom cap registered by WooCommerce.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return current_user_can( 'manage_options' );
	}
}
