<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Core\SiteMode;

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

	/** Capability an account needs to use the Stonewright MCP servers unless the site filters it. */
	public const MCP_DEFAULT_CAPABILITY = 'edit_posts';

	/**
	 * The capability an account needs to use the Stonewright MCP servers, on the Application Password
	 * route and on the OAuth route alike.
	 *
	 * Every account holds `read` and WordPress lets every account create an Application Password, so
	 * `read` would admit a subscriber or a shop customer. The default is `edit_posts`, which contributors,
	 * authors, editors and administrators hold. A site can lower or raise it with the
	 * `stonewright_mcp_required_capability` filter; a value that is not a capability name is ignored.
	 */
	public static function mcp_required_capability(): string {
		$capability = apply_filters( 'stonewright_mcp_required_capability', self::MCP_DEFAULT_CAPABILITY );

		return is_string( $capability ) && '' !== $capability ? $capability : self::MCP_DEFAULT_CAPABILITY;
	}

	/**
	 * Transport permission callback of the Application Password MCP server: the signed-in account
	 * holds the capability the MCP servers require. The request is not consulted.
	 */
	public static function can_use_mcp_transport( mixed $request = null ): bool {
		return current_user_can( self::mcp_required_capability() ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is filtered and defaults to edit_posts.
	}

	/**
	 * Whether a stored OAuth grant subject may still use the MCP server: the account
	 * exists and holds the capability the MCP transport requires of a signed-in user.
	 * Checked for a given user ID because token requests carry no logged-in user.
	 */
	public static function user_can_use_mcp( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$user = get_user_by( 'id', $user_id );
		return false !== $user && user_can( $user, self::mcp_required_capability() ); // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability is filtered and defaults to edit_posts.
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
	 * Permission gate for abilities that run code on the server: runtime PHP and the WP-CLI runners.
	 *
	 * The sandbox capability pair, and on multisite a super admin: code run there reaches every site
	 * of the network, which a site administrator may not.
	 */
	public static function can_run_code(): bool {
		if ( ! self::can_manage_sandbox() ) {
			return false;
		}

		return ! is_multisite() || is_super_admin();
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

	/**
	 * Returns false when wp-config.php sets DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS. WordPress then
	 * withdraws the theme and plugin editors (and, with the second constant, file changes altogether), so a
	 * tool that writes theme files must not write them either.
	 */
	public static function theme_file_writes_allowed(): bool {
		return ! ( defined( 'DISALLOW_FILE_EDIT' ) && true === constant( 'DISALLOW_FILE_EDIT' ) )
			&& self::file_mods_allowed();
	}

	/**
	 * The refusal for a theme file write while wp-config.php disallows file editing.
	 */
	public static function theme_file_writes_refusal(): \WP_Error {
		return new \WP_Error(
			'stonewright_file_edit_disabled',
			__( 'Theme files cannot be written because DISALLOW_FILE_EDIT or DISALLOW_FILE_MODS is set in wp-config.php.', 'stonewright' ),
			[ 'status' => 403 ]
		);
	}

	public static function destructive(): bool {
		if ( ! self::manage_options() ) {
			return false;
		}
		return ! SiteMode::is_production_safe();
	}

	public static function not_production_safe(): bool {
		return ! SiteMode::is_production_safe();
	}

	public static function is_production_safe(): bool {
		return SiteMode::is_production_safe();
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
	 * Returns true if the current user can publish posts of the given type.
	 */
	public static function can_publish_post_type( string $post_type ): bool {
		$capability = self::publish_cap_for_status( $post_type, 'publish' );
		return null !== $capability && current_user_can( $capability );
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

	/**
	 * Whether the user may edit one comment. Core maps this to the comment's post, and denies a comment that does not exist.
	 */
	public static function edit_comment( int $comment_id ): bool {
		return $comment_id > 0 && current_user_can( 'edit_comment', $comment_id );
	}

	/**
	 * Whether the user may read one post or attachment.
	 */
	public static function read_post( int $post_id ): bool {
		return $post_id > 0 && current_user_can( 'read_post', $post_id );
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

	public static function promote_users(): bool {
		return current_user_can( 'promote_users' );
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

	/**
	 * ACF field-group management. ACF does not expose a dedicated capability;
	 * manage_options is the practical admin gate.
	 */
	public static function manage_acf(): bool {
		return current_user_can( 'manage_options' );
	}
}
