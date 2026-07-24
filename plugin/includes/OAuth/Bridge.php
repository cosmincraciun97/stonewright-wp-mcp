<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/bridge.php
 * Source SHA-256: 0f73fa3bb6d05d0384e8ae524a8c1e775ee38b1305c803702cafa4c39f7496a0
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Bridge WordPress REST requests and League OAuth PSR-7 messages.
 */
final class Bridge {

	public static function to_psr7( \WP_REST_Request $request ): ServerRequestInterface {
		$headers = [];
		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ $name ] = $values;
		}

		$psr = new ServerRequest( $request->get_method(), rest_url( $request->get_route() ), $headers );
		$psr = $psr
			->withQueryParams( $request->get_query_params() )
			->withParsedBody( $request->get_body_params() )
			->withCookieParams( $_COOKIE );

		$body = $request->get_body();
		if ( '' !== $body ) {
			$psr = $psr->withBody( Stream::create( $body ) );
		}

		return $psr;
	}

	public static function from_psr7( ResponseInterface $response ): \WP_REST_Response {
		$body = (string) $response->getBody();
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			$data = [ 'raw' => $body ];
		}

		$output = new \WP_REST_Response( $data, $response->getStatusCode() );
		foreach ( $response->getHeaders() as $name => $values ) {
			$output->header( (string) $name, implode( ', ', $values ) );
		}
		return $output;
	}

	public static function new_psr7_response(): ResponseInterface {
		return ( new Psr17Factory() )->createResponse( 200 );
	}

	public static function psr7_from_globals(): ServerRequestInterface {
		$method   = (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
		$is_https = '' !== (string) ( $_SERVER['HTTPS'] ?? '' );
		$uri      = ( $is_https ? 'https' : 'http' )
			. '://'
			. (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' )
			. (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$headers  = [];

		foreach ( $_SERVER as $key => $value ) {
			if ( ! str_starts_with( $key, 'HTTP_' ) || ! is_string( $value ) ) {
				continue;
			}
			$name             = str_replace( '_', '-', strtolower( substr( $key, 5 ) ) );
			$headers[ $name ] = [ $value ];
		}

		return new ServerRequest( $method, $uri, $headers );
	}
}
