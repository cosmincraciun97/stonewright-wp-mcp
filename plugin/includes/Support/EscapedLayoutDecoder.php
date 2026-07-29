<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Language-aware, all-or-nothing escaped layout decoder.
 *
 * @internal Use CodeFormatter as the public normalization API.
 */
final class EscapedLayoutDecoder {

	public static function decode( string $code, string $language ): ?string {
		switch ( $language ) {
			case 'php':
				return self::decode_php( $code );
			case 'js':
			case 'javascript':
				return self::decode_javascript( $code );
			case 'json':
				return self::decode_json( $code );
			case 'css':
				return self::decode_css( $code );
			case 'html':
				return self::decode_html( $code );
			default:
				return null;
		}
	}

	private static function decode_php( string $code ): ?string {
		// Heredoc and nowdoc need a real PHP parser. Partial decoding is unsafe.
		if ( str_contains( $code, '<<<' ) ) {
			return null;
		}

		return self::decode_c_like(
			$code,
			[ "'", '"', '`' ],
			true,
			true,
			true,
			false
		);
	}

	private static function decode_javascript( string $code ): ?string {
		// Template interpolation re-enters JavaScript grammar. Preserve the
		// complete payload until a parser can certify those boundaries.
		if ( str_contains( $code, '${' ) ) {
			return null;
		}

		return self::decode_c_like(
			$code,
			[ "'", '"', '`' ],
			true,
			true,
			false,
			true
		);
	}

	private static function decode_json( string $code ): ?string {
		return self::decode_c_like(
			$code,
			[ '"' ],
			false,
			false,
			false,
			true
		);
	}

	private static function decode_css( string $code ): ?string {
		return self::decode_c_like(
			$code,
			[ "'", '"' ],
			false,
			true,
			false,
			false
		);
	}

	/**
	 * Decode PHP/JavaScript/JSON/CSS with explicit grammar capabilities.
	 *
	 * A null result means the scanner encountered ambiguous grammar after zero
	 * or more safe candidates. The caller must discard the entire candidate.
	 *
	 * @param array<int, string> $quotes
	 */
	private static function decode_c_like(
		string $code,
		array $quotes,
		bool $line_comments,
		bool $block_comments,
		bool $hash_comments,
		bool $abort_on_non_comment_slash
	): ?string {
		$output  = '';
		$state   = 'code';
		$quote   = '';
		$length  = strlen( $code );
		$decoded = false;

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $code[ $index ];

			if ( 'quote' === $state ) {
				$output .= $character;
				if ( '\\' === $character && $index + 1 < $length ) {
					$output .= $code[ ++$index ];
				} elseif ( $quote === $character ) {
					$state = 'code';
				}
				continue;
			}

			if ( 'block_comment' === $state ) {
				if ( '*/' === substr( $code, $index, 2 ) ) {
					$output .= '*/';
					++$index;
					$state = 'code';
				} else {
					$output .= $character;
				}
				continue;
			}

			if ( 'line_comment' === $state ) {
				$escape = self::layout_escape_at( $code, $index );
				if ( null === $escape ) {
					return null;
				}
				if ( false !== $escape && in_array( $escape['kind'], [ 'n', 'r' ], true ) ) {
					$output .= "\n";
					$index  += $escape['length'] - 1;
					$state   = 'code';
					$decoded = true;
					continue;
				}

				$output .= $character;
				continue;
			}

			if ( $block_comments && '/*' === substr( $code, $index, 2 ) ) {
				$output .= '/*';
				++$index;
				$state = 'block_comment';
				continue;
			}

			if ( $line_comments && '//' === substr( $code, $index, 2 ) ) {
				$output .= '//';
				++$index;
				$state = 'line_comment';
				continue;
			}

			if ( $hash_comments && '#' === $character && '[' !== ( $code[ $index + 1 ] ?? '' ) ) {
				$output .= '#';
				$state   = 'line_comment';
				continue;
			}

			if ( in_array( $character, $quotes, true ) ) {
				$output .= $character;
				$quote   = $character;
				$state   = 'quote';
				continue;
			}

			if ( '/' === $character && $abort_on_non_comment_slash ) {
				return null;
			}

			$escape = self::layout_escape_at( $code, $index );
			if ( null === $escape ) {
				return null;
			}
			if ( false === $escape ) {
				$output .= $character;
				continue;
			}

			$output .= $escape['replacement'];
			$index  += $escape['length'] - 1;
			$decoded = true;
		}

		return $decoded ? $output : $code;
	}

	private static function decode_html( string $code ): ?string {
		// Raw elements contain separate languages. Do not partially decode them.
		if ( 1 === preg_match( '/<\s*\/?\s*(?:script|style)\b/i', $code ) ) {
			return null;
		}

		$output  = '';
		$state   = 'text';
		$quote   = '';
		$length  = strlen( $code );
		$decoded = false;

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $code[ $index ];

			if ( 'comment' === $state ) {
				if ( '-->' === substr( $code, $index, 3 ) ) {
					$output .= '-->';
					$index  += 2;
					$state   = 'text';
				} else {
					$output .= $character;
				}
				continue;
			}

			if ( 'attribute' === $state ) {
				$output .= $character;
				if ( $quote === $character ) {
					$state = 'tag';
				}
				continue;
			}

			if ( 'unquoted_attribute' === $state ) {
				$output .= $character;
				if ( '>' === $character ) {
					$state = 'text';
				} elseif ( ctype_space( $character ) ) {
					$state = 'tag';
				}
				continue;
			}

			if ( 'before_attribute_value' === $state ) {
				$output .= $character;
				if ( ctype_space( $character ) ) {
					continue;
				}
				if ( in_array( $character, [ "'", '"' ], true ) ) {
					$quote = $character;
					$state = 'attribute';
				} elseif ( '>' === $character ) {
					$state = 'text';
				} else {
					$state = 'unquoted_attribute';
				}
				continue;
			}

			if ( 'text' === $state && '<!--' === substr( $code, $index, 4 ) ) {
				$output .= '<!--';
				$index  += 3;
				$state   = 'comment';
				continue;
			}

			if ( 'text' === $state && '<' === $character ) {
				$output .= '<';
				$state   = 'tag';
				continue;
			}

			if ( 'tag' === $state && '>' === $character ) {
				$output .= '>';
				$state   = 'text';
				continue;
			}

			if ( 'tag' === $state && in_array( $character, [ "'", '"' ], true ) ) {
				$output .= $character;
				$quote   = $character;
				$state   = 'attribute';
				continue;
			}

			if ( 'tag' === $state && '=' === $character ) {
				$output .= '=';
				$state   = 'before_attribute_value';
				continue;
			}

			$escape = self::layout_escape_at( $code, $index );
			if ( null === $escape ) {
				return null;
			}
			if ( false === $escape ) {
				$output .= $character;
				continue;
			}

			$output .= $escape['replacement'];
			$index  += $escape['length'] - 1;
			$decoded = true;
		}

		return $decoded ? $output : $code;
	}

	/**
	 * @return array{kind: string, length: int, replacement: string}|false|null
	 */
	private static function layout_escape_at( string $code, int $index ): array|false|null {
		if ( '\\' !== ( $code[ $index ] ?? '' ) ) {
			return false;
		}

		$slash_count = 1;
		while ( '\\' === ( $code[ $index + $slash_count ] ?? '' ) ) {
			++$slash_count;
		}

		$kind = $code[ $index + $slash_count ] ?? '';
		if ( ! in_array( $kind, [ 'n', 'r', 't' ], true ) ) {
			return false;
		}

		// Multiple slashes can mean escaped data or layout preceded by literal
		// slashes. That distinction belongs to the caller, so preserve all of it.
		if ( 1 !== $slash_count ) {
			return null;
		}

		if ( 'r' === $kind && '\\n' === substr( $code, $index + 2, 2 ) ) {
			return [
				'kind'        => 'r',
				'length'      => 4,
				'replacement' => "\n",
			];
		}

		return [
			'kind'        => $kind,
			'length'      => 2,
			'replacement' => 't' === $kind ? "\t" : "\n",
		];
	}
}
