<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;

/**
 * Renders a DesignSpec heading/paragraph node as an Elementor heading widget.
 *
 * Accepted spec types: `heading`, `paragraph`.
 * For `paragraph` the header_size defaults to `p` (rendered as a div by Elementor).
 */
final class Heading {

	/**
	 * @param array<string, mixed> $node
	 * @param Resolver             $resolver
	 * @param string               $canonical_path
	 * @return array<string, mixed>
	 */
	public static function render( array $node, Resolver $resolver, string $canonical_path ): array {
		$type        = (string) ( $node['type'] ?? 'heading' );
		$header_size = 'heading' === $type
			? 'h' . max( 1, min( 6, (int) ( $node['level'] ?? 2 ) ) )
			: 'p';

		$settings = [
			'title'       => (string) ( $node['text'] ?? '' ),
			'header_size' => $header_size,
		];

		if ( isset( $node['align'] ) ) {
			$settings['align'] = (string) $node['align'];
		}

		if ( isset( $node['color'] ) ) {
			$settings['title_color'] = (string) $resolver->resolve( (string) $node['color'] );
		}

		if ( isset( $node['link']['url'] ) ) {
			$settings['link'] = [ 'url' => (string) $node['link']['url'] ];
		}

		return [
			'id'         => Section::stable_id( $canonical_path ),
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => $settings,
			'elements'   => [],
		];
	}
}
