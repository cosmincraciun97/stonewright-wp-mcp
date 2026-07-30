<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Detects text corruption that is valid UTF-8 but no longer valid human copy.
 */
final class TextIntegrity {

	/**
	 * @return array{path:string,code:string,value:string}|null
	 */
	public static function first_violation( mixed $value, string $path = 'value' ): ?array {
		if ( is_string( $value ) ) {
			if ( 1 !== preg_match( '//u', $value ) ) {
				return [ 'path' => $path, 'code' => 'invalid_utf8', 'value' => self::excerpt( $value ) ];
			}

			// Catches lost JSON escapes such as "pou021bi" and "u00een".
			if ( 1 === preg_match( '/u(?:00|01|02|03)[0-9a-f]{2}/i', $value ) ) {
				return [ 'path' => $path, 'code' => 'stripped_unicode_escape', 'value' => self::excerpt( $value ) ];
			}

			// Common UTF-8 interpreted as Windows-1252/Latin-1 markers.
			if ( 1 === preg_match( '/(?:Ã.|Â.|â(?:€|€™|€œ|€\x9d|€“|€”))/', $value ) ) {
				return [ 'path' => $path, 'code' => 'mojibake', 'value' => self::excerpt( $value ) ];
			}

			return null;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		foreach ( $value as $key => $item ) {
			$violation = self::first_violation( $item, $path . '.' . (string) $key );
			if ( null !== $violation ) {
				return $violation;
			}
		}

		return null;
	}

	private static function excerpt( string $value ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 160 ) : substr( $value, 0, 160 );
	}
}
