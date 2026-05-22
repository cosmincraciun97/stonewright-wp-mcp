<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\TokenMapper;

/**
 * Renders a DesignSpec button/buttons node as core/buttons containing core/button.
 */
final class Buttons {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver|null        $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, ?Resolver $resolver = null ): array {
		// Normalize: a single button spec and a buttons container are treated the same.
		if ( 'buttons' === ( $node['type'] ?? '' ) && isset( $node['buttons'] ) && is_array( $node['buttons'] ) ) {
			$buttons = $node['buttons'];
		} else {
			$buttons = [ $node ];
		}

		$button_blocks = [];
		foreach ( $buttons as $b ) {
			$button_blocks[] = self::button_block( (array) $b );
		}

		$attrs = [];
		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'background' );
		}

		$open  = '<div class="wp-block-buttons">';
		$close = '</div>';

		$content = [ $open ];
		foreach ( $button_blocks as $_ ) {
			$content[] = null;
		}
		$content[] = $close;

		return [
			'blockName'    => 'core/buttons',
			'attrs'        => $attrs,
			'innerHTML'    => $open . $close,
			'innerContent' => $content,
			'innerBlocks'  => $button_blocks,
		];
	}

	/**
	 * @param array<string, mixed> $button
	 * @return array<string, mixed>
	 */
	private static function button_block( array $button ): array {
		$text  = (string) ( $button['text'] ?? 'Button' );
		$url   = (string) ( $button['url'] ?? '#' );
		$style = isset( $button['style'] ) ? (string) $button['style'] : 'fill';

		$attrs = [];
		if ( 'outline' === $style ) {
			$attrs['className'] = 'is-style-outline';
		}

		$html = '<div class="wp-block-button">'
			. '<a class="wp-block-button__link wp-element-button" href="' . esc_url( $url ) . '">'
			. esc_html( $text )
			. '</a></div>';

		return [
			'blockName'    => 'core/button',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
