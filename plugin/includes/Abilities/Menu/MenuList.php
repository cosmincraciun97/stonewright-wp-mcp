<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Menu;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Menu\MenuStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * List every nav menu defined on the site.
 *
 * Returns the small identity DTO { id, name, slug } per menu — not the
 * items inside them. Models typically chain into MenuAddItem /
 * MenuAssignLocation after this lookup to figure out which menu they need
 * to mutate.
 *
 * @stonewright-status stable
 */
final class MenuList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/menu-list';
	}

	public function label(): string {
		return __( 'Menu: List', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists every WordPress nav menu as { id, name, slug }.', 'stonewright' );
	}

	public function category(): string {
		return 'menu';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'menus' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => [
							'id'   => [ 'type' => 'integer' ],
							'name' => [ 'type' => 'string' ],
							'slug' => [ 'type' => 'string' ],
						],
						'required' => [ 'id', 'name', 'slug' ],
					],
				],
			],
			'required' => [ 'menus' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				return [ 'menus' => MenuStore::list_menus() ];
			}
		);
	}
}
