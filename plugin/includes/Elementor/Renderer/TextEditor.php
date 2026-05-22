<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec `text-editor` node as an Elementor text-editor widget.
 *
 * The `text-editor` spec type carries richer HTML content than a plain heading.
 * If the value in `html` is already HTML it is preserved; plain `text` is wrapped
 * in a `<p>` tag. Never uses esc_html on stored HTML — Elementor stores raw HTML
 * in the editor setting and escapes on output.
 */
final class TextEditor {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		if ( isset( $node['html'] ) ) {
			$content = (string) $node['html'];
		} elseif ( isset( $node['text'] ) ) {
			$content = '<p>' . esc_html( (string) $node['text'] ) . '</p>';
		} else {
			$content = '';
		}

		$settings = [
			'editor' => $content,
		];

		if ( isset( $node['align'] ) ) {
			$settings['align'] = (string) $node['align'];
		}

		if ( isset( $node['color'] ) ) {
			$settings['text_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
