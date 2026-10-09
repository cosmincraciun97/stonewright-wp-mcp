<?php
/**
 * The views of the Setup page and their addresses.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Setup;

/**
 * Setup has four views: Get started, Settings, Connections and Updates. The server renders the one the `tab`
 * argument names (the first when it is missing or unknown), so a link, a redirect after a save and a reload all
 * land on the right view without script.
 */
final class SetupTabs {

	public const PARAM = 'tab';
	public const SLUG  = 'stonewright';

	/** @return list<string> */
	public static function ids(): array {
		return [ 'get-started', 'settings', 'connections', 'updates' ];
	}

	public static function current(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selector.
		$requested = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? (string) wp_unslash( $_GET[ self::PARAM ] ) : '';

		return in_array( $requested, self::ids(), true ) ? $requested : self::ids()[0];
	}

	/**
	 * The address of a view, with extra query arguments and an anchor when given. The first view has no `tab`.
	 *
	 * @param array<string, string> $args
	 */
	public static function url( string $tab = '', array $args = [], string $anchor = '' ): string {
		$query = [ 'page' => self::SLUG ];
		if ( in_array( $tab, self::ids(), true ) && self::ids()[0] !== $tab ) {
			$query[ self::PARAM ] = $tab;
		}
		$url = add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );

		return '' !== $anchor ? $url . '#' . $anchor : $url;
	}
}
