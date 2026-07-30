<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Shared Elementor tree compaction: depth-first outline with hard caps.
 *
 * Used by GetPageStructure and ReadAtomicTree so summary reads stay token-cheap
 * without each ability reimplementing the walk.
 */
final class TreeSummary {

	/**
	 * Build a capped outline of an Elementor-style element tree.
	 *
	 * @param array<int, mixed>                                                         $tree
	 * @param int                                                                       $max_elements Maximum outline rows.
	 * @param callable(array<string, mixed> $element, array<string, mixed> $ctx): array $row_mapper
	 * @return array{
	 *   outline: list<array<string, mixed>>,
	 *   count: int,
	 *   returned_count: int,
	 *   truncated: bool,
	 *   estimated_tokens: int
	 * }
	 */
	public static function outline( array $tree, int $max_elements, callable $row_mapper ): array {
		$max_elements = max( 1, $max_elements );
		$outline      = [];
		self::walk( $tree, [], null, $outline, $max_elements, $row_mapper );
		$count = self::count_nodes( $tree );

		$payload = [
			'outline'        => $outline,
			'count'          => $count,
			'returned_count' => count( $outline ),
			'truncated'      => $count > count( $outline ),
		];
		$json = function_exists( 'wp_json_encode' )
			? (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			: (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$payload['estimated_tokens'] = (int) ceil( strlen( $json ) / 4 );

		return $payload;
	}

	/**
	 * Default GetPageStructure / V4 summary row shape.
	 *
	 * @param array<string, mixed> $element
	 * @param array<string, mixed> $ctx
	 * @return array<string, mixed>
	 */
	public static function default_row( array $element, array $ctx ): array {
		$settings = isset( $ctx['settings'] ) && is_array( $ctx['settings'] ) ? $ctx['settings'] : [];

		return [
			'id'            => (string) ( $ctx['id'] ?? '' ),
			'parent_id'     => $ctx['parent_id'] ?? null,
			'path'          => (string) ( $ctx['path'] ?? '' ),
			'depth'         => (int) ( $ctx['depth'] ?? 0 ),
			'elType'        => (string) ( $element['elType'] ?? '' ),
			'widgetType'    => (string) ( $element['widgetType'] ?? '' ),
			'label'         => self::label_from_settings( $settings ),
			'settings_keys' => array_values( array_slice( array_map( 'strval', array_keys( $settings ) ), 0, 30 ) ),
			'child_count'   => (int) ( $ctx['child_count'] ?? 0 ),
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public static function label_from_settings( array $settings ): string {
		foreach ( [ '_title', 'title', 'header_title', 'text', 'editor' ] as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_scalar( $settings[ $key ] ) ) {
				continue;
			}
			$raw_label = (string) $settings[ $key ];
			$label     = function_exists( 'wp_strip_all_tags' )
				? trim( wp_strip_all_tags( $raw_label ) )
				: trim( strip_tags( $raw_label ) );
			if ( '' === $label ) {
				continue;
			}
			return strlen( $label ) > 80 ? substr( $label, 0, 77 ) . '...' : $label;
		}
		return '';
	}

	/**
	 * @param array<int, mixed>          $elements
	 * @param list<int>                  $path
	 * @param list<array<string, mixed>> $out
	 * @param callable                   $row_mapper
	 */
	private static function walk(
		array $elements,
		array $path,
		?string $parent_id,
		array &$out,
		int $max_elements,
		callable $row_mapper
	): void {
		foreach ( $elements as $index => $element ) {
			if ( count( $out ) >= $max_elements ) {
				return;
			}
			if ( ! is_array( $element ) ) {
				continue;
			}

			$current_path = array_merge( $path, [ (int) $index ] );
			$id           = (string) ( $element['id'] ?? '' );
			$settings     = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];
			$children     = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];

			$ctx = [
				'id'          => $id,
				'parent_id'   => $parent_id,
				'path'        => implode( '.', array_map( 'strval', $current_path ) ),
				'depth'       => count( $current_path ) - 1,
				'settings'    => $settings,
				'child_count' => count( $children ),
			];

			$row = $row_mapper( $element, $ctx );
			if ( is_array( $row ) ) {
				$out[] = $row;
			}

			if ( [] !== $children && count( $out ) < $max_elements ) {
				self::walk( $children, $current_path, '' !== $id ? $id : null, $out, $max_elements, $row_mapper );
			}
		}
	}

	/**
	 * @param array<int, mixed> $tree
	 */
	private static function count_nodes( array $tree ): int {
		$count = 0;
		$stack = array_values( $tree );
		while ( [] !== $stack ) {
			$node = array_pop( $stack );
			if ( ! is_array( $node ) ) {
				continue;
			}
			++$count;
			if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				foreach ( $node['elements'] as $child ) {
					$stack[] = $child;
				}
			}
		}
		return $count;
	}
}
