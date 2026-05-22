<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;
use Stonewright\WpMcp\Elementor\Renderer\StyleMapper;

/**
 * Renders a DesignSpec `image` node as an Elementor image widget.
 *
 * Elementor's image widget uses `image_border_*` for border keys (so the
 * border style helper's prefix flips to `image_border`); border-radius is the
 * non-prefixed `border_radius` dimension.
 */
final class Image {

	/**
	 * @return array<string, string|array<string, mixed>>
	 */
	private static function style_map(): array {
		return [
			'width'         => [ 'key' => 'width', 'is_size' => true ],
			'height'        => [ 'key' => 'height', 'is_size' => true ],
			'max_width'     => [ 'key' => 'width', 'is_size' => true ],
			'border_radius' => [ 'key' => 'border_radius', 'is_dimension' => true ],
			'border'        => [ 'is_border' => true, 'prefix' => 'image_border' ],
			'padding'       => [ 'key' => '_padding', 'is_dimension' => true ],
			'margin'        => [ 'key' => '_margin', 'is_dimension' => true ],
			'opacity'       => [ 'key' => 'opacity', 'is_size' => true ],
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$settings = [
			'image' => [
				'url' => (string) ( $node['url'] ?? '' ),
				'id'  => isset( $node['id'] ) ? (int) $node['id'] : '',
				'alt' => (string) ( $node['alt'] ?? '' ),
			],
		];

		if ( isset( $node['width'] ) ) {
			$settings = Responsive::apply( $settings, 'width', $node['width'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
		}

		if ( isset( $node['size'] ) ) {
			$settings['image_size'] = (string) $node['size'];
		}

		if ( isset( $node['link']['url'] ) ) {
			$settings['link_to'] = 'custom';
			$settings['link']    = [ 'url' => (string) $node['link']['url'] ];
		}

		if ( isset( $node['caption'] ) ) {
			$settings['caption_source'] = 'custom';
			$settings['caption']        = (string) $node['caption'];
		}

		if ( isset( $node['style'] ) && is_array( $node['style'] ) ) {
			$style    = self::resolve_style( (array) $node['style'], $resolver );
			$settings = StyleMapper::apply( $settings, $style, self::style_map() );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'image',
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
