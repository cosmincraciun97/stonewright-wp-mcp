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
 * - an administrator sees an error notice on every Stonewright screen that names the plugin that owns the
 *   adapter, its version, and what to do;
 * - the two Stonewright routes answer HTTP 503 with the same explanation instead of "no route".
 *
 * Neither is shown while an adapter serves the route, including another plugin's older copy that
 * Stonewright can use.
 */
final class McpRuntimeNotice {

	public const ERROR_CODE = 'stonewright_mcp_adapter_conflict';

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'on_admin_notices' ] );
		// After the adapter registered its own routes, so a route that exists is never replaced.
		add_action( 'rest_api_init', [ self::class, 'register_fallback_routes' ], 999 );
	}

	/** WordPress hands do_action() callbacks an empty string when the action has no arguments, so the screen is read here. */
	public static function on_admin_notices(): void {
		self::render( self::current_screen_id() );
	}

	/**
	 * @param string $screen_id The screen to render for.
	 */
	public static function render( string $screen_id ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$status = McpRuntimeStatus::current();
		if ( McpRuntimeStatus::BLOCKED !== $status['state'] ) {
			return;
		}
		if ( ! str_contains( $screen_id, 'stonewright' ) ) {
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
		echo '</div>';
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
