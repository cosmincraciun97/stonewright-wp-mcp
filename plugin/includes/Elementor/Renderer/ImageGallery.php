<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec gallery as Elementor's native Basic Gallery widget.
 */
final class ImageGallery {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$images     = [];
		$raw_images = isset( $node['images'] ) && is_array( $node['images'] ) ? $node['images'] : [];

		foreach ( $raw_images as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}

			$images[] = [
				'id'  => isset( $image['id'] ) ? (int) $image['id'] : '',
				'url' => (string) ( $image['url'] ?? $image['src'] ?? '' ),
			];
		}

		$gallery_link = (string) ( $node['gallery_link'] ?? $node['link_to'] ?? 'file' );
		if ( ! in_array( $gallery_link, [ 'file', 'attachment', 'none' ], true ) ) {
			$gallery_link = 'file';
		}

		$settings = [
			'wp_gallery'   => $images,
			'gallery_link' => $gallery_link,
			'gallery_rand' => self::gallery_rand( (string) ( $node['orderby'] ?? 'default' ) ),
		];

		if ( 'file' === $gallery_link ) {
			$lightbox = (string) ( $node['open_lightbox'] ?? $node['lightbox'] ?? 'yes' );
			if ( ! in_array( $lightbox, [ 'default', 'yes', 'no' ], true ) ) {
				$lightbox = 'yes';
			}
			$settings['open_lightbox'] = $lightbox;
		}

		if ( isset( $node['columns'] ) ) {
			$settings['gallery_columns'] = max( 1, min( 10, (int) $node['columns'] ) );
		}

		if ( isset( $node['image_size'] ) ) {
			$settings['thumbnail_size'] = (string) $node['image_size'];
		}

		if ( isset( $node['spacing'] ) ) {
			$settings['image_spacing_custom'] = [
				'unit' => 'px',
				'size' => (int) $node['spacing'],
			];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'image-gallery',
			'settings'   => $settings,
			'elements'   => [],
		];
	}

	private static function gallery_rand( string $orderby ): string {
		return in_array( strtolower( $orderby ), [ 'rand', 'random' ], true ) ? 'rand' : '';
	}
}
