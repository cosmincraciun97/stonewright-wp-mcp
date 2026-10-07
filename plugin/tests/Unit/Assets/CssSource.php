<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

/**
 * A small CSS reader for the asset contract tests.
 *
 * It understands what the admin stylesheets use: comments, nested @media / @supports
 * blocks, @keyframes, custom properties, !important and quoted strings. It is not a general
 * CSS parser, and it does not know CSS nesting (the admin stylesheets do not use it).
 */
final class CssSource {

	/** The plugin's assets directory. */
	public static function assets_dir(): string {
		return dirname( __DIR__, 3 ) . '/assets';
	}

	/** Contents of an asset, relative to plugin/assets (for example "admin/shell.css"). */
	public static function read( string $relative ): string {
		$path = self::assets_dir() . '/' . $relative;
		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( $relative . ' must exist' );
		}

		return (string) file_get_contents( $path );
	}

	public static function strip_comments( string $css ): string {
		return (string) preg_replace( '~/\*.*?\*/~s', '', $css );
	}

	/**
	 * Every style rule in source order. At-rule blocks that wrap style rules (@media, @supports,
	 * @starting-style) are flattened and named in "context"; @keyframes and @font-face are returned as one
	 * rule whose selector is the at-rule prelude and whose "at" flag is set.
	 *
	 * @return list<array{selector: string, body: string, context: string, at: bool, order: int}>
	 */
	public static function rules( string $css ): array {
		$rules = [];
		self::collect( self::strip_comments( $css ), '', $rules );

		return $rules;
	}

	/**
	 * @param list<array{selector: string, body: string, context: string, at: bool, order: int}> $rules
	 */
	private static function collect( string $css, string $context, array &$rules ): void {
		$length = strlen( $css );
		$offset = 0;
		while ( $offset < $length ) {
			$open = self::find_unquoted( $css, '{', $offset );
			if ( null === $open ) {
				break;
			}
			$prelude = trim( substr( $css, $offset, $open - $offset ) );
			$close   = self::matching_brace( $css, $open );
			$inner   = substr( $css, $open + 1, $close - $open - 1 );
			$offset  = $close + 1;

			if ( str_starts_with( $prelude, '@' ) ) {
				$name = strtolower( (string) strtok( $prelude, " \t\n(" ) );
				if ( in_array( $name, [ '@media', '@supports', '@starting-style', '@container', '@layer' ], true ) ) {
					self::collect( $inner, trim( $context . ' ' . $prelude ), $rules );
				} else {
					$rules[] = [
						'selector' => $prelude,
						'body'     => $inner,
						'context'  => $context,
						'at'       => true,
						'order'    => count( $rules ),
					];
				}
				continue;
			}

			$rules[] = [
				'selector' => (string) preg_replace( '/\s+/', ' ', $prelude ),
				'body'     => $inner,
				'context'  => $context,
				'at'       => false,
				'order'    => count( $rules ),
			];
		}
	}

	private static function find_unquoted( string $css, string $needle, int $from ): ?int {
		$quote  = '';
		$length = strlen( $css );
		for ( $i = $from; $i < $length; ++$i ) {
			$char = $css[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( $char === $needle ) {
				return $i;
			}
		}

		return null;
	}

	private static function matching_brace( string $css, int $open ): int {
		$depth  = 0;
		$quote  = '';
		$length = strlen( $css );
		for ( $i = $open; $i < $length; ++$i ) {
			$char = $css[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return $length;
	}

	/**
	 * Declarations of one rule body, in order.
	 *
	 * @return list<array{name: string, value: string, important: bool}>
	 */
	public static function declarations( string $body ): array {
		$out = [];
		foreach ( self::split_top_level( $body, ';' ) as $declaration ) {
			$declaration = trim( $declaration );
			$colon       = strpos( $declaration, ':' );
			if ( '' === $declaration || false === $colon ) {
				continue;
			}
			$name      = trim( substr( $declaration, 0, $colon ) );
			$value     = trim( substr( $declaration, $colon + 1 ) );
			$important = 1 === preg_match( '/\s*!\s*important\s*$/i', $value );
			if ( $important ) {
				$value = trim( (string) preg_replace( '/\s*!\s*important\s*$/i', '', $value ) );
			}
			$out[] = [
				'name'      => str_starts_with( $name, '--' ) ? $name : strtolower( $name ),
				'value'     => (string) preg_replace( '/\s+/', ' ', $value ),
				'important' => $important,
			];
		}

		return $out;
	}

	/**
	 * Split on a separator that is outside parentheses, brackets and quotes.
	 *
	 * @return list<string>
	 */
	public static function split_top_level( string $text, string $separator ): array {
		$parts  = [];
		$depth  = 0;
		$quote  = '';
		$start  = 0;
		$length = strlen( $text );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $text[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $char ) {
					++$i;
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
			} elseif ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				--$depth;
			} elseif ( $char === $separator && 0 === $depth ) {
				$parts[] = substr( $text, $start, $i - $start );
				$start   = $i + 1;
			}
		}
		$parts[] = substr( $text, $start );

		return $parts;
	}

	/** @return list<string> The selectors of a selector list, trimmed. */
	public static function selectors( string $list ): array {
		return array_values( array_filter( array_map( 'trim', self::split_top_level( $list, ',' ) ), static fn ( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Specificity of one selector as [ids, classes and attributes and pseudo-classes, types and pseudo-elements].
	 *
	 * :where() counts for nothing; :is(), :not() and :has() count as their most specific argument.
	 *
	 * @return array{0: int, 1: int, 2: int}
	 */
	public static function specificity( string $selector ): array {
		$a = 0;
		$b = 0;
		$c = 0;
		$s = trim( $selector );

		// Functional pseudo-classes first, innermost handled by recursion.
		while ( 1 === preg_match( '/:(where|is|not|has|matches)\(/i', $s, $m, PREG_OFFSET_CAPTURE ) ) {
			$name  = strtolower( $m[1][0] );
			$start = $m[0][1];
			$open  = $start + strlen( $m[0][0] ) - 1;
			$depth = 0;
			$end   = strlen( $s ) - 1;
			for ( $i = $open, $n = strlen( $s ); $i < $n; ++$i ) {
				if ( '(' === $s[ $i ] ) {
					++$depth;
				} elseif ( ')' === $s[ $i ] ) {
					--$depth;
					if ( 0 === $depth ) {
						$end = $i;
						break;
					}
				}
			}
			$inner = substr( $s, $open + 1, $end - $open - 1 );
			if ( 'where' !== $name ) {
				$best = [ 0, 0, 0 ];
				foreach ( self::selectors( $inner ) as $argument ) {
					$candidate = self::specificity( $argument );
					if ( $candidate > $best ) {
						$best = $candidate;
					}
				}
				$a += $best[0];
				$b += $best[1];
				$c += $best[2];
			}
			$s = substr( $s, 0, $start ) . ' ' . substr( $s, $end + 1 );
		}

		$s = (string) preg_replace( '/\[[^\]]*\]/', "\0", $s, -1, $attributes );
		$b += $attributes;
		$s  = (string) preg_replace( '/"[^"]*"|\'[^\']*\'/', '', $s );
		$s  = (string) preg_replace( '/::[a-zA-Z-]+(?:\([^)]*\))?/', "\1", $s, -1, $elements );
		$c += $elements;
		$s  = (string) preg_replace( '/:(before|after|first-line|first-letter)\b/i', "\1", $s, -1, $legacy_elements );
		$c += $legacy_elements;
		$s  = (string) preg_replace( '/:[a-zA-Z-]+(?:\([^)]*\))?/', "\2", $s, -1, $classes );
		$b += $classes;
		$s  = (string) preg_replace( '/#[\w-]+/', "\3", $s, -1, $ids );
		$a += $ids;
		$s  = (string) preg_replace( '/\.[\w-]+/', "\4", $s, -1, $class_names );
		$b += $class_names;
		$s  = (string) preg_replace( '/[>+~]/', ' ', $s );
		foreach ( preg_split( '/\s+/', trim( str_replace( [ "\0", "\1", "\2", "\3", "\4" ], ' ', $s ) ) ) ?: [] as $token ) {
			if ( '' !== $token && '*' !== $token ) {
				++$c;
			}
		}

		return [ $a, $b, $c ];
	}

	/** Human readable "(0,2,0)". */
	public static function format_specificity( array $specificity ): string {
		return '(' . implode( ',', $specificity ) . ')';
	}

	/**
	 * Custom properties declared by rules whose selector list satisfies the filter, in one at-rule context
	 * ('' is the top level, so a media or supports block never overrides the defaults).
	 *
	 * @param callable(string): bool $selector_filter
	 * @return array<string, string> Last declaration wins.
	 */
	public static function custom_properties( string $css, callable $selector_filter, string $context = '' ): array {
		$properties = [];
		foreach ( self::rules( $css ) as $rule ) {
			if ( $rule['at'] || $rule['context'] !== $context || ! $selector_filter( $rule['selector'] ) ) {
				continue;
			}
			foreach ( self::declarations( $rule['body'] ) as $declaration ) {
				if ( str_starts_with( $declaration['name'], '--' ) ) {
					$properties[ $declaration['name'] ] = $declaration['value'];
				}
			}
		}

		return $properties;
	}

	/** Declarations of the first top-level (context-free) rule with exactly this selector list. */
	public static function rule_declarations( string $css, string $selector, string $context = '' ): array {
		foreach ( self::rules( $css ) as $rule ) {
			if ( ! $rule['at'] && $rule['selector'] === $selector && $rule['context'] === $context ) {
				return self::declarations( $rule['body'] );
			}
		}

		throw new \RuntimeException( 'No rule for "' . $selector . '"' . ( '' !== $context ? ' in ' . $context : '' ) );
	}

	/** The last value a rule declares for a property, or null. */
	public static function value_of( array $declarations, string $property ): ?string {
		$value = null;
		foreach ( $declarations as $declaration ) {
			if ( $declaration['name'] === $property ) {
				$value = $declaration['value'];
			}
		}

		return $value;
	}

	// ---------------------------------------------------------------------------------------------
	// Colour helpers shared by the contrast checks.
	// ---------------------------------------------------------------------------------------------

	/** @return array{0: float, 1: float, 2: float} sRGB channels 0-255 of a #rgb or #rrggbb colour. */
	public static function hex_to_rgb( string $hex ): array {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			throw new \InvalidArgumentException( 'Not a hex colour: ' . $hex );
		}

		return [ (float) hexdec( substr( $hex, 0, 2 ) ), (float) hexdec( substr( $hex, 2, 2 ) ), (float) hexdec( substr( $hex, 4, 2 ) ) ];
	}

	/**
	 * Composite a colour with alpha over an opaque background.
	 *
	 * @param array{0: float, 1: float, 2: float} $foreground
	 * @param array{0: float, 1: float, 2: float} $background
	 * @return array{0: float, 1: float, 2: float}
	 */
	public static function over( array $foreground, float $alpha, array $background ): array {
		return [
			$foreground[0] * $alpha + $background[0] * ( 1 - $alpha ),
			$foreground[1] * $alpha + $background[1] * ( 1 - $alpha ),
			$foreground[2] * $alpha + $background[2] * ( 1 - $alpha ),
		];
	}

	/**
	 * What color-mix( in srgb, A p%, B ) computes to.
	 *
	 * @param array{0: float, 1: float, 2: float} $a
	 * @param array{0: float, 1: float, 2: float} $b
	 * @return array{0: float, 1: float, 2: float}
	 */
	public static function mix( array $a, float $percent_of_a, array $b ): array {
		return self::over( $a, $percent_of_a / 100, $b );
	}

	/** @param array{0: float, 1: float, 2: float} $rgb */
	public static function luminance( array $rgb ): float {
		$channels = array_map(
			static function ( float $value ): float {
				$value /= 255;

				return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * WCAG contrast ratio.
	 *
	 * @param array{0: float, 1: float, 2: float} $a
	 * @param array{0: float, 1: float, 2: float} $b
	 */
	public static function contrast( array $a, array $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}
}
