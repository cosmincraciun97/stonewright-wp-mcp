<?php
/**
 * RS256 compact JSON Web Signatures.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/**
 * Signs and verifies JWTs with RSASSA-PKCS1-v1_5 SHA-256 (RFC 7515, RFC 7518). The
 * header is exactly {"typ":"JWT","alg":"RS256"}; verification accepts only RS256, so
 * "none" and HMAC algorithms are refused before any key is used.
 */
final class JsonWebSignature {

	public const MAXIMUM_LENGTH = 8192;

	public static function looks_like( string $token ): bool {
		return strlen( $token ) <= self::MAXIMUM_LENGTH && 1 === preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token );
	}

	/**
	 * @param array<string, mixed> $claims
	 * @throws OAuthFault When signing fails (server_error).
	 */
	public static function sign( array $claims, string $private_key ): string {
		$input = self::encode( [ 'typ' => 'JWT', 'alg' => 'RS256' ] ) . '.' . self::encode( $claims );
		$signature = '';
		if ( ! openssl_sign( $input, $signature, $private_key, OPENSSL_ALGO_SHA256 ) || ! is_string( $signature ) ) {
			throw new OAuthFault( 'server_error', 500 );
		}
		return $input . '.' . self::base64url( $signature );
	}

	/**
	 * Claims of a token whose RS256 signature verifies with $public_key, else null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function verify( string $token, string $public_key ): ?array {
		if ( ! self::looks_like( $token ) ) {
			return null;
		}
		[ $header_part, $claims_part, $signature_part ] = explode( '.', $token );
		$header = self::decode( $header_part );
		if ( null === $header || 'RS256' !== ( $header['alg'] ?? null ) || isset( $header['crit'] ) || ( isset( $header['typ'] ) && ( ! is_string( $header['typ'] ) || 'JWT' !== strtoupper( $header['typ'] ) ) ) ) {
			return null;
		}
		$signature = self::base64url_decode( $signature_part );
		if ( null === $signature ) {
			return null;
		}
		$verified = openssl_verify( $header_part . '.' . $claims_part, $signature, $public_key, OPENSSL_ALGO_SHA256 );
		while ( false !== openssl_error_string() ) {
			continue;
		}
		return 1 === $verified ? self::decode( $claims_part ) : null;
	}

	/**
	 * @param array<string, mixed> $value
	 * @throws OAuthFault When the value cannot be encoded (server_error).
	 */
	private static function encode( array $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			throw new OAuthFault( 'server_error', 500 );
		}
		return self::base64url( $json );
	}

	/** @return array<string, mixed>|null */
	private static function decode( string $part ): ?array {
		$json = self::base64url_decode( $part );
		if ( null === $json ) {
			return null;
		}
		$value = json_decode( $json, true, 16 );
		return is_array( $value ) && [] !== $value && ! array_is_list( $value ) ? $value : null;
	}

	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $text ): ?string {
		$padded = strtr( $text, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );
		$decoded = base64_decode( $padded, true );
		return false === $decoded ? null : $decoded;
	}
}
