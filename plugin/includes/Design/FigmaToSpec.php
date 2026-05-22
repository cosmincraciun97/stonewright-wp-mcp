<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design;

/**
 * Figma → Stonewright DesignSpec adapter.
 *
 * Walks a Figma REST API node tree (the shape `IngestFigma` consumes) and
 * emits a Stonewright DesignSpec root that the V3/V4 renderers can directly
 * consume.
 *
 * Mapping summary (Figma type → DesignSpec block type):
 *   - FRAME / GROUP / SECTION / COMPONENT / INSTANCE → `section` at the top
 *     level, `column` when nested inside another section.
 *   - TEXT → `heading` when style.fontSize ≥ 18 (level computed from size),
 *     otherwise `paragraph`.
 *   - RECTANGLE / ELLIPSE / POLYGON / IMAGE with image fill → `image`.
 *   - VECTOR → `icon`.
 *   - Button-shaped FRAME (single TEXT child + visible fill, leaf-ish) →
 *     `button`.
 *   - Anything unrecognised → `paragraph` carrying `style.__unsupported`
 *     with the original Figma type so callers can audit fallback usage.
 *
 * Responsive pairing: when a Figma node has a sibling whose name matches its
 * own with a `/mobile` (or ` mobile`) suffix, the emitted block's `style`
 * properties become `{ desktop: ..., mobile: ... }` per-key maps so the V3/V4
 * responsive emitters can pick them up. When no mobile sibling exists, props
 * stay scalar.
 *
 * The class is intentionally pure: it never reads from WordPress globals,
 * never performs I/O, and never validates the spec — callers (the ability or
 * tests) should run `Validator::validate()` on the output before rendering.
 *
 * @stonewright-status stable
 */
final class FigmaToSpec {

	/**
	 * Figma types that produce a DesignSpec section (top level) or column
	 * (nested) container.
	 */
	private const CONTAINER_TYPES = [ 'FRAME', 'GROUP', 'SECTION', 'COMPONENT', 'INSTANCE' ];

	/**
	 * Figma types that should produce an `image` block when a fill of type
	 * IMAGE is present on the node.
	 */
	private const IMAGEABLE_TYPES = [ 'RECTANGLE', 'ELLIPSE', 'POLYGON', 'IMAGE' ];

	/**
	 * Maximum recursion depth — caps pathological Figma trees so we never
	 * stack-overflow on an unexpected cycle. Empirically Figma frames rarely
	 * go beyond 12 levels deep.
	 */
	private const MAX_DEPTH = 32;

	/**
	 * Adapt a single Figma node (typically a FRAME representing a page) into a
	 * complete Stonewright DesignSpec root.
	 *
	 * @param array<string, mixed> $figma_node The Figma node JSON, in the shape
	 *                                         returned by the Figma REST API
	 *                                         under `nodes[].document`.
	 * @return array<string, mixed> A DesignSpec object (version, page, sections,
	 *                              optional tokens) ready to be validated.
	 */
	public static function to_spec( array $figma_node ): array {
		$page_title = self::node_name( $figma_node, __( 'Imported Figma page', 'stonewright' ) );

		// Top-level: any child that looks like a container becomes its own
		// section. If the root has no container children, we wrap the root
		// itself as a single section so the spec is always renderable.
		$sections = self::extract_sections( $figma_node );

		// Edge case: empty node — emit a single empty section so the spec
		// still validates (schema requires sections.minItems = 1).
		if ( [] === $sections ) {
			$sections[] = [
				'id'     => self::section_id( $figma_node, 0 ),
				'name'   => $page_title,
				'width'  => 'boxed',
				'layout' => 'stack',
				'blocks' => [],
			];
		}

		return [
			'version'  => '1.0.0',
			'source'   => [
				'type'    => 'figma',
				'node_id' => (string) ( $figma_node['id'] ?? '' ),
			],
			'page'     => [
				'title' => $page_title,
			],
			'tokens'   => new \stdClass(),
			'sections' => $sections,
		];
	}

	// ─────────────────────────────────────────────────────────────────────
	// Sections
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $node
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_sections( array $node ): array {
		$children = self::children( $node );

		// Root has container children → each child becomes a section.
		$container_children = array_values(
			array_filter(
				$children,
				static fn ( array $c ): bool => in_array( (string) ( $c['type'] ?? '' ), self::CONTAINER_TYPES, true )
			)
		);

		if ( [] !== $container_children ) {
			$sections = [];
			foreach ( $container_children as $i => $child ) {
				$sections[] = self::node_to_section( $child, $i );
			}
			return $sections;
		}

		// No container children — treat the root itself as a single section.
		if ( [] === $children ) {
			return [];
		}
		return [ self::node_to_section( $node, 0 ) ];
	}

	/**
	 * Convert a Figma container node into a top-level DesignSpec section.
	 *
	 * @param array<string, mixed> $node
	 */
	private static function node_to_section( array $node, int $index ): array {
		$children = self::children( $node );
		$blocks   = self::children_to_blocks( $children, 1 );

		$section = [
			'id'     => self::section_id( $node, $index ),
			'name'   => self::node_name( $node, 'section_' . $index ),
			'width'  => 'boxed',
			'layout' => self::layout_for_node( $node ),
			'blocks' => $blocks,
		];

		$padding = self::extract_padding( $node );
		if ( null !== $padding ) {
			$section['padding'] = $padding;
		}

		$gap = self::extract_gap( $node );
		if ( null !== $gap ) {
			$section['gap'] = $gap;
		}

		$background = self::extract_background( $node );
		if ( [] !== $background ) {
			$section['background'] = $background;
		}

		return $section;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Blocks
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Convert a flat list of sibling Figma nodes into DesignSpec blocks,
	 * pairing `/mobile`-suffixed siblings as responsive variants of their
	 * desktop counterparts before mapping each survivor.
	 *
	 * @param array<int, array<string, mixed>> $siblings
	 * @return array<int, array<string, mixed>>
	 */
	private static function children_to_blocks( array $siblings, int $depth ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return [];
		}

		[ $primary, $mobile_map ] = self::partition_responsive_variants( $siblings );

		$blocks = [];
		foreach ( $primary as $node ) {
			$mobile_variant = $mobile_map[ self::responsive_key( $node ) ] ?? null;
			$block          = self::node_to_block( $node, $mobile_variant, $depth );
			if ( null !== $block ) {
				$blocks[] = $block;
			}
		}
		return $blocks;
	}

	/**
	 * Split siblings into (primary, mobile-variants-by-key). A node is a
	 * mobile variant when its name ends with `/mobile` or ` mobile`
	 * (case-insensitive); the lookup key is the trimmed base name.
	 *
	 * @param array<int, array<string, mixed>> $siblings
	 * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<string, mixed>>}
	 */
	private static function partition_responsive_variants( array $siblings ): array {
		$mobile_map = [];
		$primary    = [];

		foreach ( $siblings as $node ) {
			$name = (string) ( $node['name'] ?? '' );
			if ( self::is_mobile_variant_name( $name ) ) {
				$base                = self::strip_mobile_suffix( $name );
				$mobile_map[ $base ] = $node;
				continue;
			}
			$primary[] = $node;
		}

		return [ $primary, $mobile_map ];
	}

	private static function responsive_key( array $node ): string {
		return self::strip_mobile_suffix( (string) ( $node['name'] ?? '' ) );
	}

	private static function is_mobile_variant_name( string $name ): bool {
		$trimmed = trim( $name );
		if ( '' === $trimmed ) {
			return false;
		}
		// Matches "Foo/mobile", "Foo / mobile", "Foo mobile" — anything where
		// the trailing token after a slash or whitespace separator is the
		// literal word `mobile` (case-insensitive).
		return (bool) preg_match( '#(^|[/\s])mobile$#i', $trimmed );
	}

	private static function strip_mobile_suffix( string $name ): string {
		$stripped = preg_replace( '#[/\s]+mobile$#i', '', trim( $name ) ) ?? '';
		// Strip a now-orphaned trailing `/` or whitespace that the suffix used
		// to follow, so the base key matches the desktop sibling name.
		return trim( rtrim( $stripped, " /\t\n\r\0\x0B" ) );
	}

	/**
	 * Map a single Figma node (with optional paired mobile variant) into a
	 * single DesignSpec block, or null if the node should be omitted.
	 *
	 * @param array<string, mixed>      $node
	 * @param array<string, mixed>|null $mobile_variant
	 */
	private static function node_to_block( array $node, ?array $mobile_variant, int $depth ): ?array {
		$type = (string) ( $node['type'] ?? '' );

		// Buttons: detect before falling into the generic container branch.
		if ( in_array( $type, self::CONTAINER_TYPES, true ) && self::looks_like_button( $node ) ) {
			return self::frame_to_button( $node, $mobile_variant );
		}

		switch ( $type ) {
			case 'TEXT':
				return self::text_to_block( $node, $mobile_variant );

			case 'RECTANGLE':
			case 'ELLIPSE':
			case 'POLYGON':
			case 'IMAGE':
				if ( self::has_image_fill( $node ) ) {
					return self::node_to_image_block( $node, $mobile_variant );
				}
				// Decorative rectangle with no image fill → ignore (no
				// `spacer` block emitted here; the renderer is in charge of
				// section-level spacing decisions).
				return null;

			case 'VECTOR':
			case 'BOOLEAN_OPERATION':
			case 'STAR':
			case 'LINE':
			case 'REGULAR_POLYGON':
				return self::node_to_icon_block( $node, $mobile_variant );

			case 'FRAME':
			case 'GROUP':
			case 'COMPONENT':
			case 'INSTANCE':
			case 'SECTION':
				return self::frame_to_column( $node, $depth );

			case '':
				return null;

			default:
				return self::unsupported_block( $node, $mobile_variant );
		}
	}

	private static function text_to_block( array $node, ?array $mobile_variant ): array {
		$text  = (string) ( $node['characters'] ?? '' );
		$style = self::extract_text_style( $node, $mobile_variant );
		$size  = (float) ( ( $node['style']['fontSize'] ?? null ) ?? 16 );

		if ( $size >= 18 ) {
			$level = match ( true ) {
				$size >= 40 => 1,
				$size >= 32 => 2,
				$size >= 24 => 3,
				$size >= 20 => 4,
				default     => 5,
			};
			$block = [
				'type'  => 'heading',
				'level' => $level,
				'text'  => $text,
			];
		} else {
			$block = [
				'type' => 'paragraph',
				'text' => $text,
			];
		}

		if ( [] !== $style ) {
			$block['style'] = $style;
		}
		return $block;
	}

	private static function node_to_image_block( array $node, ?array $mobile_variant ): array {
		$block = [
			'type' => 'image',
			'url'  => self::extract_image_ref( $node ),
			'alt'  => self::node_name( $node, '' ),
		];
		$style = self::extract_box_style( $node, $mobile_variant );
		if ( [] !== $style ) {
			$block['style'] = $style;
		}
		return $block;
	}

	private static function node_to_icon_block( array $node, ?array $mobile_variant ): array {
		$block = [
			'type' => 'icon',
			'text' => self::node_name( $node, '' ),
		];
		$style = self::extract_box_style( $node, $mobile_variant );
		$color = self::extract_solid_fill( $node['fills'] ?? [] );
		if ( null !== $color ) {
			$style = self::merge_style_key( $style, 'color', $color, $mobile_variant ? self::extract_solid_fill( $mobile_variant['fills'] ?? [] ) : null );
		}
		if ( [] !== $style ) {
			$block['style'] = $style;
		}
		return $block;
	}

	/**
	 * Convert a Figma frame into a DesignSpec `column` block, recursing into
	 * its children. `column` is the schema's accepted nested container block.
	 */
	private static function frame_to_column( array $node, int $depth ): array {
		$children = self::children( $node );
		$blocks   = self::children_to_blocks( $children, $depth + 1 );

		$block = [
			'type'   => 'column',
			'blocks' => $blocks,
		];

		$style = [];
		$padding = self::extract_padding( $node );
		if ( null !== $padding ) {
			$style['padding'] = $padding;
		}
		$gap = self::extract_gap( $node );
		if ( null !== $gap ) {
			$style['gap'] = $gap;
		}
		$bg = self::extract_solid_fill( $node['fills'] ?? [] );
		if ( null !== $bg ) {
			$style['background_color'] = $bg;
		}
		$direction = self::layout_direction( $node );
		if ( null !== $direction ) {
			$style['flex_direction'] = $direction;
		}

		if ( [] !== $style ) {
			$block['style'] = $style;
		}
		return $block;
	}

	private static function frame_to_button( array $node, ?array $mobile_variant ): array {
		$label_node = self::find_first_text_child( $node );
		$label      = null !== $label_node ? (string) ( $label_node['characters'] ?? '' ) : self::node_name( $node, '' );

		$block = [
			'type' => 'button',
			'text' => $label,
		];

		$style = self::extract_box_style( $node, $mobile_variant );
		$bg    = self::extract_solid_fill( $node['fills'] ?? [] );
		if ( null !== $bg ) {
			$style = self::merge_style_key(
				$style,
				'background_color',
				$bg,
				$mobile_variant ? self::extract_solid_fill( $mobile_variant['fills'] ?? [] ) : null
			);
		}
		if ( null !== $label_node ) {
			$color = self::extract_solid_fill( $label_node['fills'] ?? [] );
			if ( null !== $color ) {
				$style = self::merge_style_key( $style, 'color', $color, null );
			}
		}
		if ( [] !== $style ) {
			$block['style'] = $style;
		}
		return $block;
	}

	private static function unsupported_block( array $node, ?array $mobile_variant ): array {
		$style = self::extract_box_style( $node, $mobile_variant );
		$style['__unsupported'] = (string) ( $node['type'] ?? 'UNKNOWN' );

		return [
			'type'  => 'paragraph',
			'text'  => self::node_name( $node, '' ),
			'style' => $style,
		];
	}

	// ─────────────────────────────────────────────────────────────────────
	// Button heuristic
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * A Figma frame "looks like" a button when it has exactly one TEXT child
	 * (the label), a visible fill or stroke, and no nested containers. This
	 * matches the way buttons are typically modelled in Figma — a small auto
	 * layout frame around a single label.
	 */
	private static function looks_like_button( array $node ): bool {
		$children = self::children( $node );
		if ( [] === $children ) {
			return false;
		}

		$text_count      = 0;
		$container_count = 0;
		foreach ( $children as $child ) {
			$ctype = (string) ( $child['type'] ?? '' );
			if ( 'TEXT' === $ctype ) {
				$text_count++;
				continue;
			}
			if ( in_array( $ctype, self::CONTAINER_TYPES, true ) ) {
				$container_count++;
			}
		}

		if ( 1 !== $text_count || 0 !== $container_count ) {
			return false;
		}

		// Must have a visible fill OR a stroke to qualify — naked frames
		// with one TEXT child are just text containers, not buttons.
		$has_fill   = null !== self::extract_solid_fill( $node['fills'] ?? [] );
		$has_stroke = ! empty( $node['strokes'] );
		return $has_fill || $has_stroke;
	}

	private static function find_first_text_child( array $node ): ?array {
		foreach ( self::children( $node ) as $child ) {
			if ( 'TEXT' === ( $child['type'] ?? '' ) ) {
				return $child;
			}
		}
		return null;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Style extraction
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Extract TEXT-specific style props (font size, color, line height) and
	 * pair them with mobile variants where present.
	 *
	 * @param array<string, mixed>      $node
	 * @param array<string, mixed>|null $mobile
	 * @return array<string, mixed>
	 */
	private static function extract_text_style( array $node, ?array $mobile ): array {
		$style = [];

		$size_desktop = isset( $node['style']['fontSize'] ) ? self::px( $node['style']['fontSize'] ) : null;
		$size_mobile  = null !== $mobile && isset( $mobile['style']['fontSize'] ) ? self::px( $mobile['style']['fontSize'] ) : null;
		if ( null !== $size_desktop ) {
			$style = self::merge_style_key( $style, 'font_size', $size_desktop, $size_mobile );
		}

		$family = $node['style']['fontFamily'] ?? null;
		if ( is_string( $family ) && '' !== $family ) {
			$style['font_family'] = $family;
		}

		$weight = $node['style']['fontWeight'] ?? null;
		if ( is_numeric( $weight ) ) {
			$style['font_weight'] = (string) (int) $weight;
		}

		$line_height = $node['style']['lineHeightPx'] ?? null;
		if ( is_numeric( $line_height ) ) {
			$style['line_height'] = self::px( (float) $line_height );
		}

		$align = $node['style']['textAlignHorizontal'] ?? null;
		if ( is_string( $align ) && '' !== $align ) {
			$style['text_align'] = strtolower( $align );
		}

		$color = self::extract_solid_fill( $node['fills'] ?? [] );
		if ( null !== $color ) {
			$mobile_color = null !== $mobile ? self::extract_solid_fill( $mobile['fills'] ?? [] ) : null;
			$style        = self::merge_style_key( $style, 'color', $color, $mobile_color );
		}

		return $style;
	}

	/**
	 * Extract container/box style props common to image, icon, button, and
	 * unsupported nodes.
	 *
	 * @param array<string, mixed>      $node
	 * @param array<string, mixed>|null $mobile
	 * @return array<string, mixed>
	 */
	private static function extract_box_style( array $node, ?array $mobile ): array {
		$style = [];

		$padding = self::extract_padding( $node );
		if ( null !== $padding ) {
			$style['padding'] = $padding;
		}

		$radius = self::extract_radius( $node );
		if ( null !== $radius ) {
			$mobile_radius = null !== $mobile ? self::extract_radius( $mobile ) : null;
			$style         = self::merge_style_key( $style, 'border_radius', $radius, $mobile_radius );
		}

		$width  = isset( $node['absoluteBoundingBox']['width'] ) ? self::px( (float) $node['absoluteBoundingBox']['width'] ) : null;
		$height = isset( $node['absoluteBoundingBox']['height'] ) ? self::px( (float) $node['absoluteBoundingBox']['height'] ) : null;
		if ( null !== $width ) {
			$mobile_width = null !== $mobile && isset( $mobile['absoluteBoundingBox']['width'] )
				? self::px( (float) $mobile['absoluteBoundingBox']['width'] )
				: null;
			$style        = self::merge_style_key( $style, 'width', $width, $mobile_width );
		}
		if ( null !== $height ) {
			$mobile_height = null !== $mobile && isset( $mobile['absoluteBoundingBox']['height'] )
				? self::px( (float) $mobile['absoluteBoundingBox']['height'] )
				: null;
			$style         = self::merge_style_key( $style, 'height', $height, $mobile_height );
		}

		return $style;
	}

	/**
	 * Merge one logical style key into the style array. If a mobile value
	 * differs from the desktop value, emit a per-viewport map; otherwise emit
	 * a scalar.
	 *
	 * @param array<string, mixed> $style
	 * @param mixed                $desktop
	 * @param mixed                $mobile
	 * @return array<string, mixed>
	 */
	private static function merge_style_key( array $style, string $key, mixed $desktop, mixed $mobile ): array {
		if ( null === $mobile || $mobile === $desktop ) {
			$style[ $key ] = $desktop;
			return $style;
		}
		$style[ $key ] = [
			'desktop' => $desktop,
			'mobile'  => $mobile,
		];
		return $style;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Background / fills / strokes
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string, mixed>
	 */
	private static function extract_background( array $node ): array {
		$color = self::extract_solid_fill( $node['fills'] ?? [] );
		if ( null === $color ) {
			return [];
		}
		return [ 'color' => $color ];
	}

	/**
	 * @param array<int, mixed>|mixed $fills
	 */
	private static function extract_solid_fill( mixed $fills ): ?string {
		if ( ! is_array( $fills ) ) {
			return null;
		}
		foreach ( $fills as $fill ) {
			if ( ! is_array( $fill ) ) {
				continue;
			}
			$visible = $fill['visible'] ?? true;
			if ( false === $visible ) {
				continue;
			}
			if ( ( $fill['type'] ?? '' ) !== 'SOLID' ) {
				continue;
			}
			if ( ! isset( $fill['color'] ) || ! is_array( $fill['color'] ) ) {
				continue;
			}
			$c = $fill['color'];
			$a = isset( $fill['opacity'] ) ? (float) $fill['opacity'] : (float) ( $c['a'] ?? 1.0 );
			$r = (int) round( (float) ( $c['r'] ?? 0 ) * 255 );
			$g = (int) round( (float) ( $c['g'] ?? 0 ) * 255 );
			$b = (int) round( (float) ( $c['b'] ?? 0 ) * 255 );
			if ( $a < 0.999 ) {
				return sprintf( 'rgba(%d,%d,%d,%.2f)', $r, $g, $b, $a );
			}
			return sprintf( '#%02x%02x%02x', $r, $g, $b );
		}
		return null;
	}

	private static function has_image_fill( array $node ): bool {
		$fills = $node['fills'] ?? [];
		if ( ! is_array( $fills ) ) {
			return false;
		}
		foreach ( $fills as $fill ) {
			if ( is_array( $fill ) && ( $fill['type'] ?? '' ) === 'IMAGE' ) {
				return true;
			}
		}
		return false;
	}

	private static function extract_image_ref( array $node ): string {
		$fills = $node['fills'] ?? [];
		if ( is_array( $fills ) ) {
			foreach ( $fills as $fill ) {
				if ( is_array( $fill ) && ( $fill['type'] ?? '' ) === 'IMAGE' && ! empty( $fill['imageRef'] ) ) {
					return 'figma-image:' . (string) $fill['imageRef'];
				}
			}
		}
		return '';
	}

	// ─────────────────────────────────────────────────────────────────────
	// Geometry
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string, int>|null
	 */
	private static function extract_padding( array $node ): ?array {
		$top    = $node['paddingTop'] ?? null;
		$right  = $node['paddingRight'] ?? null;
		$bottom = $node['paddingBottom'] ?? null;
		$left   = $node['paddingLeft'] ?? null;
		if ( null === $top && null === $right && null === $bottom && null === $left ) {
			return null;
		}
		return [
			'top'    => (int) ( $top ?? 0 ),
			'right'  => (int) ( $right ?? 0 ),
			'bottom' => (int) ( $bottom ?? 0 ),
			'left'   => (int) ( $left ?? 0 ),
		];
	}

	private static function extract_gap( array $node ): ?int {
		$gap = $node['itemSpacing'] ?? null;
		if ( null === $gap ) {
			return null;
		}
		return (int) $gap;
	}

	private static function extract_radius( array $node ): ?string {
		$radius = $node['cornerRadius'] ?? null;
		if ( null === $radius ) {
			return null;
		}
		return self::px( (float) $radius );
	}

	private static function layout_for_node( array $node ): string {
		$direction = self::layout_direction( $node );
		if ( 'row' === $direction ) {
			return 'row';
		}
		return 'stack';
	}

	private static function layout_direction( array $node ): ?string {
		$mode = $node['layoutMode'] ?? null;
		if ( 'HORIZONTAL' === $mode ) {
			return 'row';
		}
		if ( 'VERTICAL' === $mode ) {
			return 'column';
		}
		return null;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Plumbing
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string, mixed> $node
	 * @return array<int, array<string, mixed>>
	 */
	private static function children( array $node ): array {
		$children = $node['children'] ?? [];
		if ( ! is_array( $children ) ) {
			return [];
		}
		$out = [];
		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	private static function node_name( array $node, string $fallback ): string {
		$name = $node['name'] ?? '';
		return is_string( $name ) && '' !== $name ? $name : $fallback;
	}

	private static function section_id( array $node, int $index ): string {
		$raw = (string) ( $node['id'] ?? '' );
		if ( '' === $raw ) {
			return 'section_' . $index;
		}
		// Figma node IDs use `:` (e.g. `1:2`) which is fine in our schema as a
		// `string`, but renderers prefer kebab-safe ids. Normalise to a slug
		// with a stable prefix so collisions across sections are impossible.
		$slug = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '_', $raw ) ?? '' );
		return 's_' . trim( $slug, '_' );
	}

	private static function px( float|int|string $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		// Figma sizes are floats; round to 0.5px and drop trailing zeros.
		$rounded = round( (float) $value * 2 ) / 2;
		if ( (float) (int) $rounded === $rounded ) {
			return ( (int) $rounded ) . 'px';
		}
		return rtrim( rtrim( sprintf( '%.2f', $rounded ), '0' ), '.' ) . 'px';
	}
}
