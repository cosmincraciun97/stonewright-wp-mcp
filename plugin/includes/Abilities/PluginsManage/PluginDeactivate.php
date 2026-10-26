<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\PluginsManage;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\CachePurgeAdvisor;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RescueGuard;

/**
 * Deactivates a plugin (cannot deactivate Stonewright itself).
 *
 * @stonewright-status stable
 */
final class PluginDeactivate extends AbilityKernel {
	public function name(): string {
		return 'stonewright/plugin-deactivate';
	}

	public function label(): string {
		return __( 'Plugin: Deactivate', 'stonewright' );
	}

	public function description(): string {
		return __( 'Deactivates a plugin. Cannot deactivate Stonewright itself. Requires a confirmation token in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'plugins';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'plugin'             => [ 'type' => 'string' ],
				'confirmation_token' => [ 'type' => 'string' ],
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
				'cache_purge' => [ 'type' => 'object' ],
			],
			'required'             => [ 'plugin', 'active' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::activate_plugins();
	}

	/**
	 * Checked before the confirmation token is looked at, so a refusal never spends one.
	 *
	 * @param array<string, mixed> $args
	 */
	protected function write_precondition_error( array $args ): ?\WP_Error {
		$plugin = (string) ( $args['plugin'] ?? '' );
		if ( PluginTarget::is_self( $plugin ) ) {
			return new \WP_Error( 'stonewright_self_protection', 'Cannot deactivate Stonewright from itself.' );
		}
		return PluginTarget::exists( PluginTarget::normalise( $plugin ) ) ? null : PluginTarget::not_found_error();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$plugin = PluginTarget::normalise( (string) $args['plugin'] );
				RescueGuard::arm_plugin_write( $plugin, is_plugin_active( $plugin ) );
				deactivate_plugins( $plugin );
				return CachePurgeAdvisor::attach_to_plugin_change(
					[
						'plugin' => $plugin,
						'active' => is_plugin_active( $plugin ),
					]
				);
			}
		);
	}
}
