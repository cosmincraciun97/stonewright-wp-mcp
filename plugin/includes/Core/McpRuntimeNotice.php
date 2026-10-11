<?php
/**
 * The admin notice and the explicit route answer for a site where no usable MCP adapter is loaded.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use WP_Error;

/**
 * Without a usable adapter Stonewright cannot register its MCP server, and a client that posts to
 * `/wp-json/mcp/stonewright` is told "no route", which says nothing about the cause. This class makes the
 * cause visible in two places and changes nothing else:
 *
 * - an administrator sees an error notice on every Stonewright screen, the Plugins screen and the Dashboard that
 *   names the plugin that owns the adapter, its version, and what to do. Each user can dismiss it for 7 days; it
 *   comes back at once when the cause changes (another owner, another version or other reasons);
 * - the two Stonewright routes answer HTTP 503 with the same explanation instead of "no route".
 *
 * Neither is shown while an adapter serves the route, including another plugin's older copy that
 * Stonewright can use.
 */
final class McpRuntimeNotice {

	public const ERROR_CODE = 'stonewright_mcp_adapter_conflict';

	public const DISMISS_ACTION = 'stonewright_dismiss_mcp_runtime_notice';

	public const DISMISS_META = 'stonewright_mcp_runtime_notice_dismissed';

	public const DISMISS_SECONDS = 604800;

	/** Screens other than Stonewright's that show the notice. */
	private const WIDE_SCREENS = [ 'plugins', 'dashboard' ];

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'on_admin_notices' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ self::class, 'handle_dismiss' ] );
		// After the adapter registered its own routes, so a route that exists is never replaced.
		add_action( 'rest_api_init', [ self::class, 'register_fallback_routes' ], 999 );
	}

	/** WordPress hands do_action() callbacks an empty string when the action has no arguments, so the screen is read here. */
	public static function on_admin_notices(): void {
		self::render( self::current_screen_id() );
	}

	/**
	 * @param string   $screen_id The screen to render for.
	 * @param int|null $now       The time to judge a dismissal by; the current time when null.
	 */
	public static function render( string $screen_id, ?int $now = null ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = McpRuntimeStatus::current();
		if ( McpRuntimeStatus::BLOCKED !== $status['state'] ) {
			return;
		}
		if ( ! str_contains( $screen_id, 'stonewright' ) && ! in_array( $screen_id, self::WIDE_SCREENS, true ) ) {
			return;
		}
		if ( self::is_dismissed( get_current_user_id(), $status, $now ?? time() ) ) {
			return;
		}

		echo '<div class="notice notice-error stonewright-notice"><p><strong>Stonewright:</strong> ';
		echo esc_html( $status['summary'] );
		echo '</p><p>' . esc_html( $status['remedy'] ) . '</p>';
		if ( ! str_contains( $screen_id, 'troubleshoot' ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=stonewright-troubleshoot' ) ) . '">';
			echo esc_html__( 'Open Troubleshoot', 'stonewright' );
			echo '</a></p>';
		}
		echo '<p><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION ) ) . '">';
		echo esc_html__( 'Dismiss for 7 days', 'stonewright' );
		echo '</a></p></div>';
	}

	/** Hides the notice for the current user for 7 days; the cause is read here, never taken from the request. */
	public static function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to dismiss this notice.', 'stonewright' ) );
		}
		check_admin_referer( self::DISMISS_ACTION );

		self::dismiss( get_current_user_id() );

		$back = function_exists( 'wp_get_referer' ) ? wp_get_referer() : false;
		wp_safe_redirect( is_string( $back ) && '' !== $back ? $back : admin_url() );
		exit;
	}

	/** Remembers that this user dismissed the cause that is blocking the route now. */
	public static function dismiss( int $user_id, ?int $now = null ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta(
			$user_id,
			self::DISMISS_META,
			[
				'cause' => self::cause_key( McpRuntimeStatus::current() ),
				'until' => ( $now ?? time() ) + self::DISMISS_SECONDS,
			]
		);
	}

	/** @param array<string,mixed> $status */
	private static function is_dismissed( int $user_id, array $status, int $now ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$stored = get_user_meta( $user_id, self::DISMISS_META, true );

		return is_array( $stored )
			&& (int) ( $stored['until'] ?? 0 ) > $now
			&& hash_equals( (string) ( $stored['cause'] ?? '' ), self::cause_key( $status ) );
	}

	/**
	 * What is blocking the route, as one short token: another owner, version or reason is another cause.
	 *
	 * @param array<string,mixed> $status
	 */
	private static function cause_key( array $status ): string {
		$reasons = array_map( 'strval', (array) ( $status['blocking_reasons'] ?? [] ) );
		sort( $reasons );

		return substr(
			hash( 'sha256', implode( '|', [ (string) ( $status['adapter_owner'] ?? '' ), (string) ( $status['adapter_version'] ?? '' ), implode( ',', $reasons ) ] ) ),
			0,
			16
		);
	}

	/** Gives both Stonewright routes an answer that says why they cannot serve MCP requests. */
	public static function register_fallback_routes(): void {
		if ( McpRuntimeStatus::BLOCKED !== McpRuntimeStatus::current()['state'] ) {
			return;
		}
		foreach ( [ ServerRegistration::ROUTE, ServerRegistration::OAUTH_ROUTE ] as $route ) {
			register_rest_route(
				ServerRegistration::ROUTE_NAMESPACE,
				'/' . $route,
				[
					'methods'             => [ 'GET', 'POST', 'DELETE' ],
					'callback'            => [ self::class, 'fallback_response' ],
					// The answer is the public explanation only; it reaches the client that is told to connect.
					'permission_callback' => static fn(): bool => true,
				]
			);
		}
	}

	/**
	 * @param mixed $request Unused.
	 */
	public static function fallback_response( mixed $request = null ): WP_Error {
		unset( $request );
		$status = McpRuntimeStatus::current();

		return new WP_Error(
			self::ERROR_CODE,
			trim( $status['summary'] . ' ' . $status['remedy'] ),
			[ 'status' => 503 ]
		);
	}

	private static function current_screen_id(): string {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return '';
		}
		$screen = get_current_screen();

		return is_object( $screen ) ? (string) $screen->id : '';
	}
}
