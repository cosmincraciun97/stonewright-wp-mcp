<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Site;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact operational snapshot for generalist WordPress workflows.
 *
 * @stonewright-status stable
 */
final class SiteSnapshot extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-snapshot';
	}

	public function label(): string {
		return __( 'Site snapshot', 'stonewright' );
	}

	public function description(): string {
		return __( 'Compact site snapshot: name, URL, theme, plugin counts, post counts, mode, and MCP surface.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'name'         => [ 'type' => 'string' ],
				'url'          => [ 'type' => 'string' ],
				'wp_version'   => [ 'type' => 'string' ],
				'php_version'  => [ 'type' => 'string' ],
				'theme'        => [
					'type'       => 'object',
					'properties' => [
						'name'    => [ 'type' => 'string' ],
						'version' => [ 'type' => 'string' ],
						'stylesheet' => [ 'type' => 'string' ],
					],
				],
				'plugins'      => [
					'type'       => 'object',
					'properties' => [
						'active'   => [ 'type' => 'integer' ],
						'inactive' => [ 'type' => 'integer' ],
						'total'    => [ 'type' => 'integer' ],
					],
				],
				'post_counts'  => [
					'type'                 => 'object',
					'additionalProperties' => true,
				],
				'mode'         => [ 'type' => 'string' ],
				'mcp_surface'  => [ 'type' => 'string' ],
				'stonewright'  => [ 'type' => 'string' ],
			],
			'required'   => [ 'name', 'url', 'theme', 'plugins', 'post_counts', 'mode', 'mcp_surface' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array {
		$theme = wp_get_theme();
		$theme_payload = [
			'name'       => $theme ? (string) $theme->get( 'Name' ) : '',
			'version'    => $theme ? (string) $theme->get( 'Version' ) : '',
			'stylesheet' => $theme ? (string) $theme->get_stylesheet() : '',
		];

		return [
			'name'        => (string) get_bloginfo( 'name' ),
			'url'         => home_url( '/' ),
			'wp_version'  => (string) get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'theme'       => $theme_payload,
			'plugins'     => $this->plugin_counts(),
			'post_counts' => $this->post_counts(),
			'mode'        => (string) get_option( 'stonewright_mode', 'development' ),
			'mcp_surface' => AbilityRegistry::mcp_surface(),
			'stonewright' => defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '',
		];
	}

	/**
	 * @return array{active:int,inactive:int,total:int}
	 */
	private function plugin_counts(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			$plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $plugin_file ) ) {
				require_once $plugin_file;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return [ 'active' => 0, 'inactive' => 0, 'total' => 0 ];
		}

		$all      = get_plugins();
		$active   = 0;
		$inactive = 0;
		foreach ( array_keys( $all ) as $file ) {
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
				++$active;
			} else {
				++$inactive;
			}
		}

		return [
			'active'   => $active,
			'inactive' => $inactive,
			'total'    => $active + $inactive,
		];
	}

	/**
	 * Compact counts for core + public post types only.
	 *
	 * @return array<string, array{publish:int,draft:int,trash:int,total:int}>
	 */
	private function post_counts(): array {
		$types = [ 'post', 'page' ];
		if ( function_exists( 'get_post_types' ) ) {
			$public = get_post_types( [ 'public' => true ], 'names' );
			if ( is_array( $public ) ) {
				foreach ( $public as $slug ) {
					$slug = (string) $slug;
					if ( '' === $slug || in_array( $slug, $types, true ) || 'attachment' === $slug ) {
						continue;
					}
					$types[] = $slug;
					if ( count( $types ) >= 12 ) {
						break;
					}
				}
			}
		}

		$out = [];
		foreach ( $types as $type ) {
			$counts = function_exists( 'wp_count_posts' ) ? wp_count_posts( $type ) : null;
			$publish = 0;
			$draft   = 0;
			$trash   = 0;
			if ( is_object( $counts ) ) {
				$publish = (int) ( $counts->publish ?? 0 );
				$draft   = (int) ( $counts->draft ?? 0 ) + (int) ( $counts->pending ?? 0 ) + (int) ( $counts->{'auto-draft'} ?? 0 );
				$trash   = (int) ( $counts->trash ?? 0 );
			}
			$out[ $type ] = [
				'publish' => $publish,
				'draft'   => $draft,
				'trash'   => $trash,
				'total'   => $publish + $draft + $trash,
			];
		}

		return $out;
	}
}
