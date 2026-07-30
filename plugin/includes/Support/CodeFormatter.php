<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Security\PhpSyntaxValidator;

/**
 * Normalizes inbound code formatting without guessing caller intent.
 */
final class CodeFormatter {

	/**
	 * Normalize physical layout and, only when explicitly requested, decode
	 * escaped layout in grammar regions where doing so is unambiguous.
	 */
	public static function normalize(
		string $code,
		string $language,
		bool $decode_escaped_layout = false
	): string {
		$language = strtolower( trim( $language ) );

		if (
			$decode_escaped_layout
			&& ! str_contains( $code, "\n" )
			&& ! str_contains( $code, "\r" )
			&& 1 === preg_match( '/\\\\[nrt]/', $code )
		) {
			$candidate = EscapedLayoutDecoder::decode( $code, $language );

			if (
				null !== $candidate
				&& ( 'php' !== $language || self::php_is_valid( $candidate ) )
				&& ( 'json' !== $language || self::json_is_valid( $candidate ) )
			) {
				$code = $candidate;
			}
		}

		return self::tidy_physical_layout( $code, $language );
	}

	private static function php_is_valid( string $code ): bool {
		return true === PhpSyntaxValidator::validate_complete_file( $code );
	}

	private static function json_is_valid( string $code ): bool {
		json_decode( $code );
		return JSON_ERROR_NONE === json_last_error();
	}

	private static function tidy_physical_layout( string $code, string $language ): string {
		if ( '' === $code ) {
			return '';
		}

		// Protected grammar requires a real parser before physical whitespace can
		// be changed safely. The file newline is the only canonicalization here.
		if ( self::has_protected_grammar( $code, $language ) ) {
			return str_ends_with( $code, "\n" ) ? $code : $code . "\n";
		}

		$code  = str_replace( [ "\r\n", "\r" ], "\n", $code );
		$lines = explode( "\n", $code );
		foreach ( $lines as $index => $line ) {
			$lines[ $index ] = rtrim( $line, " \t" );
		}

		$code = rtrim( implode( "\n", $lines ), "\n" );
		return '' === $code ? '' : $code . "\n";
	}

	private static function has_protected_grammar( string $code, string $language ): bool {
		switch ( $language ) {
			case 'php':
				return 1 === preg_match( '/[\'"`]|\/\/|#|\/\*|<<</', $code );
			case 'js':
			case 'javascript':
				return 1 === preg_match( '/[\'"`\/]/', $code );
			case 'json':
				return str_contains( $code, '"' );
			case 'css':
				return 1 === preg_match( '/[\'"]|\/\*/', $code );
			case 'html':
				return true;
			default:
				return false;
		}
	}
}
