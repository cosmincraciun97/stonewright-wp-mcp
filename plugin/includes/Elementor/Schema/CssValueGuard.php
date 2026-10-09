<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

use Stonewright\WpMcp\Elementor\ElementorCustomCssGate;

/**
 * Strict checks for the values that Elementor prints into generated CSS:
 * colours, typography fields, units, numeric sides and shadows.
 *
 * Elementor substitutes these values into stylesheet rules without escaping
 * them, so a value is accepted only when it is a real value of its kind.
 */
final class CssValueGuard {

	/** Units accepted in slider and dimension objects, besides the empty unit. */
	public const UNITS = '% px em rem ex ch lh rlh pt pc cm mm in q vh vw vmin vmax dvh dvw svh svw lvh lvw cqw cqh cqi cqb cqmin cqmax deg grad rad turn s ms fr custom';

	public const IDENTIFIER_EXPECTED = 'an identifier of letters, digits, hyphen and underscore, 1 to 64 characters';

	public const COLOR_EXPECTED = 'a colour: hex (3, 4, 6 or 8 digits), rgb(), rgba(), hsl(), hsla(), a CSS colour name, transparent, or a global colour variable';

	private const NAMED_COLORS = 'aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white whitesmoke yellow yellowgreen transparent currentcolor';

	private const WEIGHT_KEYWORDS    = [ '', 'normal', 'bold', 'bolder', 'lighter' ];
	private const TRANSFORM_VALUES   = [ '', 'none', 'capitalize', 'uppercase', 'lowercase' ];
	private const STYLE_VALUES       = [ '', 'normal', 'italic', 'oblique' ];
	private const DECORATION_VALUES  = [ '', 'none', 'underline', 'overline', 'line-through' ];
	private const SLIDER_TYPOGRAPHY  = '/(?:^|_)(?:font_size|line_height|letter_spacing|word_spacing)(?:_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile))?$/';
	private const SENSITIVE_KEY      = '/color|font_family|typography|(?:^|_)(?:unit|font_weight|font_style|text_transform|text_decoration)$|^(?:_id|size|sizes|top|right|bottom|left|horizontal|vertical|blur|spread)$/i';
	private const BREAKOUT_PATTERN   = '/[;{}<>\\\\\x00-\x08\x0b-\x1f]|\/\*|\*\/|url\s*\(|expression\s*\(|@import|javascript\s*:|behavior\s*:|-moz-binding/i';

	/**
	 * Hex, rgb()/rgba()/hsl()/hsla() with numeric arguments, CSS named colours,
	 * transparent, currentColor and Elementor global colour variables.
	 */
	public static function is_color( mixed $value, bool $allow_empty = true ): bool {
		if ( null === $value || '' === $value ) {
			return $allow_empty;
		}
		if ( ! is_string( $value ) || strlen( $value ) > 80 ) {
			return false;
		}
		if ( 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/iD', $value ) ) {
			return true;
		}
		$number   = '[+-]?(?:\d+(?:\.\d+)?|\.\d+)(?:%|deg|grad|rad|turn)?';
		$comma    = '/^(?:rgb|hsl)a?\(\s*' . $number . '(?:\s*,\s*' . $number . '){2}(?:\s*,\s*' . $number . ')?\s*\)$/iD';
		$space    = '/^(?:rgb|hsl)a?\(\s*' . $number . '(?:\s+' . $number . '){2}(?:\s*\/\s*' . $number . ')?\s*\)$/iD';
		if ( 1 === preg_match( $comma, $value ) || 1 === preg_match( $space, $value ) ) {
			return true;
		}
		if ( 1 === preg_match( '/^var\(--e-global-color-[A-Za-z0-9_-]{1,64}\)$/D', $value ) ) {
			return true;
		}
		return in_array( strtolower( $value ), explode( ' ', self::NAMED_COLORS ), true );
	}

	/** Stored form of an Elementor global reference, e.g. `globals/colors?id=primary`. */
	public static function is_global_reference( mixed $value ): bool {
		if ( '' === $value ) {
			return true;
		}
		return is_string( $value ) && 1 === preg_match( '/^globals\/[a-z_-]{1,32}\?id=[A-Za-z0-9_-]{1,64}$/D', $value );
	}

	/** Identifier used in generated CSS variable names, e.g. `--e-global-color-<id>`. */
	public static function is_identifier( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $value );
	}

	public static function is_font_family( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[\p{L}\p{N} _.,+&-]{0,200}$/uD', $value );
	}

	public static function is_unit( mixed $value ): bool {
		return is_string( $value ) && ( '' === $value || in_array( strtolower( $value ), explode( ' ', self::UNITS ), true ) );
	}

	/** A plain number: int, float or a numeric string; the empty string and null clear the value. */
	public static function is_css_number( mixed $value ): bool {
		return null === $value || '' === $value || is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) );
	}

	/**
	 * Safety of the parts of a slider object: unit, size and ranged sizes.
	 *
	 * @param array<mixed> $value
	 */
	public static function slider_parts_safe( array $value ): bool {
		if ( array_key_exists( 'unit', $value ) && ! self::is_unit( $value['unit'] ) ) {
			return false;
		}
		if ( array_key_exists( 'size', $value ) && ! self::is_css_number( $value['size'] ) ) {
			return false;
		}
		if ( array_key_exists( 'sizes', $value ) ) {
			if ( ! is_array( $value['sizes'] ) ) {
				return false;
			}
			foreach ( $value['sizes'] as $size ) {
				if ( ! self::is_css_number( $size ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Numeric sides and unit of a dimensions object.
	 *
	 * @param array<mixed> $value
	 */
	public static function dimensions_parts_safe( array $value ): bool {
		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			if ( array_key_exists( $side, $value ) && ! self::is_css_number( $value[ $side ] ) ) {
				return false;
			}
		}
		return ! array_key_exists( 'unit', $value ) || self::is_unit( $value['unit'] );
	}

	/**
	 * Box and text shadow objects: numeric geometry plus a real colour.
	 */
	public static function is_shadow( mixed $value, bool $box ): bool {
		if ( null === $value || '' === $value || [] === $value ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		$geometry = $box ? [ 'horizontal', 'vertical', 'blur', 'spread' ] : [ 'horizontal', 'vertical', 'blur' ];
		$allowed  = array_merge( $geometry, [ 'color' ], $box ? [ 'position' ] : [] );
		foreach ( $value as $key => $part ) {
			$key = (string) $key;
			if ( ! in_array( $key, $allowed, true ) ) {
				return false;
			}
			if ( in_array( $key, $geometry, true ) && ! self::is_css_number( $part ) ) {
				return false;
			}
			if ( 'color' === $key && ! self::is_color( $part ) ) {
				return false;
			}
			if ( 'position' === $key && ! in_array( $part, [ '', 'inset' ], true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Typography field check by control key. Returns null when the key is not a
	 * typography field that this guard knows; otherwise whether the value is safe.
	 */
	public static function typography_field_is_valid( string $key, mixed $value ): ?bool {
		if ( 1 === preg_match( '/(?:^|_)font_family$/', $key ) ) {
			return self::is_font_family( $value );
		}
		if ( 1 === preg_match( '/(?:^|_)font_weight$/', $key ) ) {
			return is_string( $value ) && ( in_array( $value, self::WEIGHT_KEYWORDS, true ) || 1 === preg_match( '/^(?:[1-9]\d{0,2}|1000)$/D', $value ) );
		}
		if ( 1 === preg_match( '/(?:^|_)text_transform$/', $key ) ) {
			return is_string( $value ) && in_array( $value, self::TRANSFORM_VALUES, true );
		}
		if ( 1 === preg_match( '/(?:^|_)font_style$/', $key ) ) {
			return is_string( $value ) && in_array( $value, self::STYLE_VALUES, true );
		}
		if ( 1 === preg_match( '/(?:^|_)text_decoration$/', $key ) ) {
			return is_string( $value ) && in_array( $value, self::DECORATION_VALUES, true );
		}
		if ( 1 === preg_match( self::SLIDER_TYPOGRAPHY, $key ) && str_contains( $key, 'typography' ) ) {
			return self::slider_value_is_safe( $value );
		}
		return null;
	}

	/** A slider object, or a bare number. */
	public static function slider_value_is_safe( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return ( array_key_exists( 'size', $value ) || array_key_exists( 'sizes', $value ) ) && self::slider_parts_safe( $value );
		}
		return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) );
	}

	/** Whether a string carries any marker that could end a CSS declaration or start another rule. */
	public static function contains_breakout( string $value ): bool {
		return 1 === preg_match( self::BREAKOUT_PATTERN, $value );
	}

	/**
	 * Key-based check for settings that have no live schema, such as kit settings.
	 *
	 * @return array{path:string,expected:string}|null Violation relative to the key, or null when the value is acceptable or the key is not covered.
	 */
	public static function setting_violation( string $key, mixed $value ): ?array {
		$lower = strtolower( $key );
		if ( '__globals__' === $lower ) {
			if ( ! is_array( $value ) ) {
				return [ 'path' => '', 'expected' => 'an object of global references' ];
			}
			foreach ( $value as $target => $reference ) {
				if ( ! self::is_global_reference( $reference ) ) {
					return [ 'path' => (string) $target, 'expected' => 'a global reference such as globals/colors?id=primary' ];
				}
			}
			return null;
		}
		if ( 1 === preg_match( '/^(?:custom|system|global)_colors$/', $lower ) ) {
			return is_array( $value ) ? self::color_rows_violation( $value, false ) : [ 'path' => '', 'expected' => 'a list of colour rows' ];
		}
		if ( 1 === preg_match( '/^(?:custom|system|global)_typography$/', $lower ) ) {
			return is_array( $value ) ? self::typography_rows_violation( $value, false ) : [ 'path' => '', 'expected' => 'a list of typography rows' ];
		}
		if ( 1 === preg_match( '/(?:^|_)color(?:_b)?$/', $lower ) ) {
			return self::is_color( $value ) ? null : [ 'path' => '', 'expected' => self::COLOR_EXPECTED ];
		}
		if ( str_contains( $lower, 'typography' ) ) {
			return self::typography_setting_violation( $lower, $value );
		}
		if ( is_array( $value ) && array_key_exists( 'unit', $value ) && ! ( self::slider_parts_safe( $value ) && self::dimensions_parts_safe( $value ) ) ) {
			return [ 'path' => '', 'expected' => 'an object with numeric values and a unit from the supported list' ];
		}
		return null;
	}

	/**
	 * Colour rows of the kit palette.
	 *
	 * @param array<mixed> $rows
	 * @return array{path:string,expected:string}|null
	 */
	public static function color_rows_violation( array $rows, bool $require_id ): ?array {
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return [ 'path' => (string) $index, 'expected' => 'a colour row object' ];
			}
			$violation = self::color_row_violation( $row, $require_id );
			if ( null !== $violation ) {
				return [ 'path' => $index . '.' . $violation['path'], 'expected' => $violation['expected'] ];
			}
		}
		return null;
	}

	/**
	 * @param array<mixed> $row Keys `id` or `_id`, `color`.
	 * @return array{path:string,expected:string}|null
	 */
	public static function color_row_violation( array $row, bool $require_id ): ?array {
		foreach ( [ 'id', '_id' ] as $id_key ) {
			if ( ! array_key_exists( $id_key, $row ) ) {
				continue;
			}
			if ( ( '' !== $row[ $id_key ] || $require_id ) && ! self::is_identifier( $row[ $id_key ] ) ) {
				return [ 'path' => $id_key, 'expected' => self::IDENTIFIER_EXPECTED ];
			}
		}
		if ( $require_id && ! array_key_exists( 'id', $row ) && ! array_key_exists( '_id', $row ) ) {
			return [ 'path' => 'id', 'expected' => self::IDENTIFIER_EXPECTED ];
		}
		if ( array_key_exists( 'color', $row ) && ! self::is_color( $row['color'], ! $require_id ) ) {
			return [ 'path' => 'color', 'expected' => self::COLOR_EXPECTED ];
		}
		return null;
	}

	/**
	 * Typography rows of the kit.
	 *
	 * @param array<mixed> $rows
	 * @return array{path:string,expected:string}|null
	 */
	public static function typography_rows_violation( array $rows, bool $require_id ): ?array {
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return [ 'path' => (string) $index, 'expected' => 'a typography row object' ];
			}
			$violation = self::typography_row_violation( $row, $require_id );
			if ( null !== $violation ) {
				return [ 'path' => $index . '.' . $violation['path'], 'expected' => $violation['expected'] ];
			}
		}
		return null;
	}

	/**
	 * One typography row. Accepts the short field names of the kit writers
	 * (`font_family`, `font_weight`, `font_size`, `line_height`, `letter_spacing`)
	 * and stored `typography_*` keys.
	 *
	 * @param array<mixed> $row
	 * @return array{path:string,expected:string}|null
	 */
	public static function typography_row_violation( array $row, bool $require_id ): ?array {
		foreach ( [ 'id', '_id' ] as $id_key ) {
			if ( array_key_exists( $id_key, $row ) && ( '' !== $row[ $id_key ] || $require_id ) && ! self::is_identifier( $row[ $id_key ] ) ) {
				return [ 'path' => $id_key, 'expected' => self::IDENTIFIER_EXPECTED ];
			}
		}
		if ( $require_id && ! array_key_exists( 'id', $row ) && ! array_key_exists( '_id', $row ) ) {
			return [ 'path' => 'id', 'expected' => self::IDENTIFIER_EXPECTED ];
		}
		foreach ( [ 'font_family', 'font_weight', 'font_size', 'line_height', 'letter_spacing' ] as $short ) {
			if ( ! array_key_exists( $short, $row ) || null === $row[ $short ] ) {
				continue;
			}
			if ( true !== self::typography_field_is_valid( 'typography_' . $short, $row[ $short ] ) ) {
				return [ 'path' => $short, 'expected' => self::typography_expected( 'typography_' . $short ) ];
			}
		}
		foreach ( $row as $key => $value ) {
			$key = (string) $key;
			if ( ! str_starts_with( $key, 'typography_' ) ) {
				continue;
			}
			$violation = self::typography_setting_violation( $key, $value );
			if ( null !== $violation ) {
				return [ 'path' => $key, 'expected' => $violation['expected'] ];
			}
		}
		return null;
	}

	/**
	 * Refusal for a value that is not safe to store; same code and shape as the settings validator.
	 */
	public static function refusal( string $path, string $expected, mixed $got ): \WP_Error {
		return new \WP_Error(
			'stonewright_elementor_settings_invalid',
			sprintf(
				/* translators: 1: setting path, 2: expected shape, 3: received PHP type */
				__( 'Elementor setting %1$s rejected: expected %2$s; received %3$s.', 'stonewright' ),
				$path,
				$expected,
				get_debug_type( $got )
			),
			[
				'status'     => 400,
				'retryable'  => true,
				'violations' => [
					[
						'path'        => $path,
						'code'        => 'invalid_shape',
						'expected'    => $expected,
						'got_type'    => get_debug_type( $got ),
						'suggestions' => [],
					],
				],
				'repair'     => 'Replace the rejected value with one of the accepted forms and rerun the call.',
			]
		);
	}

	/**
	 * @return array{path:string,expected:string}|null
	 */
	private static function typography_setting_violation( string $key, mixed $value ): ?array {
		$known = self::typography_field_is_valid( $key, $value );
		if ( null === $known ) {
			if ( self::value_contains_breakout( $value ) ) {
				return [ 'path' => '', 'expected' => 'a typography value without CSS control characters' ];
			}
			return null;
		}
		return $known ? null : [ 'path' => '', 'expected' => self::typography_expected( $key ) ];
	}

	public static function typography_expected( string $key ): string {
		return match ( true ) {
			1 === preg_match( '/font_family$/', $key )     => 'a font family name of letters, digits, spaces and . , + & -',
			1 === preg_match( '/font_weight$/', $key )     => 'a font weight: normal, bold, bolder, lighter or 1 to 1000',
			1 === preg_match( '/text_transform$/', $key )  => 'one of none, capitalize, uppercase, lowercase',
			1 === preg_match( '/font_style$/', $key )      => 'one of normal, italic, oblique',
			1 === preg_match( '/text_decoration$/', $key ) => 'one of none, underline, overline, line-through',
			default                                        => 'a number or an object with numeric size and a unit from the supported list',
		};
	}

	private static function value_contains_breakout( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return self::contains_breakout( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::value_contains_breakout( $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Values in a stored Elementor tree that carry CSS control characters under
	 * colour, typography, unit or numeric-side keys. Custom CSS keys are skipped
	 * because they are covered by the custom-code approval gate.
	 *
	 * @param array<int|string, mixed> $tree Elementor element tree.
	 * @return list<array{path:string,key:string,element_id:string}>
	 */
	public static function unsafe_values_in_tree( array $tree, string $path = 'root' ): array {
		$found = [];
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$element_path = $path . '.' . (string) $index;
			$element_id   = isset( $element['id'] ) && is_scalar( $element['id'] ) ? (string) $element['id'] : '';
			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				self::scan_settings( $element['settings'], $element_path . '.settings', $element_id, false, $found );
			}
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				foreach ( self::unsafe_values_in_tree( $element['elements'], $element_path . '.elements' ) as $item ) {
					$found[] = $item;
				}
			}
		}
		return $found;
	}

	/**
	 * Same scan for a settings object that is not part of an element tree, such as page or kit settings.
	 *
	 * @param array<mixed> $settings
	 * @return list<array{path:string,key:string,element_id:string}>
	 */
	public static function unsafe_values_in_settings( array $settings, string $path = 'settings' ): array {
		$found = [];
		self::scan_settings( $settings, $path, '', false, $found );
		return $found;
	}

	/**
	 * @param array<mixed> $settings
	 * @param list<array{path:string,key:string,element_id:string}> $found
	 */
	private static function scan_settings( array $settings, string $path, string $element_id, bool $inherited, array &$found ): void {
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( '__dynamic__' === $key || ElementorCustomCssGate::is_css_key( $key ) ) {
				continue;
			}
			$sensitive = $inherited || 1 === preg_match( self::SENSITIVE_KEY, $key );
			if ( is_string( $value ) && $sensitive && self::contains_breakout( $value ) ) {
				$found[] = [ 'path' => $path . '.' . $key, 'key' => $key, 'element_id' => $element_id ];
				continue;
			}
			if ( is_array( $value ) ) {
				self::scan_settings( $value, $path . '.' . $key, $element_id, $sensitive, $found );
			}
		}
	}
}
