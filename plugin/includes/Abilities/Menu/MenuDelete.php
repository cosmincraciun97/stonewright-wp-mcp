<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Menu;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Menu\MenuStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Delete a nav menu by id.
 *
 * Removes the `nav_menu` term and cascades through WordPress' own cleanup —
 * each `nav_menu_item` post that was attached to the term is detached, and
 * any theme-location pointing at the menu is dropped. We do not snapshot
 * the items first; menus are cheap to rebuild and the WP confirmation gate
 * (edit_theme_options) is enough.
 *
 * Returns `deleted: true` on success, or a `menu_not_found` error if the
 * id never matched a real menu.
 *
 * @stonewright-status stable
 */
final class MenuDelete extends AbilityKernel {

	public function name(): string {
		return 'stonewright/menu-delete';
	}

	public function label(): string {
		return __( 'Menu: Delete', 'stonewright' );
	}

	public function description(): string {
		return __( 'Deletes a nav menu by term id, detaching all its items and clearing any theme-location pointing at it.', 'stonewright' );
	}

	public function category(): string {
		return 'menu';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'menu_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required' => [ 'menu_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted' => [ 'type' => 'boolean' ],
			],
			'required' => [ 'deleted' ],
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
				$result  = MenuStore::delete( $menu_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return [ 'deleted' => true === $result ];
			}
		);
	}
}
