<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * UTF-8 string normalization utilities.
 *
 * Applied at the ability-kernel boundary so all execute() inputs are
 * guaranteed to be valid UTF-8 regardless of the HTTP client's encoding
 * behaviour. This is especially important on Windows where PowerShell's
 * ConvertTo-Json emits \uXXXX escapes — PHP json_decode handles them
 * correctly, but broken-encoding strings from other paths are sanitized here.
 *
 * @package Stonewright\WpMcp\Support
 */
final class Utf8 {

	/**
	 * Recursively ensures every string in a nested array is valid UTF-8.
	 *
	 * Strings with broken encoding are re-encoded via mb_convert_encoding.
	 * Non-string, non-array values are returned unchanged.
	 *
	 * @param mixed $value Any value — strings are sanitized, arrays are recursed.
	 * @return mixed Sanitized value of the same type.
	 */
	public static function deep_sanitize( mixed $value ): mixed {
		if ( is_string( $value ) ) {
			if ( ! mb_check_encoding( $value, 'UTF-8' ) ) {
				$value = mb_convert_encoding( $value, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8' );
			}
			return $value;
		}

		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'deep_sanitize' ], $value );
		}

		return $value;
	}
}
