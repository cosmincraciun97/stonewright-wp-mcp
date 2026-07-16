<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ContentModel;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/** @stonewright-status stable */
final class CptList extends AbilityKernel {

	public function name(): string {
		return 'stonewright/cpt-list';
	}

	public function label(): string {
		return __( 'Content Model: List CPTs', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists registered post types with a coarse source hint (core/theme/plugin/stonewright).', 'stonewright' );
	}

	public function category(): string {
		return 'content-model';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'public_only' => [ 'type' => 'boolean', 'default' => false ],
			],
		];
	}

	public function output_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => true ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				$public_only = (bool) ( $args['public_only'] ?? false );
				$stone       = get_option( 'cptui_post_types', [] );
				$stone       = is_array( $stone ) ? $stone : [];
				$types       = function_exists( 'get_post_types' )
					? get_post_types( $public_only ? [ 'public' => true ] : [], 'objects' )
					: [];
				$items = [];
				foreach ( (array) $types as $obj ) {
					if ( ! is_object( $obj ) ) {
						continue;
					}
					$row  = get_object_vars( $obj );
					$name = (string) ( $row['name'] ?? '' );
					if ( '' === $name ) {
						continue;
					}
					$source = 'plugin';
					if ( in_array( $name, [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item' ], true ) ) {
						$source = 'core';
					} elseif ( isset( $stone[ $name ] ) ) {
						$source = 'stonewright';
					}
					$items[] = [
						'slug'   => $name,
						'label'  => (string) ( $row['label'] ?? $name ),
						'public' => (bool) ( $row['public'] ?? false ),
						'source' => $source,
					];
				}
				return [ 'items' => $items, 'total' => count( $items ) ];
			}
		);
	}
}
