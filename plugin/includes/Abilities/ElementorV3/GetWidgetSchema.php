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
		return __( 'Returns compact Content, Style, and Advanced control groups for a single Elementor widget by default, or full control defaults when responseMode=full.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'name'         => [ 'type' => 'string' ],
				'responseMode' => [
					'type'        => 'string',
					'enum'        => [ 'summary', 'full' ],
					'default'     => 'summary',
					'description' => 'Use summary for control names, types, labels, sections, and editor tabs; use full only when default values are required.',
				],
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
							'tab'     => [ 'type' => 'string' ],
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
				'response_mode'     => [ 'type' => 'string' ],
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
				'defaults_omitted'  => [ 'type' => 'boolean' ],
				'full_mode_hint'    => [ 'type' => 'string' ],
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

		$response_mode = (string) ( $args['responseMode'] ?? 'summary' );
		$compact_controls = self::compact_controls( $controls );
		$output_controls  = 'full' === $response_mode ? $controls : $compact_controls;

		return [
			'name'              => (string) $args['name'],
			'response_mode'     => 'full' === $response_mode ? 'full' : 'summary',
			'title'             => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : '',
			'categories'        => method_exists( $widget, 'get_categories' ) ? (array) $widget->get_categories() : [],
			'controls'          => $output_controls,
			'tab_groups'        => EditorTabKnowledge::group_controls( $compact_controls ),
			'research_guidance' => 'Research official Elementor documentation online when this widget schema lacks enough Content or Style controls for the requested design.',
			'defaults_omitted'  => 'full' !== $response_mode,
			'full_mode_hint'    => 'Call with responseMode=full only when default values are required for the next write.',
		];
	}

	/**
	 * @param list<array<string, mixed>> $controls
	 * @return list<array<string, string>>
	 */
	private static function compact_controls( array $controls ): array {
		return array_map(
			static fn( array $control ): array => [
				'name'    => (string) ( $control['name'] ?? '' ),
				'type'    => (string) ( $control['type'] ?? '' ),
				'label'   => (string) ( $control['label'] ?? '' ),
				'tab'     => (string) ( $control['tab'] ?? '' ),
				'section' => (string) ( $control['section'] ?? '' ),
			],
			$controls
		);
	}
}
