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
final class ListWidgets extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-list-widgets';
	}

	public function label(): string {
		return __( 'List Elementor widgets', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns all registered Elementor V3 widget types including third-party widgets.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'widgets' => [ 'type' => 'array' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return $this->error( 'elementor_inactive', __( 'Elementor is not loaded.', 'stonewright' ) );
		}

		$widgets_manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
		if ( ! $widgets_manager ) {
			return $this->error( 'elementor_inactive', __( 'Elementor widgets manager is unavailable.', 'stonewright' ) );
		}

		$widgets = [];
		foreach ( $widgets_manager->get_widget_types() as $name => $widget ) {
			$widgets[] = [
				'name'       => (string) $name,
				'title'      => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '',
				'icon'       => method_exists( $widget, 'get_icon' ) ? (string) $widget->get_icon() : '',
				'categories' => method_exists( $widget, 'get_categories' ) ? (array) $widget->get_categories() : [],
				'keywords'   => method_exists( $widget, 'get_keywords' ) ? (array) $widget->get_keywords() : [],
			];
		}

		return [ 'widgets' => $widgets ];
	}
}
