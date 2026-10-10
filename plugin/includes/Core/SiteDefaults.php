<?php
/**
 * The options a site starts with.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Writes the mode and the first-activation surface of a site, in one place for every path that
 * first reaches a site:
 * - on_activate(), for the site a plugin is activated on, or the main site on a network activation;
 * - maybe_upgrade(), on a site's first request after a network activation and on the first request
 *   after an update, because WordPress runs the activation hook once for a whole network;
 * - seed_new_site(), for a site created after a network activation.
 *
 * A value that already exists is never replaced. The mode follows the environment type
 * (production gives production-safe, staging gives staging, anything else development). The surface
 * options are written only for a site that has not run the plugin before: an update leaves them unset,
 * so AbilityRegistry::mcp_surface() keeps mapping from the legacy essential-tools flag.
 */
final class SiteDefaults {

	/**
	 * Initialize what is missing on the current site.
	 *
	 * @param bool $first_install True for a site that has never run the plugin (no stonewright_version):
	 *                            the surface options are initialized as well.
	 */
	public static function seed( bool $first_install ): void {
		if ( ! get_option( 'stonewright_mode' ) ) {
			update_option( 'stonewright_mode', self::mode_for_environment() );
		}
		// New installs start on the useful bounded surface. Bootstrap remains an explicit
		// transport/profile diagnostic, never a permanent install default.
		if ( $first_install ) {
			if ( null === get_option( 'stonewright_mcp_surface', null ) ) {
				update_option( 'stonewright_mcp_surface', 'essential', false );
			}
			if ( null === get_option( 'stonewright_essential_tools_mode', null ) ) {
				update_option( 'stonewright_essential_tools_mode', true, false );
			}
		}
	}

	/**
	 * The mode a site gets when none is stored.
	 */
	public static function mode_for_environment(): string {
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'development';
		return match ( $environment ) {
			'production' => 'production-safe',
			'staging'    => 'staging',
			default      => 'development',
		};
	}

	/**
	 * On wp_initialize_site: give a site created after a network activation its defaults at once.
	 * A plugin that is not network-active is left out, because it may not be active on the new site.
	 * The version option stays unset, so the site's first request still runs the rest of its setup.
	 *
	 * @param object $site            The new site (WP_Site); only blog_id is read.
	 * @param string $plugin_basename The plugin's path relative to the plugins directory.
	 */
	public static function seed_new_site( object $site, string $plugin_basename ): void {
		if ( ! is_multisite() || ! self::network_active( $plugin_basename ) ) {
			return;
		}
		$blog_id = (int) ( $site->blog_id ?? 0 );
		if ( $blog_id < 1 ) {
			return;
		}
		switch_to_blog( $blog_id );
		try {
			self::seed( true );
		} finally {
			restore_current_blog();
		}
	}

	private static function network_active( string $plugin_basename ): bool {
		$active = get_site_option( 'active_sitewide_plugins', [] );
		return is_array( $active ) && isset( $active[ $plugin_basename ] );
	}
}
