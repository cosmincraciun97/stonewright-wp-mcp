<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Renderers;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Elementor\LayoutNormalizer;
use Stonewright\WpMcp\Elementor\V4\AtomicRenderer;

/**
 * Renders a Stonewright Design Spec into an Elementor V4 atomic element tree.
 *
 * V4 dropped the V3 section/column/widget hierarchy in favour of flexbox
 * containers (`e-flexbox`) and atomic widgets (`e-heading`, `e-image`, …).
 * Every prop is wrapped in V4's typed envelope `{ $$type, value }`.
 *
 * Pipeline:
 *
 *   spec → translate to PascalCase node tree → AtomicRenderer::render_node()
 *
 * Section and container styling (layout and direction, gap, padding, background
 * color, full width, alignment, z-index) is written as a local Atomic style
 * class with typed envelopes. Every other property is refused with a structured
 * error naming the property and its spec path; nothing is dropped silently.
 * Unknown nodes and settings are hard errors. A partial V4 tree is never
 * returned as success.
 */
final class ElementorV4SpecRenderer {

	/**
	 * Lowercase DesignSpec block type → PascalCase node type understood by
	 * {@see AtomicRenderer}. `row` / `column` / `card` are layout primitives
	 * without a one-to-one V4 atomic; all collapse onto `Container` (a flexbox).
	 */
	private const BLOCK_TYPE_TO_NODE_TYPE = [
		'heading'   => 'Heading',
		'paragraph' => 'TextEditor',
		'image'     => 'Image',
		'button'    => 'Button',
		'separator' => 'Divider',
		'icon'      => 'Icon',
		'row'       => 'Container',
		'column'    => 'Container',
		'card'      => 'Container',
	];

	/** Block types that are containers and render their styling as local Atomic styles. */
	private const CONTAINER_BLOCK_TYPES = [ 'row', 'column', 'card' ];

	/** Properties a container (section, row, column, card) renders as local Atomic styles. */
	private const CONTAINER_STYLE_KEYS = [ 'layout', 'direction', 'gap', 'padding', 'background', 'width', 'fullWidth', 'full_width', 'justify_content', 'align_items', 'z_index' ];

	/** Section keys that identify the section and carry no styling. */
	private const SECTION_META_KEYS = [ 'id', 'name', 'role', 'type', 'blocks' ];

	/** Keys of a block that identify it or hold its children and carry no styling. */
	private const BLOCK_META_KEYS = [ 'type', 'id', 'blocks' ];

	/** Styling properties a leaf block may carry in a spec; none has an Atomic mapping yet. */
	private const LEAF_STYLE_KEYS = [
		'padding',
		'background',
		'background_color',
		'color',
		'margin',
		'gap',
		'border_radius',
		'min_height',
		'layout',
		'direction',
		'justify_content',
		'align_items',
		'wrap',
		'css_classes',
		'hide_on',
		'sticky',
		'sticky_on',
		'sticky_offset',
		'z_index',
		'style',
		'style_source',
		'alignment',
		'align',
		'full_width',
		'fullWidth',
		'overlay',
		'overlay_color',
		'toggle_color',
		'icon_color',
		'divider_color',
	];

	private const SIDES = [
		'top'    => 'block-start',
		'right'  => 'inline-end',
		'bottom' => 'block-end',
		'left'   => 'inline-start',
	];

	/**
	 * Convert a Stonewright Design Spec to an Elementor V4 atomic element tree.
	 *
	 * @param array<string, mixed>                  $spec
	 * @param array<int, array<string, mixed>>|null $diagnostics Unsupported-node diagnostics are appended here.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function render( array $spec, ?array &$diagnostics = null ): array|\WP_Error {
		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$diagnostics ??= [];
		$spec         = $validated;
		$sections     = isset( $spec['sections'] ) && is_array( $spec['sections'] ) ? $spec['sections'] : [];
		$out          = [];
		foreach ( $sections as $section_index => $section ) {
			$path     = [ 'sections', (int) $section_index ];
			$node     = self::section_to_node( (array) $section, $path );
			$rendered = is_wp_error( $node )
				? $node
				: AtomicRenderer::render_node( $node, $path, [ 'children_key' => 'blocks', 'props_in_path' => false ] );
			if ( is_wp_error( $rendered ) ) {
				$rendered      = self::describe_unknown_node( $rendered );
				$diagnostics[] = [
					'code'    => $rendered->get_error_code(),
					'message' => $rendered->get_error_message(),
					'data'    => $rendered->get_error_data(),
				];
				return $rendered;
			}
			$out[] = $rendered;
		}
		return $out;
	}

	/**
	 * Map a Design Spec section (with its child blocks) onto the canonical
	 * node-tree shape that {@see AtomicRenderer} consumes.
	 *
	 * @param array<string, mixed> $section
	 * @param list<int|string>     $path
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function section_to_node( array $section, array $path ): array|\WP_Error {
		$props = self::container_props( $section, $path, self::SECTION_META_KEYS, 'column' );
		if ( is_wp_error( $props ) ) {
			return $props;
		}
		$node = [
			'type'  => 'Section',
			'props' => $props,
		];

		$blocks = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : [];

		$children = [];
		foreach ( $blocks as $block_index => $block ) {
			$child = self::block_to_node( (array) $block, array_merge( $path, [ 'blocks', (int) $block_index ] ) );
			if ( is_wp_error( $child ) ) {
				return $child;
			}
			$children[] = $child;
		}
		$node['children'] = $children;

		if ( isset( $section['label'] ) ) {
			$node['editor_settings'] = [ 'label' => (string) $section['label'] ];
		}

		return $node;
	}

	/**
	 * Convert a spec block (with lowercase `type` + flat scalar props) into
	 * a renderer node (`type` PascalCase, `props` bag, recursive `children`).
	 *
	 * @param array<string, mixed> $block
	 * @param list<int|string>     $path
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function block_to_node( array $block, array $path ): array|\WP_Error {
		$type      = (string) ( $block['type'] ?? '' );
		$node_type = self::BLOCK_TYPE_TO_NODE_TYPE[ $type ] ?? $type;

		$props = [];
		switch ( $type ) {
			case 'heading':
				if ( isset( $block['text'] ) ) {
					$props['text'] = (string) $block['text'];
				}
				if ( isset( $block['level'] ) ) {
					$props['level'] = (int) $block['level'];
				}
				break;
			case 'paragraph':
				if ( isset( $block['text'] ) ) {
					$props['text'] = (string) $block['text'];
				}
				break;
			case 'image':
				if ( isset( $block['url'] ) ) {
					$props['url'] = (string) $block['url'];
				}
				if ( isset( $block['alt'] ) ) {
					$props['alt'] = (string) $block['alt'];
				}
				break;
			case 'button':
				if ( isset( $block['text'] ) ) {
					$props['text'] = (string) $block['text'];
				}
				if ( isset( $block['url'] ) ) {
					$props['link'] = (string) $block['url'];
				}
				break;
			case 'icon':
				if ( isset( $block['url'] ) ) {
					$props['url'] = (string) $block['url'];
				}
				break;
			case 'separator':
				// no atomic-level props
				break;
			case 'row':
			case 'column':
			case 'card':
				$container = self::container_props( $block, $path, self::BLOCK_META_KEYS, 'row' === $type ? 'row' : 'column' );
				if ( is_wp_error( $container ) ) {
					return $container;
				}
				$props = $container;
				break;
		}

		if ( isset( self::BLOCK_TYPE_TO_NODE_TYPE[ $type ] ) && ! in_array( $type, self::CONTAINER_BLOCK_TYPES, true ) ) {
			foreach ( self::LEAF_STYLE_KEYS as $style_key ) {
				if ( array_key_exists( $style_key, $block ) ) {
					return self::unsupported(
						array_merge( $path, [ $style_key ] ),
						$style_key,
						$block[ $style_key ],
						'Atomic styling is rendered for sections, rows, columns and cards only; remove the property or style the parent container.'
					);
				}
			}
		}

		$children = [];
		foreach ( (array) ( $block['blocks'] ?? [] ) as $child_index => $child ) {
			$rendered = self::block_to_node( (array) $child, array_merge( $path, [ 'blocks', (int) $child_index ] ) );
			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}
			$children[] = $rendered;
		}

		return [
			'type'     => $node_type,
			'props'    => $props,
			'children' => $children,
		];
	}

	/**
	 * Translate the styling of a section or container block into node props, or refuse
	 * the first property that has no certified Atomic mapping.
	 *
	 * @param array<string, mixed> $source
	 * @param list<int|string>     $path
	 * @param list<string>         $meta_keys Keys that identify the element and carry no styling.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function container_props( array $source, array $path, array $meta_keys, string $default_direction ): array|\WP_Error {
		foreach ( array_keys( $source ) as $key ) {
			$key = (string) $key;
			if ( in_array( $key, $meta_keys, true ) || in_array( $key, self::CONTAINER_STYLE_KEYS, true ) || 'label' === $key ) {
				continue;
			}
			return self::unsupported(
				array_merge( $path, [ $key ] ),
				$key,
				$source[ $key ],
				'Elementor V4 containers do not render this property. Remove it, or build the section with the V3 renderer.'
			);
		}

		$props = [];

		$direction = self::direction( $source, $path, $default_direction );
		if ( is_wp_error( $direction ) ) {
			return $direction;
		}
		$props['direction'] = $direction;

		if ( isset( $source['gap'] ) && '' !== $source['gap'] ) {
			$gap = self::size_value( $source['gap'], array_merge( $path, [ 'gap' ] ), 'gap' );
			if ( is_wp_error( $gap ) ) {
				return $gap;
			}
			$props['gap'] = $gap;
		}

		if ( isset( $source['padding'] ) ) {
			$padding = self::padding( $source['padding'], array_merge( $path, [ 'padding' ] ) );
			if ( is_wp_error( $padding ) ) {
				return $padding;
			}
			if ( [] !== $padding ) {
				$props['padding'] = $padding;
			}
		}

		if ( isset( $source['background'] ) ) {
			$background = self::background( $source['background'], array_merge( $path, [ 'background' ] ) );
			if ( is_wp_error( $background ) ) {
				return $background;
			}
			if ( [] !== $background ) {
				$props['background'] = $background;
			}
		}

		$width = self::width( $source, $path );
		if ( is_wp_error( $width ) ) {
			return $width;
		}
		if ( null !== $width ) {
			$props['width'] = $width;
		}

		foreach ( [ 'justify_content' => 'justify-content', 'align_items' => 'align-items' ] as $key => $style_key ) {
			if ( ! isset( $source[ $key ] ) ) {
				continue;
			}
			$checked = AtomicRenderer::style_value( 'string', $style_key, $source[ $key ], array_merge( $path, [ $key ] ) );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$props[ $key ] = (string) $source[ $key ];
		}

		if ( isset( $source['z_index'] ) ) {
			$checked = AtomicRenderer::style_value( 'number', 'z-index', $source['z_index'], array_merge( $path, [ 'z_index' ] ) );
			if ( is_wp_error( $checked ) ) {
				return $checked;
			}
			$props['z_index'] = $source['z_index'];
		}

		return $props;
	}

	/**
	 * Flex direction from `layout` and `direction`: a direction wins over a layout
	 * for the same viewport, and a container with neither uses its default direction.
	 *
	 * @param array<string, mixed> $source
	 * @param list<int|string>     $path
	 * @return string|array<string, string>|\WP_Error
	 */
	private static function direction( array $source, array $path, string $default_direction ): string|array|\WP_Error {
		$resolved = [];
		if ( array_key_exists( 'layout', $source ) ) {
			$layout = is_array( $source['layout'] ) ? $source['layout'] : [ 'desktop' => $source['layout'] ];
			foreach ( $layout as $viewport => $value ) {
				$subpath = is_array( $source['layout'] ) ? array_merge( $path, [ 'layout', (string) $viewport ] ) : array_merge( $path, [ 'layout' ] );
				if ( 'grid' === $value ) {
					return self::unsupported( $subpath, 'layout', $value, 'Elementor V4 sections render as flex containers; use stack or row.' );
				}
				$intent = LayoutNormalizer::resolve_layout_intent( (string) $value );
				if ( null !== $intent['flex_direction'] ) {
					$resolved[ (string) $viewport ] = $intent['flex_direction'];
				}
			}
		}
		if ( array_key_exists( 'direction', $source ) ) {
			$direction = is_array( $source['direction'] ) ? $source['direction'] : [ 'desktop' => $source['direction'] ];
			foreach ( $direction as $viewport => $value ) {
				$subpath = is_array( $source['direction'] ) ? array_merge( $path, [ 'direction', (string) $viewport ] ) : array_merge( $path, [ 'direction' ] );
				$flex    = LayoutNormalizer::resolve_direction( (string) $value );
				if ( null === $flex ) {
					return new \WP_Error(
						'stonewright_v4_invalid_value',
						sprintf( 'The direction "%1$s" at %2$s is not a flex direction. Allowed: row, column, row-reverse, column-reverse.', (string) $value, implode( '.', array_map( 'strval', $subpath ) ) ),
						[ 'path' => $subpath, 'received' => (string) $value, 'allowed' => [ 'row', 'column', 'row-reverse', 'column-reverse' ] ]
					);
				}
				$resolved[ (string) $viewport ] = $flex;
			}
		}
		if ( [] === $resolved ) {
			return $default_direction;
		}
		return 1 === count( $resolved ) && isset( $resolved['desktop'] ) ? $resolved['desktop'] : $resolved;
	}

	/**
	 * @param array<string, mixed> $source
	 * @param list<int|string>     $path
	 * @return array{unit:string,size:int}|\WP_Error|null
	 */
	private static function width( array $source, array $path ): array|\WP_Error|null {
		$full = ! empty( $source['fullWidth'] ) || ( ! empty( $source['full_width'] ) && 'false' !== $source['full_width'] );
		if ( isset( $source['width'] ) ) {
			if ( 'full' === $source['width'] ) {
				$full = true;
			} else {
				return self::unsupported(
					array_merge( $path, [ 'width' ] ),
					'width',
					$source['width'],
					'Atomic containers have no boxed or narrow content width. Use width "full", or omit width.'
				);
			}
		}
		return $full ? [ 'unit' => '%', 'size' => 100 ] : null;
	}

	/**
	 * @param list<int|string> $path
	 * @return array<string, array{unit:string,size:int|float}>|\WP_Error
	 */
	private static function padding( mixed $padding, array $path ): array|\WP_Error {
		if ( ! is_array( $padding ) ) {
			return self::unsupported( $path, 'padding', $padding, 'Provide padding as per-side top, right, bottom and left values.' );
		}
		$sides = [];
		foreach ( $padding as $side => $value ) {
			$side_path = array_merge( $path, [ (string) $side ] );
			if ( ! isset( self::SIDES[ (string) $side ] ) ) {
				return self::unsupported( $side_path, (string) $side, $value, 'Use top, right, bottom or left.' );
			}
			$size = self::size_value( $value, $side_path, 'padding' );
			if ( is_wp_error( $size ) ) {
				return $size;
			}
			if ( (float) $size['size'] < 0 ) {
				return self::unsupported( $side_path, (string) $side, $value, 'Padding cannot be negative.' );
			}
			$sides[ self::SIDES[ (string) $side ] ] = $size;
		}
		return $sides;
	}

	/**
	 * @param list<int|string> $path
	 * @return array{color?:string}|\WP_Error
	 */
	private static function background( mixed $background, array $path ): array|\WP_Error {
		if ( ! is_array( $background ) ) {
			return self::unsupported( $path, 'background', $background, 'Provide the background as an object with a color.' );
		}
		foreach ( array_keys( $background ) as $key ) {
			if ( 'color' !== $key ) {
				return self::unsupported(
					array_merge( $path, [ (string) $key ] ),
					(string) $key,
					$background[ $key ],
					'Atomic sections render a background color only; background images, overlays, position, size and repeat are not rendered.'
				);
			}
		}
		if ( ! isset( $background['color'] ) ) {
			return [];
		}
		$checked = AtomicRenderer::style_value( 'color', 'background', $background['color'], array_merge( $path, [ 'color' ] ) );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		return [ 'color' => (string) $background['color'] ];
	}

	/**
	 * Parse a CSS size or a bare number (pixels) into an Atomic size value.
	 *
	 * @param list<int|string> $path
	 * @return array{unit:string,size:int|float}|\WP_Error
	 */
	private static function size_value( mixed $value, array $path, string $style_key ): array|\WP_Error {
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
			$value = trim( (string) $value ) . 'px';
		}
		$envelope = AtomicRenderer::style_value( 'size', $style_key, $value, $path );
		if ( is_wp_error( $envelope ) ) {
			return $envelope;
		}
		/** @var array{unit:string,size:int|float} $size */
		$size = $envelope['value'];
		return $size;
	}

	/**
	 * Say which block type and spec path an unknown node had, and which block types render.
	 */
	private static function describe_unknown_node( \WP_Error $error ): \WP_Error {
		if ( 'stonewright_v4_unknown_node' !== $error->get_error_code() ) {
			return $error;
		}
		$data                          = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		$path                          = isset( $data['path'] ) && is_array( $data['path'] ) ? $data['path'] : [];
		$supported                     = array_keys( self::BLOCK_TYPE_TO_NODE_TYPE );
		$data['supported_block_types'] = $supported;
		return new \WP_Error(
			'stonewright_v4_unknown_node',
			sprintf(
				'The "%1$s" block at %2$s cannot be rendered as Elementor V4 Atomic elements, so nothing was written. Supported block types: %3$s.',
				(string) ( $data['received'] ?? '' ),
				implode( '.', array_map( 'strval', $path ) ),
				implode( ', ', $supported )
			),
			$data
		);
	}

	/**
	 * @param list<int|string> $path
	 */
	private static function unsupported( array $path, string $property, mixed $received, string $repair ): \WP_Error {
		return new \WP_Error(
			'stonewright_v4_unsupported_property',
			sprintf(
				'The "%1$s" property at %2$s cannot be rendered as Elementor V4 Atomic styling, so nothing was written. %3$s',
				$property,
				implode( '.', array_map( 'strval', $path ) ),
				$repair
			),
			[
				'path'     => $path,
				'property' => $property,
				'received' => is_scalar( $received ) || null === $received ? $received : get_debug_type( $received ),
				'repair'   => $repair,
			]
		);
	}
}
