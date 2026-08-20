<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg\Renderer;

/**
 * Renders a DesignSpec query node as core/query wrapping core/post-template.
 */
final class Query {

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function render( array $node, string $path ): array {
		$post_type = sanitize_key( (string) ( $node['post_type'] ?? 'post' ) );
		if ( '' === $post_type ) {
			$post_type = 'post';
		}
		$count   = max( 1, min( 100, (int) ( $node['count'] ?? 3 ) ) );
		$order   = strtolower( (string) ( $node['order'] ?? 'desc' ) );
		$order   = in_array( $order, [ 'asc', 'desc' ], true ) ? $order : 'desc';
		$inherit = (bool) ( $node['inherit'] ?? false );

		$inner = [
			self::leaf( 'core/post-title' ),
			self::leaf( 'core/post-excerpt' ),
		];

		$template = [
			'blockName'    => 'core/post-template',
			'attrs'        => [],
			'innerHTML'    => '',
			'innerContent' => array_merge( [ '' ], array_fill( 0, count( $inner ), null ) ),
			'innerBlocks'  => $inner,
		];

		$open  = '<div class="wp-block-query">';
		$close = '</div>';

		return [
			'blockName'    => 'core/query',
			'attrs'        => [
				'query' => [
					'perPage'  => $count,
					'postType' => $post_type,
					'order'    => $order,
					'orderBy'  => 'date',
					'inherit'  => $inherit,
				],
			],
			'innerHTML'    => $open . $close,
			'innerContent' => [ $open, null, $close ],
			'innerBlocks'  => [ $template ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function leaf( string $name ): array {
		return [
			'blockName'    => $name,
			'attrs'        => [],
			'innerHTML'    => '',
			'innerContent' => [ '' ],
			'innerBlocks'  => [],
		];
	}
}
