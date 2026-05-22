<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\FSE;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetThemeJson extends AbilityKernel {

	public function name(): string {
		return 'stonewright/fse-get-theme-json';
	}

	public function label(): string {
		return __( 'Get theme.json', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns merged theme.json data (theme + user overrides) plus raw user styles.', 'stonewright' );
	}

	public function category(): string {
		return 'fse';
	}

	public function output_schema(): array {
		return [ 'type' => 'object' ];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! class_exists( \WP_Theme_JSON_Resolver::class ) ) {
			return $this->error( 'theme_json_unavailable', __( 'theme.json resolver is not available on this site.', 'stonewright' ) );
		}

		$merged = \WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data();
		$theme  = \WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data();
		$user   = \WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();

		return [
			'merged'        => $merged,
			'theme'         => $theme,
			'user'          => $user,
			'supports_v3'   => version_compare( get_bloginfo( 'version' ), '6.6', '>=' ),
			'theme_slug'    => get_stylesheet(),
		];
	}
}
