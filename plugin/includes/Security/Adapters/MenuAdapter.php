<?php
/**
 * The menu family in the change ledger: the image of a nav menu with its items, and the restore that rebuilds it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Security\Adapters;

use Stonewright\WpMcp\Menu\MenuStore;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * A menu is a term of the nav menu taxonomy, its items are posts of the item type that hang under it, and
 * the theme locations that show it are an entry of the "nav_menu_locations" theme mod. This adapter
 * describes the first two, and the locations that point at the menu, as one image; and the assignment of
 * one location as an image of its own (a location holds one menu, so assigning a menu displaces another).
 *
 * A menu image holds:
 *
 *   v, kind "menu"
 *   menu       term_id, name, slug, description
 *   items      the items in menu order, each with id, parent (an item id, 0 at the top), title, url, type,
 *              object, object_id, target, attr_title, description, classes, xfn and status
 *   locations  the slugs of the locations that point at the menu
 *
 * Item positions are not part of the image: the list order is the order. A location image holds
 * { v, kind "menu_location", location, menu_id } with menu_id null when the location was empty.
 *
 * restore() rebuilds a menu in place, or creates it again when it was deleted (the new term has a new id:
 * the result says which). Items that exist are updated, items that are gone are created, items that are not
 * in the image are deleted, and the parents are set in a second pass, when every item has an id. The order
 * is written as 1, 2, 3 and so on. It reads the menu back and reports what still differs. It checks no
 * permission, token or newer change: the code that calls it does.
 */
final class MenuAdapter {

	public const IMAGE_VERSION = 1;

	/** Start of the summary of a row that created its menu. */
	public const CREATED_SUMMARY = 'Created ';

	private const SUMMARY_MAX = 120;

	// -----------------------------------------------------------------------
	// Image.
	// -----------------------------------------------------------------------

	/**
	 * The image of a menu as it is now.
	 *
	 * @return array<string, mixed>|null Null when the menu does not exist.
	 */
	public static function image( int $menu_id ): ?array {
		$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;
		if ( ! is_object( $menu ) ) {
			return null;
		}
		$raw = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'any' ] );
		$items = [];
		foreach ( is_array( $raw ) ? $raw : [] as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$classes = [];
			foreach ( (array) ( $item->classes ?? [] ) as $class ) {
				if ( is_string( $class ) && '' !== $class ) {
					$classes[] = $class;
				}
			}
			$items[] = [
				'id'          => (int) ( $item->db_id ?? $item->ID ?? 0 ),
				'parent'      => (int) ( $item->menu_item_parent ?? 0 ),
				'title'       => (string) ( $item->title ?? '' ),
				'url'         => (string) ( $item->url ?? '' ),
				'type'        => (string) ( $item->type ?? 'custom' ),
				'object'      => (string) ( $item->object ?? '' ),
				'object_id'   => (int) ( $item->object_id ?? 0 ),
				'target'      => (string) ( $item->target ?? '' ),
				'attr_title'  => (string) ( $item->attr_title ?? '' ),
				'description' => (string) ( $item->description ?? '' ),
				'classes'     => $classes,
				'xfn'         => (string) ( $item->xfn ?? '' ),
				'status'      => (string) ( $item->post_status ?? 'publish' ),
			];
		}
		$locations = [];
		foreach ( MenuStore::get_locations() as $slug => $assigned ) {
			if ( $assigned === $menu_id ) {
				$locations[] = (string) $slug;
			}
		}
		sort( $locations, SORT_STRING );
		return [
			'v'         => self::IMAGE_VERSION,
			'kind'      => 'menu',
			'menu'      => [
				'term_id'     => $menu_id,
				'name'        => self::term_field( $menu, 'name' ),
				'slug'        => self::term_field( $menu, 'slug' ),
				'description' => self::term_field( $menu, 'description' ),
			],
			'items'     => $items,
			'locations' => $locations,
		];
	}

	/**
	 * The image of one theme location: the menu it holds now.
	 *
	 * @return array<string, mixed>
	 */
	public static function location_image( string $location ): array {
		$assigned = MenuStore::get_locations()[ $location ] ?? 0;
		return [
			'v'        => self::IMAGE_VERSION,
			'kind'     => 'menu_location',
			'location' => $location,
			'menu_id'  => $assigned > 0 ? $assigned : null,
		];
	}

	/**
	 * A short plain summary of a menu change, without any URL.
	 *
	 * @param array<string, mixed> $image
	 */
	public static function summary( array $image ): string {
		$name  = (string) ( $image['menu']['name'] ?? '' );
		$count = count( (array) ( $image['items'] ?? [] ) );
		$text  = 'Menu' . ( '' === $name ? '' : ': ' . $name ) . ' (' . $count . ' item' . ( 1 === $count ? '' : 's' ) . ')';
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::SUMMARY_MAX ) : substr( $text, 0, self::SUMMARY_MAX );
	}

	/** Whether a ledger row is the creation of its menu. */
	public static function is_created_row( array $row ): bool {
		return 'menu' === ( $row['resource_type'] ?? '' )
			&& true === ( $row['restorable'] ?? false )
			&& '' === (string) ( $row['before_ref'] ?? 'x' )
			&& '' === (string) ( $row['before_sha256'] ?? 'x' )
			&& str_starts_with( (string) ( $row['summary'] ?? '' ), self::CREATED_SUMMARY );
	}

	// -----------------------------------------------------------------------
	// Restore.
	// -----------------------------------------------------------------------

	/**
	 * Rebuild a menu from its image, then read it back.
	 *
	 * @param array<string, mixed> $image
	 * @return array{ok:bool,menu_id:int,previous_menu_id:int,recreated:bool,skipped:list<string>,differences:list<string>}|\WP_Error ok is true only when the menu now equals the image.
	 */
	public static function restore( array $image ): array|\WP_Error {
		if ( self::IMAGE_VERSION !== ( $image['v'] ?? null ) || 'menu' !== ( $image['kind'] ?? '' ) || ! is_array( $image['menu'] ?? null ) || ! is_array( $image['items'] ?? null ) || '' === (string) ( $image['menu']['name'] ?? '' ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The image is not a menu image this version can restore.', 'stonewright' ) );
		}
		if ( AdapterSupport::has_mask( [ $image['menu'], $image['items'] ] ) ) {
			return new \WP_Error( 'stonewright_image_masked', __( 'The image had credentials masked out of it, so it cannot be written back.', 'stonewright' ) );
		}
		$items = [];
		foreach ( $image['items'] as $item ) {
			if ( ! is_array( $item ) || (int) ( $item['id'] ?? 0 ) < 1 ) {
				return new \WP_Error( 'stonewright_image_invalid', __( 'The image is not a menu image this version can restore.', 'stonewright' ) );
			}
			$items[] = $item;
		}

		$previous  = (int) ( $image['menu']['term_id'] ?? 0 );
		$menu_id   = $previous;
		$recreated = false;
		$skipped   = [];
		if ( $previous < 1 || ! is_nav_menu( $previous ) ) {
			$created = wp_create_nav_menu( (string) $image['menu']['name'] );
			if ( $created instanceof \WP_Error ) {
				return $created;
			}
			$menu_id   = (int) $created;
			$recreated = true;
		}

		$name = (string) $image['menu']['name'];
		$live = wp_get_nav_menu_object( $menu_id );
		if ( is_object( $live ) && ( self::term_field( $live, 'name' ) !== $name || self::term_field( $live, 'description' ) !== (string) ( $image['menu']['description'] ?? '' ) ) ) {
			$updated = wp_update_nav_menu_object( $menu_id, [ 'menu-name' => $name, 'description' => (string) ( $image['menu']['description'] ?? '' ) ] );
			if ( $updated instanceof \WP_Error ) {
				$skipped[] = 'menu.name';
			}
		}

		// Items that exist now, by id. Only the items a reader of the menu sees are touched.
		$current = [];
		foreach ( self::current_ids( $menu_id ) as $existing ) {
			$current[ $existing ] = true;
		}

		// First pass: every item exists, at its place in the order, under no parent. A parent may come later
		// in the order than its child, so the parents are set once every item has an id.
		$map = [];
		foreach ( $items as $index => $item ) {
			$old   = (int) $item['id'];
			$known = isset( $current[ $old ] ) && ! $recreated ? $old : 0;
			$saved = wp_update_nav_menu_item( $menu_id, $known, self::item_args( $item, 0, $index + 1 ) );
			if ( $saved instanceof \WP_Error || (int) $saved < 1 ) {
				$skipped[] = 'item.' . $old;
				continue;
			}
			$map[ $old ] = (int) $saved;
		}
		// Second pass: the parents.
		foreach ( $items as $index => $item ) {
			$old    = (int) $item['id'];
			$parent = (int) ( $item['parent'] ?? 0 );
			if ( $parent < 1 || ! isset( $map[ $old ] ) ) {
				continue;
			}
			if ( ! isset( $map[ $parent ] ) ) {
				$skipped[] = 'item.' . $old . '.parent';
				continue;
			}
			wp_update_nav_menu_item( $menu_id, $map[ $old ], self::item_args( $item, $map[ $parent ], $index + 1 ) );
		}
		// Items that the image does not have. An item that is in the image but could not be written stays.
		$kept = array_flip( array_values( $map ) );
		foreach ( $items as $item ) {
			$kept[ (int) $item['id'] ] = true;
		}
		foreach ( array_keys( $current ) as $existing ) {
			if ( ! isset( $kept[ $existing ] ) ) {
				wp_delete_post( $existing, true );
			}
		}

		foreach ( (array) ( $image['locations'] ?? [] ) as $slug ) {
			if ( is_string( $slug ) && '' !== $slug ) {
				MenuStore::assign_location( $slug, $menu_id );
			}
		}

		$live_image  = self::image( $menu_id );
		$differences = null === $live_image ? [ 'menu' ] : self::differences( $image, $live_image );
		return [
			'ok'               => [] === $differences,
			'menu_id'          => $menu_id,
			'previous_menu_id' => $previous,
			'recreated'        => $recreated,
			'skipped'          => $skipped,
			'differences'      => $differences,
		];
	}

	/**
	 * Put one theme location back as its image says: on the menu it held, or empty.
	 *
	 * @param array<string, mixed> $image
	 * @return array{ok:bool,location:string,skipped:list<string>,differences:list<string>}|\WP_Error
	 */
	public static function restore_location( array $image ): array|\WP_Error {
		$location = (string) ( $image['location'] ?? '' );
		if ( self::IMAGE_VERSION !== ( $image['v'] ?? null ) || 'menu_location' !== ( $image['kind'] ?? '' ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-.]{0,199}$/D', $location ) || ! array_key_exists( 'menu_id', $image ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The image is not a menu location image this version can restore.', 'stonewright' ) );
		}
		$skipped = [];
		$wanted  = null === $image['menu_id'] ? 0 : (int) $image['menu_id'];
		if ( $wanted > 0 && ! is_nav_menu( $wanted ) ) {
			$skipped[] = 'menu.' . $wanted;
		} elseif ( $wanted > 0 ) {
			MenuStore::assign_location( $location, $wanted );
		} else {
			$locations = get_theme_mod( 'nav_menu_locations', [] );
			if ( is_array( $locations ) && array_key_exists( $location, $locations ) ) {
				unset( $locations[ $location ] );
				set_theme_mod( 'nav_menu_locations', $locations );
			}
		}
		$live        = self::location_image( $location );
		$differences = [] === $skipped && ( $live['menu_id'] ?? null ) !== ( $wanted > 0 ? $wanted : null ) ? [ 'location.' . $location ] : [];
		return [
			'ok'          => [] === $differences && [] === $skipped,
			'location'    => $location,
			'skipped'     => $skipped,
			'differences' => $differences,
		];
	}

	/**
	 * Delete a menu that a change created. Its items go with it, as they do when the menu is deleted in wp-admin.
	 *
	 * @return array{ok:bool,menu_id:int,action:string}|\WP_Error
	 */
	public static function delete_created( int $menu_id ): array|\WP_Error {
		if ( $menu_id < 1 || ! is_nav_menu( $menu_id ) ) {
			return [ 'ok' => true, 'menu_id' => $menu_id, 'action' => 'already_deleted' ];
		}
		$deleted = MenuStore::delete( $menu_id );
		if ( $deleted instanceof \WP_Error ) {
			return $deleted;
		}
		return [ 'ok' => false === wp_get_nav_menu_object( $menu_id ), 'menu_id' => $menu_id, 'action' => 'deleted' ];
	}

	/**
	 * Undo one ledger row of this family: delete the menu a change created, put a location back, or rebuild a
	 * menu from its before image.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function undo( string $change_id ): array|\WP_Error {
		$row = ChangeLedger::get( $change_id );
		if ( null === $row ) {
			return new \WP_Error( 'stonewright_change_not_found', __( 'The change is not recorded.', 'stonewright' ) );
		}
		if ( 'menu' !== $row['family'] || ! in_array( $row['resource_type'], [ 'menu', 'menu_location' ], true ) ) {
			return new \WP_Error( 'stonewright_change_not_a_menu', __( 'The change is not a change of a menu.', 'stonewright' ) );
		}
		if ( ! $row['restorable'] ) {
			return new \WP_Error( 'stonewright_change_not_restorable', __( 'The change cannot be undone from the ledger.', 'stonewright' ), [ 'reason' => $row['restorable_reason'] ] );
		}
		if ( self::is_created_row( $row ) ) {
			return self::delete_created( (int) $row['resource_id'] );
		}
		$image = ChangeLedger::read_image( $change_id, 'before' );
		if ( $image instanceof \WP_Error ) {
			return $image;
		}
		if ( ! is_array( $image ) ) {
			return new \WP_Error( 'stonewright_image_invalid', __( 'The stored image is not a menu image.', 'stonewright' ) );
		}
		return 'menu_location' === $row['resource_type'] ? self::restore_location( $image ) : self::restore( $image );
	}

	// -----------------------------------------------------------------------
	// Helpers.
	// -----------------------------------------------------------------------

	/** A field of a menu term, or '' when the term does not carry it. */
	private static function term_field( object $term, string $field ): string {
		$value = get_object_vars( $term )[ $field ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * The ids of the items that a reader of the menu sees now.
	 *
	 * @return list<int>
	 */
	private static function current_ids( int $menu_id ): array {
		$ids = [];
		$raw = wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'any' ] );
		foreach ( is_array( $raw ) ? $raw : [] as $item ) {
			if ( is_object( $item ) ) {
				$ids[] = (int) ( $item->db_id ?? $item->ID ?? 0 );
			}
		}
		return array_values( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) );
	}

	/**
	 * The arguments of wp_update_nav_menu_item() for an item of an image.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function item_args( array $item, int $parent, int $position ): array {
		$classes = [];
		foreach ( (array) ( $item['classes'] ?? [] ) as $class ) {
			if ( is_string( $class ) && '' !== $class ) {
				$classes[] = $class;
			}
		}
		return [
			'menu-item-title'       => (string) ( $item['title'] ?? '' ),
			'menu-item-url'         => (string) ( $item['url'] ?? '' ),
			'menu-item-type'        => (string) ( $item['type'] ?? 'custom' ),
			'menu-item-object'      => (string) ( $item['object'] ?? '' ),
			'menu-item-object-id'   => (int) ( $item['object_id'] ?? 0 ),
			'menu-item-target'      => (string) ( $item['target'] ?? '' ),
			'menu-item-attr-title'  => (string) ( $item['attr_title'] ?? '' ),
			'menu-item-description' => (string) ( $item['description'] ?? '' ),
			'menu-item-classes'     => implode( ' ', $classes ),
			'menu-item-xfn'         => (string) ( $item['xfn'] ?? '' ),
			'menu-item-status'      => '' === (string) ( $item['status'] ?? '' ) ? 'publish' : (string) $item['status'],
			'menu-item-parent-id'   => $parent,
			'menu-item-position'    => $position,
		];
	}

	/**
	 * What differs between the image and the live menu, ignoring item ids: the items are compared in order,
	 * each by its fields and by the place of its parent in the list.
	 *
	 * @param array<string, mixed> $wanted
	 * @param array<string, mixed> $live
	 * @return list<string>
	 */
	private static function differences( array $wanted, array $live ): array {
		$out = [];
		if ( (string) ( $wanted['menu']['name'] ?? '' ) !== (string) ( $live['menu']['name'] ?? '' ) ) {
			$out[] = 'menu.name';
		}
		if ( (string) ( $wanted['menu']['description'] ?? '' ) !== (string) ( $live['menu']['description'] ?? '' ) ) {
			$out[] = 'menu.description';
		}
		$want = array_values( (array) $wanted['items'] );
		$have = array_values( (array) $live['items'] );
		if ( count( $want ) !== count( $have ) ) {
			$out[] = 'items.count';
		}
		$live_index = [];
		foreach ( $have as $index => $item ) {
			$live_index[ (int) $item['id'] ] = $index;
		}
		$want_index = [];
		foreach ( $want as $index => $item ) {
			$want_index[ (int) $item['id'] ] = $index;
		}
		foreach ( $want as $index => $item ) {
			$other = $have[ $index ] ?? null;
			if ( null === $other ) {
				continue;
			}
			foreach ( [ 'title', 'url', 'type', 'object', 'object_id', 'target', 'attr_title', 'description', 'classes', 'xfn', 'status' ] as $field ) {
				if ( ! AdapterSupport::same( $item[ $field ] ?? null, $other[ $field ] ?? null ) ) {
					$out[] = 'items.' . $index . '.' . $field;
				}
			}
			$wanted_parent = (int) ( $item['parent'] ?? 0 );
			$live_parent   = (int) ( $other['parent'] ?? 0 );
			$wanted_place  = $wanted_parent > 0 ? ( $want_index[ $wanted_parent ] ?? -1 ) : -1;
			$live_place    = $live_parent > 0 ? ( $live_index[ $live_parent ] ?? -2 ) : -1;
			if ( $wanted_place !== $live_place ) {
				$out[] = 'items.' . $index . '.parent';
			}
		}
		foreach ( (array) ( $wanted['locations'] ?? [] ) as $slug ) {
			if ( is_string( $slug ) && ! in_array( $slug, (array) ( $live['locations'] ?? [] ), true ) ) {
				$out[] = 'locations.' . $slug;
			}
		}
		return $out;
	}
}
