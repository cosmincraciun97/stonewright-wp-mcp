<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

use Stonewright\WpMcp\Gutenberg\UrlGuard;

/**
 * Renders a DesignSpec embed node as a core/embed block.
 *
 * Spec node shape:
 * {
 *   type: 'embed',
 *   url: string,
 *   provider_name_slug?: string,
 *   caption?: string,
 *   aspect_ratio?: string
 * }
 *
 * provider_name_slug is sanitized to lowercase a-z0-9- only.
 */
final class Embed {

	/**
	 * @param array<string, mixed>             $node
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array<string, mixed>|null  null when URL is unsafe/missing.
	 */
	public static function render( array $node, string $path, array &$diagnostics = [] ): ?array {
		$raw_url      = (string) ( $node['url'] ?? '' );
		$caption      = (string) ( $node['caption'] ?? '' );
		$aspect_ratio = (string) ( $node['aspect_ratio'] ?? '' );

		// Sanitize provider slug: only lowercase a-z0-9 and hyphens.
		$raw_provider = isset( $node['provider_name_slug'] ) ? (string) $node['provider_name_slug'] : '';
		$provider     = '' !== $raw_provider
			? preg_replace( '/[^a-z0-9-]/', '', strtolower( $raw_provider ) )
			: '';

		$safe_url = UrlGuard::safe_url( $raw_url );

		if ( null === $safe_url ) {
			$diagnostics[] = [
				'code'     => 'unsafe_embed_url',
				'type'     => 'embed',
				'path'     => $path,
				'renderer' => 'gutenberg',
				'message'  => 'core/embed requires a safe http/https URL.',
			];
			return null;
		}

		$attrs = [ 'url' => $safe_url ];
		if ( '' !== $provider ) {
			$attrs['providerNameSlug'] = $provider;
		}
		if ( '' !== $aspect_ratio ) {
			$attrs['aspectRatio'] = $aspect_ratio;
		}
		if ( '' !== $caption ) {
			$attrs['caption'] = $caption;
		}

		$html = '<figure class="wp-block-embed">';
		$html .= '<div class="wp-block-embed__wrapper">';
		$html .= esc_url( $safe_url );
		$html .= '</div>';
		if ( '' !== $caption ) {
			$html .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}
		$html .= '</figure>';

		return [
			'blockName'    => 'core/embed',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
