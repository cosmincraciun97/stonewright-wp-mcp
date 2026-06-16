<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Compact Elementor container setting guide for low-token layout writes.
 *
 * @stonewright-status stable
 */
final class ContainerSchema extends AbilityKernel {

	public function name(): string {
		return 'stonewright/elementor-v3-container-schema';
	}

	public function label(): string {
		return __( 'Elementor V3 container schema', 'stonewright' );
	}

	public function description(): string {
		return __( 'Returns compact safe Elementor container controls, aliases, and blocked settings for faster section layout writes.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'include_controls' => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true and Elementor is loaded, include live container controls. Defaults false for compact startup.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'element_type'     => [ 'type' => 'string' ],
				'elementor_active' => [ 'type' => 'boolean' ],
				'source'           => [ 'type' => 'string' ],
				'include_controls' => [ 'type' => 'boolean' ],
				'control_count'    => [ 'type' => 'integer' ],
				'core_controls'    => [ 'type' => 'object' ],
				'safe_aliases'     => [ 'type' => 'object' ],
				'blocked_settings' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'usage_rules'      => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'write_tools'      => [ 'type' => 'object' ],
				'controls'         => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ],
			],
			'required'   => [ 'element_type', 'core_controls', 'safe_aliases', 'blocked_settings', 'usage_rules', 'write_tools' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		$include_controls = true === ( $args['include_controls'] ?? false );
		$controls         = $include_controls ? self::live_controls() : [];
		$elementor_active = class_exists( '\\Elementor\\Plugin' );

		return [
			'element_type'     => 'container',
			'elementor_active' => $elementor_active,
			'source'           => [] !== $controls ? 'elementor_live_controls' : 'stonewright_safe_defaults',
			'include_controls' => $include_controls,
			'control_count'    => count( $controls ),
			'core_controls'    => self::core_controls(),
			'safe_aliases'     => ContainerSettings::safe_aliases(),
			'blocked_settings' => ContainerSettings::blocked_settings(),
			'usage_rules'      => [
				'Use flex_justify_content, flex_align_items, and flex_align_content instead of unprefixed flex shorthands.',
				'Use layout=flex or layout=grid only as short input aliases; Stonewright writes container_type and flex_direction.',
				'Do not send flex_wrap, _flex_size, _flex_grow, or _flex_shrink in container writes.',
				'Prefer gap, percentage width, max-width, margin, and padding over fixed viewport-wide sizing.',
				'Read live controls only for unusual layouts; keep include_controls=false for normal section work.',
			],
			'write_tools'      => [
				'first_pass'    => 'stonewright/elementor-v3-build-page-from-spec',
				'surgical'      => 'stonewright/elementor-v3-batch-mutate',
				'single_update' => 'stonewright/elementor-v3-update-element',
			],
			'controls'         => $controls,
		];
	}

	/**
	 * @return array<string, list<string>>
	 */
	private static function core_controls(): array {
		return [
			'layout'     => [
				'container_type',
				'flex_direction',
				'flex_justify_content',
				'flex_align_items',
				'flex_align_content',
				'gap',
				'content_width',
				'width',
				'min_height',
			],
			'style'      => [
				'background_background',
				'background_color',
				'background_image',
				'border_border',
				'border_width',
				'border_color',
				'border_radius',
				'box_shadow_box_shadow',
			],
			'advanced'   => [
				'margin',
				'padding',
				'z_index',
				'css_id',
				'css_classes',
				'position',
			],
			'responsive' => [
				'width_tablet',
				'width_mobile',
				'padding_tablet',
				'padding_mobile',
				'margin_tablet',
				'margin_mobile',
			],
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function live_controls(): array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return [];
		}

		$plugin  = \Elementor\Plugin::$instance ?? null;
		$manager = $plugin->elements_manager ?? null;
		if ( ! $manager || ! method_exists( $manager, 'get_element_types' ) ) {
			return [];
		}

		$container = $manager->get_element_types( 'container' );
		if ( ! $container || ! method_exists( $container, 'get_controls' ) ) {
			return [];
		}

		$controls = [];
		foreach ( (array) $container->get_controls() as $name => $control ) {
			$control = is_array( $control ) ? $control : [];
			$item    = [
				'name'    => (string) $name,
				'type'    => (string) ( $control['type'] ?? '' ),
				'label'   => (string) ( $control['label'] ?? '' ),
				'tab'     => (string) ( $control['tab'] ?? '' ),
				'section' => (string) ( $control['section'] ?? '' ),
			];
			if ( array_key_exists( 'default', $control ) ) {
				$item['default'] = $control['default'];
			}
			if ( isset( $control['options'] ) && is_array( $control['options'] ) ) {
				$item['options'] = array_values(
					array_filter(
						array_map( 'strval', array_keys( $control['options'] ) ),
						static fn( string $value ): bool => '' !== $value
					)
				);
			}
			$controls[] = $item;
		}

		return $controls;
	}
}
