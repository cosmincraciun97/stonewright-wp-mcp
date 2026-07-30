<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\PluginsManage;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Activates an installed plugin.
 *
 * @stonewright-status stable
 */
final class PluginActivate extends AbilityKernel {

	public function name(): string {
		return 'stonewright/plugin-activate';
	}

	public function label(): string {
		return __( 'Plugin: Activate', 'stonewright' );
	}

	public function description(): string {
		return __( 'Activates an installed plugin.', 'stonewright' );
	}

	public function category(): string {
		return 'plugins';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'plugin' => [ 'type' => 'string' ],
			],
			'required'             => [ 'plugin' ],
		];
	}

	public function output_schema(): array {
		return [
			'additionalProperties' => true,
			'type'                 => 'object',
			'properties'           => [
				'plugin' => [ 'type' => 'string' ],
				'active' => [ 'type' => 'boolean' ],
			],
			'required'             => [ 'plugin', 'active' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::activate_plugins();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			static function ( array $args ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$plugin = (string) $args['plugin'];
				$result = activate_plugin( $plugin );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return [
					'plugin' => $plugin,
					'active' => is_plugin_active( $plugin ),
				];
			}
		);
	}
}
