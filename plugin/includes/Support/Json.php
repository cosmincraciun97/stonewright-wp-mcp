<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Helpers for working with JSON values that come in via MCP / REST.
 */
final class Json {

	public static function encode( mixed $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '{}' : $encoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function decode( string $json ): array {
		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			return [];
		}
		return is_array( $decoded ) ? $decoded : [];
	}

	public static function hash( mixed $value ): string {
		return hash( 'sha256', self::encode( $value ) );
	}
}
