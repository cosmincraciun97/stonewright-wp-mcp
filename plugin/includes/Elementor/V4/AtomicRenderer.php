<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Support\ElementorData;

/** Schema-driven renderer for Elementor V4 Atomic elements. */
final class AtomicRenderer {

	/**
	 * @param array<string, mixed> $node
	 * @param list<int|string>     $path
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function render_node( array $node, array $path = [] ): array|\WP_Error {
		$type   = (string) ( $node['type'] ?? '' );
		$schema = AtomicSchemaRepository::for_design_type( $type );
		if ( null === $schema ) {
			return self::error( 'stonewright_v4_unknown_node', $path, $type, array_keys( AtomicSchemaRepository::all() ), 'Discover the installed Atomic schema before compiling this node.' );
		}

		$props   = isset( $node['props'] ) && is_array( $node['props'] ) ? $node['props'] : [];
		$allowed = isset( $schema['props'] ) && is_array( $schema['props'] ) ? $schema['props'] : [];
		$unknown = array_values( array_diff( array_keys( $props ), array_keys( $allowed ) ) );
		if ( [] !== $unknown ) {
			return self::error( 'stonewright_v4_unknown_property', array_merge( $path, [ 'props', $unknown[0] ] ), $unknown[0], array_keys( $allowed ), 'Remove the property or refresh the live Atomic schema.' );
		}

		$id             = isset( $node['id'] ) ? (string) $node['id'] : ElementorData::generate_id();
		$settings       = [];
		$generated_style_props = [];
		$image_parts    = [];
		foreach ( $props as $name => $value ) {
			$definition = $allowed[ $name ];
			$type_name  = (string) $definition['type'];
			if ( str_starts_with( $type_name, 'style-' ) ) {
				$typed = self::typed_value( substr( $type_name, 6 ), $value, array_merge( $path, [ 'props', $name ] ) );
				if ( is_wp_error( $typed ) ) {
return $typed; }
				$generated_style_props[ (string) $definition['key'] ] = $typed;
				continue;
			}
			if ( str_starts_with( $type_name, 'image-' ) ) {
				$image_parts[ substr( $type_name, 6 ) ] = $value;
				continue;
			}
			if ( 'raw-json' === $type_name ) {
				$expected_type = (string) ( $definition['json_schema']['properties']['$$type']['const'] ?? '' );
				if ( ! is_array( $value ) || '' === $expected_type || ( $value['$$type'] ?? null ) !== $expected_type || ! array_key_exists( 'value', $value ) ) {
					return self::error( 'stonewright_v4_invalid_runtime_prop', array_merge( $path, [ 'props', $name ] ), $value, [ '$$type=' . $expected_type . ' with value' ], 'Use the exact live runtime prop schema.' );
				}
				$settings[ (string) $definition['key'] ] = $value;
				continue;
			}
			$typed = self::typed_value( $type_name, $value, array_merge( $path, [ 'props', $name ] ) );
			if ( is_wp_error( $typed ) ) {
				return $typed;
			}
			$settings[ (string) $definition['key'] ] = $typed;
		}
		if ( [] !== $image_parts ) {
			$image = self::typed_image( $image_parts, array_merge( $path, [ 'props', 'url' ] ) );
			if ( is_wp_error( $image ) ) {
return $image; }
			$settings['image'] = $image;
		}

		$styles = self::validate_styles( $node['styles'] ?? [], array_merge( $path, [ 'styles' ] ) );
		if ( is_wp_error( $styles ) ) {
			return $styles;
		}
		$class_ids = isset( $node['class_ids'] ) && is_array( $node['class_ids'] ) ? array_values( array_map( 'strval', $node['class_ids'] ) ) : [];
		if ( [] !== $generated_style_props ) {
			$style_id = 'e-' . $id . '-style';
			$styles[ $style_id ] = [
				'id'       => $style_id,
				'label'    => 'Stonewright local style',
				'type'     => 'class',
				'variants' => [ [ 'meta' => [ 'breakpoint' => 'desktop', 'state' => null ], 'props' => $generated_style_props ] ],
			];
			$class_ids[] = $style_id;
		}
		if ( [] !== $class_ids ) {
			foreach ( $class_ids as $class_id ) {
				if ( ! preg_match( '/^[a-z][a-z-_0-9]*$/i', $class_id ) ) {
					return self::error( 'stonewright_v4_invalid_class_id', array_merge( $path, [ 'class_ids' ] ), $class_id, [ 'valid Atomic class id' ], 'Use an id returned by the class repository.' );
				}
			}
			$settings['classes'] = [ '$$type' => 'classes', 'value' => array_values( array_unique( $class_ids ) ) ];
		}
		$interactions = self::validate_list( $node['interactions'] ?? [], array_merge( $path, [ 'interactions' ] ) );
		if ( is_wp_error( $interactions ) ) {
			return $interactions;
		}
		$editor_settings = isset( $node['editor_settings'] ) && is_array( $node['editor_settings'] ) ? $node['editor_settings'] : [];

		$children = [];
		foreach ( (array) ( $node['children'] ?? [] ) as $index => $child ) {
			if ( ! is_array( $child ) ) {
				return self::error( 'stonewright_v4_invalid_child', array_merge( $path, [ 'children', (int) $index ] ), get_debug_type( $child ), [ 'object' ], 'Provide an Atomic node object.' );
			}
			$rendered = self::render_node( $child, array_merge( $path, [ 'children', (int) $index ] ) );
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
			$children[] = $rendered;
		}

		$atomic_type = (string) $schema['atomic_type'];
		$out         = [
			'id'              => $id,
			'version'         => (string) $schema['version'],
			'elType'          => 'layout' === $schema['kind'] ? $atomic_type : 'widget',
			'isInner'         => (bool) ( $node['is_inner'] ?? false ),
			'settings'        => [] === $settings ? [] : $settings,
			'editor_settings' => [] === $editor_settings ? [] : $editor_settings,
			'interactions'    => $interactions,
			'styles'          => $styles,
			'elements'        => $children,
		];
		if ( 'widget' === $schema['kind'] ) {
			$out['widgetType'] = $atomic_type;
		}
		return $out;
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function typed_value( string $type, mixed $value, array $path ): array|\WP_Error {
		if ( 'html-v3' === $type ) {
			return [ '$$type' => 'html-v3', 'value' => [ 'content' => [ '$$type' => 'string', 'value' => (string) $value ], 'children' => [] ] ];
		}
		if ( 'heading-level' === $type ) {
			$level = (int) $value;
			if ( $level < 1 || $level > 6 ) {
				return self::error( 'stonewright_v4_invalid_value', $path, $value, [ 1, 2, 3, 4, 5, 6 ], 'Use a heading level from 1 through 6.' );
			}
			return [ '$$type' => 'string', 'value' => 'h' . $level ];
		}
		if ( 'size' === $type ) {
			if ( is_array( $value ) && isset( $value['unit'], $value['size'] ) ) {
				return [ '$$type' => 'size', 'value' => [ 'unit' => (string) $value['unit'], 'size' => (float) $value['size'] ] ];
			}
			if ( is_string( $value ) && preg_match( '/^(-?(?:\d+\.?\d*|\.\d+))(px|em|rem|%|vh|vw)$/', trim( $value ), $match ) ) {
				return [ '$$type' => 'size', 'value' => [ 'unit' => $match[2], 'size' => (float) $match[1] ] ];
			}
			return self::error( 'stonewright_v4_invalid_value', $path, $value, [ '{unit,size}', '24px' ], 'Use a numeric Atomic size with an explicit unit.' );
		}
		if ( 'svg-src' === $type ) {
			$url = is_array( $value ) ? (string) ( $value['url'] ?? '' ) : (string) $value;
			if ( '' === $url ) {
				return self::error( 'stonewright_v4_invalid_value', $path, $value, [ 'non-empty SVG media URL' ], 'Resolve the SVG asset before compiling.' );
			}
			return [ '$$type' => 'svg-src', 'value' => [ 'id' => null, 'url' => [ '$$type' => 'url', 'value' => $url ] ] ];
		}
		if ( 'link' === $type ) {
			$href = is_array( $value ) ? (string) ( $value['href'] ?? '' ) : (string) $value;
			if ( '' === $href ) {
				return self::error( 'stonewright_v4_unresolved_action', $path, $value, [ 'non-empty href/action' ], 'Resolve the destination before writing.' );
			}
			return [ '$$type' => 'link', 'value' => [ 'destination' => [ '$$type' => 'url', 'value' => $href ], 'isTargetBlank' => [ '$$type' => 'boolean', 'value' => (bool) ( is_array( $value ) ? ( $value['isTargetBlank'] ?? false ) : false ) ], 'tag' => [ '$$type' => 'string', 'value' => 'a' ] ] ];
		}
		if ( ! in_array( $type, [ 'string' ], true ) ) {
			return self::error( 'stonewright_v4_unknown_prop_type', $path, $type, [ 'string', 'html-v3', 'svg-src', 'size', 'link', 'heading-level' ], 'Refresh the runtime schema adapter.' );
		}
		return [ '$$type' => $type, 'value' => (string) $value ];
	}

	/** @return list<array<string, mixed>>|\WP_Error */
	private static function validate_list( mixed $value, array $path ): array|\WP_Error {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return self::error( 'stonewright_v4_invalid_list', $path, get_debug_type( $value ), [ 'array/list' ], 'Provide a JSON list.' );
		}
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				return self::error( 'stonewright_v4_invalid_list', $path, get_debug_type( $item ), [ 'object items' ], 'Provide only object entries.' );
			}
		}
		return $value;
	}

	/** @param array<string, mixed> $parts @return array<string, mixed>|\WP_Error */
	private static function typed_image( array $parts, array $path ): array|\WP_Error {
		$url = (string) ( $parts['url'] ?? '' );
		if ( '' === $url ) {
			return self::error( 'stonewright_v4_invalid_value', $path, $parts, [ 'non-empty image URL' ], 'Resolve the image asset before compiling.' );
		}
		return [
			'$$type' => 'image',
			'value' => [
				'src'  => [ '$$type' => 'image-src', 'value' => [ 'id' => null, 'url' => [ '$$type' => 'url', 'value' => $url ], 'alt' => [ '$$type' => 'string', 'value' => (string) ( $parts['alt'] ?? '' ) ] ] ],
				'size' => [ '$$type' => 'string', 'value' => 'full' ],
			],
		];
	}

	/** @return array<string, array<string, mixed>>|\WP_Error */
	private static function validate_styles( mixed $value, array $path ): array|\WP_Error {
		if ( ! is_array( $value ) ) {
			return self::error( 'stonewright_v4_invalid_styles', $path, get_debug_type( $value ), [ 'object keyed by style id' ], 'Provide an Atomic styles object.' );
		}
		$styles = $value;
		foreach ( $styles as $index => $style ) {
			if ( ! is_array( $style ) || empty( $style['id'] ) || (string) $index !== (string) $style['id'] || 'class' !== ( $style['type'] ?? null ) || ! isset( $style['variants'] ) || ! is_array( $style['variants'] ) ) {
				return self::error( 'stonewright_v4_invalid_style', array_merge( $path, [ $index ] ), $style, [ 'id,label,type=class,variants' ], 'Use the documented Atomic style shape.' );
			}
			$seen = [];
			foreach ( $style['variants'] as $variant_index => $variant ) {
				$breakpoint = $variant['meta']['breakpoint'] ?? null;
				$state      = $variant['meta']['state'] ?? null;
				$key        = (string) $breakpoint . ':' . (string) $state;
				if ( null === $breakpoint || ! isset( $variant['props'] ) || ! is_array( $variant['props'] ) || isset( $seen[ $key ] ) ) {
					return self::error( 'stonewright_v4_invalid_style_variant', array_merge( $path, [ $index, 'variants', $variant_index ] ), $variant, [ 'unique breakpoint/state + props' ], 'Fix the Atomic style variant.' );
				}
				$seen[ $key ] = true;
			}
		}
		return $styles;
	}

	private static function error( string $code, array $path, mixed $received, array $allowed, string $repair ): \WP_Error {
		return new \WP_Error(
			$code,
			$repair,
			[
				'path'        => $path,
				'received'    => is_scalar( $received ) || null === $received ? $received : get_debug_type( $received ),
				'allowed'     => $allowed,
				'schema_hash' => AtomicSchemaRepository::fingerprint(),
				'repair'      => $repair,
			]
		);
	}
}
