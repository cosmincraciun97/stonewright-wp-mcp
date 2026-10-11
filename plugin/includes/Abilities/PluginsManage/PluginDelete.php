<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\PluginsManage;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Deletes an inactive plugin (cannot delete Stonewright itself).
 *
 * @stonewright-status stable
 */
final class PluginDelete extends AbilityKernel {
	public function name(): string {
		return 'stonewright/plugin-delete';
	}

	public function label(): string {
		return __( 'Plugin: Delete', 'stonewright' );
	}

	public function description(): string {
		return __( 'Deletes an inactive plugin. Cannot delete Stonewright itself.', 'stonewright' );
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
				'deleted' => [ 'type' => 'boolean' ],
				'plugin'  => [ 'type' => 'string' ],
			],
			'required'             => [ 'deleted', 'plugin' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::delete_plugins();
	}

	/**
	 * Checked before the confirmation token is looked at, so a refusal never spends one.
	 *
	 * @param array<string, mixed> $args
	 */
	protected function write_precondition_error( array $args ): ?\WP_Error {
		$plugin = (string) ( $args['plugin'] ?? '' );
		if ( PluginTarget::is_self( $plugin ) ) {
			return new \WP_Error( 'stonewright_self_protection', 'Cannot delete Stonewright from itself.' );
		}
		$plugin = PluginTarget::normalise( $plugin );
		if ( ! PluginTarget::exists( $plugin ) ) {
			return PluginTarget::not_found_error();
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( $plugin ) ) {
			return new \WP_Error( 'stonewright_plugin_active', 'Plugin must be inactive before delete.' );
		}
		return null;
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$plugin = PluginTarget::normalise( (string) $args['plugin'] );
				$result = delete_plugins( [ $plugin ] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( null === $result ) {
					return new \WP_Error( 'stonewright_plugin_delete_incomplete', 'The plugin was not deleted: WordPress needs filesystem credentials for it.', [ 'plugin' => $plugin ] );
				}
				if ( true !== $result ) {
					return new \WP_Error( 'stonewright_plugin_delete_failed', 'The plugin could not be deleted.', [ 'plugin' => $plugin ] );
				}
				return [
					'deleted' => true,
					'plugin'  => $plugin,
				];
			}
		);
	}
}
