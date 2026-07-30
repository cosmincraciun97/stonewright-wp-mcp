<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Menu;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Menu\MenuStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Assign a nav menu to a theme location.
 *
 * Theme locations are the named slots a theme exposes via
 * `register_nav_menus()` (e.g. `primary`, `footer`, `mobile`) — each one
 * can hold at most one menu. WordPress stores the slot -> menu_id pairing
 * inside the `nav_menu_locations` theme_mod. We read-modify-write that
 * array so existing assignments for other locations are preserved.
 *
 * The output includes:
 *   - `previous_menu_id` — the menu that previously occupied the slot
 *     (null when the slot was empty), so the caller can detect that the
 *     assignment displaced a prior menu rather than filled a vacancy;
 *   - `available_locations` — the slugs the active theme actually
 *     registered, so callers that picked the wrong slug see what they had
 *     to choose from instead of silently writing a no-op.
 *
 * @stonewright-status stable
 */
final class MenuAssignLocation extends AbilityKernel {

	public function name(): string {
		return 'stonewright/menu-assign-location';
	}

	public function label(): string {
		return __( 'Menu: Assign to theme location', 'stonewright' );
	}

	public function description(): string {
		return __( 'Assigns a nav menu to a theme location slot (primary / footer / mobile / etc.).', 'stonewright' );
	}

	public function category(): string {
		return 'menu';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'location' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
				'menu_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required' => [ 'location', 'menu_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'location'            => [ 'type' => 'string' ],
				'menu_id'             => [ 'type' => 'integer' ],
				'previous_menu_id'    => [ 'type' => [ 'integer', 'null' ] ],
				'available_locations' => [ 'type' => 'object' ],
			],
			'required' => [ 'location', 'menu_id', 'previous_menu_id', 'available_locations' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$location = (string) $args['location'];
				$menu_id  = (int) $args['menu_id'];

				if ( ! is_nav_menu( $menu_id ) ) {
					return $this->error(
						'menu_not_found',
						/* translators: %d: menu term id that does not exist */
						sprintf( __( 'No nav menu with id %d.', 'stonewright' ), $menu_id ),
						[ 'status' => 404, 'menu_id' => $menu_id ]
					);
				}

				$previous_locations = MenuStore::get_locations();
				$previous_menu_id   = isset( $previous_locations[ $location ] ) && (int) $previous_locations[ $location ] > 0
					? (int) $previous_locations[ $location ]
					: null;

				MenuStore::assign_location( $location, $menu_id );

				return [
					'location'            => $location,
					'menu_id'             => $menu_id,
					'previous_menu_id'    => $previous_menu_id,
					'available_locations' => MenuStore::get_registered_locations(),
				];
			}
		);
	}
}
