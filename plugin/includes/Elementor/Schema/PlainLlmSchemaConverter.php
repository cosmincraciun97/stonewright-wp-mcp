<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

use Stonewright\WpMcp\Elementor\Renderer\ProGate;

/**
 * Converts live Elementor control schemas into plain values for LLM clients.
 *
 * Unwraps `{$$type, value}` envelopes, collapses duplicate union branches,
 * enriches enums from control options, and omits Elementor Pro controls when
 * that upstream plugin is not active.
 */
final class PlainLlmSchemaConverter {

	/**
	 * @param array<string, mixed> $schema Control map or JSON-schema-like tree.
	 * @param array{elementor_pro_active?:bool, mode?:string} $options
	 * @return array<string, mixed>
	 */
	public static function convert( array $schema, array $options = [] ): array {
		$pro_active = array_key_exists( 'elementor_pro_active', $options )
			? (bool) $options['elementor_pro_active']
			: ProGate::active();
		$mode       = (string) ( $options['mode'] ?? 'full' );
		$converted  = self::convert_control_map( $schema, $pro_active );
		if ( 'summary' === $mode ) {
			return self::summarize_control_map( $converted );
		}
		return $converted;
	}

	/**
	 * @param list<array<string, mixed>> $items Widget list rows.
	 * @param array{elementor_pro_active?:bool, mode?:string}|bool|null $options
	 * @return list<array<string, mixed>>
	 */
	public static function convert_widget_list( array $items, array|bool|null $options = null ): array {
		if ( is_bool( $options ) ) {
			$options = [ 'elementor_pro_active' => $options ];
		}
		$options    = is_array( $options ) ? $options : [];
		$pro_active = array_key_exists( 'elementor_pro_active', $options )
			? (bool) $options['elementor_pro_active']
			: ProGate::active();
		$mode       = (string) ( $options['mode'] ?? 'full' );
		$out        = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( ! $pro_active && self::is_pro_marked( $item ) ) {
				continue;
			}
			if ( 'summary' === $mode ) {
				$item = self::summarize_widget_item( $item );
			} else {
				$item = self::convert_node( $item, $pro_active );
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $item
	 * @return array{type:string, title:string, description:string}
	 */
	private static function summarize_widget_item( array $item ): array {
		$description = (string) ( $item['description'] ?? '' );
		if ( '' === $description && isset( $item['categories'] ) && is_array( $item['categories'] ) ) {
			$description = implode( ', ', array_map( 'strval', $item['categories'] ) );
		}

		return [
			'type'        => (string) ( $item['type'] ?? $item['name'] ?? $item['widget_type'] ?? '' ),
			'title'       => (string) ( $item['title'] ?? '' ),
			'description' => $description,
		];
	}

	/**
	 * @param array<string, mixed> $map
	 * @return array<string, mixed>
	 */
	private static function convert_control_map( array $map, bool $pro_active ): array {
		$out = [];
		foreach ( $map as $key => $control ) {
			if ( ! is_array( $control ) ) {
				$out[ $key ] = $control;
				continue;
			}
			if ( ! $pro_active && self::is_pro_marked( $control ) ) {
				continue;
			}
			$out[ $key ] = self::convert_node( $control, $pro_active );
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $map
	 * @return array<string, array{type:string, description:string}>
	 */
	private static function summarize_control_map( array $map ): array {
		$out = [];
		foreach ( $map as $key => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$out[ (string) $key ] = [
				'type'        => (string) ( $control['type'] ?? '' ),
				'description' => (string) ( $control['description'] ?? $control['label'] ?? '' ),
			];
		}
		return $out;
	}

	private static function convert_node( mixed $node, bool $pro_active ): mixed {
		if ( ! is_array( $node ) ) {
			return $node;
		}
		if ( self::is_envelope( $node ) ) {
			return self::convert_node( $node['value'], $pro_active );
		}

		foreach ( [ 'anyOf', 'oneOf' ] as $union ) {
			if ( ! isset( $node[ $union ] ) || ! is_array( $node[ $union ] ) ) {
				continue;
			}
			$branches = [];
			foreach ( $node[ $union ] as $branch ) {
				$branches[] = self::convert_node( $branch, $pro_active );
			}
			$node[ $union ] = self::unique_branches( $branches );
			if ( 1 === count( $node[ $union ] ) && is_array( $node[ $union ][0] ) ) {
				$branch = $node[ $union ][0];
				unset( $node[ $union ] );
				$node = array_merge( $node, $branch );
			}
		}

		if ( isset( $node['fields'] ) && is_array( $node['fields'] ) ) {
			$node['fields'] = self::convert_control_map( $node['fields'], $pro_active );
		}
		if ( isset( $node['options'] ) && is_array( $node['options'] ) && ! array_is_list( $node['options'] ) ) {
			$node['enum'] = array_values( array_map( 'strval', array_keys( $node['options'] ) ) );
		}

		foreach ( $node as $key => $value ) {
			if ( in_array( (string) $key, [ 'anyOf', 'oneOf', 'fields', 'options', 'enum' ], true ) ) {
				continue;
			}
			$node[ $key ] = self::convert_node( $value, $pro_active );
		}
		return $node;
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private static function is_envelope( array $value ): bool {
		return isset( $value['$$type'] )
			&& is_string( $value['$$type'] )
			&& array_key_exists( 'value', $value );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function is_pro_marked( array $item ): bool {
		if ( ! empty( $item['pro_required'] ) ) {
			return true;
		}
		$source = strtolower( (string) ( $item['source'] ?? $item['source_plugin'] ?? $item['license_requirement'] ?? '' ) );
		return str_contains( $source, 'elementor-pro' );
	}

	/**
	 * @param list<mixed> $branches
	 * @return list<mixed>
	 */
	private static function unique_branches( array $branches ): array {
		$seen = [];
		$out  = [];
		foreach ( $branches as $branch ) {
			$encoded = (string) wp_json_encode( self::canonicalize( $branch ) );
			if ( isset( $seen[ $encoded ] ) ) {
				continue;
			}
			$seen[ $encoded ] = true;
			$out[]            = $branch;
		}
		return $out;
	}

	private static function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
