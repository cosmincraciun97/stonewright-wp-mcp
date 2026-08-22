<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Serializes a normalized block array (either parse_blocks() output or the
 * lighter Stonewright shape with `name`/`attrs`/`innerHTML`/`innerBlocks`)
 * back into post-content HTML.
 */
final class BlockSerializer {

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 */
	public static function serialize( array $blocks ): string {
		if ( empty( $blocks ) ) {
			return '';
		}

		$normalized = array_map( [ self::class, 'normalize' ], $blocks );

		if ( function_exists( 'serialize_blocks' ) ) {
			return serialize_blocks( $normalized );
		}

		$out = '';
		foreach ( $normalized as $block ) {
			$out .= self::serialize_one( $block );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	public static function normalize( array $block ): array {
		$name         = $block['blockName'] ?? ( $block['name'] ?? null );
		$attrs        = self::input_attrs( $block );
		$inner_html   = (string) ( $block['innerHTML'] ?? '' );
		$inner_blocks = array_map(
			[ self::class, 'normalize' ],
			array_values( array_filter( (array) ( $block['innerBlocks'] ?? [] ), 'is_array' ) )
		);

		$inner_content = $block['innerContent'] ?? null;
		if ( ! is_array( $inner_content ) || ( [] !== $inner_blocks && ! in_array( null, $inner_content, true ) ) ) {
			$inner_content = '' !== $inner_html ? [ $inner_html ] : [];
			$inner_content = array_merge( $inner_content, array_fill( 0, count( $inner_blocks ), null ) );
		}

		return [
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
			'innerBlocks'  => $inner_blocks,
		];
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, mixed>
	 */
	private static function input_attrs( array $block ): array {
		foreach ( [ 'attributes', 'attrs' ] as $key ) {
			if ( ! isset( $block[ $key ] ) ) {
				continue;
			}
			if ( is_array( $block[ $key ] ) ) {
				return $block[ $key ];
			}
			if ( is_object( $block[ $key ] ) ) {
				return (array) $block[ $key ];
			}
		}
		return [];
	}

	/**
	 * @param array<string, mixed> $block
	 */
	private static function serialize_one( array $block ): string {
		$name = $block['blockName'] ?? null;
		if ( null === $name ) {
			return (string) ( $block['innerHTML'] ?? '' );
		}

		$attrs_json = empty( $block['attrs'] ) ? '' : wp_json_encode( $block['attrs'] );
		$opener     = '<!-- wp:' . $name . ( '' !== $attrs_json ? ' ' . $attrs_json : '' ) . ' -->';
		$closer     = '<!-- /wp:' . $name . ' -->';

		if ( empty( $block['innerBlocks'] ) ) {
			return $opener . $block['innerHTML'] . $closer;
		}

		$inner_serialized = '';
		foreach ( $block['innerBlocks'] as $child ) {
			$inner_serialized .= self::serialize_one( $child );
		}

		return $opener . $block['innerHTML'] . $inner_serialized . $closer;
	}
}
