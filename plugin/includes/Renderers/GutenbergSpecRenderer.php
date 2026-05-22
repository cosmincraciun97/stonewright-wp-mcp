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
		$sections = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$blocks   = [];
		foreach ( $sections as $section_index => $section ) {
			$blocks[] = self::render_section( (array) $section, (int) $section_index, $diagnostics );
		}
		return $blocks;
	}

	/**
	 * @param array<string, mixed> $section
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>
	 */
	private static function render_section( array $section, int $section_index, array &$diagnostics ): array {
		$children = [];
		foreach ( (array) ( $section['blocks'] ?? [] ) as $block_index => $block ) {
			$rendered = self::render_block( (array) $block, [ 'sections', $section_index, 'blocks', (int) $block_index ], $diagnostics );
			if ( null !== $rendered ) {
				$children[] = $rendered;
			}
		}

		$style = [];
		if ( isset( $section['background']['color'] ) ) {
			$style['color']['background'] = (string) $section['background']['color'];
		}
		if ( isset( $section['padding'] ) && is_array( $section['padding'] ) ) {
			$style['spacing']['padding'] = [
				'top'    => self::px( $section['padding']['top']    ?? 0 ),
				'right'  => self::px( $section['padding']['right']  ?? 0 ),
				'bottom' => self::px( $section['padding']['bottom'] ?? 0 ),
				'left'   => self::px( $section['padding']['left']   ?? 0 ),
			];
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
	 * @return array<string, mixed>|null
	 */
	private static function render_block( array $block, array $path, array &$diagnostics ): ?array {
		$type = (string) ( $block['type'] ?? '' );
		switch ( $type ) {
			case 'heading':
				return self::heading( (int) ( $block['level'] ?? 2 ), (string) ( $block['text'] ?? '' ) );
			case 'paragraph':
				return self::paragraph( (string) ( $block['text'] ?? '' ) );
			case 'image':
				return self::image( $block );
			case 'button':
				return self::button( $block );
			case 'spacer':
				return self::spacer( (int) ( $block['height'] ?? 40 ) );
			case 'separator':
				return self::separator();
			case 'row':
			case 'column':
				$children = [];
				foreach ( (array) ( $block['blocks'] ?? [] ) as $child_index => $child ) {
					$mapped = self::render_block( (array) $child, array_merge( $path, [ 'blocks', (int) $child_index ] ), $diagnostics );
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

	private static function heading( int $level, string $text ): array {
		$level = max( 1, min( 6, $level ) );
		$html  = '<h' . $level . ' class="wp-block-heading">' . esc_html( $text ) . '</h' . $level . '>';
		return [
			'blockName'    => 'core/heading',
			'attrs'        => 1 !== $level ? [ 'level' => $level ] : [],
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}

	private static function paragraph( string $text ): array {
		$html = '<p>' . esc_html( $text ) . '</p>';
		return [
			'blockName'    => 'core/paragraph',
			'attrs'        => [],
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
	 */
	private static function button( array $block ): array {
		$text = (string) ( $block['text'] ?? 'Click me' );
		$url  = (string) ( $block['url'] ?? '#' );
		$inner = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></div>';
		$open  = '<div class="wp-block-buttons">';
		$close = '</div>';

		$button_block = [
			'blockName'    => 'core/button',
			'attrs'        => [],
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
