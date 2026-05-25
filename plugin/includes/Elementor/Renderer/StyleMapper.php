<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

/**
 * Maps a DesignSpec block.style dict onto Elementor V3 widget settings.
 *
 * The schema's `block.style` field is open-ended (additionalProperties: true),
 * so individual widget renderers used to ignore it entirely — meaning live
 * builds rendered every heading in the theme's default link colour and every
 * button in Elementor's default green regardless of the design spec. This
 * helper turns those style hints into the concrete Elementor setting keys
 * (`title_color`, `button_text_color`, `background_color`, dimensions arrays
 * with unit/top/right/bottom/left/isLinked, viewport-keyed `_tablet`/`_mobile`
 * siblings via Responsive::apply, etc.).
 *
 * The map argument decides which `style.*` keys translate to which Elementor
 * settings, so each widget renderer keeps full control over the mapping while
 * sharing the value-normalisation logic for dimensions, sizes, borders, and
 * backgrounds.
 *
 * Map entry shapes:
 *
 *   - `'style_key' => 'elementor_setting_key'` — scalar passthrough.
 *   - `'style_key' => [
 *         'key'          => 'elementor_setting_key',
 *         'is_size'      => true|false,    // wraps value in {unit, size}
 *         'is_dimension' => true|false,    // wraps value in {unit, top, right, ...}
 *         'is_color'     => true|false,    // type-hint, no normalisation
 *         'is_background'=> true|false,    // also sets <prefix>_background = 'classic'
 *         'is_border'    => true|false,    // parses CSS border shorthand
 *     ]`.
 *
 * Responsive support: every value passes through Responsive::apply, so an
 * input like `{ desktop: '32px', mobile: '24px' }` lands as `<key>` plus
 * `<key>_mobile` automatically.
 */
final class StyleMapper {

	/**
	 * Normalise the two DesignSpec style dialects Stonewright currently sees:
	 * plugin-native `style` keys and external `styles` + `typography`
	 * keys. The renderer consumes the plugin-native snake_case shape.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	public static function node_style( array $node, Resolver $resolver ): array {
		$style = [];

		if ( isset( $node['style'] ) && is_array( $node['style'] ) ) {
			$style = array_merge( $style, (array) $node['style'] );
		}

		if ( isset( $node['styles'] ) && is_array( $node['styles'] ) ) {
			foreach ( (array) $node['styles'] as $key => $value ) {
				$normalised_key = self::normalise_style_key( (string) $key );
				$style[ $normalised_key ] = $value;
			}
		}

		if ( isset( $node['typography'] ) && is_array( $node['typography'] ) ) {
			$typography     = (array) $node['typography'];
			$typography_map = [
				'fontFamily'    => 'font_family',
				'font_family'   => 'font_family',
				'fontSize'      => 'font_size',
				'font_size'     => 'font_size',
				'fontWeight'    => 'font_weight',
				'font_weight'   => 'font_weight',
				'lineHeightPx'  => 'line_height',
				'line_height'   => 'line_height',
				'letterSpacing' => 'letter_spacing',
				'letter_spacing' => 'letter_spacing',
			];

			foreach ( $typography_map as $source => $target ) {
				if ( array_key_exists( $source, $typography ) ) {
					$style[ $target ] = $typography[ $source ];
				}
			}
		}

		foreach ( $style as $key => $value ) {
			if ( is_string( $value ) ) {
				$style[ $key ] = $resolver->resolve( $value );
			}
			if ( 'font_weight' === $key && is_numeric( $style[ $key ] ) ) {
				$style[ $key ] = (string) (int) $style[ $key ];
			}
		}

		return $style;
	}

	/**
	 * Apply DesignSpec style fields to an Elementor settings array.
	 *
	 * @param array<string, mixed> $settings  Existing settings (not mutated).
	 * @param array<string, mixed> $style     The DesignSpec block.style dict.
	 * @param array<string, string|array<string, mixed>> $map
	 *   style-key → elementor-setting-key, or descriptor array.
	 * @return array<string, mixed> New settings.
	 */
	public static function apply( array $settings, array $style, array $map ): array {
		foreach ( $map as $style_key => $descriptor ) {
			if ( ! array_key_exists( $style_key, $style ) ) {
				continue;
			}
			$value = $style[ $style_key ];
			if ( null === $value || '' === $value ) {
				continue;
			}

			$desc = is_array( $descriptor )
				? $descriptor
				: [ 'key' => (string) $descriptor ];

			$key = (string) ( $desc['key'] ?? $style_key );

			if ( ! empty( $desc['is_dimension'] ) ) {
				$settings = self::apply_normalised( $settings, $key, $value, [ self::class, 'dimensions' ] );
				continue;
			}

			if ( ! empty( $desc['is_size'] ) ) {
				$settings = self::apply_normalised( $settings, $key, $value, [ self::class, 'size' ] );
				continue;
			}

			if ( ! empty( $desc['is_background'] ) ) {
				$color = self::color( $value );
				if ( '' !== $color && self::looks_like_color( $color ) ) {
					$prefix                       = self::background_prefix( $key );
					$settings[ $prefix . '_background' ] = 'classic';
					$settings[ $key ]             = $color;
				} elseif ( '' !== $color ) {
					// Not a solid colour — gradient/image/url shorthand; out of scope, pass through.
					$settings[ $key ] = $color;
				}
				continue;
			}

			if ( ! empty( $desc['is_border'] ) ) {
				$prefix    = (string) ( $desc['prefix'] ?? 'border' );
				$parsed    = self::border( $value );
				$settings  = self::merge_border( $settings, $prefix, $parsed );
				continue;
			}

			if ( ! empty( $desc['is_color'] ) ) {
				$settings[ $key ] = self::color( $value );
				continue;
			}

			// Plain scalar / responsive scalar passthrough.
			$settings = Responsive::apply( $settings, $key, $value );
		}

		return self::activate_groups( $settings );
	}

	/**
	 * Ensure every group-control sub-key has its activator companion set.
	 *
	 * Elementor stores each group control (typography / border / background /
	 * box-shadow) behind an activator key. When the activator is missing or
	 * empty, Elementor's renderer treats the sub-keys as inherited from theme
	 * defaults and discards the bespoke values. The discard is silent: the
	 * spec round-trips fine in the editor, but the page paints in the theme
	 * default colour / typography / border. This pass is the structural fix
	 * for the live-build symptom "colours don't apply, typography is plain,
	 * border doesn't render".
	 *
	 * The activator key follows the pattern `<name>_<group>` where `<name>`
	 * is whatever the widget called the group instance (often the same word
	 * as the group, sometimes prefixed — `typography_typography`,
	 * `border_border`, `_background_background`, `digits_typography_typography`,
	 * `image_border_border`, etc.). Sub-keys are `<name>_<sub_field>` where
	 * `<sub_field>` belongs to a hardcoded allowlist per group.
	 *
	 * Restricting to an allowlist instead of regex-matching every `<x>_<y>` is
	 * deliberate — it keeps `title_color`, `button_text_color`, `border_radius`
	 * and other standalone settings out of the activation pass.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private static function activate_groups( array $settings ): array {
		$to_add = [];

		foreach ( array_keys( $settings ) as $key ) {
			// Treat the desktop / tablet / mobile sibling of any responsive
			// sub-key the same as its desktop base — they share the activator.
			$canonical = (string) preg_replace( '/_(tablet|mobile|laptop|widescreen|min|max)$/', '', (string) $key );

			$activator = self::detect_group_activator( $canonical );
			if ( null === $activator ) {
				continue;
			}

			[ $activator_key, $default ] = $activator;

			// Defensive: never try to activate from the activator key itself.
			if ( $activator_key === $key || $activator_key === $canonical ) {
				continue;
			}

			// Honour any value the caller already supplied (the descriptor
			// path for `is_background` and the shorthand `border()` parser
			// both set the activator explicitly).
			if ( isset( $settings[ $activator_key ] ) || isset( $to_add[ $activator_key ] ) ) {
				continue;
			}

			$to_add[ $activator_key ] = $default;
		}

		return $settings + $to_add;
	}

	/**
	 * Group-activator rules: `<group_base> => [<activator_default>, [<sub_field>, ...]]`.
	 *
	 * `<group_base>` is the suffix that ends the activator key — the activator
	 * is always `<prefix>_<group_base>` for some `<prefix>` that itself ends
	 * with `<group_base>`. `typography_typography` (prefix `typography` ends
	 * with `typography`) → activator. `digits_typography_typography` (prefix
	 * `digits_typography` ends with `typography`) → activator. `border_radius`
	 * has prefix `border` ending with `border` but `radius` is not a border
	 * sub-field, so no activation fires.
	 *
	 * @return array<string, array{0:string,1:array<int,string>}>
	 */
	private static function group_rules(): array {
		return [
			'typography' => [
				'custom',
				[
					'font_family',
					'font_size',
					'font_weight',
					'font_style',
					'text_transform',
					'text_decoration',
					'line_height',
					'letter_spacing',
					'word_spacing',
				],
			],
			'border' => [
				'solid',
				[
					'width',
					'color',
				],
			],
			'background' => [
				'classic',
				[
					'color',
					'image',
					'position',
					'size',
					'repeat',
					'attachment',
					'xpos',
					'ypos',
					'bg_width',
				],
			],
		];
	}

	/**
	 * Match a setting key against the group-activator rules.
	 *
	 * Returns `[<activator_key>, <activator_default>]` when the key looks
	 * like `<prefix>_<sub_field>` where `<prefix>` ends with a known
	 * `<group_base>` AND `<sub_field>` is in that group's allowlist.
	 *
	 * Returns null for standalone settings (`title_color`, `border_radius`,
	 * `button_text_color`, …) that do not need an activator.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function detect_group_activator( string $key ): ?array {
		foreach ( self::group_rules() as $group_base => $rule ) {
			[ $default, $sub_fields ] = $rule;
			foreach ( $sub_fields as $sub_field ) {
				$suffix = '_' . $sub_field;
				if ( ! str_ends_with( $key, $suffix ) ) {
					continue;
				}
				$prefix = substr( $key, 0, -strlen( $suffix ) );
				if ( '' === $prefix ) {
					continue;
				}
				if ( ! str_ends_with( $prefix, $group_base ) ) {
					continue;
				}
				return [ $prefix . '_' . $group_base, $default ];
			}
		}
		return null;
	}

	/**
	 * Normalise a CSS dimension expression into Elementor's
	 * {unit, top, right, bottom, left, isLinked} array.
	 *
	 * Accepts:
	 *   - Single number/string: `12`, `'12px'` → all sides 12.
	 *   - Two-value shorthand:  `'12px 8px'`   → top/bottom 12, left/right 8.
	 *   - Four-value shorthand: `'1px 2px 3px 4px'`.
	 *   - Keyed array: `[ 'top' => 12, 'right' => 8, 'bottom' => 12, 'left' => 8 ]`.
	 *   - null → null (caller should skip).
	 *
	 * @param string|int|float|array<string, mixed>|null $value
	 * @return array<string, mixed>|null
	 */
	public static function dimensions( string|int|float|array|null $value ): ?array {
		if ( null === $value ) {
			return null;
		}

		// Keyed dimensions object.
		if ( is_array( $value ) ) {
			$top    = self::px_int( $value['top']    ?? 0 );
			$right  = self::px_int( $value['right']  ?? 0 );
			$bottom = self::px_int( $value['bottom'] ?? 0 );
			$left   = self::px_int( $value['left']   ?? 0 );
			$linked = ( $top === $right ) && ( $right === $bottom ) && ( $bottom === $left );
			return [
				'unit'     => 'px',
				'top'      => (string) $top,
				'right'    => (string) $right,
				'bottom'   => (string) $bottom,
				'left'     => (string) $left,
				'isLinked' => $linked,
			];
		}

		// Scalar form — split into 1, 2, 3, or 4 segments.
		$str    = trim( (string) $value );
		$parts  = preg_split( '/\s+/', $str ) ?: [];
		$nums   = array_map( [ self::class, 'px_int' ], $parts );
		$count  = count( $nums );

		switch ( $count ) {
			case 1:
				$top    = $nums[0];
				$right  = $nums[0];
				$bottom = $nums[0];
				$left   = $nums[0];
				break;
			case 2:
				$top    = $nums[0];
				$bottom = $nums[0];
				$right  = $nums[1];
				$left   = $nums[1];
				break;
			case 3:
				$top    = $nums[0];
				$right  = $nums[1];
				$left   = $nums[1];
				$bottom = $nums[2];
				break;
			case 4:
			default:
				$top    = $nums[0] ?? 0;
				$right  = $nums[1] ?? 0;
				$bottom = $nums[2] ?? 0;
				$left   = $nums[3] ?? 0;
				break;
		}

		$linked = ( $top === $right ) && ( $right === $bottom ) && ( $bottom === $left );
		return [
			'unit'     => 'px',
			'top'      => (string) $top,
			'right'    => (string) $right,
			'bottom'   => (string) $bottom,
			'left'     => (string) $left,
			'isLinked' => $linked,
		];
	}

	/**
	 * Normalise a CSS size into Elementor's `{unit, size, sizes: []}` array
	 * (or a viewport-keyed dict mapping each viewport to that array).
	 *
	 * Accepts:
	 *   - int                       → { unit: px, size: <int> }.
	 *   - 'NNpx' / 'NNem' / 'NN%'   → { unit: <unit>, size: <int> }.
	 *   - bare 'NN'                 → { unit: px, size: <int> }.
	 *   - { desktop: '32px', mobile: '24px' } → viewport-keyed dict of the above.
	 *
	 * @param string|int|float|array<string, mixed>|null $value
	 * @return array<string, mixed>|null
	 */
	public static function size( string|int|float|array|null $value ): mixed {
		if ( null === $value || '' === $value ) {
			return null;
		}

		// Viewport-keyed dict.
		if ( is_array( $value ) && self::is_viewport_dict( $value ) ) {
			$out = [];
			foreach ( $value as $bp => $bp_value ) {
				$out[ $bp ] = self::size( $bp_value );
			}
			return $out;
		}

		// Plain array (already-normalised size dict) — pass through.
		if ( is_array( $value ) ) {
			return $value;
		}

		$str = trim( (string) $value );
		if ( '' === $str ) {
			return null;
		}

		// Extract numeric portion + unit suffix.
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)\s*(px|em|rem|%|vh|vw)?$/', $str, $m ) ) {
			$num  = (float) $m[1];
			$unit = isset( $m[2] ) && '' !== $m[2] ? $m[2] : 'px';
			return [
				'unit'  => $unit,
				'size'  => self::int_or_float( $num ),
				'sizes' => [],
			];
		}

		// Unparseable — return as-is so callers can decide.
		return [ 'unit' => 'px', 'size' => 0, 'sizes' => [] ];
	}

	/**
	 * Normalise a colour value. Currently a passthrough cast, but kept as a
	 * named hook so future logic (clamping to 6/8-char hex, name lookup, etc.)
	 * has one home.
	 */
	public static function color( mixed $value ): string {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return '';
	}

	private static function normalise_style_key( string $key ): string {
		$map = [
			'backgroundColor'  => 'background',
			'background-color' => 'background',
			'background_color' => 'background',
			'borderRadius'     => 'border_radius',
			'border-radius'    => 'border_radius',
			'fontFamily'       => 'font_family',
			'font-family'      => 'font_family',
			'fontSize'         => 'font_size',
			'font-size'        => 'font_size',
			'fontWeight'       => 'font_weight',
			'font-weight'      => 'font_weight',
			'lineHeight'       => 'line_height',
			'lineHeightPx'     => 'line_height',
			'line-height'      => 'line_height',
			'letterSpacing'    => 'letter_spacing',
			'letter-spacing'   => 'letter_spacing',
			'textAlign'        => 'text_align',
			'text-align'       => 'text_align',
			'textTransform'    => 'text_transform',
			'text-transform'   => 'text_transform',
		];

		return $map[ $key ] ?? $key;
	}

	/**
	 * Parse a CSS `border` shorthand into the pieces Elementor stores.
	 *
	 * Returns an associative array of any subset of:
	 *   - 'border_border' => 'solid'|'dashed'|'dotted'|...
	 *   - 'border_width'  => dimension array (linked, all sides equal)
	 *   - 'border_color'  => string
	 *
	 * Accepts:
	 *   - '1px solid #FFFFFF' (full shorthand, any order)
	 *   - '1px' (width only)
	 *   - '#FFFFFF' (colour only)
	 *   - 'solid' (style only)
	 *   - array form with keys width/style/color.
	 *
	 * @param string|array<string, mixed>|null $value
	 * @return array<string, mixed>
	 */
	public static function border( string|array|null $value ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}

		// Already-structured form.
		if ( is_array( $value ) ) {
			$out = [];
			if ( isset( $value['style'] ) ) {
				$out['border_border'] = (string) $value['style'];
			}
			if ( isset( $value['width'] ) ) {
				$dim = self::dimensions( $value['width'] );
				if ( null !== $dim ) {
					$out['border_width'] = $dim;
				}
			}
			if ( isset( $value['color'] ) ) {
				$out['border_color'] = self::color( $value['color'] );
			}
			return $out;
		}

		$tokens = preg_split( '/\s+/', trim( $value ) ) ?: [];
		$styles = [ 'solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset', 'none', 'hidden' ];
		$out    = [];

		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			if ( self::looks_like_color( $token ) ) {
				$out['border_color'] = $token;
				continue;
			}

			if ( in_array( strtolower( $token ), $styles, true ) ) {
				$out['border_border'] = strtolower( $token );
				continue;
			}

			// Width (number or "NNpx" etc).
			if ( preg_match( '/^-?\d/', $token ) ) {
				$dim = self::dimensions( $token );
				if ( null !== $dim ) {
					$out['border_width'] = $dim;
				}
				continue;
			}
		}

		return $out;
	}

	/**
	 * Merge border-related keys into a settings array, supporting prefix
	 * variants ('border', '_border', 'image_border', etc.). The shapes
	 * Elementor expects are stable; only the leading prefix differs across
	 * widgets and image-style sections.
	 *
	 * @param array<string, mixed> $settings
	 * @param array<string, mixed> $parsed
	 * @return array<string, mixed>
	 */
	private static function merge_border( array $settings, string $prefix, array $parsed ): array {
		foreach ( $parsed as $piece_key => $piece_value ) {
			// $piece_key always starts with 'border_'; swap that for the requested prefix.
			$elementor_key = preg_replace( '/^border/', $prefix, $piece_key );
			$settings[ (string) $elementor_key ] = $piece_value;
		}
		return $settings;
	}

	/**
	 * Normalise the value (size or dimension) and write it to the settings array,
	 * splitting viewport-keyed inputs into the `<key>`/`<key>_tablet`/`<key>_mobile`
	 * siblings Elementor expects. The normaliser is run *per viewport*, so each
	 * sibling gets its own well-formed dict.
	 *
	 * @param array<string, mixed> $settings
	 * @param mixed                $value
	 * @param callable             $normaliser
	 * @return array<string, mixed>
	 */
	private static function apply_normalised( array $settings, string $key, mixed $value, callable $normaliser ): array {
		if ( is_array( $value ) && self::is_viewport_dict( $value ) ) {
			foreach ( $value as $bp => $bp_value ) {
				$normalised = $normaliser( $bp_value );
				if ( null === $normalised ) {
					continue;
				}
				$suffix              = ( 'desktop' === $bp ) ? '' : '_' . $bp;
				$settings[ $key . $suffix ] = $normalised;
			}
			return $settings;
		}

		$normalised = $normaliser( $value );
		if ( null !== $normalised ) {
			$settings[ $key ] = $normalised;
		}
		return $settings;
	}

	/**
	 * @param array<int|string, mixed> $value
	 */
	private static function is_viewport_dict( array $value ): bool {
		if ( [] === $value ) {
			return false;
		}
		foreach ( array_keys( $value ) as $k ) {
			if ( ! in_array( $k, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function looks_like_color( string $value ): bool {
		$v = trim( $value );
		if ( '' === $v ) {
			return false;
		}
		if ( '#' === $v[0] ) {
			return true;
		}
		$lower = strtolower( $v );
		return str_starts_with( $lower, 'rgb' )
			|| str_starts_with( $lower, 'hsl' )
			|| str_starts_with( $lower, 'var(' );
	}

	/**
	 * Background-related keys can use one of several prefix conventions:
	 *   - `_background_color`  → prefix '_background'
	 *   - `background_color`   → prefix 'background'
	 *   - `card_background_color` → prefix 'card_background'
	 *
	 * The convention is "everything up to the trailing `_color`".
	 */
	private static function background_prefix( string $key ): string {
		if ( str_ends_with( $key, '_color' ) ) {
			return substr( $key, 0, -strlen( '_color' ) );
		}
		return $key;
	}

	private static function px_int( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return (int) $value;
		}
		if ( ! is_string( $value ) ) {
			return 0;
		}
		if ( preg_match( '/-?\d+/', $value, $m ) ) {
			return (int) $m[0];
		}
		return 0;
	}

	private static function int_or_float( float $value ): int|float {
		$rounded = (int) $value;
		return ( (float) $rounded === $value ) ? $rounded : $value;
	}
}
