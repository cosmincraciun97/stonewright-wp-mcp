<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

use Stonewright\WpMcp\Support\ElementorData;

/** Schema-driven renderer for Elementor V4 Atomic elements. */
final class AtomicRenderer {

	/** Breakpoints a style value may be given for; desktop is the base. */
	private const BREAKPOINTS = [ 'desktop', 'tablet', 'mobile' ];

	/** Allowed values of the string style props the renderer validates itself. */
	private const STYLE_ENUMS = [
		'flex-direction'  => [ 'row', 'row-reverse', 'column', 'column-reverse' ],
		'justify-content' => [ 'center', 'start', 'end', 'flex-start', 'flex-end', 'left', 'right', 'normal', 'space-between', 'space-around', 'space-evenly', 'stretch' ],
		'align-items'     => [ 'normal', 'stretch', 'center', 'start', 'end', 'flex-start', 'flex-end', 'self-start', 'self-end', 'anchor-center' ],
	];

	private const SIZE_UNITS = [ 'px', 'em', 'rem', '%', 'vh', 'vw', 'ch' ];

	private const COLOR_PATTERN = '/\A(?:#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|[a-zA-Z]{3,24}|(?:rgb|hsl)a?\(\s*[0-9.]+%?(?:\s*[, ]\s*[0-9.]+%?){2}(?:\s*[,\/]\s*[0-9.]+%?)?\s*\)|var\(--[a-zA-Z0-9_-]+\))\z/D';

	/**
	 * @param array<string, mixed> $node
	 * @param list<int|string>     $path
	 * @param array{children_key?:string,props_in_path?:bool} $options Path vocabulary: the Design Spec renderer reports paths in spec terms.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function render_node( array $node, array $path = [], array $options = [] ): array|\WP_Error {
		$children_key  = (string) ( $options['children_key'] ?? 'children' );
		$props_in_path = (bool) ( $options['props_in_path'] ?? true );
		$type          = (string) ( $node['type'] ?? '' );
		$schema        = AtomicSchemaRepository::for_design_type( $type );
		if ( null === $schema ) {
			return self::error(
				'stonewright_v4_unknown_node',
				$path,
				$type,
				array_keys( AtomicSchemaRepository::all() ),
				'Discover the installed Atomic schema before compiling this node.',
				sprintf( 'Node type "%1$s" at %2$s is not in the installed Atomic schema.', $type, self::path_label( $path ) )
			);
		}

		$props   = isset( $node['props'] ) && is_array( $node['props'] ) ? $node['props'] : [];
		$allowed = isset( $schema['props'] ) && is_array( $schema['props'] ) ? $schema['props'] : [];
		$unknown = array_values( array_diff( array_keys( $props ), array_keys( $allowed ) ) );
		if ( [] !== $unknown ) {
			return self::error( 'stonewright_v4_unknown_property', self::prop_path( $path, (string) $unknown[0], $props_in_path ), $unknown[0], array_keys( $allowed ), 'Remove the property or refresh the live Atomic schema.' );
		}

		$id             = isset( $node['id'] ) ? (string) $node['id'] : ElementorData::generate_id();
		$settings       = [];
		$style_props_by_breakpoint = [];
		$image_parts    = [];
		foreach ( $props as $name => $value ) {
			$definition = $allowed[ $name ];
			$type_name  = (string) $definition['type'];
			$prop_path  = self::prop_path( $path, (string) $name, $props_in_path );
			if ( str_starts_with( $type_name, 'style-' ) ) {
				$style_key = (string) $definition['key'];
				$is_map    = self::is_breakpoint_map( $value );
				$by_bp     = $is_map ? $value : [ 'desktop' => $value ];
				foreach ( $by_bp as $breakpoint => $bp_value ) {
					$typed = self::typed_style_value( substr( $type_name, 6 ), $style_key, $bp_value, $is_map ? array_merge( $prop_path, [ (string) $breakpoint ] ) : $prop_path );
					if ( is_wp_error( $typed ) ) {
						return $typed;
					}
					$style_props_by_breakpoint[ (string) $breakpoint ][ $style_key ] = $typed;
				}
				continue;
			}
			if ( str_starts_with( $type_name, 'image-' ) ) {
				$image_parts[ substr( $type_name, 6 ) ] = $value;
				continue;
			}
			if ( 'raw-json' === $type_name ) {
				$expected_type = (string) ( $definition['json_schema']['properties']['$$type']['const'] ?? '' );
				if ( ! is_array( $value ) || '' === $expected_type || ( $value['$$type'] ?? null ) !== $expected_type || ! array_key_exists( 'value', $value ) ) {
					return self::error( 'stonewright_v4_invalid_runtime_prop', $prop_path, $value, [ '$$type=' . $expected_type . ' with value' ], 'Use the exact live runtime prop schema.' );
				}
				$settings[ (string) $definition['key'] ] = $value;
				continue;
			}
			$typed = self::typed_value( $type_name, $value, $prop_path );
			if ( is_wp_error( $typed ) ) {
				return $typed;
			}
			$settings[ (string) $definition['key'] ] = $typed;
		}
		if ( [] !== $image_parts ) {
			$image = self::typed_image( $image_parts, self::prop_path( $path, 'url', $props_in_path ) );
			if ( is_wp_error( $image ) ) {
return $image; }
			$settings['image'] = $image;
		}

		$styles = self::validate_styles( $node['styles'] ?? [], array_merge( $path, [ 'styles' ] ) );
		if ( is_wp_error( $styles ) ) {
			return $styles;
		}
		$class_ids = isset( $node['class_ids'] ) && is_array( $node['class_ids'] ) ? array_values( array_map( 'strval', $node['class_ids'] ) ) : [];
		if ( [] !== $style_props_by_breakpoint ) {
			$style_id = 'e-' . $id . '-style';
			$variants = [];
			foreach ( self::BREAKPOINTS as $breakpoint ) {
				if ( isset( $style_props_by_breakpoint[ $breakpoint ] ) ) {
					$variants[] = [ 'meta' => [ 'breakpoint' => $breakpoint, 'state' => null ], 'props' => $style_props_by_breakpoint[ $breakpoint ] ];
				}
			}
			$styles[ $style_id ] = [
				'id'       => $style_id,
				'label'    => 'Stonewright local style',
				'type'     => 'class',
				'variants' => $variants,
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
				return self::error( 'stonewright_v4_invalid_child', array_merge( $path, [ $children_key, (int) $index ] ), get_debug_type( $child ), [ 'object' ], 'Provide an Atomic node object.' );
			}
			$rendered = self::render_node( $child, array_merge( $path, [ $children_key, (int) $index ] ), $options );
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
			if ( is_array( $value ) && isset( $value['unit'], $value['size'] ) && is_numeric( $value['size'] ) && in_array( (string) $value['unit'], self::SIZE_UNITS, true ) ) {
				return self::size_envelope( (string) $value['unit'], (float) $value['size'] );
			}
			if ( is_string( $value ) && preg_match( '/^(-?(?:\d+\.?\d*|\.\d+))(px|em|rem|%|vh|vw|ch)$/', trim( $value ), $match ) ) {
				return self::size_envelope( $match[2], (float) $match[1] );
			}
			return self::error( 'stonewright_v4_invalid_value', $path, $value, [ '{unit,size}', '24px' ], 'Use a numeric Atomic size with an explicit unit.' );
		}
		if ( 'dimensions' === $type ) {
			if ( ! is_array( $value ) || [] === $value ) {
				return self::error( 'stonewright_v4_invalid_value', $path, $value, [ 'block-start', 'inline-end', 'block-end', 'inline-start' ], 'Provide the sides as Atomic sizes.' );
			}
			$sides = [];
			foreach ( [ 'block-start', 'inline-end', 'block-end', 'inline-start' ] as $side ) {
				if ( ! array_key_exists( $side, $value ) ) {
					continue;
				}
				$size = self::typed_value( 'size', $value[ $side ], array_merge( $path, [ $side ] ) );
				if ( is_wp_error( $size ) ) {
					return $size;
				}
				if ( (float) $size['value']['size'] < 0 ) {
					return self::error( 'stonewright_v4_invalid_value', array_merge( $path, [ $side ] ), $value[ $side ], [ 'a non-negative size' ], 'Use a non-negative size for padding.' );
				}
				$sides[ $side ] = $size;
			}
			$unknown_sides = array_values( array_diff( array_keys( $value ), array_keys( $sides ) ) );
			if ( [] !== $unknown_sides ) {
				return self::error( 'stonewright_v4_unknown_property', array_merge( $path, [ (string) $unknown_sides[0] ] ), $unknown_sides[0], [ 'block-start', 'inline-end', 'block-end', 'inline-start' ], 'Remove the side or use the Atomic side names.' );
			}
			return [ '$$type' => 'dimensions', 'value' => $sides ];
		}
		if ( 'background' === $type ) {
			$unsupported = is_array( $value ) ? array_values( array_diff( array_keys( $value ), [ 'color' ] ) ) : [ 'value' ];
			if ( [] !== $unsupported || ! is_array( $value ) || ! array_key_exists( 'color', $value ) ) {
				return self::error( 'stonewright_v4_unknown_property', array_merge( $path, [ (string) ( $unsupported[0] ?? 'color' ) ] ), $unsupported[0] ?? null, [ 'color' ], 'Only a background color is supported.' );
			}
			$color = self::typed_value( 'color', $value['color'], array_merge( $path, [ 'color' ] ) );
			if ( is_wp_error( $color ) ) {
				return $color;
			}
			return [ '$$type' => 'background', 'value' => [ 'color' => $color ] ];
		}
		if ( 'color' === $type ) {
			if ( ! is_string( $value ) || 1 !== preg_match( self::COLOR_PATTERN, trim( $value ) ) ) {
				return self::error( 'stonewright_v4_invalid_value', $path, $value, [ '#rrggbb', 'rgb()/rgba()/hsl()/hsla()', 'a color name' ], 'Use a plain CSS color value.' );
			}
			return [ '$$type' => 'color', 'value' => trim( $value ) ];
		}
		if ( 'number' === $type ) {
			if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
				return self::error( 'stonewright_v4_invalid_value', $path, $value, [ 'a number' ], 'Use a numeric value.' );
			}
			return [ '$$type' => 'number', 'value' => +$value ];
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

	/**
	 * A zero is written as an integer: the Atomic size validator rejects 0.0.
	 *
	 * @return array<string, mixed>
	 */
	private static function size_envelope( string $unit, float $size ): array {
		return [ '$$type' => 'size', 'value' => [ 'unit' => $unit, 'size' => 0.0 === $size ? 0 : $size ] ];
	}

	/**
	 * Validates and wraps one style prop value; the Design Spec renderer uses it to check values against spec paths.
	 *
	 * @param list<int|string> $path
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function style_value( string $type, string $style_key, mixed $value, array $path ): array|\WP_Error {
		return self::typed_style_value( $type, $style_key, $value, $path );
	}

	/** @return array<string, mixed>|\WP_Error */
	private static function typed_style_value( string $type, string $style_key, mixed $value, array $path ): array|\WP_Error {
		if ( 'string' === $type && isset( self::STYLE_ENUMS[ $style_key ] ) ) {
			if ( ! is_string( $value ) || ! in_array( $value, self::STYLE_ENUMS[ $style_key ], true ) ) {
				return self::error( 'stonewright_v4_invalid_value', $path, $value, self::STYLE_ENUMS[ $style_key ], 'Use one of the Atomic values for this property.' );
			}
		}
		return self::typed_value( $type, $value, $path );
	}

	private static function is_breakpoint_map( mixed $value ): bool {
		return is_array( $value ) && [] !== $value && [] === array_diff( array_keys( $value ), self::BREAKPOINTS );
	}

	/** @param list<int|string> $path @return list<int|string> */
	private static function prop_path( array $path, string $name, bool $props_in_path ): array {
		return $props_in_path ? array_merge( $path, [ 'props', $name ] ) : array_merge( $path, [ $name ] );
	}

	/** @param list<int|string> $path */
	private static function path_label( array $path ): string {
		return [] === $path ? 'the root' : implode( '.', array_map( 'strval', $path ) );
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

	private static function error( string $code, array $path, mixed $received, array $allowed, string $repair, ?string $summary = null ): \WP_Error {
		$shown    = is_scalar( $received ) || null === $received ? var_export( $received, true ) : get_debug_type( $received );
		$choices  = array_slice( array_map( 'strval', array_filter( $allowed, 'is_scalar' ) ), 0, 12 );
		$message  = ( $summary ?? sprintf( 'Invalid value at %s.', self::path_label( $path ) ) ) . ' ' . $repair;
		if ( null === $summary ) {
			$message .= sprintf( ' Received %s.', $shown );
		}
		if ( [] !== $choices ) {
			$message .= ' Allowed: ' . implode( ', ', $choices ) . ( count( $allowed ) > count( $choices ) ? ', ...' : '' ) . '.';
		}
		return new \WP_Error(
			$code,
			$message,
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
