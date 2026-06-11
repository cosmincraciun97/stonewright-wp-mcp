<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\WidgetRegistry\EditorTabKnowledge;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class GetWidgetSchema extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-get-widget-schema';
	}

	public function label(): string {
		return __( 'Get Elementor widget schema', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns the control schema (name, type, defaults) for a single Elementor widget.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name' => [ 'type' => 'string' ],
			],
			'required'             => [ 'name' ],
		];
	}

	public function output_schema(): array {
		$tab_group_schema = [
			'type'       => 'object',
			'properties' => [
				'count'           => [ 'type' => 'integer' ],
				'controls'        => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'    => [ 'type' => 'string' ],
							'type'    => [ 'type' => 'string' ],
							'label'   => [ 'type' => 'string' ],
							'section' => [ 'type' => 'string' ],
						],
					],
				],
				'global_controls' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'name'              => [ 'type' => 'string' ],
				'title'             => [ 'type' => 'string' ],
				'categories'        => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'controls'          => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'tab_groups'        => [
					'type'       => 'object',
					'properties' => [
						'Content'  => $tab_group_schema,
						'Style'    => $tab_group_schema,
						'Advanced' => $tab_group_schema,
						'Unknown'  => $tab_group_schema,
					],
				],
				'research_guidance' => [ 'type' => 'string' ],
			],
			'required'   => [ 'name', 'controls', 'tab_groups', 'research_guidance' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return $this->error( 'elementor_inactive', __( 'Elementor is not loaded.', 'stonewright' ) );
		}

		$manager = \Elementor\Plugin::$instance->widgets_manager ?? null;
		if ( ! $manager ) {
			return $this->error( 'elementor_inactive', __( 'Widgets manager unavailable.', 'stonewright' ) );
		}

		$widget = $manager->get_widget_types( (string) $args['name'] );
		if ( ! $widget ) {
			return $this->error( 'unknown_widget', sprintf( __( 'Widget "%s" not found.', 'stonewright' ), (string) $args['name'] ) );
		}

		$controls = [];
		if ( method_exists( $widget, 'get_controls' ) ) {
			foreach ( (array) $widget->get_controls() as $key => $control ) {
				$controls[] = [
					'name'    => (string) $key,
					'type'    => (string) ( $control['type'] ?? '' ),
					'label'   => (string) ( $control['label'] ?? '' ),
					'default' => $control['default'] ?? null,
					'tab'     => (string) ( $control['tab'] ?? '' ),
					'section' => (string) ( $control['section'] ?? '' ),
				];
			}
		}

		return [
			'name'              => (string) $args['name'],
			'title'             => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '',
			'categories'        => method_exists( $widget, 'get_categories' ) ? (array) $widget->get_categories() : [],
			'controls'          => $controls,
			'tab_groups'        => EditorTabKnowledge::group_controls( $controls ),
			'research_guidance' => 'Research official Elementor documentation online when this widget schema lacks enough Content or Style controls for the requested design.',
		];
	}
}
