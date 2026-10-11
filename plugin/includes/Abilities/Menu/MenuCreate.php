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
				'confirmation_token' => [ 'type' => 'string' ],
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
				'items_failed' => [
					'type'        => 'array',
					'description' => 'The seeded items that could not be stored, with their position in items and the reason. The menu and the other items are kept.',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'index'         => [ 'type' => 'integer' ],
							'title'         => [ 'type' => 'string' ],
							'error_code'    => [ 'type' => 'string' ],
							'error_message' => [ 'type' => 'string' ],
						],
					],
				],
			],
			'required' => [ 'menu_id', 'items_added', 'items_failed' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				$menu_id = MenuStore::create( (string) $args['name'] );
				if ( is_wp_error( $menu_id ) ) {
					return $menu_id;
				}

				$items_added  = [];
				$items_failed = [];
				foreach ( array_values( (array) ( $args['items'] ?? [] ) ) as $index => $raw ) {
					if ( ! is_array( $raw ) ) {
						$items_failed[] = [
							'index'         => $index,
							'title'         => '',
							'error_code'    => 'stonewright_menu_item_invalid',
							'error_message' => 'The item is not an object.',
						];
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
						// Keep the menu and the items that went in (rolling the menu back is
						// more destructive than helpful), and say which items did not.
						$items_failed[] = [
							'index'         => $index,
							'title'         => (string) ( $raw['title'] ?? '' ),
							'error_code'    => (string) $item_id->get_error_code(),
							'error_message' => (string) $item_id->get_error_message(),
						];
						continue;
					}
					$items_added[] = (int) $item_id;
				}

				return [
					'menu_id'      => (int) $menu_id,
					'items_added'  => $items_added,
					'items_failed' => $items_failed,
				];
			}
		);
	}
}
