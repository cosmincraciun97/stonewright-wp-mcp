<?php
/**
 * Sends a person who has just activated Stonewright to the Overview, once.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Activation arms a short-lived flag; the next admin request that can use it takes it and redirects.
 *
 * A site that has already decided whether Stonewright is on (the `stonewright_enabled` option exists) is not
 * sent anywhere, and neither is a bulk activation, an AJAX request, the network admin or someone who cannot
 * manage options. The flag is consumed on the first admin request in every case, so it can never redirect a
 * later visit.
 */
final class ActivationRedirect {

	public const TRANSIENT = 'stonewright_activation_redirect';

	private const TTL = 60;

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'run' ] );
	}

	/** Called from the activation routine. */
	public static function arm(): void {
		if ( self::option_exists() ) {
			return;
		}
		set_transient( self::TRANSIENT, 1, self::TTL );
	}

	/** The `admin_init` callback: redirect and stop. */
	public static function run(): void {
		if ( self::maybe_redirect() ) {
			exit;
		}
	}

	/** Take the flag and redirect when the request allows it. Returns whether a redirect was sent. */
	public static function maybe_redirect(): bool {
		if ( ! get_transient( self::TRANSIENT ) ) {
			return false;
		}
		delete_transient( self::TRANSIENT );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads WordPress's own bulk-activation marker.
		if ( isset( $_GET['activate-multi'] ) ) {
			return false;
		}
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'is_network_admin' ) && is_network_admin() ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=stonewright-status' ) );

		return true;
	}

	/** Whether the operator choice exists at all, even as a stored "off". */
	private static function option_exists(): bool {
		$missing = new \stdClass();

		return $missing !== get_option( 'stonewright_enabled', $missing );
	}
}
