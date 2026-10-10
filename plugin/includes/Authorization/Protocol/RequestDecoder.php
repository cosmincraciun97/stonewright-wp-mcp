<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\Protocol;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;

/** Bounded wire decoding; it does not authenticate or mutate a client. */
final class RequestDecoder {

	public function form( string $body, int $maximum_bytes ): ParameterBag {
		$this->require_size( $body, $maximum_bytes );
		$parameters = [];
		if ( '' === $body ) {
			return new ParameterBag( [] );
		}
		foreach ( explode( '&', $body ) as $pair ) {
			if ( preg_match( '/%(?![0-9a-fA-F]{2})/', $pair ) ) {
				throw new OAuthFault( 'invalid_request' );
			}
			$parts = explode( '=', $pair, 2 );
			$name = urldecode( $parts[0] );
			if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_.~-]*$/D', $name ) ) {
				throw new OAuthFault( 'invalid_request' );
			}
			$parameters[ $name ][] = urldecode( $parts[1] ?? '' );
		}
		return new ParameterBag( $parameters );
	}

	/**
	 * @return array<string, mixed>
	 * @throws OAuthFault When the protocol input is invalid.
	 */
	public function registration( string $json, int $maximum_bytes ): array {
		$this->require_size( $json, $maximum_bytes );
		try {
			$object = json_decode( $json, false, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new OAuthFault( 'invalid_request' );
		}
		if ( ! $object instanceof \stdClass ) {
			throw new OAuthFault( 'invalid_request' );
		}
		if ( self::repeats_top_level_member( $json ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		return get_object_vars( $object );
	}

	/**
	 * Whether the top-level object of already validated JSON repeats a member name.
	 *
	 * One linear pass that does not use the pattern engine, so a long value cannot
	 * exhaust a backtracking or JIT stack: string contents are skipped with strcspn and
	 * only top-level member names are decoded (escapes included) and compared.
	 *
	 * @throws OAuthFault When a string is not terminated.
	 */
	private static function repeats_top_level_member( string $json ): bool {
		$length = strlen( $json );
		$depth = 0;
		$expect_key = false;
		$seen = [];
		for ( $index = 0; $index < $length; ++$index ) {
			$char = $json[ $index ];
			if ( '"' === $char ) {
				$end = self::string_end( $json, $index, $length );
				if ( 1 === $depth && $expect_key ) {
					try {
						$key = (string) json_decode( substr( $json, $index, $end - $index + 1 ), true, 2, JSON_THROW_ON_ERROR );
					} catch ( \JsonException $exception ) {
						throw new OAuthFault( 'invalid_request' );
					}
					if ( isset( $seen[ $key ] ) ) {
						return true;
					}
					$seen[ $key ] = true;
					$expect_key = false;
				}
				$index = $end;
				continue;
			}
			if ( '{' === $char || '[' === $char ) {
				++$depth;
				if ( 1 === $depth ) {
					$expect_key = '{' === $char;
				}
			} elseif ( '}' === $char || ']' === $char ) {
				--$depth;
			} elseif ( ',' === $char && 1 === $depth ) {
				$expect_key = true;
			}
		}
		return false;
	}

	/**
	 * Offset of the quote that closes the string opened at $start.
	 *
	 * @throws OAuthFault When the string is not terminated.
	 */
	private static function string_end( string $json, int $start, int $length ): int {
		$index = $start + 1;
		while ( $index < $length ) {
			$index += strcspn( $json, '"\\', $index );
			if ( $index >= $length ) {
				break;
			}
			if ( '\\' === $json[ $index ] ) {
				$index += 2;
				continue;
			}
			return $index;
		}
		throw new OAuthFault( 'invalid_request' );
	}

	private function require_size( string $body, int $maximum_bytes ): void {
		if ( $maximum_bytes < 1 || strlen( $body ) > $maximum_bytes ) {
			throw new OAuthFault( 'invalid_request' );
		}
	}
}
