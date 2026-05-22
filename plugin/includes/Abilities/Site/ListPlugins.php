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
final class ListPlugins extends AbilityKernel {

	public function name(): string {
		return 'stonewright/site-plugins-list';
	}

	public function label(): string {
		return __( 'List plugins', 'stonewright' );
	}

	public function description(): string {
		return __( 'Lists installed plugins with their status, name, and version.', 'stonewright' );
	}

	public function category(): string {
		return 'site';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'plugins' => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'file'    => [ 'type' => 'string' ],
							'name'    => [ 'type' => 'string' ],
							'version' => [ 'type' => 'string' ],
							'active'  => [ 'type' => 'boolean' ],
						],
					],
				],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = [];
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = [
				'file'    => (string) $file,
				'name'    => (string) ( $data['Name'] ?? '' ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => is_plugin_active( $file ),
			];
		}
		return [ 'plugins' => $plugins ];
	}
}
