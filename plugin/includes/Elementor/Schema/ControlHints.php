<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Schema;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * What a refused Elementor setting tells the caller: the control it belongs to, what was received (type and a short,
 * redacted preview), what the control accepts, and an example that would be accepted.
 *
 * Everything here is derived from the live control definition and the value sent. Nothing is guessed about the site.
 */
final class ControlHints {

	/** Longest preview of a received value, in characters. */
	public const PREVIEW_MAX = 60;

	/** Most option keys listed for a control. */
	public const OPTIONS_MAX = 25;

	/** Most keys of a control group listed for a nested value. */
	private const GROUP_KEYS_MAX = 12;

	/**
	 * Type and a short preview of a value. Credentials are redacted by the audit redactor, a long text is cut, and an
	 * object keeps its shape but never its secrets.
	 *
	 * @return array{type:string,preview:string,truncated:bool}
	 */
	public static function received( mixed $value ): array {
		$type = get_debug_type( $value );
		if ( is_array( $value ) ) {
			$redacted = AuditLog::redact_sensitive( $value );
			$text     = (string) wp_json_encode( $redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} elseif ( is_bool( $value ) ) {
			$text = $value ? 'true' : 'false';
		} elseif ( null === $value ) {
			$text = 'null';
		} elseif ( is_scalar( $value ) ) {
			$text = (string) AuditLog::redact_sensitive( [ 'value' => (string) $value ] )['value'];
		} else {
			$text = '';
		}
		$text = (string) preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', $text );
		$cut  = mb_strlen( $text ) > self::PREVIEW_MAX;

		return [
			'type'      => $type,
			'preview'   => $cut ? mb_substr( $text, 0, self::PREVIEW_MAX - 1 ) . '…' : $text,
			'truncated' => $cut,
		];
	}

	/**
	 * The words for a received value inside a message: a short scalar is quoted, an object is only named.
	 */
	public static function received_phrase( mixed $value ): string {
		$received = self::received( $value );
		if ( is_array( $value ) || is_object( $value ) ) {
			return $received['type'];
		}
		if ( null === $value || is_bool( $value ) ) {
			return $received['type'] . ' ' . $received['preview'];
		}
		$preview = mb_strlen( $received['preview'] ) > 40 ? mb_substr( $received['preview'], 0, 39 ) . '…' : $received['preview'];

		return $received['type'] . ' "' . $preview . '"';
	}

	/**
	 * The control, as far as a caller needs it to correct a value.
	 *
	 * @param array<string, mixed> $control Normalized control.
	 * @return array<string, mixed>
	 */
	public static function control( string $key, array $control ): array {
		$out = [
			'key'  => $key,
			'type' => strtolower( (string) ( $control['type'] ?? '' ) ),
		];
		if ( '' !== (string) ( $control['label'] ?? '' ) ) {
			$out['label'] = (string) $control['label'];
		}
		$units = self::units( $control );
		if ( [] !== $units ) {
			$out['units'] = $units;
		}
		if ( ! empty( $control['responsive'] ) || ResponsiveScope::control_is_responsive( $control, $key ) ) {
			$out['responsive']      = true;
			$out['responsive_keys'] = array_map( static fn( string $suffix ): string => $key . $suffix, self::breakpoint_suffixes() );
		}
		$allowed = self::allowed_values( $control );
		if ( [] !== $allowed ) {
			$out['options'] = $allowed;
		}
		if ( array_key_exists( 'default', $control ) && ( is_scalar( $control['default'] ) || is_array( $control['default'] ) ) && [] !== $control['default'] && '' !== $control['default'] ) {
			$out['default'] = $control['default'];
		}
		if ( isset( $control['fields'] ) && is_array( $control['fields'] ) ) {
			$out['row_fields'] = array_slice( array_map( 'strval', array_keys( $control['fields'] ) ), 0, 30 );
		}

		return $out;
	}

	/**
	 * Option keys of a select, choose or select2 control, bounded.
	 *
	 * @param array<string, mixed> $control
	 * @return list<string>
	 */
	public static function allowed_values( array $control ): array {
		if ( ! in_array( strtolower( (string) ( $control['type'] ?? '' ) ), [ 'select', 'choose', 'select2', 'visual_choice' ], true ) || ! is_array( $control['options'] ?? null ) ) {
			return [];
		}

		return array_slice( array_map( 'strval', array_keys( $control['options'] ) ), 0, self::OPTIONS_MAX );
	}

	/**
	 * Units a slider or dimensions control takes.
	 *
	 * @param array<string, mixed> $control
	 * @return list<string>
	 */
	public static function units( array $control ): array {
		if ( ! in_array( strtolower( (string) ( $control['type'] ?? '' ) ), [ 'slider', 'dimensions', 'gaps' ], true ) || ! is_array( $control['size_units'] ?? null ) ) {
			return [];
		}

		return array_values( array_map( 'strval', $control['size_units'] ) );
	}

	/**
	 * Suffixes of the breakpoints that are active, for example `_tablet` and `_mobile`.
	 *
	 * @return list<string>
	 */
	public static function breakpoint_suffixes(): array {
		$names = [];
		$plugin = class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance ) ? \Elementor\Plugin::$instance : null;
		$manager = is_object( $plugin ) ? ( $plugin->breakpoints ?? null ) : null;
		if ( is_object( $manager ) && method_exists( $manager, 'get_active_breakpoints' ) ) {
			try {
				$names = array_map( 'strval', array_keys( (array) $manager->get_active_breakpoints() ) );
			} catch ( \Throwable ) {
				$names = [];
			}
		}
		if ( [] === $names ) {
			$names = [ 'tablet', 'mobile' ];
		}
		$known = ResponsiveScope::breakpoint_suffixes();
		$out   = [];
		foreach ( $names as $name ) {
			if ( isset( $known[ $name ] ) && '' !== $known[ $name ] ) {
				$out[] = $known[ $name ];
			}
		}
		// The order Elementor lists them: the widest screens first, the narrowest last.
		return array_values( array_filter( [ '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile_extra', '_mobile' ], static fn( string $suffix ): bool => in_array( $suffix, $out, true ) ) );
	}

	/**
	 * An example value that the control accepts.
	 *
	 * @param array<string, mixed> $control
	 */
	public static function example_value( string $key, array $control ): mixed {
		$type  = strtolower( (string) ( $control['type'] ?? '' ) );
		$units = self::units( $control );
		$unit  = '' !== (string) ( $units[0] ?? '' ) && 'custom' !== ( $units[0] ?? '' ) ? (string) $units[0] : 'px';
		return match ( true ) {
			'color' === $type                                => '#1a73e8',
			'slider' === $type                               => [ 'unit' => $unit, 'size' => self::example_size( $unit ) ],
			'dimensions' === $type                           => [ 'unit' => $unit, 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10', 'isLinked' => true ],
			'gaps' === $type                                 => [ 'column' => '16', 'row' => '16', 'isLinked' => true, 'unit' => $unit ],
			'url' === $type                                  => [ 'url' => 'https://example.com/', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ],
			'media' === $type                                => [ 'id' => 123, 'url' => 'https://example.com/wp-content/uploads/image.jpg' ],
			'icons' === $type                                => [ 'value' => 'fas fa-star', 'library' => 'fa-solid' ],
			'box_shadow' === $type                           => [ 'horizontal' => 0, 'vertical' => 4, 'blur' => 12, 'spread' => 0, 'color' => 'rgba(0,0,0,0.2)' ],
			'text_shadow' === $type                          => [ 'horizontal' => 0, 'vertical' => 2, 'blur' => 4, 'color' => 'rgba(0,0,0,0.3)' ],
			'font' === $type                                 => 'Inter',
			'switcher' === $type                             => (string) ( $control['return_value'] ?? 'yes' ),
			'popover_toggle' === $type                       => (string) ( $control['return_value'] ?? 'yes' ),
			'number' === $type                               => isset( $control['min'] ) && is_numeric( $control['min'] ) ? 0 + $control['min'] : 1,
			1 === preg_match( '/(?:^|_)font_weight$/', $key ) => '600',
			[] !== self::allowed_values( $control )          => self::first_option( $control ),
			default                                          => null,
		};
	}

	/**
	 * The first option that sets something, or the first one.
	 *
	 * @param array<string, mixed> $control
	 */
	private static function first_option( array $control ): string {
		$allowed = self::allowed_values( $control );
		foreach ( $allowed as $option ) {
			if ( '' !== trim( $option ) ) {
				return $option;
			}
		}

		return (string) ( $allowed[0] ?? '' );
	}

	private static function example_size( string $unit ): int {
		return match ( $unit ) {
			'%', 'vh', 'vw', 'vmin', 'vmax', 'dvh', 'svh', 'lvh' => 50,
			'em', 'rem', 'ch', 'lh', 'rlh'                         => 1,
			's'                                                    => 1,
			'ms'                                                   => 300,
			'deg'                                                  => 90,
			default                                                => 100,
		};
	}

	/**
	 * The settings that must accompany a control for its condition to hold, as name => value. Only the parts that
	 * can be satisfied by one plain value are listed.
	 *
	 * @param array<string, mixed>                $condition Control condition (flat map, or relation with terms).
	 * @param array<string, array<string, mixed>> $controls Controls of the element.
	 * @return array<string, mixed>
	 */
	public static function requirements( array $condition, array $controls ): array {
		$out = [];
		if ( isset( $condition['terms'] ) && is_array( $condition['terms'] ) ) {
			$terms = 'or' === ( $condition['relation'] ?? null ) ? array_slice( $condition['terms'], 0, 1 ) : $condition['terms'];
			foreach ( $terms as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}
				if ( isset( $term['terms'] ) && is_array( $term['terms'] ) ) {
					$out += self::requirements( $term, $controls );
					continue;
				}
				$name = is_string( $term['name'] ?? null ) ? (string) preg_replace( '/\[.*$/', '', $term['name'] ) : '';
				if ( '' === $name || isset( $out[ $name ] ) ) {
					continue;
				}
				$operator = (string) ( $term['operator'] ?? '' );
				$value    = $term['value'] ?? null;
				$negated  = in_array( $operator, [ '!==', '!=', '!in', '!contains' ], true );
				$wanted   = is_array( $value ) && ! $negated ? ( $value[0] ?? null ) : $value;
				if ( in_array( $operator, [ '', '===', '==', 'in', 'contains' ], true ) && is_scalar( $wanted ) ) {
					$out[ $name ] = $wanted;
				} elseif ( $negated ) {
					$out[ $name ] = self::unlike( $controls[ $name ] ?? [], is_array( $value ) ? $value : [ $value ] );
				}
			}

			return array_filter( $out, static fn( mixed $item ): bool => null !== $item );
		}
		foreach ( $condition as $raw => $expected ) {
			if ( ! is_string( $raw ) || '__unresolved__' === $raw || ( is_array( $expected ) && array_key_exists( '__unresolved__', $expected ) ) ) {
				continue;
			}
			$negated = str_ends_with( $raw, '!' );
			$name    = (string) preg_replace( '/\[.*$/', '', $negated ? substr( $raw, 0, -1 ) : $raw );
			if ( '' === $name || isset( $out[ $name ] ) ) {
				continue;
			}
			if ( $negated ) {
				$out[ $name ] = self::unlike( $controls[ $name ] ?? [], is_array( $expected ) ? $expected : [ $expected ] );
			} else {
				$out[ $name ] = is_array( $expected ) ? ( $expected[0] ?? '' ) : $expected;
			}
		}

		return array_filter( $out, static fn( mixed $item ): bool => null !== $item );
	}

	/**
	 * A value for a control that is none of the given ones: a switch or popover toggle uses its own return value,
	 * a list the first other option, anything else `yes`.
	 *
	 * @param array<string, mixed> $control
	 * @param list<mixed>          $avoid
	 */
	private static function unlike( array $control, array $avoid ): ?string {
		$avoid = array_map( static fn( mixed $item ): string => is_scalar( $item ) ? (string) $item : '', $avoid );
		if ( isset( $control['return_value'] ) && is_scalar( $control['return_value'] ) && ! in_array( (string) $control['return_value'], $avoid, true ) ) {
			return (string) $control['return_value'];
		}
		foreach ( self::allowed_values( $control ) as $option ) {
			if ( ! in_array( $option, $avoid, true ) ) {
				return $option;
			}
		}
		if ( [] !== self::allowed_values( $control ) ) {
			return null;
		}

		return 'yes';
	}

	/**
	 * Keys of the control group a nested value was sent for: `typography` => the `typography_*` controls.
	 *
	 * @param array<string, array<string, mixed>> $controls
	 * @return list<string>
	 */
	public static function group_keys( string $prefix, array $controls ): array {
		if ( '' === $prefix ) {
			return [];
		}
		$found = [];
		foreach ( array_keys( $controls ) as $control_key ) {
			$control_key = (string) $control_key;
			if ( str_starts_with( $control_key, $prefix . '_' ) && 1 !== preg_match( '/_(?:widescreen|laptop|tablet_extra|tablet|mobile_extra|mobile)$/', $control_key ) ) {
				$found[] = $control_key;
			}
		}

		return array_slice( $found, 0, self::GROUP_KEYS_MAX );
	}

	/**
	 * The names of the controls a condition refers to: no `!` suffix and no `[sub]` part.
	 *
	 * @param array<string, mixed> $condition
	 * @return list<string>
	 */
	public static function condition_control_names( array $condition ): array {
		$names = [];
		if ( isset( $condition['terms'] ) && is_array( $condition['terms'] ) ) {
			foreach ( $condition['terms'] as $term ) {
				if ( is_array( $term ) && isset( $term['terms'] ) && is_array( $term['terms'] ) ) {
					$names = array_merge( $names, self::condition_control_names( $term ) );
				} elseif ( is_array( $term ) && is_string( $term['name'] ?? null ) ) {
					$names[] = (string) preg_replace( '/\[.*$/', '', $term['name'] );
				}
			}
		} else {
			foreach ( array_keys( $condition ) as $raw ) {
				if ( ! is_string( $raw ) || '__unresolved__' === $raw ) {
					continue;
				}
				$names[] = (string) preg_replace( '/\[.*$/', '', str_ends_with( $raw, '!' ) ? substr( $raw, 0, -1 ) : $raw );
			}
		}

		return array_values( array_unique( array_filter( $names, static fn( string $name ): bool => '' !== $name ) ) );
	}
}
