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

	/** The class of a sidebar item that is kept for WordPress, which opens the right menu on its page, and not shown. */
	public const HIDDEN_CLASS = 'sw-menu-hidden';

	/** The setting that shows Stonewright in the sidebar (on by default). */
	public const SHOW_OPTION = 'stonewright_admin_menu';

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'apply' ], self::PRIORITY );
	}

	/** Whether Stonewright is shown in the sidebar. */
	public static function is_shown(): bool {
		return (bool) get_option( self::SHOW_OPTION, true );
	}

	/**
	 * Reorder and rename the entries under the Stonewright top-level menu. With the sidebar setting off, the top-level
	 * item keeps its registration and is only hidden, so every Stonewright address and the links on the Plugins
	 * screen keep working.
	 */
	public static function apply(): void {
		global $menu, $submenu;
		if ( ! self::is_shown() && is_array( $menu ) ) {
			foreach ( $menu as $position => $item ) {
				if ( is_array( $item ) && MenuRegistry::PARENT === ( $item[2] ?? null ) ) {
					$menu[ $position ][4] = trim( (string) ( $item[4] ?? '' ) . ' ' . self::HIDDEN_CLASS ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Hiding the item keeps its registration.
				}
			}
		}
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
		// A page that is another page's own tab has an address and a registration, but no entry of its own. Its item stays
		// in the menu, at the end and hidden, because WordPress finds the menu to open on a page by looking for its item.
		$views = [];
		foreach ( MenuRegistry::entries() as $entry ) {
			if ( '' !== $entry['parent'] ) {
				$views[ $entry['slug'] ] = true;
			}
		}
		$by_slug = [];
		$hidden  = [];
		foreach ( $items as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( isset( $views[ $slug ] ) ) {
				$item[4]  = trim( (string) ( $item[4] ?? '' ) . ' ' . self::HIDDEN_CLASS );
				$hidden[] = $item;
				continue;
			}
			$by_slug[ $slug ] = $item;
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

		return array_merge( $ordered, array_values( $by_slug ), $hidden );
	}

	/**
	 * The sidebar text: the registry name, followed by the small EXP marker for a page that is still changing.
	 *
	 * @param Entry $entry
	 */
	private static function title( array $entry ): string {
		$label = MenuRegistry::menu_label( $entry );
		$title = $entry['beta'] ? AdminShell::beta_menu_title( $label ) : $label;

		return $title . self::menu_count( $entry );
	}

	/**
	 * The count beside a sidebar entry, for a page that asks for it: the number and its words for assistive technology,
	 * in the bubble WordPress draws beside its own entries. Empty when there is nothing to show.
	 *
	 * @param Entry $entry
	 */
	private static function menu_count( array $entry ): string {
		if ( ! MenuRegistry::shows_menu_count( $entry['slug'] ) ) {
			return '';
		}
		$count = MenuRegistry::page_count( $entry['slug'] );
		if ( null === $count ) {
			return '';
		}
		$words = MenuRegistry::page_count_label( $entry['slug'] );

		return ' <span class="awaiting-mod sw-menu-count"><span class="sw-menu-count__n" aria-hidden="true">' . esc_html( (string) $count ) . '</span>'
			. '<span class="screen-reader-text"> ' . esc_html( (string) $count . ( '' !== $words ? ' ' . $words : '' ) ) . '</span></span>';
	}
}
