<?php
/**
 * Links on the Stonewright row of the Plugins screen.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * The plugin list is where people look for a plugin after activating it. The row offers the two places to go
 * first (Overview and Setup) ahead of Deactivate, and a link to the documentation among the row details.
 */
final class PluginActionLinks {

	private const DOCS_URL = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/tree/main/docs';

	public static function register(): void {
		add_filter( 'plugin_action_links_' . self::basename(), [ self::class, 'action_links' ] );
		add_filter( 'plugin_row_meta', [ self::class, 'row_meta' ], 10, 2 );
	}

	/**
	 * @param array<string, string> $links The links WordPress prints for this plugin.
	 * @return array<string, string>
	 */
	public static function action_links( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		return array_merge(
			[
				'stonewright-overview' => '<a href="' . esc_url( admin_url( 'admin.php?page=stonewright-status' ) ) . '">' . esc_html__( 'Overview', 'stonewright' ) . '</a>',
				'stonewright-setup'    => '<a href="' . esc_url( admin_url( 'admin.php?page=stonewright' ) ) . '">' . esc_html__( 'Setup', 'stonewright' ) . '</a>',
			],
			$links
		);
	}

	/**
	 * @param array<int|string, string> $meta Details printed under the plugin description.
	 * @return array<int|string, string>
	 */
	public static function row_meta( array $meta, string $plugin_file ): array {
		if ( self::basename() !== $plugin_file ) {
			return $meta;
		}

		$meta[] = '<a href="' . esc_url( self::DOCS_URL ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'Stonewright documentation (opens in a new tab)', 'stonewright' ) . '">' . esc_html__( 'Docs', 'stonewright' ) . '</a>';

		return $meta;
	}

	private static function basename(): string {
		return defined( 'STONEWRIGHT_FILE' ) ? plugin_basename( (string) constant( 'STONEWRIGHT_FILE' ) ) : 'stonewright/stonewright.php';
	}
}
