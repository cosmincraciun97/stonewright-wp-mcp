<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

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
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
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

		if ( isset( $node['icon'] ) ) {
			$settings['icon'] = [ 'value' => (string) $node['icon'], 'library' => 'fa-solid' ];
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
}
