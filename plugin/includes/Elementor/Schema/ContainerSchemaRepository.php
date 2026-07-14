<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

/**
 * Fingerprinted schemas for Elementor V3 structural elements.
 *
 * Live Elementor controls always win. The bundled schema exists only for
 * offline compilation/tests where Elementor itself is not booted.
 */
final class ContainerSchemaRepository {

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function get( string $element_type = 'container' ): array|\WP_Error {
		$element_type = in_array( $element_type, [ 'container', 'section', 'column' ], true ) ? $element_type : 'container';
		$fingerprint  = RuntimeFingerprint::describe();
		$element      = self::live_element( $element_type );

		if ( is_object( $element ) ) {
			$controls = self::normalize_controls( method_exists( $element, 'get_controls' ) ? (array) $element->get_controls() : [] );
			$source   = 'elementor_live_controls';
		} elseif ( defined( 'ELEMENTOR_VERSION' ) ) {
			return new \WP_Error(
				'stonewright_elementor_container_schema_unavailable',
				__( 'The live Elementor structural schema is unavailable; the write was refused.', 'stonewright' ),
				[ 'status' => 503, 'element_type' => $element_type, 'capture_required' => true ]
			);
		} else {
			$controls = self::fallback_controls();
			$source   = 'stonewright_offline_renderer_contract';
		}

		$record = [
			'element_type'        => $element_type,
			'controls'            => $controls,
			'source'              => $source,
			'elementor_core'      => (string) ( $fingerprint['components']['elementor_core'] ?? '' ),
			'runtime_fingerprint' => (string) ( $fingerprint['hash'] ?? '' ),
		];
		$record['schema_hash'] = hash( 'sha256', (string) wp_json_encode( self::canonicalize( $record ) ) );
		return $record;
	}

	private static function live_element( string $element_type ): ?object {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return null;
		}
		$manager = \Elementor\Plugin::$instance->elements_manager ?? null;
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'get_element_types' ) ) {
			return null;
		}
		$element = $manager->get_element_types( $element_type );
		return is_object( $element ) ? $element : null;
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_controls( array $raw ): array {
		$controls = [];
		foreach ( $raw as $key => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$name = (string) $key;
			$controls[ $name ] = [
				'key'        => $name,
				'type'       => is_scalar( $control['type'] ?? '' ) ? (string) ( $control['type'] ?? '' ) : '',
				'label'      => is_scalar( $control['label'] ?? '' ) ? (string) ( $control['label'] ?? '' ) : '',
				'tab'        => is_scalar( $control['tab'] ?? '' ) ? (string) ( $control['tab'] ?? '' ) : '',
				'section'    => is_scalar( $control['section'] ?? '' ) ? (string) ( $control['section'] ?? '' ) : '',
				'responsive' => (bool) ( $control['responsive'] ?? $control['is_responsive'] ?? false ),
				'dynamic'    => (array) ( $control['dynamic'] ?? [] ),
				'condition'  => (array) ( $control['condition'] ?? $control['conditions'] ?? [] ),
				'provenance' => 'live_elementor_runtime',
			];
			foreach ( [ 'default', 'options', 'min', 'max', 'step', 'multiple', 'return_value' ] as $field ) {
				if ( array_key_exists( $field, $control ) ) {
					$controls[ $name ][ $field ] = $control[ $field ];
				}
			}
		}
		ksort( $controls );
		return $controls;
	}

	/**
	 * Controls emitted by Stonewright renderers and direct container writes.
	 * This is never substituted for a missing schema on a booted Elementor site.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function fallback_controls(): array {
		$responsive_slider     = [ 'type' => 'slider', 'responsive' => true, 'provenance' => 'stonewright_renderer_contract' ];
		$responsive_dimensions = [ 'type' => 'dimensions', 'responsive' => true, 'provenance' => 'stonewright_renderer_contract' ];
		$responsive_select     = [ 'type' => 'select', 'responsive' => true, 'provenance' => 'stonewright_renderer_contract' ];
		$controls              = [
			'container_type'       => [ 'type' => 'select', 'options' => [ 'flex' => 'Flex', 'grid' => 'Grid' ], 'default' => 'flex' ],
			'content_width'        => [ 'type' => 'select', 'options' => [ 'boxed' => 'Boxed', 'full' => 'Full' ] ],
			'flex_direction'       => [ 'type' => 'choose', 'responsive' => true ],
			'flex_justify_content' => $responsive_select,
			'flex_align_items'     => $responsive_select,
			'flex_align_content'   => $responsive_select,
			'flex_wrap'            => $responsive_select,
			'flex_gap'             => $responsive_slider,
			'grid_columns_grid'    => $responsive_slider,
			'width'                => $responsive_slider,
			'height'               => $responsive_slider,
			'min_height'           => $responsive_slider,
			'padding'              => $responsive_dimensions,
			'margin'               => $responsive_dimensions,
			'_margin'              => $responsive_dimensions,
			'background_background' => [ 'type' => 'select' ],
			'background_color'      => [ 'type' => 'color' ],
			'background_image'      => [ 'type' => 'media' ],
			'background_position'   => $responsive_select,
			'background_size'       => $responsive_select,
			'background_repeat'     => $responsive_select,
			'border_border'         => [ 'type' => 'select' ],
			'border_width'          => $responsive_dimensions,
			'border_color'          => [ 'type' => 'color' ],
			'border_radius'         => $responsive_dimensions,
			'box_shadow_box_shadow' => [ 'type' => 'switcher' ],
			'z_index'               => [ 'type' => 'number', 'responsive' => true ],
			'css_id'                => [ 'type' => 'text' ],
			'css_classes'           => [ 'type' => 'text' ],
			'_css_classes'          => [ 'type' => 'text' ],
			'position'              => [ 'type' => 'select' ],
			'_position'             => [ 'type' => 'select' ],
			'sticky'                => [ 'type' => 'select' ],
			'sticky_on'             => [ 'type' => 'select2', 'multiple' => true ],
			'sticky_offset'         => [ 'type' => 'number', 'responsive' => true ],
			'hide_desktop'          => [ 'type' => 'switcher' ],
			'hide_tablet'           => [ 'type' => 'switcher' ],
			'hide_mobile'           => [ 'type' => 'switcher' ],
		];
		foreach ( $controls as $key => &$control ) {
			$control['key']        = $key;
			$control['provenance'] = $control['provenance'] ?? 'stonewright_renderer_contract';
		}
		unset( $control );
		ksort( $controls );
		return $controls;
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
