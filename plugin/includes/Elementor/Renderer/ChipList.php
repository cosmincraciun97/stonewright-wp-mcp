<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\LayoutNormalizer;

/**
 * Semantic chip-list / pill-grid via native flex-wrap containers + children.
 *
 * - White fill, contrast-safe text, radius, padding, gap
 * - Row wrap on desktop; stable wrap/stack on narrow viewports
 * - Semantic text vs link/button per chip
 * - No list fallback unless the node explicitly sets `fallback: "list"`
 *
 * Spec shape:
 *   {
 *     type: "chip-list" | "pill-grid" | "chips",
 *     items: [
 *       { text: "Result", url?: "...", style?: "button"|"text" }
 *     ],
 *     gap?: number,
 *     background?: color,
 *     color?: color,
 *     border_radius?: number
 *   }
 */
final class ChipList {

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path, array &$diagnostics = [] ): array {
		$fallback = strtolower( (string) ( $node['fallback'] ?? '' ) );
		if ( 'list' === $fallback ) {
			// Explicit list fallback only — never default to it.
			return self::render_list_fallback( $node, $resolver, $canonical_path );
		}

		$items = isset( $node['items'] ) && is_array( $node['items'] ) ? $node['items'] : [];
		$gap   = isset( $node['gap'] ) ? (int) $node['gap'] : 12;
		$radius = isset( $node['border_radius'] ) ? (int) $node['border_radius'] : 999;
		$bg     = (string) $resolver->resolve( (string) ( $node['background'] ?? $node['background_color'] ?? '#ffffff' ) );
		$color  = (string) $resolver->resolve( (string) ( $node['color'] ?? $node['text_color'] ?? '#111111' ) );

		// Contrast safety: pure white bg with near-white text is rejected.
		if ( self::is_light( $bg ) && self::is_light( $color ) ) {
			$color = '#111111';
			$diagnostics[] = [
				'code'     => 'chip_contrast_adjusted',
				'path'     => $canonical_path,
				'renderer' => 'elementor_v3',
				'message'  => 'Chip text color was adjusted for contrast against a light fill.',
			];
		}

		$layout = LayoutNormalizer::for_spec(
			[ 'desktop' => 'row', 'tablet' => 'row', 'mobile' => 'row' ],
			[ 'desktop' => 'row', 'tablet' => 'row', 'mobile' => 'row' ]
		);

		$settings = [
			'container_type'  => 'flex',
			'flex_direction'  => 'row',
			'flex_wrap'       => 'wrap',
			'flex_gap'        => [
				'unit'     => 'px',
				'size'     => $gap,
				'column'   => (string) $gap,
				'row'      => (string) $gap,
				'isLinked' => true,
			],
			'flex_align_items'     => 'center',
			'flex_justify_content' => (string) ( $node['justify_content'] ?? 'flex-start' ),
		];

		// Stable wrap on narrow: keep wrap; optional stack when explicitly requested.
		$narrow = strtolower( (string) ( $node['narrow'] ?? 'wrap' ) );
		if ( 'stack' === $narrow ) {
			$settings = LayoutNormalizer::apply_direction(
				$settings,
				[ 'desktop' => 'row', 'tablet' => 'column', 'mobile' => 'column' ]
			);
			$settings['flex_wrap'] = 'nowrap';
		} else {
			// wrap remains on all breakpoints.
			$settings['flex_wrap']        = 'wrap';
			$settings['flex_wrap_tablet'] = 'wrap';
			$settings['flex_wrap_mobile'] = 'wrap';
		}

		$children = [];
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				$item = [ 'text' => (string) $item ];
			}
			$child_path = $canonical_path . '.chip.' . $i;
			$children[] = self::render_chip( $item, $resolver, $child_path, $bg, $color, $radius );
		}

		return [
			'id'       => Section::stable_id( $canonical_path ),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => $settings,
			'elements' => $children,
		];
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private static function render_chip( array $item, Resolver $resolver, string $path, string $bg, string $color, int $radius ): array {
		$text  = (string) ( $item['text'] ?? $item['label'] ?? '' );
		$url   = (string) ( $item['url'] ?? $item['link']['url'] ?? '' );
		$style = strtolower( (string) ( $item['style'] ?? ( '' !== $url ? 'button' : 'text' ) ) );

		$chip_bg    = isset( $item['background'] ) ? (string) $resolver->resolve( (string) $item['background'] ) : $bg;
		$chip_color = isset( $item['color'] ) ? (string) $resolver->resolve( (string) $item['color'] ) : $color;
		$chip_radius = isset( $item['border_radius'] ) ? (int) $item['border_radius'] : $radius;

		if ( 'button' === $style || 'link' === $style || '' !== $url ) {
			return [
				'id'         => Section::stable_id( $path ),
				'elType'     => 'widget',
				'widgetType' => 'button',
				'settings'   => [
					'text'              => $text,
					'link'              => [
						'url'         => $url,
						'is_external' => ! empty( $item['external'] ),
						'nofollow'    => ! empty( $item['nofollow'] ),
					],
					'size'              => 'sm',
					'background_color'  => $chip_bg,
					'button_text_color' => $chip_color,
					'border_radius'     => [
						'unit'     => 'px',
						'top'      => (string) $chip_radius,
						'right'    => (string) $chip_radius,
						'bottom'   => (string) $chip_radius,
						'left'     => (string) $chip_radius,
						'isLinked' => true,
					],
					'text_padding'      => [
						'unit'     => 'px',
						'top'      => '8',
						'right'    => '16',
						'bottom'   => '8',
						'left'     => '16',
						'isLinked' => false,
					],
				],
				'elements'   => [],
			];
		}

		// Semantic text chip: heading widget styled as a pill via background on wrapper...
		// Free Elementor has no chip widget; text-editor inside a mini container keeps
		// non-link chips editable without forcing a button.
		return [
			'id'       => Section::stable_id( $path ),
			'elType'   => 'container',
			'isInner'  => true,
			'settings' => [
				'container_type'        => 'flex',
				'flex_direction'        => 'row',
				'flex_align_items'      => 'center',
				'flex_justify_content'  => 'center',
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => $chip_bg,
				'border_radius'         => [
					'unit'     => 'px',
					'top'      => (string) $chip_radius,
					'right'    => (string) $chip_radius,
					'bottom'   => (string) $chip_radius,
					'left'     => (string) $chip_radius,
					'isLinked' => true,
				],
				'padding'               => [
					'unit'     => 'px',
					'top'      => '8',
					'right'    => '16',
					'bottom'   => '8',
					'left'     => '16',
					'isLinked' => false,
				],
			],
			'elements' => [
				[
					'id'         => Section::stable_id( $path . '.label' ),
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => [
						'title'       => $text,
						'header_size' => 'span',
						'title_color' => $chip_color,
						'typography_font_size' => [
							'unit' => 'px',
							'size' => 14,
						],
					],
					'elements'   => [],
				],
			],
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private static function render_list_fallback( array $node, Resolver $resolver, string $path ): array {
		$items = isset( $node['items'] ) && is_array( $node['items'] ) ? $node['items'] : [];
		$html  = '<ul class="stonewright-chip-list-fallback">';
		foreach ( $items as $item ) {
			$label = is_array( $item ) ? (string) ( $item['text'] ?? $item['label'] ?? '' ) : (string) $item;
			$html .= '<li>' . esc_html( $label ) . '</li>';
		}
		$html .= '</ul>';

		return [
			'id'         => Section::stable_id( $path ),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => [ 'editor' => $html ],
			'elements'   => [],
		];
	}

	private static function is_light( string $color ): bool {
		$color = ltrim( trim( $color ), '#' );
		if ( 3 === strlen( $color ) ) {
			$color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
		}
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $color ) ) {
			return false;
		}
		$r = hexdec( substr( $color, 0, 2 ) );
		$g = hexdec( substr( $color, 2, 2 ) );
		$b = hexdec( substr( $color, 4, 2 ) );
		// Relative luminance threshold.
		$luma = ( 0.2126 * $r + 0.7152 * $g + 0.0722 * $b ) / 255;

		return $luma > 0.75;
	}
}
