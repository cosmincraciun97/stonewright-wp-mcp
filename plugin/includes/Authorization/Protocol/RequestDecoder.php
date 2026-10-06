<?php
/**
 * Authorization foundation.
 * SPDX-License-Identifier: AGPL-3.0-or-later
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
		// JSON is already valid; tokens identify duplicate top-level member names.
		// A scan that cannot finish (for example an exhausted pattern stack) rejects the body.
		if ( false === preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/u', $json, $matches ) ) {
			throw new OAuthFault( 'invalid_request' );
		}
		$depth = 0;
		$expect_key = true;
		$seen = [];
		foreach ( $matches[0] as $token ) {
			if ( '{' === $token || '[' === $token ) {
				++$depth;
			} elseif ( '}' === $token || ']' === $token ) {
				--$depth;
			} elseif ( 1 === $depth && ',' === $token ) {
				$expect_key = true;
			} elseif ( 1 === $depth && $expect_key && '"' === $token[0] ) {
				$key = (string) json_decode( $token, true, 2, JSON_THROW_ON_ERROR );
				if ( isset( $seen[ $key ] ) ) {
					throw new OAuthFault( 'invalid_request' );
				}
				$seen[ $key ] = true;
				$expect_key = false;
			}
		}
		return get_object_vars( $object );
	}

	private function require_size( string $body, int $maximum_bytes ): void {
		if ( $maximum_bytes < 1 || strlen( $body ) > $maximum_bytes ) {
			throw new OAuthFault( 'invalid_request' );
		}
	}
}
