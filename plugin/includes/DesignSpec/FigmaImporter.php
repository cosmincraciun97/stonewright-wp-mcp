<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\DesignSpec;

/**
 * Talks to the Figma REST API and converts a single node (frame, section,
 * component, instance) into a Stonewright Design Spec. Heuristic mapping:
 * Figma containers → spec sections; text nodes → heading/paragraph; image
 * fills → image block; rectangle with image fill → image block; everything
 * else degrades gracefully.
 *
 * Companion-side rendering uses Figma's Code Connect / Dev Mode MCP when
 * available — this importer is the fallback that works with just a PAT.
 */
final class FigmaImporter {

	private const API_HOST = 'https://api.figma.com';

	/**
	 * @return array{file_key:string, node_id:string}|null
	 */
	public static function parse_url( string $url ): ?array {
		if ( ! preg_match( '#figma\\.com/(?:design|file)/([A-Za-z0-9]+)/?[^?]*#', $url, $m ) ) {
			return null;
		}
		$file_key = $m[1];

		$node_id = '';
		$query   = wp_parse_url( $url, PHP_URL_QUERY ) ?: '';
		parse_str( $query, $params );
		if ( ! empty( $params['node-id'] ) ) {
			$node_id = str_replace( '-', ':', (string) $params['node-id'] );
		}

		if ( '' === $node_id ) {
			return null;
		}

		return [ 'file_key' => $file_key, 'node_id' => $node_id ];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fetch( string $file_key, string $node_id, string $token ) {
		$url = self::API_HOST . '/v1/files/' . rawurlencode( $file_key ) . '/nodes?ids=' . rawurlencode( $node_id ) . '&geometry=paths';

		$response = wp_remote_get(
			$url,
			[
				'headers' => [ 'X-Figma-Token' => $token ],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new \WP_Error( 'figma_http_' . wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
		}

		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$nodes   = isset( $body['nodes'] ) && is_array( $body['nodes'] ) ? $body['nodes'] : [];
		$wrapper = $nodes[ $node_id ] ?? null;
		if ( ! is_array( $wrapper ) || empty( $wrapper['document'] ) ) {
			return new \WP_Error( 'figma_no_document', 'Figma node payload missing document.' );
		}

		$doc  = (array) $wrapper['document'];
		$spec = [
			'version' => '1.0.0',
			'source'  => [
				'type'     => 'figma',
				'url'      => 'https://www.figma.com/design/' . $file_key . '?node-id=' . $node_id,
				'node_id'  => $node_id,
				'captured_at' => gmdate( 'c' ),
			],
			'page'     => [ 'title' => (string) ( $doc['name'] ?? __( 'Imported Figma page', 'stonewright' ) ) ],
			'tokens'   => new \stdClass(),
			'sections' => self::extract_sections( $doc ),
		];

		return $spec;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_sections( array $node ): array {
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];

		if ( empty( $children ) ) {
			return [ self::node_to_section( $node ) ];
		}

		$sections = [];
		foreach ( $children as $child ) {
			$type = (string) ( $child['type'] ?? '' );
			if ( in_array( $type, [ 'FRAME', 'SECTION', 'COMPONENT', 'INSTANCE', 'GROUP' ], true ) ) {
				$sections[] = self::node_to_section( (array) $child );
			} else {
				$mapped = self::node_to_block( (array) $child );
				if ( null !== $mapped ) {
					$sections[ count( $sections ) - 1 ]['blocks'][] = $mapped;
				}
			}
		}

		if ( empty( $sections ) ) {
			$sections[] = self::node_to_section( $node );
		}
		return $sections;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private static function node_to_section( array $node ): array {
		$blocks   = [];
		$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];
		foreach ( $children as $child ) {
			$block = self::node_to_block( (array) $child );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}

		$bg_color = self::extract_solid_fill( $node['fills'] ?? [] );
		$padding  = self::extract_padding( $node );

		return [
			'id'         => 's_' . substr( (string) ( $node['id'] ?? wp_generate_uuid4() ), 0, 8 ),
			'name'       => (string) ( $node['name'] ?? '' ),
			'width'      => 'boxed',
			'layout'     => 'auto' === ( $node['layoutMode'] ?? '' ) ? 'stack' : 'stack',
			'padding'    => $padding,
			'background' => $bg_color ? [ 'color' => $bg_color ] : new \stdClass(),
			'blocks'     => $blocks,
		];
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>|null
	 */
	private static function node_to_block( array $node ): ?array {
		$type = (string) ( $node['type'] ?? '' );
		switch ( $type ) {
			case 'TEXT':
				$text  = (string) ( $node['characters'] ?? '' );
				$style = (array) ( $node['style'] ?? [] );
				$size  = (float) ( $style['fontSize'] ?? 16 );
				if ( $size >= 28 ) {
					return [ 'type' => 'heading', 'level' => 1, 'text' => $text ];
				}
				if ( $size >= 22 ) {
					return [ 'type' => 'heading', 'level' => 2, 'text' => $text ];
				}
				if ( $size >= 18 ) {
					return [ 'type' => 'heading', 'level' => 3, 'text' => $text ];
				}
				return [ 'type' => 'paragraph', 'text' => $text ];

			case 'RECTANGLE':
			case 'ELLIPSE':
			case 'POLYGON':
				$fills = isset( $node['fills'] ) && is_array( $node['fills'] ) ? $node['fills'] : [];
				foreach ( $fills as $fill ) {
					if ( isset( $fill['type'] ) && 'IMAGE' === $fill['type'] ) {
						return [ 'type' => 'image', 'url' => '', 'alt' => (string) ( $node['name'] ?? '' ) ];
					}
				}
				return [ 'type' => 'spacer', 'height' => (int) ( $node['absoluteBoundingBox']['height'] ?? 24 ) ];

			case 'COMPONENT':
			case 'INSTANCE':
			case 'GROUP':
			case 'FRAME':
				return [
					'type'   => 'column',
					'blocks' => array_values(
						array_filter(
							array_map(
								[ self::class, 'node_to_block' ],
								(array) ( $node['children'] ?? [] )
							)
						)
					),
				];

			case 'VECTOR':
				return [ 'type' => 'icon', 'text' => (string) ( $node['name'] ?? '' ) ];

			default:
				return null;
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $fills
	 */
	private static function extract_solid_fill( array $fills ): ?string {
		foreach ( $fills as $fill ) {
			if ( ( $fill['type'] ?? '' ) === 'SOLID' && isset( $fill['color'] ) ) {
				$c = $fill['color'];
				$a = isset( $fill['opacity'] ) ? (float) $fill['opacity'] : 1.0;
				$r = (int) round( (float) ( $c['r'] ?? 0 ) * 255 );
				$g = (int) round( (float) ( $c['g'] ?? 0 ) * 255 );
				$b = (int) round( (float) ( $c['b'] ?? 0 ) * 255 );
				if ( $a < 1 ) {
					return sprintf( 'rgba(%d,%d,%d,%.2f)', $r, $g, $b, $a );
				}
				return sprintf( '#%02x%02x%02x', $r, $g, $b );
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, int>
	 */
	private static function extract_padding( array $node ): array {
		return [
			'top'    => (int) ( $node['paddingTop'] ?? 0 ),
			'right'  => (int) ( $node['paddingRight'] ?? 0 ),
			'bottom' => (int) ( $node['paddingBottom'] ?? 0 ),
			'left'   => (int) ( $node['paddingLeft'] ?? 0 ),
		];
	}
}
