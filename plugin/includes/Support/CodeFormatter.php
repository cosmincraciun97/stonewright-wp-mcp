<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Security\PhpSyntaxValidator;

/**
 * Normalizes inbound code payload formatting without changing string or comment
 * semantics.
 */
final class CodeFormatter {

	/**
	 * Languages whose layout escapes may be expanded.
	 *
	 * @var array<int, string>
	 */
	private const EXPANDABLE_LANGUAGES = [ 'php', 'css', 'js', 'javascript', 'json', 'html' ];

	/**
	 * Normalize line endings, safe layout escapes, indentation, and trailing
	 * whitespace.
	 */
	public static function normalize( string $code, string $language ): string {
		$language = strtolower( trim( $language ) );
		$code     = str_replace( [ "\r\n", "\r" ], "\n", $code );

		if (
			! str_contains( $code, "\n" )
			&& in_array( $language, self::EXPANDABLE_LANGUAGES, true )
			&& 1 === preg_match( '/\\\\[nrt]/', $code )
		) {
			[ $candidate, $expanded ] = self::expand_layout_escapes( $code, $language );

			if ( $expanded && ( 'php' !== $language || self::php_is_valid( $candidate ) ) ) {
				$code = $candidate;

				if ( 'php' === $language ) {
					$reindented = self::reindent_expanded_php( $code );
					if ( self::php_is_valid( $reindented ) ) {
						$code = $reindented;
					}
				}
			}
		}

		return self::tidy_lines( $code );
	}

	/**
	 * Expand only unescaped layout sequences found outside strings and comments.
	 *
	 * @return array{string, bool}
	 */
	private static function expand_layout_escapes( string $code, string $language ): array {
		$output   = '';
		$expanded = false;
		$state    = 'code';
		$length   = strlen( $code );

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $code[ $index ];

			if ( in_array( $state, [ 'single_quote', 'double_quote', 'backtick' ], true ) ) {
				$output .= $character;

				if ( '\\' === $character && $index + 1 < $length ) {
					$output .= $code[ ++$index ];
					continue;
				}

				if (
					( 'single_quote' === $state && "'" === $character )
					|| ( 'double_quote' === $state && '"' === $character )
					|| ( 'backtick' === $state && '`' === $character )
				) {
					$state = 'code';
				}
				continue;
			}

			if ( 'line_comment' === $state ) {
				$output .= $character;
				continue;
			}

			if ( 'block_comment' === $state ) {
				if ( '*' === $character && '/' === ( $code[ $index + 1 ] ?? '' ) ) {
					$output .= '*/';
					++$index;
					$state = 'code';
					continue;
				}

				$output .= $character;
				continue;
			}

			if ( 'html_comment' === $state ) {
				if ( '-->' === substr( $code, $index, 3 ) ) {
					$output .= '-->';
					$index  += 2;
					$state   = 'code';
					continue;
				}

				$output .= $character;
				continue;
			}

			if ( 'html' === $language && '<!--' === substr( $code, $index, 4 ) ) {
				$output .= '<!--';
				$index  += 3;
				$state   = 'html_comment';
				continue;
			}

			if ( self::supports_block_comments( $language ) && '/*' === substr( $code, $index, 2 ) ) {
				$output .= '/*';
				++$index;
				$state = 'block_comment';
				continue;
			}

			if ( self::supports_line_comments( $language ) && '//' === substr( $code, $index, 2 ) ) {
				$output .= '//';
				++$index;
				$state = 'line_comment';
				continue;
			}

			if ( 'php' === $language && '#' === $character && '[' !== ( $code[ $index + 1 ] ?? '' ) ) {
				$output .= $character;
				$state   = 'line_comment';
				continue;
			}

			if ( "'" === $character ) {
				$output .= $character;
				$state   = 'single_quote';
				continue;
			}

			if ( '"' === $character ) {
				$output .= $character;
				$state   = 'double_quote';
				continue;
			}

			if ( '`' === $character && in_array( $language, [ 'php', 'js', 'javascript' ], true ) ) {
				$output .= $character;
				$state   = 'backtick';
				continue;
			}

			if ( '\\' !== $character ) {
				$output .= $character;
				continue;
			}

			$slash_count = 1;
			while ( '\\' === ( $code[ $index + $slash_count ] ?? '' ) ) {
				++$slash_count;
			}

			$escape = $code[ $index + $slash_count ] ?? '';
			if ( ! in_array( $escape, [ 'n', 'r', 't' ], true ) || 0 === $slash_count % 2 ) {
				$output .= str_repeat( '\\', $slash_count );
				$index  += $slash_count - 1;
				continue;
			}

			$output .= str_repeat( '\\', $slash_count - 1 );

			if ( 1 === $slash_count && 'r' === $escape && '\\n' === substr( $code, $index + 2, 2 ) ) {
				$output .= "\n";
				$index  += 3;
			} else {
				$output .= [ 'n' => "\n", 'r' => "\n", 't' => "\t" ][ $escape ];
				$index  += $slash_count;
			}
			$expanded = true;
		}

		return [ $output, $expanded ];
	}

	private static function supports_block_comments( string $language ): bool {
		return in_array( $language, [ 'php', 'css', 'js', 'javascript', 'json' ], true );
	}

	private static function supports_line_comments( string $language ): bool {
		return in_array( $language, [ 'php', 'js', 'javascript', 'json' ], true );
	}

	private static function php_is_valid( string $code ): bool {
		return true === PhpSyntaxValidator::validate_complete_file( $code );
	}

	/**
	 * Apply brace indentation only to line boundaries supplied by the caller.
	 *
	 * This deliberately does not split statements at semicolons or braces.
	 */
	private static function reindent_expanded_php( string $code ): string {
		if ( str_contains( $code, '<<<' ) || str_contains( $code, '?>' ) ) {
			return $code;
		}

		$lines = explode( "\n", $code );
		$depth = 0;
		$state = 'code';

		foreach ( $lines as $line_number => $line ) {
			$state_at_start = $state;
			$line_analysis  = self::analyse_php_line( $line, $state );
			$state          = $line_analysis['state'];

			if ( '' !== trim( $line ) && 'code' === $state_at_start ) {
				$indent                = max( 0, $depth - $line_analysis['leading_closes'] );
				$lines[ $line_number ] = str_repeat( "\t", $indent ) . ltrim( $line, " \t" );
			}

			$depth = max( 0, $depth + $line_analysis['opens'] - $line_analysis['closes'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return array{state: string, opens: int, closes: int, leading_closes: int}
	 */
	private static function analyse_php_line( string $line, string $initial_state ): array {
		$state          = $initial_state;
		$opens          = 0;
		$closes         = 0;
		$leading_closes = 0;
		$has_code       = false;
		$length         = strlen( $line );

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $line[ $index ];

			if ( in_array( $state, [ 'single_quote', 'double_quote', 'backtick' ], true ) ) {
				if ( '\\' === $character ) {
					++$index;
					continue;
				}
				if (
					( 'single_quote' === $state && "'" === $character )
					|| ( 'double_quote' === $state && '"' === $character )
					|| ( 'backtick' === $state && '`' === $character )
				) {
					$state = 'code';
				}
				continue;
			}

			if ( 'block_comment' === $state ) {
				if ( '*/' === substr( $line, $index, 2 ) ) {
					++$index;
					$state = 'code';
				}
				continue;
			}

			if ( '/*' === substr( $line, $index, 2 ) ) {
				++$index;
				$state = 'block_comment';
				continue;
			}

			if ( '//' === substr( $line, $index, 2 ) || ( '#' === $character && '[' !== ( $line[ $index + 1 ] ?? '' ) ) ) {
				break;
			}

			if ( "'" === $character ) {
				$state    = 'single_quote';
				$has_code = true;
				continue;
			}

			if ( '"' === $character ) {
				$state    = 'double_quote';
				$has_code = true;
				continue;
			}

			if ( '`' === $character ) {
				$state    = 'backtick';
				$has_code = true;
				continue;
			}

			if ( '{' === $character ) {
				++$opens;
				$has_code = true;
				continue;
			}

			if ( '}' === $character ) {
				++$closes;
				if ( ! $has_code ) {
					++$leading_closes;
				}
				continue;
			}

			if ( ! ctype_space( $character ) ) {
				$has_code = true;
			}
		}

		return [
			'state'          => $state,
			'opens'          => $opens,
			'closes'         => $closes,
			'leading_closes' => $leading_closes,
		];
	}

	private static function tidy_lines( string $code ): string {
		$lines = explode( "\n", $code );
		foreach ( $lines as $index => $line ) {
			$lines[ $index ] = rtrim( $line, " \t" );
		}

		$code = rtrim( implode( "\n", $lines ), "\n" );

		return '' === $code ? '' : $code . "\n";
	}
}
