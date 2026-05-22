<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Menu;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Menu\MenuStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Append a single menu item to an existing nav menu.
 *
 * This is the surgical follow-up to {@see MenuCreate} (which can also seed
 * items at create-time): use this when adding a single link to a menu that
 * already exists, or when retrying an item that failed during the bulk
 * MenuCreate path.
 *
 * The input is flattened from WP's `menu-item-*` shape into a tidy
 * { title, url, parent_id?, position? } DTO — the underlying facade
 * translates to the verbose keys that wp_update_nav_menu_item() expects.
 *
 * @stonewright-status stable
 */
final class MenuAddItem extends AbilityKernel {

	public function name(): string {
		return 'stonewright/menu-add-item';
	}

	public function label(): string {
		return __( 'Menu: Add item', 'stonewright' );
	}

	public function description(): string {
		return __( 'Appends a single { title, url } item to an existing nav menu. Supports parent_id for sub-menus and position for ordering.', 'stonewright' );
	}

	public function category(): string {
		return 'menu';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'menu_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
				'title'     => [ 'type' => 'string', 'minLength' => 1 ],
				'url'       => [ 'type' => 'string', 'minLength' => 1 ],
				'parent_id' => [ 'type' => 'integer', 'minimum' => 0 ],
				'position'  => [ 'type' => 'integer', 'minimum' => 0 ],
			],
			'required' => [ 'menu_id', 'title', 'url' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'item_id' => [ 'type' => 'integer' ],
				'menu_id' => [ 'type' => 'integer' ],
			],
			'required' => [ 'item_id', 'menu_id' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$menu_id = (int) $args['menu_id'];
				if ( ! is_nav_menu( $menu_id ) ) {
					return $this->error(
						'menu_not_found',
						/* translators: %d: menu term id that does not exist */
						sprintf( __( 'No nav menu with id %d.', 'stonewright' ), $menu_id ),
						[ 'status' => 404, 'menu_id' => $menu_id ]
					);
				}

				$item = [
					'menu-item-title' => (string) $args['title'],
					'menu-item-url'   => (string) $args['url'],
				];
				if ( isset( $args['parent_id'] ) ) {
					$item['menu-item-parent-id'] = (int) $args['parent_id'];
				}
				if ( isset( $args['position'] ) ) {
					$item['menu-item-position'] = (int) $args['position'];
				}

				$item_id = MenuStore::add_item( $menu_id, $item );
				if ( is_wp_error( $item_id ) ) {
					return $item_id;
				}

				return [
					'item_id' => (int) $item_id,
					'menu_id' => $menu_id,
				];
			}
		);
	}
}
