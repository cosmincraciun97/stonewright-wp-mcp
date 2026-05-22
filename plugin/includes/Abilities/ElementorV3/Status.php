<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class Status extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-status';
	}

	public function label(): string {
		return __( 'Elementor V3 status', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns whether Elementor V3 is installed/active, its version, and Pro status.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'installed' => [ 'type' => 'boolean' ],
				'active'    => [ 'type' => 'boolean' ],
				'version'   => [ 'type' => 'string' ],
				'has_pro'   => [ 'type' => 'boolean' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$installed = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
		$active    = $installed && did_action( 'elementor/loaded' );

		return [
			'installed' => $installed,
			'active'    => (bool) $active,
			'version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'has_pro'   => defined( 'ELEMENTOR_PRO_VERSION' ),
		];
	}
}
