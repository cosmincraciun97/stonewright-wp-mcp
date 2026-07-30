<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Gutenberg\Renderer as GutenbergRenderer;
use Stonewright\WpMcp\Gutenberg\TokenMapper;
use Stonewright\WpMcp\Gutenberg\UrlGuard;

/**
 * Renders a DesignSpec media-text node as a core/media-text block.
 *
 * Spec node shape:
 * {
 *   type: 'media-text',
 *   media: { url: string, alt: string },
 *   content: <inline-block-nodes[]>,
 *   media_position?: 'left'|'right',
 *   vertical_alignment?: 'top'|'center'|'bottom'
 * }
 */
final class MediaText {

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @param Resolver|null                    $resolver Token resolver (null = no token mapping).
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path, array &$diagnostics, ?Resolver $resolver = null ): array {
		$media    = isset( $node['media'] ) && is_array( $node['media'] ) ? $node['media'] : [];
		$url      = (string) ( $media['url'] ?? '' );
		$alt      = (string) ( $media['alt'] ?? '' );
		$position = isset( $node['media_position'] ) ? (string) $node['media_position'] : 'left';
		$valign   = isset( $node['vertical_alignment'] ) ? (string) $node['vertical_alignment'] : 'center';

		// Sanitize: position must be 'left' or 'right'.
		if ( ! in_array( $position, [ 'left', 'right' ], true ) ) {
			$position = 'left';
		}
		// Sanitize: vertical_alignment must be 'top', 'center', or 'bottom'.
		if ( ! in_array( $valign, [ 'top', 'center', 'bottom' ], true ) ) {
			$valign = 'center';
		}

		$safe_url = '' !== $url ? UrlGuard::safe_url( $url ) : null;

		$attrs = [
			'mediaType' => 'image',
		];
		if ( null !== $safe_url ) {
			$attrs['mediaUrl'] = $safe_url;
		}
		if ( '' !== $alt ) {
			$attrs['mediaAlt'] = $alt;
		}
		if ( 'right' === $position ) {
			$attrs['mediaPosition'] = 'right';
		}
		if ( 'center' !== $valign ) {
			$attrs['verticalAlignment'] = $valign;
		}

		if ( null !== $resolver ) {
			$attrs = TokenMapper::apply( $node, $attrs, $resolver, 'background' );
		}

		// Render content blocks.
		$content_blocks = [];
		$content_nodes  = isset( $node['content'] ) && is_array( $node['content'] ) ? $node['content'] : [];
		foreach ( $content_nodes as $b_idx => $child ) {
			$rendered = GutenbergRenderer::render_block( (array) $child, $path . '.c' . $b_idx, $diagnostics, $resolver );
			if ( null !== $rendered ) {
				$content_blocks[] = $rendered;
			}
		}

		$classes   = 'wp-block-media-text';
		if ( 'right' === $position ) {
			$classes .= ' has-media-on-the-right';
		}

		$img_html    = null !== $safe_url
			? '<figure class="wp-block-media-text__media"><img src="' . esc_url( $safe_url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-0 size-full"/></figure>'
			: '<figure class="wp-block-media-text__media"></figure>';
		$content_div = '<div class="wp-block-media-text__content">';
		$close_div   = '</div>';

		$inner_content   = [ '<div class="' . esc_attr( $classes ) . '">', $img_html, $content_div ];
		foreach ( $content_blocks as $_ ) {
			$inner_content[] = null;
		}
		$inner_content[] = $close_div . '</div>';

		return [
			'blockName'    => 'core/media-text',
			'attrs'        => $attrs,
			'innerHTML'    => '<div class="' . esc_attr( $classes ) . '">' . $img_html . $content_div . $close_div . '</div>',
			'innerContent' => $inner_content,
			'innerBlocks'  => $content_blocks,
		];
	}
}
