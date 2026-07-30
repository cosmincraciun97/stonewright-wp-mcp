<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV4;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
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
				'elementor_version'        => [ 'type' => 'string' ],
				'pro_elements_active'      => [ 'type' => 'boolean' ],
				'active_widget_types'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'unsupported_widgets'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'v4_atomic_support_status' => [ 'type' => 'string' ],
				'v4_write_ready'           => [ 'type' => 'boolean' ],
				'recommended_renderer'     => [ 'type' => 'string' ],
				'agent_action'             => [ 'type' => 'string' ],
				'schema_fingerprint'       => [ 'type' => 'string' ],
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
				$widgets      = self::active_widget_types();
				$v4_ready     = $v4_available && $atomic_flag && 'production-safe' !== get_option( 'stonewright_mode', 'development' );

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
					'elementor_version'        => $build,
					'pro_elements_active'      => defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin' ),
					'active_widget_types'      => $widgets,
					'unsupported_widgets'      => self::unsupported_widgets( $widgets ),
					'v4_atomic_support_status' => self::v4_support_status( $v4_available, $atomic_flag ),
					'v4_write_ready'           => $v4_ready,
					'recommended_renderer'     => $v4_ready ? 'elementor-v4-atomic' : 'blocked-v4',
					'agent_action'             => self::agent_action( $v4_ready ),
					'schema_fingerprint'       => AtomicSchemaRepository::fingerprint(),
				];
			}
		);
	}

	/**
	 * @return list<string>
	 */
	private static function active_widget_types(): array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return [];
		}

		$widgets_manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
		if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'get_widget_types' ) ) {
			return [];
		}

		$widgets = $widgets_manager->get_widget_types();
		if ( ! is_array( $widgets ) ) {
			return [];
		}

		return array_values( array_map( 'strval', array_keys( $widgets ) ) );
	}

	/**
	 * @param list<string> $active_widgets
	 * @return list<string>
	 */
	private static function unsupported_widgets( array $active_widgets ): array {
		$active = array_map( 'strtolower', $active_widgets );
		return array_values( array_diff( self::required_native_widgets(), $active ) );
	}

	/**
	 * @return list<string>
	 */
	private static function required_native_widgets(): array {
		return [ 'button', 'container', 'heading', 'icon', 'icon-box', 'icon-list', 'image', 'text-editor' ];
	}

	private static function v4_support_status( bool $supported, bool $enabled ): string {
		if ( $supported && $enabled ) {
			return 'enabled';
		}
		if ( $supported ) {
			return 'available-disabled';
		}
		return $enabled ? 'enabled-but-unavailable' : 'unavailable';
	}

	private static function agent_action( bool $v4_ready ): string {
		if ( $v4_ready ) {
			return 'Use Elementor V4 atomic tools for dry-run rendering, then write only after diagnostics pass.';
		}

		return 'Block V4 writes until Atomic support is available and enabled; never translate a V4 payload to V3 implicitly.';
	}
}
