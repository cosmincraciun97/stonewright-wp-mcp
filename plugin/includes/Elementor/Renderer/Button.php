<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

/**
 * Renders a DesignSpec `button` node as an Elementor button widget.
 */
final class Button {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$settings = [
			'text' => (string) ( $node['text'] ?? '' ),
			'link' => [
				'url'        => (string) ( $node['url'] ?? '' ),
				'is_external' => ! empty( $node['external'] ),
				'nofollow'   => ! empty( $node['nofollow'] ),
			],
		];

		if ( isset( $node['font_size'] ) ) {
			$settings = Responsive::apply( $settings, 'typography_font_size', $node['font_size'] );
		}

		if ( isset( $node['align'] ) ) {
			$settings = Responsive::apply( $settings, 'align', $node['align'] );
		}

		if ( isset( $node['padding'] ) ) {
			$settings = Responsive::apply( $settings, 'padding', $node['padding'] );
		}

		if ( isset( $node['size'] ) ) {
			$settings['size'] = (string) $node['size'];
		}

		if ( isset( $node['type'] ) && 'button' !== $node['type'] ) {
			// node['type'] is the spec type; button_type is Elementor's style variant.
		} elseif ( isset( $node['style'] ) ) {
			$settings['button_type'] = (string) $node['style'];
		}

		if ( isset( $node['icon'] ) ) {
			$settings['icon'] = [ 'value' => (string) $node['icon'], 'library' => 'fa-solid' ];
		}

		if ( isset( $node['color'] ) ) {
			$settings['button_text_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['background_color'] ) ) {
			$settings['button_background_color'] = (string) $resolver->resolve( (string) $node['background_color'] );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'button',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
