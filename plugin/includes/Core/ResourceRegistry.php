<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

use Stonewright\WpMcp\Memory\Memory;

/**
 * Exposes Stonewright-owned MCP resources (site profile, design tokens, theme.json, kit, etc.).
 */
final class ResourceRegistry {

	public static function register(): void {
		add_filter( 'stonewright_resources', [ self::class, 'collect_resources' ] );
		add_action( 'rest_api_init', [ self::class, 'register_rest' ] );
	}

	public static function register_rest(): void {
		register_rest_route(
			'stonewright/v1',
			'/resources',
			[
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function () {
					return rest_ensure_response( apply_filters( 'stonewright_resources', [] ) );
				},
			]
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $resources
	 * @return array<int, array<string, mixed>>
	 */
	public static function collect_resources( array $resources ): array {
		$resources[] = [
			'uri'   => 'stonewright://site/profile',
			'name'  => 'Site profile',
			'data'  => Memory::get_scope( 'site' ),
			'mime'  => 'application/json',
		];

		$resources[] = [
			'uri'   => 'stonewright://site/design-tokens',
			'name'  => 'Design tokens',
			'data'  => Memory::get_scope( 'design' ),
			'mime'  => 'application/json',
		];

		$resources[] = [
			'uri'   => 'stonewright://site/builder-policy',
			'name'  => 'Builder policy',
			'data'  => Memory::get_scope( 'builder' ),
			'mime'  => 'application/json',
		];

		$theme_json = wp_get_global_settings();
		$resources[] = [
			'uri'   => 'stonewright://gutenberg/theme-json',
			'name'  => 'theme.json global settings',
			'data'  => $theme_json,
			'mime'  => 'application/json',
		];

		return $resources;
	}
}
