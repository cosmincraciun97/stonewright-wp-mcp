<?php
/**
 * Nav menu functions the menu adapter calls and the shared test bootstrap does not define.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	/**
	 * The items of a fake menu in menu order (then in the order they were added), shaped as core returns them
	 * after wp_setup_nav_menu_item(): classes as a list, ids and parents as strings.
	 *
	 * @param int|string|object    $menu
	 * @param array<string, mixed> $args
	 * @return list<object>|false
	 */
	function wp_get_nav_menu_items( $menu, array $args = [] ) {
		$id   = is_object( $menu ) ? (int) ( $menu->term_id ?? 0 ) : (int) $menu;
		$term = $GLOBALS['stonewright_test_nav_menus'][ $id ] ?? null;
		if ( null === $term ) {
			return false;
		}
		$rows  = [];
		$order = 0;
		foreach ( (array) $term->items as $item_id => $item ) {
			$data   = (array) ( $item['data'] ?? [] );
			$custom = 'custom' === (string) ( $data['menu-item-type'] ?? 'custom' );
			$rows[] = [
				// Core appends an item saved with no position.
				'order'  => (int) ( $data['menu-item-position'] ?? 0 ) > 0 ? (int) $data['menu-item-position'] : PHP_INT_MAX,
				'seq'    => ++$order,
				'object' => (object) [
					'ID'               => (int) $item_id,
					'db_id'            => (int) $item_id,
					'menu_item_parent' => (string) (int) ( $data['menu-item-parent-id'] ?? 0 ),
					'menu_order'       => (int) ( $data['menu-item-position'] ?? 0 ),
					'title'            => (string) ( $data['menu-item-title'] ?? '' ),
					'url'              => (string) ( $data['menu-item-url'] ?? '' ),
					'type'             => (string) ( $data['menu-item-type'] ?? 'custom' ),
					'object'           => $custom ? 'custom' : (string) ( $data['menu-item-object'] ?? 'custom' ),
					// Core keeps the item's own id as the object id of a custom link.
					'object_id'        => $custom ? (string) (int) $item_id : (string) (int) ( $data['menu-item-object-id'] ?? 0 ),
					'target'           => (string) ( $data['menu-item-target'] ?? '' ),
					'attr_title'       => (string) ( $data['menu-item-attr-title'] ?? '' ),
					'description'      => (string) ( $data['menu-item-description'] ?? '' ),
					'classes'          => array_values( explode( ' ', (string) ( $data['menu-item-classes'] ?? '' ) ) ),
					'xfn'              => (string) ( $data['menu-item-xfn'] ?? '' ),
					'post_status'      => (string) ( $data['menu-item-status'] ?? 'publish' ),
				],
			];
		}
		usort( $rows, static fn ( array $a, array $b ): int => [ $a['order'], $a['seq'] ] <=> [ $b['order'], $b['seq'] ] );
		return array_values( array_map( static fn ( array $row ): object => $row['object'], $rows ) );
	}
}

if ( ! function_exists( 'wp_update_nav_menu_object' ) ) {
	/**
	 * Renames a fake menu and keeps its description.
	 *
	 * @param array<string, mixed> $menu_data
	 */
	function wp_update_nav_menu_object( int $menu_id = 0, array $menu_data = [] ): int|\WP_Error {
		$term = $GLOBALS['stonewright_test_nav_menus'][ $menu_id ] ?? null;
		if ( null === $term ) {
			return new \WP_Error( 'invalid_menu_id', 'Invalid menu id.' );
		}
		if ( isset( $menu_data['menu-name'] ) ) {
			foreach ( (array) $GLOBALS['stonewright_test_nav_menus'] as $other ) {
				if ( (int) $other->term_id !== $menu_id && (string) $other->name === (string) $menu_data['menu-name'] ) {
					return new \WP_Error( 'menu_exists', 'Menu already exists.' );
				}
			}
			$term->name = (string) $menu_data['menu-name'];
		}
		if ( array_key_exists( 'description', $menu_data ) ) {
			$term->description = (string) $menu_data['description'];
		}
		return $menu_id;
	}
}
