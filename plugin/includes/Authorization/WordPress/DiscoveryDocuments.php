<?php
/**
 * Well-known discovery documents (RFC 8414, RFC 9728).
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

/**
 * Serves the protected resource metadata, the authorization server metadata and its
 * OpenID configuration alias at the locations SiteProfile::discovery_document() names,
 * early in parse_request, so neither rewrite rules nor the permalink mode matter. The
 * answer is 200 application/json for any method but OPTIONS (a CORS preflight, 204);
 * the documents are public, so any origin may read them. Other paths, and every path
 * while OAuth is unavailable, fall through to WordPress. Discovery is not rate limited.
 */
final class DiscoveryDocuments {

	public function __construct( private SiteProfile $site ) {}

	/** The answer for a request, or null when the path is not a discovery document. */
	public function respond( string $method, string $request_uri ): ?OAuthReply {
		$parts = parse_url( '' === $request_uri ? '/' : $request_uri );
		if ( ! is_array( $parts ) || ! $this->site->available() ) {
			return null;
		}
		$document = $this->site->discovery_document( (string) ( $parts['path'] ?? '' ), (string) ( $parts['query'] ?? '' ) );
		if ( null === $document ) {
			return null;
		}
		$headers = [
			'Content-Type'                => 'application/json; charset=UTF-8',
			'Access-Control-Allow-Origin' => '*',
			'X-Content-Type-Options'      => 'nosniff',
		];
		if ( 'OPTIONS' === strtoupper( $method ) ) {
			return new OAuthReply(
				204,
				null,
				$headers + [
					'Access-Control-Allow-Methods' => 'GET, OPTIONS',
					'Access-Control-Allow-Headers' => 'Content-Type, MCP-Protocol-Version',
					'Access-Control-Max-Age'       => '86400',
				]
			);
		}
		$body = 'resource' === $document ? $this->site->protected_resource_metadata() : $this->site->authorization_server_metadata();
		return new OAuthReply( 200, $body, $headers );
	}

	/** The answer for the current HTTP request, or null. */
	public static function answer(): ?OAuthReply {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with fixed discovery paths.
		if ( ! str_contains( $uri, '/.well-known/' ) ) {
			return null;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with fixed method names.
		return ( new self( HttpSurface::site() ) )->respond( $method, $uri );
	}

	/** Sends a discovery document and stops on parse_request, or lets WordPress continue. */
	public static function serve(): void {
		$reply = self::answer();
		if ( null === $reply ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared with HEAD.
		$reply->send( 'HEAD' !== $method );
		exit;
	}
}
