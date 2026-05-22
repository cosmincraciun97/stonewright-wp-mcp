<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Menu;

/**
 * Thin facade over WordPress' native nav-menu API.
 *
 * Menus live in two layers in WordPress:
 *
 *   1. The `nav_menu` taxonomy (terms) — a "menu" is a term row whose
 *      term_id is the menu id every other API accepts. `wp_create_nav_menu()`
 *      returns that term_id on success.
 *
 *   2. The `nav_menu_item` post type — each item rendered inside a menu is a
 *      post of this type, related to its parent menu via
 *      `wp_set_object_terms()`. Items carry their own URL / title / parent
 *      pointer through post meta, but `wp_update_nav_menu_item()` is the only
 *      sanctioned way to set them — it normalises a flat array of menu-item
 *      fields, handles the taxonomy assignment, the link-text → post_title
 *      mapping, and the menu-item-* meta keys. Calling it with `$item_id = 0`
 *      inserts a new item; passing the existing item id updates in place.
 *
 *   3. Theme locations (header / footer / mobile / …) are stored in the
 *      `nav_menu_locations` theme-mod, an associative array
 *      `{ location_slug => menu_id }`. The theme declares which slugs exist
 *      via `register_nav_menus()` — read that registry with
 *      `get_registered_nav_menus()` so the assign-location ability can
 *      report the available targets to the model.
 *
 * This facade exists so each ability touches a single narrow surface (and
 * tests can stub a small set of WP functions) — the rest of the plugin
 * never has to know about either the term-vs-post split or the menu-item
 * meta-key naming convention.
 */
final class MenuStore {

	/**
	 * Create an empty nav menu.
	 *
	 * Returns the new term_id on success, or whatever WP_Error
	 * wp_create_nav_menu() raised (duplicate name, empty name, etc.).
	 */
	public static function create( string $name ): int|\WP_Error {
		$result = wp_create_nav_menu( $name );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return (int) $result;
	}

	/**
	 * Append an item to an existing menu.
	 *
	 * The `$item` array follows WP's `wp_update_nav_menu_item()` input shape:
	 *
	 *   {
	 *     'menu-item-title'     : string  — the visible link text,
	 *     'menu-item-url'       : string  — href; for type=custom this is
	 *                                       the literal URL the link points at,
	 *     'menu-item-status'    : string  — 'publish' so the item is rendered
	 *                                       on the front-end (default 'draft'
	 *                                       items never show up),
	 *     'menu-item-type'      : string  — 'custom' for plain URLs; other
	 *                                       valid values are 'post_type' /
	 *                                       'taxonomy' / 'post_type_archive'
	 *                                       — these require menu-item-object-id
	 *                                       and menu-item-object,
	 *     'menu-item-parent-id' : int     — id of the parent menu item for
	 *                                       sub-menus (0 = top-level),
	 *     'menu-item-position'  : int     — ordering (1-based; omit to append).
	 *   }
	 *
	 * Returns the new item's post_id on success or a WP_Error.
	 *
	 * @param array<string, mixed> $item
	 */
	public static function add_item( int $menu_id, array $item ): int|\WP_Error {
		// WP's `wp_update_nav_menu_item` defaults menu-item-status to 'draft'
		// and menu-item-type to '' — both of which produce items that are
		// silently dropped from the rendered menu. Default to publish + custom
		// so callers that only supply title + url still get a working link.
		$item = array_merge(
			[
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			],
			$item
		);

		$result = wp_update_nav_menu_item( $menu_id, 0, $item );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// wp_update_nav_menu_item can return 0 on failure paths that do not
		// raise a WP_Error (e.g. an invalid menu_id). Surface that as an
		// explicit error so callers don't get a misleading "ok" with no id.
		if ( 0 === (int) $result ) {
			return new \WP_Error(
				'stonewright_menu_item_insert_failed',
				__( 'Could not insert menu item — wp_update_nav_menu_item returned 0.', 'stonewright' ),
				[ 'status' => 500, 'menu_id' => $menu_id ]
			);
		}
		return (int) $result;
	}

	/**
	 * Project the nav-menu term list to a small DTO suitable for the
	 * stonewright/menu-list ability.
	 *
	 * @return array<int, array{id:int, name:string, slug:string}>
	 */
	public static function list_menus(): array {
		$menus = wp_get_nav_menus();
		if ( ! is_array( $menus ) ) {
			return [];
		}
		$out = [];
		foreach ( $menus as $menu ) {
			$out[] = [
				'id'   => (int) ( $menu->term_id ?? 0 ),
				'name' => (string) ( $menu->name ?? '' ),
				'slug' => (string) ( $menu->slug ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Delete a nav menu by term_id.
	 *
	 * WP returns:
	 *   - true on successful delete
	 *   - false if the menu didn't exist (we surface that as a WP_Error so
	 *     callers can distinguish "found nothing to delete" from "delete
	 *     succeeded")
	 *   - WP_Error on permission / invalid-term-id failures
	 */
	public static function delete( int $menu_id ): bool|\WP_Error {
		$result = wp_delete_nav_menu( $menu_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error(
				'stonewright_menu_not_found',
				/* translators: %d: menu term id that wp_delete_nav_menu rejected */
				sprintf( __( 'Menu with id %d does not exist.', 'stonewright' ), $menu_id ),
				[ 'status' => 404, 'menu_id' => $menu_id ]
			);
		}
		return true;
	}

	/**
	 * Assign a menu to a theme location.
	 *
	 * Theme locations live in the `nav_menu_locations` theme_mod as an
	 * associative array { location_slug => menu_id }. We read-modify-write the
	 * map so we never clobber other registered locations.
	 *
	 * Returns true (the set_theme_mod return) — assignment cannot really
	 * "fail" because set_theme_mod always writes, but we surface a bool so
	 * the abstraction matches the other write methods.
	 */
	public static function assign_location( string $location, int $menu_id ): bool {
		$locations = get_theme_mod( 'nav_menu_locations', [] );
		if ( ! is_array( $locations ) ) {
			$locations = [];
		}
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
		return true;
	}

	/**
	 * Read the currently assigned nav menu locations.
	 *
	 * @return array<string, int>
	 */
	public static function get_locations(): array {
		$locations = get_nav_menu_locations();
		if ( ! is_array( $locations ) ) {
			return [];
		}
		// Cast values to int — get_theme_mod can return mixed types when the
		// stored option was set manually or by an older plugin version.
		$out = [];
		foreach ( $locations as $slug => $id ) {
			$out[ (string) $slug ] = (int) $id;
		}
		return $out;
	}

	/**
	 * Read the locations the current theme declares via register_nav_menus().
	 *
	 * Returned shape is { slug => human-readable label }. This is what the
	 * assign-location ability surfaces to the model so it can pick a real
	 * location instead of inventing slugs.
	 *
	 * @return array<string, string>
	 */
	public static function get_registered_locations(): array {
		$locations = get_registered_nav_menus();
		if ( ! is_array( $locations ) ) {
			return [];
		}
		$out = [];
		foreach ( $locations as $slug => $label ) {
			$out[ (string) $slug ] = (string) $label;
		}
		return $out;
	}
}
