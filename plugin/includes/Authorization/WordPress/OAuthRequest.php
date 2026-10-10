<?php
/**
 * An HTTP request to an OAuth endpoint, independent of the REST server.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * Method, lowercase headers, the raw body (form or JSON, decoded by the protocol layer
 * so repeated parameters stay visible) and the address the request counts as. The address
 * is the server-observed REMOTE_ADDR; a forwarding header is read only when REMOTE_ADDR is
 * a configured trusted proxy (see ClientAddress).
 */
final class OAuthRequest {

	/** @var array<string, string> */
	public readonly array $headers;

	/** @param array<string, string> $headers Header name => value; names are lowercased with dashes. */
	public function __construct( public readonly string $method, array $headers, public readonly string $body, public readonly string $address ) {
		$normalized = [];
		foreach ( $headers as $name => $value ) {
			$normalized[ strtolower( str_replace( '_', '-', (string) $name ) ) ] = (string) $value;
		}
		$this->headers = $normalized;
	}

	public static function from_rest( \WP_REST_Request $request ): self {
		$headers = [];
		foreach ( (array) $request->get_headers() as $name => $values ) {
			$headers[ (string) $name ] = is_array( $values ) ? implode( ', ', array_map( 'strval', $values ) ) : (string) $values;
		}
		return new self( strtoupper( (string) $request->get_method() ), $headers, (string) $request->get_body(), self::remote_address() );
	}

	public function header( string $name ): ?string {
		return $this->headers[ strtolower( str_replace( '_', '-', $name ) ) ] ?? null;
	}

	/** Lowercase media type of the body without parameters, or an empty string. */
	public function media_type(): string {
		return strtolower( trim( explode( ';', (string) $this->header( 'content-type' ) )[0] ) );
	}

	/** The address the current request counts as (see ClientAddress), or an empty string. */
	public static function remote_address(): string {
		return ClientAddress::resolve();
	}
}
