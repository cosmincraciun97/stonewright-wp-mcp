<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

/**
 * Renders a DesignSpec separator node as a core/separator block.
 */
final class Separator {

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path ): array {
		$style = isset( $node['style'] ) && in_array( (string) $node['style'], [ 'wide', 'dots' ], true )
			? (string) $node['style']
			: 'default';

		$attrs = 'default' !== $style ? [ 'className' => 'is-style-' . $style ] : [];
		$html  = '<hr class="wp-block-separator has-alpha-channel-opacity"/>';

		return [
			'blockName'    => 'core/separator',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => [ $html ],
			'innerBlocks'  => [],
		];
	}
}
