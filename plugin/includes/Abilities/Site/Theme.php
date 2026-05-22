<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class Theme extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-theme';
	}

	public function label(): string {
		return __( 'Active theme', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the active theme name, version, supports, and template hierarchy summary.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'         => [ 'type' => 'string' ],
				'version'      => [ 'type' => 'string' ],
				'template'     => [ 'type' => 'string' ],
				'stylesheet'   => [ 'type' => 'string' ],
				'parent_theme' => [ 'type' => 'string' ],
				'is_block_theme' => [ 'type' => 'boolean' ],
				'supports'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array {
		$theme = wp_get_theme();
		$supports = [];
		foreach ( [ 'post-thumbnails', 'menus', 'title-tag', 'html5', 'editor-styles', 'wp-block-styles' ] as $feature ) {
			if ( current_theme_supports( $feature ) ) {
				$supports[] = $feature;
			}
		}

		return [
			'name'           => (string) $theme->get( 'Name' ),
			'version'        => (string) $theme->get( 'Version' ),
			'template'       => (string) $theme->get_template(),
			'stylesheet'     => (string) $theme->get_stylesheet(),
			'parent_theme'   => $theme->parent() ? (string) $theme->parent()->get( 'Name' ) : '',
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'supports'       => $supports,
		];
	}
}
