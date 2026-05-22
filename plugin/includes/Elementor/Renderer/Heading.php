<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Renders a DesignSpec heading/paragraph node as an Elementor heading widget.
 *
 * Accepted spec types: `heading`, `paragraph`.
 * For `paragraph` the header_size defaults to `p` (rendered as a div by Elementor).
 */
final class Heading {

	/**
	 * Map from `style.*` keys to Elementor heading-widget settings.
	 *
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'color'           => [ 'key' => 'title_color', 'is_color' => true ],
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
		$type        = (string) ( $node['type'] ?? 'heading' );
		$header_size = 'heading' === $type
			? 'h' . max( 1, min( 6, (int) ( $node['level'] ?? 2 ) ) )
			: 'p';

		$settings = [
			'title'       => (string) ( $node['text'] ?? '' ),
			'header_size' => $header_size,
		];

		if ( isset( $node['font_size'] ) ) {
			$settings = Responsive::apply( $settings, 'typography_font_size', $node['font_size'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
		}

		if ( isset( $node['color'] ) ) {
			$settings['title_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		// Prefer the nested link object (explicit form) over the top-level url shorthand.
		// Both are valid; the nested form wins when both are present.
		if ( isset( $node['link']['url'] ) ) {
			$settings['link'] = [
				'url'         => (string) $node['link']['url'],
				'is_external' => ! empty( $node['link']['external'] ),
				'nofollow'    => ! empty( $node['link']['nofollow'] ),
			];
		} elseif ( isset( $node['url'] ) ) {
			$settings['link'] = [
				'url'         => (string) $node['url'],
				'is_external' => ! empty( $node['external'] ),
				'nofollow'    => ! empty( $node['nofollow'] ),
			];
		}

		if ( isset( $node['style'] ) && is_array( $node['style'] ) ) {
			$style    = self::resolve_style( (array) $node['style'], $resolver );
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	/**
	 * Run any string values through the token resolver so style entries like
	 * `'color' => '{colors.primary}'` still work.
	 *
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
