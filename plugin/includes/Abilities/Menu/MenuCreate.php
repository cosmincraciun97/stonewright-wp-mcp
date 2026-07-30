<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Menu;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Menu\MenuStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Create a new WordPress nav menu, optionally seeded with items.
 *
 * Combining create + bulk-add in one call exists so a model can stand up a
 * whole "Primary" menu in a single ability invocation rather than chaining
 * create -> add-item -> add-item -> add-item. Each item follows the WP
 * menu-item shape ({ title, url, parent_id?, position? }) and is appended in
 * input order via {@see MenuStore::add_item()}; failures on a single item do
 * not abort the create — instead we collect every successful item id and
 * surface partial results so the caller can decide whether to retry.
 *
 * @stonewright-status stable
 */
final class MenuCreate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/menu-create';
	}

	public function label(): string {
		return __( 'Menu: Create', 'stonewright' );
	}

	public function description(): string {
		return __( 'Creates a new WordPress nav menu (a nav_menu term) and optionally seeds it with menu items in one call.', 'stonewright' );
	}

	public function category(): string {
		return 'menu';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name'  => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
				'items' => [
					'type'  => 'array',
					'items' => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'title'     => [ 'type' => 'string' ],
							'url'       => [ 'type' => 'string' ],
							'parent_id' => [ 'type' => 'integer', 'minimum' => 0 ],
							'position'  => [ 'type' => 'integer', 'minimum' => 0 ],
						],
						'required' => [ 'title', 'url' ],
					],
				],
			],
			'required' => [ 'name' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'menu_id'      => [ 'type' => 'integer' ],
				'items_added'  => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
			],
			'required' => [ 'menu_id', 'items_added' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$menu_id = MenuStore::create( (string) $args['name'] );
				if ( is_wp_error( $menu_id ) ) {
					return $menu_id;
				}

				$items_added = [];
				foreach ( (array) ( $args['items'] ?? [] ) as $raw ) {
					if ( ! is_array( $raw ) ) {
						continue;
					}
					$item = [
						'menu-item-title' => (string) ( $raw['title'] ?? '' ),
						'menu-item-url'   => (string) ( $raw['url'] ?? '' ),
					];
					if ( isset( $raw['parent_id'] ) ) {
						$item['menu-item-parent-id'] = (int) $raw['parent_id'];
					}
					if ( isset( $raw['position'] ) ) {
						$item['menu-item-position'] = (int) $raw['position'];
					}

					$item_id = MenuStore::add_item( (int) $menu_id, $item );
					if ( is_wp_error( $item_id ) ) {
						// Skip the failing item but keep the menu — the
						// alternative (roll back the menu term) is more
						// destructive than helpful and the caller can read
						// items_added to detect the gap.
						continue;
					}
					$items_added[] = (int) $item_id;
				}

				return [
					'menu_id'     => (int) $menu_id,
					'items_added' => $items_added,
				];
			}
		);
	}
}
