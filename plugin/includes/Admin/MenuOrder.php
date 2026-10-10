<?php
/**
 * Orders and names the Stonewright sidebar entries from the menu registry.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Pages register their own sidebar entry, in whatever order the plugin boots them. One pass at the end of
 * `admin_menu` puts those entries in hub order and gives them the registry's names, so the sidebar and the
 * band agree. The first entry becomes the top-level link, so a click on "Stonewright" opens the Overview.
 *
 * Nothing is added, removed or re-registered: each entry keeps its slug, its capability and its page title, which
 * is what keeps every bookmark and every capability check working. Entries that are not in the registry keep
 * their name and go after the registered ones.
 *
 * @phpstan-import-type Entry from MenuRegistry
 */
final class MenuOrder {

	/** Runs after every page has added its entry. */
	private const PRIORITY = 999;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'apply' ], self::PRIORITY );
	}

	/** Reorder and rename the entries under the Stonewright top-level menu. */
	public static function apply(): void {
		global $submenu;
		if ( ! is_array( $submenu ) || ! isset( $submenu[ MenuRegistry::PARENT ] ) || ! is_array( $submenu[ MenuRegistry::PARENT ] ) ) {
			return;
		}

		$submenu[ MenuRegistry::PARENT ] = self::order( array_values( $submenu[ MenuRegistry::PARENT ] ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Ordering the sub-menu is the purpose of this pass.
	}

	/**
	 * @param list<array<int, mixed>> $items Menu entries: title, capability, slug, page title.
	 * @return list<array<int, mixed>>
	 */
	public static function order( array $items ): array {
		$by_slug = [];
		foreach ( $items as $item ) {
			$by_slug[ (string) ( $item[2] ?? '' ) ] = $item;
		}

		$ordered = [];
		foreach ( MenuRegistry::menu_entries() as $entry ) {
			if ( ! isset( $by_slug[ $entry['slug'] ] ) ) {
				continue;
			}
			$item      = $by_slug[ $entry['slug'] ];
			$item[0]   = self::title( $entry );
			$ordered[] = $item;
			unset( $by_slug[ $entry['slug'] ] );
		}

		return array_merge( $ordered, array_values( $by_slug ) );
	}

	/**
	 * The sidebar text: the registry name, followed by the small EXP marker for a page that is still changing.
	 *
	 * @param Entry $entry
	 */
	private static function title( array $entry ): string {
		$label = MenuRegistry::menu_label( $entry );

		return $entry['beta'] ? AdminShell::beta_menu_title( $label ) : $label;
	}
}
