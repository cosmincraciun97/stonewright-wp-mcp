<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Read-only status probe for Elementor V4 atomic availability.
 * Intentionally NOT gated behind the feature flag so clients can always
 * discover whether V4 is present before attempting any write ability.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status experimental
 */
final class Status extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v4-status';
	}

	public function label(): string {
		return __( 'Elementor V4 status', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns V4 availability, atomic flag state, build string, and detected capabilities. Always readable regardless of the feature flag.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function meta(): array {
		return [ 'experimental' => true ];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'v4_available' => [ 'type' => 'boolean' ],
				'atomic_flag'  => [ 'type' => 'boolean' ],
				'build'        => [ 'type' => 'string' ],
				'capabilities' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		// Always readable — clients need this to decide whether to offer V4 abilities.
		return Permissions::read();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$v4_available = class_exists( '\\Elementor\\Modules\\AtomicWidgets\\Module' );
				$atomic_flag  = (bool) get_option( 'stonewright_elementor_v4_atomic', false );
				$build        = defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '';

				$capabilities = [];
				if ( $v4_available ) {
					$capabilities[] = 'atomic-widgets';
				}
				if ( class_exists( '\\Elementor\\Modules\\AtomicWidgets\\StyleVariants\\StyleVariantsModule' ) ) {
					$capabilities[] = 'style-variants';
				}
				if ( class_exists( '\\Elementor\\Modules\\AtomicWidgets\\Variables\\Module' ) ) {
					$capabilities[] = 'variables';
				}
				if ( class_exists( '\\Elementor\\Modules\\AtomicWidgets\\StyleClasses\\StyleClassesModule' ) ) {
					$capabilities[] = 'style-classes';
				}

				return [
					'v4_available' => $v4_available,
					'atomic_flag'  => $atomic_flag,
					'build'        => $build,
					'capabilities' => $capabilities,
				];
			}
		);
	}
}
