<?php
/**
 * REST routes of the OAuth endpoints.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Security\AuditLog;

/**
 * POST stonewright/v1/oauth/{register,token,revoke,introspect}, registered only while
 * OAuth is available. Each call is limited per endpoint and connection address
 * (429 with Retry-After and {"error":"temporarily_unavailable","reason":"rate_limited"}),
 * answered by its endpoint, given X-Stonewright-Correlation-ID on errors (the audit
 * correlation id of the request) and recorded in the audit log as oauth/{endpoint}
 * without any credential. Registration, token and revocation are public client
 * endpoints; introspection requires a site administrator (manage_options), signed in
 * with a cookie and REST nonce or with an Application Password.
 */
final class OAuthRestRoutes {

	public const CORRELATION_HEADER = 'X-Stonewright-Correlation-ID';

	/** Route => [ability suffix, limiter endpoint, handler method]. */
	private const ROUTES = [
		'/oauth/register'   => [ 'register', 'registration', 'registration' ],
		'/oauth/token'      => [ 'token', 'token', 'token' ],
		'/oauth/revoke'     => [ 'revoke', 'revocation', 'revocation' ],
		'/oauth/introspect' => [ 'introspect', 'introspection', 'introspection' ],
	];

	/** Registers the routes on rest_api_init while OAuth is available. */
	public static function register(): void {
		if ( ! HttpSurface::site()->available() ) {
			return;
		}
		foreach ( self::ROUTES as $route => [ , , $handler ] ) {
			register_rest_route(
				SiteProfile::REST_NAMESPACE,
				$route,
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, $handler ],
					'permission_callback' => 'introspection' === $handler ? [ self::class, 'introspection_permission' ] : [ self::class, 'public_client_permission' ],
				]
			);
		}
	}

	public static function token( \WP_REST_Request $request ): \WP_REST_Response {
		return self::respond( '/oauth/token', $request, static fn ( OAuthRequest $oauth ): OAuthReply => ( new TokenEndpoint( AuthorizationLifecycle::storage(), HttpSurface::site() ) )->handle( $oauth ) );
	}

	public static function registration( \WP_REST_Request $request ): \WP_REST_Response {
		$storage = AuthorizationLifecycle::storage();
		return self::respond( '/oauth/register', $request, static fn ( OAuthRequest $oauth ): OAuthReply => ( new RegistrationEndpoint( $storage->clients(), HttpSurface::site(), $storage->clock() ) )->handle( $oauth ) );
	}

	public static function revocation( \WP_REST_Request $request ): \WP_REST_Response {
		return self::respond( '/oauth/revoke', $request, static fn ( OAuthRequest $oauth ): OAuthReply => ( new RevocationEndpoint( AuthorizationLifecycle::storage()->revocation() ) )->handle( $oauth ) );
	}

	public static function introspection( \WP_REST_Request $request ): \WP_REST_Response {
		return self::respond( '/oauth/introspect', $request, static fn ( OAuthRequest $oauth ): OAuthReply => ( new IntrospectionEndpoint( AuthorizationLifecycle::storage() ) )->handle( $oauth, self::introspection_permission() ) );
	}

	/** Introspection callers are site administrators. */
	public static function introspection_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Registration, token and revocation serve public OAuth clients, which prove
	 * themselves through the protocol (PKCE, codes and credentials) rather than as
	 * WordPress users. They answer only while OAuth is available.
	 */
	public static function public_client_permission(): bool {
		return HttpSurface::site()->available();
	}

	/**
	 * Serves a successful revocation with an empty body (rest_pre_serve_request).
	 *
	 * @param mixed $result  Response about to be served.
	 * @param mixed $request Current request.
	 */
	public static function serve_empty_body( mixed $served, mixed $result = null, mixed $request = null ): bool {
		if ( true === $served ) {
			return true;
		}
		if ( ! $request instanceof \WP_REST_Request || ! $result instanceof \WP_REST_Response ) {
			return (bool) $served;
		}
		$route = strtolower( rtrim( (string) $request->get_route(), '/' ) );
		return '/' . SiteProfile::REST_NAMESPACE . '/oauth/revoke' === $route && 200 === $result->get_status() && null === $result->get_data();
	}

	/** @param \Closure(OAuthRequest): OAuthReply $handler */
	private static function respond( string $route, \WP_REST_Request $request, \Closure $handler ): \WP_REST_Response {
		[ $ability, $endpoint ] = self::ROUTES[ $route ];
		$oauth = OAuthRequest::from_rest( $request );
		$admission = HttpSurface::limiter()->admit( $endpoint, $oauth->address );
		if ( ! $admission['allowed'] ) {
			$headers = [
				'Retry-After'   => (string) $admission['retry_after'],
				'Cache-Control' => 'no-store',
			];
			if ( 'token' === $endpoint ) {
				$headers += [
					'Pragma'                        => 'no-cache',
					TokenEndpoint::CONSUMED_HEADER => '0',
				];
			}
			$reply = new OAuthReply(
				429,
				[
					'error'  => 'temporarily_unavailable',
					'reason' => 'rate_limited',
				],
				$headers
			);
		} else {
			$reply = $handler( $oauth );
		}
		if ( $reply->status >= 400 ) {
			$reply = $reply->with_headers( [ self::CORRELATION_HEADER => AuditLog::request_id() ] );
		}
		HttpSurface::audit( 'oauth/' . $ability, $reply );
		return $reply->to_rest();
	}
}
