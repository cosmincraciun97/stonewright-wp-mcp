<?php
/**
 * Origin validation of the Stonewright MCP routes.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * The MCP Streamable HTTP transport asks a server to validate the Origin header of every
 * request, so a web page cannot drive the server from a visitor's browser (DNS rebinding
 * and cross-site requests). The routes mcp/stonewright and mcp/stonewright-oauth are checked
 * before they run (rest_pre_dispatch, priority 1, ahead of the bearer guard of the OAuth route):
 *
 * - a request without an Origin header, or with an empty one, is not touched: command-line
 *   and server-side clients do not send one;
 * - a request whose Origin is the origin of the site's home URL or site URL (scheme, host and
 *   port) passes;
 * - a request whose Origin is listed by the stonewright_mcp_allowed_origins filter passes;
 * - any other Origin, including the opaque "null", is answered with 403 and a JSON-RPC error
 *   body without a request id.
 *
 * The check does not authenticate anything: credentials and permissions are still required
 * after it. Other routes are not affected.
 */
final class McpOriginGuard {

	/**
	 * Filter: extra origins that may call the MCP routes from a browser, as a list of
	 * "scheme://host[:port]" strings. Receives the list (empty by default) and the request.
	 * Entries that are not a single origin are ignored: no wildcard, no path, and not "null".
	 */
	public const ALLOWED_ORIGINS_FILTER = 'stonewright_mcp_allowed_origins';

	/** Runs before the bearer guard of the OAuth route and the REST audit, which use priority 5. */
	public const PRIORITY = 1;

	/** JSON-RPC code for a refused request; the MCP adapter maps it to HTTP 403. */
	private const ERROR_CODE = -32008;

	private const ERROR_MESSAGE = 'Permission denied: Origin not allowed.';

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', [ self::class, 'guard' ], self::PRIORITY, 3 );
	}

	/**
	 * REST routes of the Stonewright MCP servers.
	 *
	 * @return list<string>
	 */
	public static function mcp_routes(): array {
		$namespace = '/' . ServerRegistration::ROUTE_NAMESPACE . '/';
		return [ $namespace . ServerRegistration::ROUTE, $namespace . ServerRegistration::OAUTH_ROUTE ];
	}

	/**
	 * Refuses a request to an MCP route whose Origin is present and not allowed (rest_pre_dispatch).
	 *
	 * @param mixed $result  An earlier answer, or null.
	 * @param mixed $server  The REST server.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public static function guard( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if ( null !== $result || ! $request instanceof \WP_REST_Request || ! self::is_mcp_route( (string) $request->get_route() ) ) {
			return $result;
		}
		$header = $request->get_header( 'origin' );
		$origin = is_string( $header ) ? trim( $header ) : '';
		if ( '' === $origin || self::is_allowed( $origin, $request ) ) {
			return $result;
		}
		return self::refusal();
	}

	/**
	 * Whether a REST route, with or without the REST prefix, is one of the MCP routes.
	 */
	public static function is_mcp_route( string $route ): bool {
		$route  = '/' . ltrim( strtolower( trim( $route ) ), '/' );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? '/' . trim( strtolower( (string) rest_get_url_prefix() ), '/' ) : '/wp-json';
		foreach ( [ '/index.php' . $prefix, $prefix ] as $lead ) {
			if ( str_starts_with( $route, $lead . '/' ) ) {
				$route = substr( $route, strlen( $lead ) );
				break;
			}
		}
		$route = rtrim( $route, '/' );
		foreach ( self::mcp_routes() as $mcp_route ) {
			if ( $mcp_route === $route || str_starts_with( $route, $mcp_route . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The origins that may call the MCP routes: the site's own, then the operator's list.
	 *
	 * @return list<string>
	 */
	public static function allowed_origins( ?\WP_REST_Request $request = null ): array {
		$allowed = [];
		foreach ( [ home_url( '/' ), get_site_url( null, '/' ) ] as $url ) {
			$origin = self::origin_of_url( (string) $url );
			if ( null !== $origin ) {
				$allowed[] = $origin;
			}
		}

		$extra = apply_filters( self::ALLOWED_ORIGINS_FILTER, [], $request );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $value ) {
				$origin = is_string( $value ) ? self::normalize_origin( $value ) : null;
				if ( null !== $origin && ! in_array( $origin, $allowed, true ) ) {
					$allowed[] = $origin;
				}
			}
		}

		return $allowed;
	}

	/**
	 * The canonical "scheme://host[:port]" of an origin, or null when the value is not exactly
	 * one origin. Scheme and host are lower-cased, an internationalised host becomes its ASCII
	 * form, the default port of http and https is dropped, and one trailing slash is tolerated.
	 */
	public static function normalize_origin( string $value ): ?string {
		$value = trim( $value );
		if ( 1 !== preg_match( '#^([a-z][a-z0-9+.\-]*)://(\[[0-9a-f:.]+\]|[^\s/\\\\?\#@:,\[\]*]+)(?::([0-9]{1,5}))?/?$#iD', $value, $match ) ) {
			return null;
		}

		$scheme = strtolower( $match[1] );
		$host   = strtolower( $match[2] );
		if ( ! str_starts_with( $host, '[' ) ) {
			$host = self::ascii_host( $host );
			if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9._\-]*[a-z0-9])?$/D', $host ) ) {
				return null;
			}
		}

		$port = isset( $match[3] ) && '' !== $match[3] ? (int) $match[3] : 0;
		if ( $port > 65535 || ( isset( $match[3] ) && '' !== $match[3] && 0 === $port ) ) {
			return null;
		}
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}

		return $scheme . '://' . $host . ( $port > 0 ? ':' . $port : '' );
	}

	private static function is_allowed( string $origin, \WP_REST_Request $request ): bool {
		$normalized = self::normalize_origin( $origin );
		return null !== $normalized && in_array( $normalized, self::allowed_origins( $request ), true );
	}

	/** The origin of one of the site's own URLs, or null when the URL has no scheme and host. */
	private static function origin_of_url( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return self::normalize_origin( $parts['scheme'] . '://' . $parts['host'] . $port );
	}

	private static function ascii_host( string $host ): string {
		if ( 1 !== preg_match( '/[^\x20-\x7e]/', $host ) || ! function_exists( 'idn_to_ascii' ) ) {
			return $host;
		}
		$flags   = defined( 'IDNA_DEFAULT' ) ? (int) constant( 'IDNA_DEFAULT' ) : 0;
		$variant = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? (int) constant( 'INTL_IDNA_VARIANT_UTS46' ) : 1;
		$ascii   = idn_to_ascii( $host, $flags, $variant );
		return is_string( $ascii ) && '' !== $ascii ? strtolower( $ascii ) : $host;
	}

	private static function refusal(): \WP_REST_Response {
		$response = new \WP_REST_Response(
			[
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => [
					'code'    => self::ERROR_CODE,
					'message' => self::ERROR_MESSAGE,
				],
			],
			403
		);
		$response->header( 'Vary', 'Origin' );
		return $response;
	}
}
