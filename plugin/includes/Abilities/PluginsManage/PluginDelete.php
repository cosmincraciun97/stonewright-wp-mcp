<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\PluginsManage;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Deletes an inactive plugin (cannot delete Stonewright itself).
 *
 * @stonewright-status stable
 */
final class PluginDelete extends AbilityKernel {
	use ConfirmationGuard;

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

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$verify = $args;
				unset( $verify['confirmation_token'] );
				$err = $this->confirmation_token_error( $args, $verify );
				if ( null !== $err ) {
					return $err;
				}
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				$plugin = (string) $args['plugin'];
				if ( str_contains( $plugin, 'stonewright' ) ) {
					return new \WP_Error( 'stonewright_self_protection', 'Cannot delete Stonewright from itself.' );
				}
				if ( is_plugin_active( $plugin ) ) {
					return new \WP_Error( 'stonewright_plugin_active', 'Plugin must be inactive before delete.' );
				}
				$result = delete_plugins( [ $plugin ] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return [
					'deleted' => true,
					'plugin'  => $plugin,
				];
			}
		);
	}
}
