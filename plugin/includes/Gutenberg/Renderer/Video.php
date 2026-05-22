<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\Gutenberg\UrlGuard;

/**
 * Renders a DesignSpec video node as a core/video block.
 *
 * Spec node shape:
 * {
 *   type: 'video',
 *   url: string,
 *   caption?: string,
 *   poster?: string,
 *   autoplay?: bool,
 *   controls?: bool
 * }
 */
final class Video {

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>|null  null when src URL is unsafe/missing.
	 */
	public static function render( array $node, string $path, array &$diagnostics = [] ): ?array {
		$raw_url  = (string) ( $node['url'] ?? '' );
		$caption  = (string) ( $node['caption'] ?? '' );
		$raw_poster = (string) ( $node['poster'] ?? '' );
		$autoplay = isset( $node['autoplay'] ) ? (bool) $node['autoplay'] : false;
		$controls = isset( $node['controls'] ) ? (bool) $node['controls'] : true;

		$safe_url    = UrlGuard::safe_url( $raw_url );
		$safe_poster = '' !== $raw_poster ? UrlGuard::safe_url( $raw_poster ) : null;

		if ( null === $safe_url ) {
			$diagnostics[] = [
				'code'     => 'unsafe_video_url',
				'type'     => 'video',
				'path'     => $path,
				'renderer' => 'gutenberg',
				'message'  => 'core/video requires a safe http/https src URL.',
			];
			return null;
		}

		$attrs = [ 'src' => $safe_url ];
		if ( null !== $safe_poster ) {
			$attrs['poster'] = $safe_poster;
		}
		if ( $autoplay ) {
			$attrs['autoplay'] = true;
		}
		if ( ! $controls ) {
			$attrs['controls'] = false;
		}
		if ( '' !== $caption ) {
			$attrs['caption'] = $caption;
		}

		// Build <video> element.
		$video_attrs = 'class="wp-block-video__video"';
		$video_attrs .= ' src="' . esc_url( $safe_url ) . '"';
		if ( $controls ) {
			$video_attrs .= ' controls';
		}
		if ( $autoplay ) {
			$video_attrs .= ' autoplay';
		}
		if ( null !== $safe_poster ) {
			$video_attrs .= ' poster="' . esc_url( $safe_poster ) . '"';
		}

		$html = '<figure class="wp-block-video"><video ' . $video_attrs . '></video>';
		if ( '' !== $caption ) {
			$html .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}
		$html .= '</figure>';

		return [
			'blockName'    => 'core/video',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
