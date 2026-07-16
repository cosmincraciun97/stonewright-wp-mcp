<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Widgets;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Replaces the full widget id list for a sidebar (can empty the sidebar).
 *
 * @stonewright-status stable
 */
final class WidgetSave extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/widget-save';
	}

	public function label(): string {
		return __( 'Widget: Save sidebar', 'stonewright' );
	}

	public function description(): string {
		return __( 'Replaces the widget id list for a sidebar. Empty widgets array clears the sidebar; confirmation token required in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'widgets';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'sidebar_id'         => [ 'type' => 'string' ],
				'widgets'            => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'confirmation_token' => [ 'type' => 'string' ],
			],
			'required'             => [ 'sidebar_id', 'widgets' ],
		];
	}

	public function output_schema(): array {
		return [
			'additionalProperties' => true,
			'type'                 => 'object',
			'properties'           => [
				'ok'         => [ 'type' => 'boolean' ],
				'sidebar_id' => [ 'type' => 'string' ],
			],
			'required'             => [ 'ok' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_theme_options();
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
				$sidebars = wp_get_sidebars_widgets();
				if ( ! is_array( $sidebars ) ) {
					$sidebars = [];
				}
				$sidebars[ (string) $args['sidebar_id'] ] = array_values( array_map( 'strval', (array) $args['widgets'] ) );
				wp_set_sidebars_widgets( $sidebars );
				return [
					'ok'         => true,
					'sidebar_id' => (string) $args['sidebar_id'],
				];
			}
		);
	}
}
