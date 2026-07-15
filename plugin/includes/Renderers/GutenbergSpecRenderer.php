<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Renderers;

use Stonewright\WpMcp\DesignSpec\Validator;

/**
 * Renders a Stonewright Design Spec into a Gutenberg block tree compatible
 * with `serialize_blocks()`. Output shape matches what `parse_blocks()` would
 * have produced — each block has `blockName`, `attrs`, `innerHTML`,
 * `innerContent`, and `innerBlocks`.
 *
 * Sections become core/group blocks; common content blocks (heading,
 * paragraph, image, button, spacer, separator) map to their core/* analogs.
 */
final class GutenbergSpecRenderer {

	/**
	 * @param array<string, mixed> $spec
	 * @param array<int, array<string, mixed>>|null $diagnostics Unsupported-node diagnostics are appended here.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function render( array $spec, ?array &$diagnostics = null ): array|\WP_Error {
		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$diagnostics ??= [];
		$spec      = $validated;
		$tokens    = self::extract_tokens( $spec );
		$sections  = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$blocks    = [];
		foreach ( $sections as $section_index => $section ) {
			$blocks[] = self::render_section( (array) $section, (int) $section_index, $diagnostics, $tokens );
		}
		return $blocks;
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array{colors: array<string, string>, typography: array<string, mixed>}
	 */
	private static function extract_tokens( array $spec ): array {
		$tokens = isset( $spec['tokens'] ) && is_array( $spec['tokens'] ) ? $spec['tokens'] : [];
		$colors = isset( $tokens['colors'] ) && is_array( $tokens['colors'] ) ? $tokens['colors'] : [];
		$typo   = isset( $tokens['typography'] ) && is_array( $tokens['typography'] ) ? $tokens['typography'] : [];
		$out_colors = [];
		foreach ( $colors as $k => $v ) {
			if ( is_string( $k ) && is_string( $v ) && '' !== $v ) {
				$out_colors[ $k ] = $v;
			}
		}
		return [
			'colors'     => $out_colors,
			'typography' => $typo,
		];
	}

	/**
	 * @param array<string, mixed> $section
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @param array{colors: array<string, string>, typography: array<string, mixed>} $tokens
	 * @return array<string, mixed>
	 */
	private static function render_section( array $section, int $section_index, array &$diagnostics, array $tokens ): array {
		$children = [];
		foreach ( (array) ( $section['blocks'] ?? [] ) as $block_index => $block ) {
			$rendered = self::render_block( (array) $block, [ 'sections', $section_index, 'blocks', (int) $block_index ], $diagnostics, $tokens );
			if ( null !== $rendered ) {
				$children[] = $rendered;
			}
		}

		$style = [];
		$bg    = null;
		if ( isset( $section['background']['color'] ) ) {
			$bg = (string) $section['background']['color'];
		} elseif ( isset( $section['style']['background']['color'] ) ) {
			$bg = (string) $section['style']['background']['color'];
		}
		if ( is_string( $bg ) && '' !== $bg ) {
			$style['color']['background'] = $bg;
			// Text on section with background: prefer explicit text token for contrast.
			if ( ! empty( $tokens['colors']['text'] ) && self::is_dark_hex( $bg ) ) {
				$style['color']['text'] = '#ffffff';
			} elseif ( ! empty( $tokens['colors']['text'] ) ) {
				$style['color']['text'] = (string) $tokens['colors']['text'];
			}
		}
		if ( isset( $section['padding'] ) && is_array( $section['padding'] ) ) {
			$style['spacing']['padding'] = [
				'top'    => self::px( $section['padding']['top']    ?? 0 ),
				'right'  => self::px( $section['padding']['right']  ?? 0 ),
				'bottom' => self::px( $section['padding']['bottom'] ?? 0 ),
				'left'   => self::px( $section['padding']['left']   ?? 0 ),
			];
		} elseif ( isset( $section['style']['padding'] ) && is_string( $section['style']['padding'] ) ) {
			$parts = preg_split( '/\s+/', trim( (string) $section['style']['padding'] ) ) ?: [];
			if ( count( $parts ) >= 1 ) {
				$style['spacing']['padding'] = [
					'top'    => $parts[0],
					'right'  => $parts[1] ?? $parts[0],
					'bottom' => $parts[2] ?? $parts[0],
					'left'   => $parts[3] ?? ( $parts[1] ?? $parts[0] ),
				];
			}
		}
		$heading_font = self::token_font( $tokens, 'heading' );
		$body_font    = self::token_font( $tokens, 'body' );
		if ( '' !== $body_font ) {
			$style['typography']['fontFamily'] = $body_font;
		}

		$attrs = [
			'tagName' => 'section',
			'layout'  => [
				'type'           => 'constrained',
				'contentSize'    => '1200px',
			],
		];
		if ( ! empty( $style ) ) {
			$attrs['style'] = $style;
		}
		unset( $heading_font );

		$inner_html = '<section class="wp-block-group">';
		foreach ( $children as $_ ) {
			$inner_html .= '';
		}
		$inner_html .= '</section>';

		$open  = '<section class="wp-block-group">';
		$close = '</section>';

		$content = [ $open ];
		foreach ( $children as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/group',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $children,
		];
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array<int, string|int> $path
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @param array{colors: array<string, string>, typography: array<string, mixed>} $tokens
	 * @return array<string, mixed>|null
	 */
	private static function render_block( array $block, array $path, array &$diagnostics, array $tokens = [] ): ?array {
		$type = (string) ( $block['type'] ?? '' );
		switch ( $type ) {
			case 'heading':
				return self::heading( (int) ( $block['level'] ?? 2 ), (string) ( $block['text'] ?? '' ), $tokens, $block );
			case 'paragraph':
				return self::paragraph( (string) ( $block['text'] ?? '' ), $tokens, $block );
			case 'image':
				return self::image( $block );
			case 'button':
				return self::button( $block, $tokens );
			case 'spacer':
				$h = $block['height'] ?? 40;
				if ( is_string( $h ) && str_ends_with( $h, 'px' ) ) {
					$h = (int) $h;
				}
				return self::spacer( (int) $h );
			case 'separator':
				return self::separator();
			case 'row':
			case 'column':
				$children = [];
				foreach ( (array) ( $block['blocks'] ?? [] ) as $child_index => $child ) {
					$mapped = self::render_block( (array) $child, array_merge( $path, [ 'blocks', (int) $child_index ] ), $diagnostics, $tokens );
					if ( null !== $mapped ) {
						$children[] = $mapped;
					}
				}
				return [
					'blockName'    => 'core/group',
					'attrs'        => [ 'layout' => [ 'type' => 'flex', 'flexWrap' => 'wrap' ] ],
					'innerHTML'    => '<div class="wp-block-group"></div>',
					'innerContent' => array_merge( [ '<div class="wp-block-group">' ], array_fill( 0, count( $children ), null ), [ '</div>' ] ),
					'innerBlocks'  => $children,
				];
			default:
				$diagnostics[] = self::unsupported_node( $type, $path );
				return null;
		}
	}

	/**
	 * @param array<int, string|int> $path
	 * @return array<string, mixed>
	 */
	private static function unsupported_node( string $type, array $path ): array {
		return [
			'code'     => 'unsupported_node',
			'type'     => '' !== $type ? $type : 'unknown',
			'path'     => $path,
			'renderer' => 'gutenberg',
			'message'  => __( 'Spec node type is not supported by the Gutenberg renderer.', 'stonewright' ),
		];
	}

	/**
	 * @param array{colors?: array<string, string>, typography?: array<string, mixed>} $tokens
	 * @param array<string, mixed> $block
	 */
	private static function heading( int $level, string $text, array $tokens = [], array $block = [] ): array {
		$level = max( 1, min( 6, $level ) );
		$html  = '<h' . $level . ' class="wp-block-heading">' . esc_html( $text ) . '</h' . $level . '>';
		$attrs = 1 !== $level ? [ 'level' => $level ] : [];
		$style = [];
		$font  = self::token_font( $tokens, 'heading' );
		if ( '' !== $font ) {
			$style['typography']['fontFamily'] = $font;
		}
		$color = (string) ( $block['style']['color'] ?? '' );
		if ( '' === $color && ! empty( $tokens['colors']['text'] ) ) {
			$color = (string) $tokens['colors']['text'];
		}
		if ( '' !== $color ) {
			$style['color']['text'] = $color;
		}
		if ( ! empty( $style ) ) {
			$attrs['style'] = $style;
		}
		return [
			'blockName'    => 'core/heading',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}

	/**
	 * @param array{colors?: array<string, string>, typography?: array<string, mixed>} $tokens
	 * @param array<string, mixed> $block
	 */
	private static function paragraph( string $text, array $tokens = [], array $block = [] ): array {
		$html  = '<p>' . esc_html( $text ) . '</p>';
		$attrs = [];
		$style = [];
		$font  = self::token_font( $tokens, 'body' );
		if ( '' !== $font ) {
			$style['typography']['fontFamily'] = $font;
		}
		$color = (string) ( $block['style']['color'] ?? '' );
		if ( '' === $color && ! empty( $tokens['colors']['text'] ) ) {
			$color = (string) $tokens['colors']['text'];
		}
		if ( '' !== $color ) {
			$style['color']['text'] = $color;
		}
		if ( ! empty( $style ) ) {
			$attrs['style'] = $style;
		}
		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function image( array $block ): array {
		$url = (string) ( $block['url'] ?? '' );
		$alt = (string) ( $block['alt'] ?? '' );
		$id  = isset( $block['id'] ) ? (int) $block['id'] : 0;

		$attrs = [];
		if ( $id > 0 ) {
			$attrs['id'] = $id;
		}
		$attrs['sizeSlug'] = 'large';

		$html = '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . ( $id > 0 ? ' class="wp-image-' . $id . '"' : '' ) . '/></figure>';

		return [
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}

	/**
	 * @param array<string, mixed> $block
	 * @param array{colors?: array<string, string>, typography?: array<string, mixed>} $tokens
	 */
	private static function button( array $block, array $tokens = [] ): array {
		$text = (string) ( $block['text'] ?? 'Click me' );
		$url  = (string) ( $block['url'] ?? '#' );
		$bg   = (string) ( $block['style']['background'] ?? '' );
		if ( '' === $bg && ! empty( $tokens['colors']['primary'] ) ) {
			$bg = (string) $tokens['colors']['primary'];
		}
		if ( '' === $bg && ! empty( $tokens['colors']['accent'] ) ) {
			$bg = (string) $tokens['colors']['accent'];
		}
		$fg = (string) ( $block['style']['color'] ?? '' );
		if ( '' === $fg && '' !== $bg ) {
			$fg = self::is_dark_hex( $bg ) ? '#ffffff' : '#111827';
		}
		$style_attr = '';
		$attrs      = [];
		if ( '' !== $bg || '' !== $fg ) {
			$style = [];
			if ( '' !== $bg ) {
				$style['color']['background'] = $bg;
			}
			if ( '' !== $fg ) {
				$style['color']['text'] = $fg;
			}
			$attrs['style'] = $style;
			$style_attr     = ' style="'
				. ( '' !== $bg ? 'background-color:' . esc_attr( $bg ) . ';' : '' )
				. ( '' !== $fg ? 'color:' . esc_attr( $fg ) . ';' : '' )
				. '"';
		}
		$inner = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $url ) . '"' . $style_attr . '>' . esc_html( $text ) . '</a></div>';
		$open  = '<div class="wp-block-buttons">';
		$close = '</div>';

		$button_block = [
			'blockName'    => 'core/button',
			'attrs'        => $attrs,
			'innerHTML'    => $inner,
			'innerContent' => [ $inner ],
			'innerBlocks'  => [],
		];

		return [
			'blockName'    => 'core/buttons',
			'attrs'        => [],
			'innerHTML'    => $open . $close,
			'innerContent' => [ $open, null, $close ],
			'innerBlocks'  => [ $button_block ],
		];
	}

	/**
	 * @param array{colors?: array<string, string>, typography?: array<string, mixed>} $tokens
	 */
	private static function token_font( array $tokens, string $role ): string {
		$typo = $tokens['typography'][ $role ] ?? null;
		if ( is_array( $typo ) && ! empty( $typo['font_family'] ) && is_string( $typo['font_family'] ) ) {
			return $typo['font_family'];
		}
		if ( is_string( $typo ) && '' !== $typo ) {
			return $typo;
		}
		return '';
	}

	private static function is_dark_hex( string $hex ): bool {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return false;
		}
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
		$lin = static function ( float $c ): float {
			return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		$l = ( 0.2126 * $lin( $r ) ) + ( 0.7152 * $lin( $g ) ) + ( 0.0722 * $lin( $b ) );
		return $l < 0.45;
	}

	private static function spacer( int $height ): array {
		$attrs = [ 'height' => $height . 'px' ];
		$html  = '<div style="height:' . (int) $height . 'px" aria-hidden="true" class="wp-block-spacer"></div>';
		return [
			'blockName'    => 'core/spacer',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}

	private static function separator(): array {
		$html = '<hr class="wp-block-separator has-alpha-channel-opacity"/>';
		return [
			'blockName'    => 'core/separator',
			'attrs'        => [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}

	/**
	 * @param int|string $value
	 */
	private static function px( $value ): string {
		if ( is_numeric( $value ) ) {
			return (int) $value . 'px';
		}
		return (string) $value;
	}
}
