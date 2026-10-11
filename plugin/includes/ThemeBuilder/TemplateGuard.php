<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\ThemeBuilder;

use Stonewright\WpMcp\Security\Permissions;

/**
 * Permission checks shared by the Theme Builder abilities.
 *
 * A template renders on every page it applies to, so changing one needs the theme options capability as
 * well as the right to edit (or delete) that one template.
 */
final class TemplateGuard {

	/**
	 * The refusal for execute(), which answers with an error in every case.
	 *
	 * @param string $capability `edit_post` or `delete_post`.
	 */
	public static function execute_error( string $capability, int $template_id = 0 ): ?\WP_Error {
		if ( ! Permissions::edit_theme_options() ) {
			return self::forbidden( __( 'Changing Theme Builder templates needs permission to edit theme options.', 'stonewright' ) );
		}
		return self::object_error( $capability, $template_id );
	}

	/**
	 * Whether the current user may see the template: a published one to anybody who manages templates, any
	 * other status only to a user who may edit it.
	 */
	public static function can_view( int $template_id ): bool {
		$post = get_post( $template_id );
		if ( ! $post ) {
			return false;
		}
		return 'publish' === (string) $post->post_status || current_user_can( 'edit_post', $template_id );
	}

	/**
	 * A 403 error when the current user may not use $capability on the template, null otherwise.
	 *
	 * @param string $capability `edit_post` or `delete_post`.
	 * @param int    $template_id The template, or 0 when the call has none yet.
	 */
	public static function object_error( string $capability, int $template_id ): ?\WP_Error {
		if ( $template_id > 0 && ! current_user_can( $capability, $template_id ) ) {
			return self::forbidden( __( 'You are not allowed to change this template.', 'stonewright' ) );
		}
		return null;
	}

	private static function forbidden( string $message ): \WP_Error {
		return new \WP_Error( 'stonewright_template_forbidden', $message, [ 'status' => 403 ] );
	}
}
