<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

/**
 * URL safety helper for Gutenberg block attributes.
 *
 * Strips any URL whose scheme is not http or https and returns the
 * esc_url_raw-sanitized form of safe URLs.  Returns null for unsafe,
 * empty, or unparseable values so callers can omit the attribute
 * entirely rather than embed a dangerous or meaningless value.
 */
final class UrlGuard {

	/**
	 * Validate and sanitize a URL for use in a block attribute.
	 *
	 * Returns the sanitized URL string on success, or null when:
	 *   - the value is empty,
	 *   - the scheme is missing,
	 *   - the scheme is not http or https (e.g. javascript:, data:).
	 *
	 * @param string $url Raw URL from the DesignSpec node.
	 * @return string|null Sanitized URL, or null if unsafe/invalid.
	 */
	public static function safe_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) ) {
			return null;
		}
		$scheme = strtolower( $parsed['scheme'] );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return null;
		}
		return esc_url_raw( $url, [ 'http', 'https' ] );
	}
}
