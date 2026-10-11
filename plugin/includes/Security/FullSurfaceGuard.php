<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * The Full tool surface, and with it the full profile (php-execute and the other abilities that run or write
 * code), is reached only by a person in wp-admin. This guard answers two questions for the places that can
 * write the surface: does a value put Full in effect, and is the request a person in wp-admin.
 *
 * A person in wp-admin is a cookie sign-in with a valid REST nonce on a REST request, or an admin screen
 * (admin-post and admin-ajax carry their own nonce checks). An Application Password, an OAuth bearer, a
 * WP-CLI run and an ability call are not.
 *
 * @stonewright-status stable
 */
final class FullSurfaceGuard {

	public const ERROR_CODE = 'stonewright_full_surface_requires_admin';

	/**
	 * Whether writing $value to the option $name puts the Full surface or the full profile in effect.
	 */
	public static function puts_full_in_effect( string $name, mixed $value ): bool {
		return match ( $name ) {
			'stonewright_mcp_surface'          => is_string( $value ) && 'full' === strtolower( trim( $value ) ),
			'stonewright_essential_tools_mode' => false === $value || 0 === $value || '0' === $value || '' === $value,
			'stonewright_last_tool_profile'    => is_string( $value ) && 'full' === strtolower( trim( $value ) ),
			default                            => false,
		};
	}

	/**
	 * Whether the current request is a person in wp-admin.
	 *
	 * @param \WP_REST_Request|null $request The REST request, when the caller is a REST route.
	 */
	public static function human_in_wp_admin( ?\WP_REST_Request $request = null ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( null !== $request ) {
			// WordPress resets the user to nobody when a cookie request has no valid nonce; the cookie flag is only
			// true for a request that signed in by cookie. The nonce is checked again here, for this request.
			if ( true !== ( $GLOBALS['wp_rest_auth_cookie'] ?? false ) ) {
				return false;
			}
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! is_string( $nonce ) || '' === $nonce ) {
				$param = $request->get_param( '_wpnonce' );
				$nonce = is_string( $param ) ? $param : '';
			}
			return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
		}
		// Not a REST request: an admin screen, an admin-post or an admin-ajax request. WP-CLI is not one.
		return is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * The address where an administrator chooses the tool surface.
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=stonewright&tab=settings' ) . '#stonewright_mcp_surface';
	}

	/**
	 * The refusal of a change that would put the Full surface in effect without a person in wp-admin.
	 */
	public static function refusal( string $code = self::ERROR_CODE, string $message = '' ): \WP_Error {
		if ( '' === $message ) {
			$message = __( 'The Full tool surface (php-execute and the other abilities that run or write code) can only be chosen by an administrator in wp-admin, under Stonewright > Setup > Settings > MCP tool surface. Show the user the settings link and stop. Do not retry, and do not look for another way to switch it.', 'stonewright' );
		}

		return new \WP_Error(
			$code,
			$message,
			[
				'status'            => 403,
				'retryable'         => false,
				'execution_status'  => 'blocked',
				'approval_required' => true,
				'agent_must_stop'   => true,
				'approval_url'      => self::settings_url(),
			]
		);
	}
}
