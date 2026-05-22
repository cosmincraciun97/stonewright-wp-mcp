<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Renders a DesignSpec `text-editor` node as an Elementor text-editor widget.
 *
 * The `text-editor` spec type carries richer HTML content than a plain heading.
 * If the value in `html` is already HTML it is preserved; plain `text` is wrapped
 * in a `<p>` tag. Never uses esc_html on stored HTML — Elementor stores raw HTML
 * in the editor setting and escapes on output.
 */
final class TextEditor {

	/**
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'color'           => [ 'key' => 'text_color', 'is_color' => true ],
			'font_size'       => [ 'key' => 'typography_font_size', 'is_size' => true ],
			'font_weight'     => 'typography_font_weight',
			'font_family'     => 'typography_font_family',
			'line_height'     => [ 'key' => 'typography_line_height', 'is_size' => true ],
			'letter_spacing'  => [ 'key' => 'typography_letter_spacing', 'is_size' => true ],
			'text_align'      => 'align',
			'text_transform'  => 'typography_text_transform',
			'text_decoration' => 'typography_text_decoration',
			'font_style'      => 'typography_font_style',
			'padding'         => [ 'key' => '_padding', 'is_dimension' => true ],
			'margin'          => [ 'key' => '_margin', 'is_dimension' => true ],
			'background'      => [ 'key' => '_background_color', 'is_background' => true ],
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		if ( isset( $node['html'] ) ) {
			$content = (string) $node['html'];
		} elseif ( isset( $node['text'] ) ) {
			$content = '<p>' . esc_html( (string) $node['text'] ) . '</p>';
		} else {
			$content = '';
		}

		$settings = [
			'editor' => $content,
		];

		if ( isset( $node['font_size'] ) ) {
			$settings = Responsive::apply( $settings, 'typography_font_size', $node['font_size'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
		}

		if ( isset( $node['color'] ) ) {
			$settings['text_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['style'] ) && is_array( $node['style'] ) ) {
			$style    = self::resolve_style( (array) $node['style'], $resolver );
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * @param array<string, mixed> $style
	 * @return array<string, mixed>
	 */
	private static function resolve_style( array $style, Resolver $resolver ): array {
		foreach ( $style as $k => $v ) {
			if ( is_string( $v ) ) {
				$style[ $k ] = $resolver->resolve( $v );
			}
		}
		return $style;
	}
}
