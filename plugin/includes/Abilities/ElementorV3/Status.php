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
		return __( 'Returns whether Elementor is installed/active, version/pro status, widget inventory, and V4 atomic readiness.', 'stonewright' );
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
				'pro_elements_active'     => [ 'type' => 'boolean' ],
				'active_widget_types'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'unsupported_widgets'     => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'v4_atomic_supported'     => [ 'type' => 'boolean' ],
				'v4_atomic_enabled'       => [ 'type' => 'boolean' ],
				'v4_atomic_support_status' => [ 'type' => 'string' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$installed = defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' );
		$active    = $installed && did_action( 'elementor/loaded' );
		$has_pro   = defined( 'ELEMENTOR_PRO_VERSION' ) || class_exists( '\\ElementorPro\\Plugin' );
		$widgets   = self::active_widget_types();
		$v4_supported = class_exists( '\\Elementor\\Modules\\AtomicWidgets\\Module' );
		$v4_enabled   = (bool) get_option( 'stonewright_elementor_v4_atomic', false );

		return [
			'installed' => $installed,
			'active'    => (bool) $active,
			'version'   => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'has_pro'   => $has_pro,
			'pro_elements_active'      => $has_pro,
			'active_widget_types'      => $widgets,
			'unsupported_widgets'      => self::unsupported_widgets( $widgets ),
			'v4_atomic_supported'      => $v4_supported,
			'v4_atomic_enabled'        => $v4_enabled,
			'v4_atomic_support_status' => self::v4_support_status( $v4_supported, $v4_enabled ),
		];
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
}
