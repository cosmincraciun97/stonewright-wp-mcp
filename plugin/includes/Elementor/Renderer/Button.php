<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\IconValueNormalizer;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * Renders a DesignSpec `button` node as an Elementor button widget.
 *
 * Elementor's button widget is one of the few widgets that does NOT use the
 * `_background_*` underscore-prefixed convention; its background setting is
 * the bare `background_color`. Same for `button_text_color` (no prefix).
 * The map below encodes those quirks.
 */
final class Button {

	/**
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'color'           => [ 'key' => 'button_text_color', 'is_color' => true ],
			'background'      => [ 'key' => 'background_color', 'is_background' => true ],
			'hover_color'     => [ 'key' => 'hover_color', 'is_color' => true ],
			'hover_background' => [ 'key' => 'button_background_hover_color', 'is_color' => true ],
			'font_size'       => [ 'key' => 'typography_font_size', 'is_size' => true ],
			'font_weight'     => 'typography_font_weight',
			'font_family'     => 'typography_font_family',
			'line_height'     => [ 'key' => 'typography_line_height', 'is_size' => true ],
			'letter_spacing'  => [ 'key' => 'typography_letter_spacing', 'is_size' => true ],
			'text_transform'  => 'typography_text_transform',
			'text_decoration' => 'typography_text_decoration',
			'font_style'      => 'typography_font_style',
			'padding'         => [ 'key' => 'text_padding', 'is_dimension' => true ],
			'border_radius'   => [ 'key' => 'border_radius', 'is_dimension' => true ],
			'border'          => [ 'is_border' => true, 'prefix' => 'border' ],
			'width'           => [ 'key' => 'width', 'is_size' => true ],
			'height'          => [ 'key' => 'height', 'is_size' => true ],
		];
	}

	/**
	 * @param array<string, mixed>             $node
	 * @param Resolver                         $resolver
	 * @param string                           $canonical_path
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>|null Null when icon normalization fails (no partial write).
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): ?array {
		$settings = [
			'text' => (string) ( $node['text'] ?? '' ),
			'link' => [
				'url'        => (string) ( $node['url'] ?? '' ),
				'is_external' => ! empty( $node['external'] ),
				'nofollow'   => ! empty( $node['nofollow'] ),
			],
		];

		if ( isset( $node['font_size'] ) ) {
			$settings = Responsive::apply( $settings, 'typography_font_size', $node['font_size'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
		}

		if ( isset( $node['padding'] ) ) {
			$settings = Responsive::apply( $settings, 'padding', $node['padding'] );
		}

		if ( isset( $node['size'] ) ) {
			$settings['size'] = (string) $node['size'];
		}

		if ( isset( $node['icon'] ) || isset( $node['selected_icon'] ) ) {
			$controls = self::live_controls();
			if ( $controls instanceof \WP_Error ) {
				$diagnostics[] = self::icon_diagnostic( $controls, $canonical_path );
				return null;
			}
			$icon_key = isset( $controls['selected_icon'] ) ? 'selected_icon' : ( isset( $controls['icon'] ) ? 'icon' : '' );
			if ( '' === $icon_key ) {
				$error = new \WP_Error(
					IconValueNormalizer::ERROR_CODE,
					'The live Elementor Button schema has no supported icon control.',
					[ 'status' => 409, 'reason' => 'icon_control_unavailable', 'available_controls' => array_keys( $controls ) ]
				);
				$diagnostics[] = self::icon_diagnostic( $error, $canonical_path );
				return null;
			}

			$icon = IconValueNormalizer::normalize( $node['selected_icon'] ?? $node['icon'] );
			if ( $icon instanceof \WP_Error ) {
				$diagnostics[] = self::icon_diagnostic( $icon, $canonical_path );
				// Structured rejection without partial button write.
				return null;
			}
			if ( 'icon' === $icon_key && is_array( $icon['value'] ) ) {
				$error = new \WP_Error(
					IconValueNormalizer::ERROR_CODE,
					'The live legacy Button icon control cannot accept an SVG/media payload.',
					[ 'status' => 409, 'reason' => 'legacy_icon_svg_unsupported' ]
				);
				$diagnostics[] = self::icon_diagnostic( $error, $canonical_path );
				return null;
			}
			$settings[ $icon_key ] = 'icon' === $icon_key ? $icon['value'] : $icon;

			$position_requested = isset( $node['icon_position'] ) || isset( $node['icon_align'] );
			$position = IconValueNormalizer::normalize_position( $node['icon_position'] ?? $node['icon_align'] ?? null );
			if ( $position_requested && ( null === $position || ! isset( $controls['icon_align'] ) ) ) {
				$error = new \WP_Error(
					IconValueNormalizer::ERROR_CODE,
					'The requested icon position is invalid or unsupported by the live Button schema.',
					[ 'status' => 409, 'reason' => 'icon_position_unsupported' ]
				);
				$diagnostics[] = self::icon_diagnostic( $error, $canonical_path );
				return null;
			}
			if ( null !== $position ) {
				$settings['icon_align'] = $position;
			}

			if ( isset( $node['icon_spacing'] ) || isset( $node['icon_indent'] ) ) {
				if ( ! isset( $controls['icon_indent'] ) ) {
					$error = new \WP_Error(
						IconValueNormalizer::ERROR_CODE,
						'The live Button schema has no icon spacing control.',
						[ 'status' => 409, 'reason' => 'icon_spacing_unsupported' ]
					);
					$diagnostics[] = self::icon_diagnostic( $error, $canonical_path );
					return null;
				}
				$spacing = $node['icon_spacing'] ?? $node['icon_indent'];
				$settings['icon_indent'] = is_array( $spacing )
					? $spacing
					: [ 'unit' => 'px', 'size' => (int) $spacing ];
			}
		}

		if ( isset( $node['color'] ) ) {
			$settings['button_text_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['background_color'] ) ) {
			$settings['background_color'] = (string) $resolver->resolve( (string) $node['background_color'] );
		}

		$style = StyleMapper::node_style( $node, $resolver );
		if ( [] !== $style ) {
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'button',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * @return array<string, array<string, mixed>>|\WP_Error
	 */
	private static function live_controls(): array|\WP_Error {
		if ( ! class_exists( '\\Elementor\\Plugin' ) ) {
			return new \WP_Error(
				IconValueNormalizer::ERROR_CODE,
				'Elementor must be loaded before rendering a button icon.',
				[ 'status' => 409, 'reason' => 'elementor_runtime_unavailable' ]
			);
		}
		$schema = WidgetSchemaRepository::get( 'button' );
		if ( $schema instanceof \WP_Error ) {
			return new \WP_Error(
				IconValueNormalizer::ERROR_CODE,
				'The live Elementor Button schema is unavailable.',
				[ 'status' => 409, 'reason' => 'button_schema_unavailable', 'cause_code' => $schema->get_error_code() ]
			);
		}
		return is_array( $schema['controls'] ?? null ) ? $schema['controls'] : [];
	}

	/** @return array<string, mixed> */
	private static function icon_diagnostic( \WP_Error $error, string $path ): array {
		return [
			'code'     => (string) $error->get_error_code(),
			'type'     => 'button',
			'path'     => $path,
			'renderer' => 'elementor_v3',
			'message'  => $error->get_error_message(),
			'data'     => $error->get_error_data(),
		];
	}
}
