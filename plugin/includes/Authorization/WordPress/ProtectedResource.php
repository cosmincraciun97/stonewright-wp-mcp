<?php
/**
 * Bearer protection of the OAuth MCP route.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Protocol\OAuthResponses;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\Logger;

/**
 * The route mcp/stonewright-oauth accepts only a bearer access credential in the Authorization
 * header (RFC 6750 section 2.1); Basic credentials, cookies and query parameters are
 * not accepted there. The Application Password route mcp/stonewright is not affected.
 *
 * While OAuth is available, every request to the route except a CORS preflight is
 * checked before dispatch (rest_pre_dispatch):
 * - no bearer credential: 401, WWW-Authenticate: Bearer resource_metadata="...",
 *   scope="mcp", body {"code":"rest_oauth_required","message":"OAuth authentication required."};
 * - a refused credential (AccessTokenValidator): 401 with error="invalid_token" added
 *   and body code rest_oauth_error;
 * - an accepted credential signs in its subject for this request and marks the request,
 *   and the MCP transport's permission callback (permit()) admits only marked requests.
 * A request that never reached that check because WordPress refused its credentials
 * first gets the same no-credential challenge after dispatch (rest_post_dispatch).
 * While OAuth is unavailable nothing is challenged and permit() refuses everyone, so
 * WordPress answers with its own 401.
 */
final class ProtectedResource {

	public const ROUTE = '/mcp/stonewright-oauth';

	/** @var \WeakMap<\WP_REST_Request, int>|null Requests whose bearer credential was accepted => subject. */
	private static ?\WeakMap $accepted = null;

	public function __construct( private SiteProfile $site, private AccessTokenValidator $validator ) {}

	/**
	 * Challenges, or signs in the credential's subject, before the route runs (rest_pre_dispatch).
	 *
	 * @param mixed $result  An earlier answer, or null.
	 * @param mixed $server  The REST server.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public static function guard( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if ( null !== $result || ! self::applies_to( $request ) ) {
			return $result;
		}
		$site = HttpSurface::site();
		if ( ! $site->available() ) {
			return $result;
		}
		try {
			$guard = new self( $site, AuthorizationLifecycle::storage()->validator() );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_resource_check_failed', [ 'error_class' => get_class( $failure ) ] );
			return self::challenge( $site, 'invalid_token' );
		}
		return $guard->check( $request ) ?? $result;
	}

	/**
	 * Gives a request refused before the bearer check the challenge too (rest_post_dispatch).
	 *
	 * @param mixed $response The response.
	 * @param mixed $server   The REST server.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public static function finish( mixed $response, mixed $server = null, mixed $request = null ): mixed {
		if ( ! self::applies_to( $request ) || null !== self::subject( $request ) || self::is_challenge( $response ) ) {
			return $response;
		}
		$site = HttpSurface::site();
		if ( ! $site->available() ) {
			return $response;
		}
		return self::challenge( $site, null );
	}

	/** Transport permission callback of the OAuth MCP server. */
	public static function permit( mixed $request = null ): bool {
		if ( ! $request instanceof \WP_REST_Request || ! HttpSurface::site()->available() ) {
			return false;
		}
		$subject = self::subject( $request );
		return null !== $subject && get_current_user_id() === $subject && Permissions::user_can_use_mcp( $subject );
	}

	/** The challenge for a refused request, or null after signing in the credential's subject. */
	public function check( \WP_REST_Request $request ): ?\WP_REST_Response {
		$header = $request->get_header( 'authorization' );
		$token = self::bearer_token( is_string( $header ) && '' !== $header ? $header : self::authorization_header() );
		if ( null === $token ) {
			return self::challenge( $this->site, null );
		}
		try {
			$grant = $this->validator->validate( $token );
		} catch ( OAuthFault $refused ) {
			if ( 'server_error' === $refused->error() ) {
				Logger::warning( 'oauth_resource_keys_unavailable' );
			}
			return self::challenge( $this->site, 'invalid_token' );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_resource_check_failed', [ 'error_class' => get_class( $failure ) ] );
			return self::challenge( $this->site, 'invalid_token' );
		}
		$subject = (int) $grant['subject_key'];
		wp_set_current_user( $subject );
		self::$accepted ??= new \WeakMap();
		self::$accepted[ $request ] = $subject;
		return null;
	}

	/** Whether a REST route (with or without the REST prefix) is the OAuth MCP route. */
	public static function is_protected_route( string $route ): bool {
		$route = '/' . ltrim( strtolower( trim( $route ) ), '/' );
		$prefix = function_exists( 'rest_get_url_prefix' ) ? '/' . trim( strtolower( (string) rest_get_url_prefix() ), '/' ) : '/wp-json';
		foreach ( [ '/index.php' . $prefix, $prefix ] as $lead ) {
			if ( str_starts_with( $route, $lead . '/' ) ) {
				$route = substr( $route, strlen( $lead ) );
				break;
			}
		}
		$route = rtrim( $route, '/' );
		return self::ROUTE === $route || str_starts_with( $route, self::ROUTE . '/' );
	}

	/** The Authorization header as the web server passed it, or an empty string. */
	public static function authorization_header(): string {
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $name ) {
			$value = isset( $_SERVER[ $name ] ) ? trim( (string) $_SERVER[ $name ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed by bearer_token(); never echoed.
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/** The credential of a "Bearer <token>" header (RFC 6750 b64token), or null. */
	public static function bearer_token( string $header ): ?string {
		return 1 === preg_match( '/^\s*Bearer\s+([A-Za-z0-9\-._~+\/]+=*)\s*$/iD', $header, $match ) ? $match[1] : null;
	}

	/**
	 * Whether the signed-in user may use the MCP tools: the read capability the MCP
	 * transport requires; site administrators qualify through manage_options.
	 */
	public static function current_user_can_use_mcp(): bool {
		return get_current_user_id() > 0 && ( current_user_can( 'read' ) || current_user_can( 'manage_options' ) );
	}

	private static function applies_to( mixed $request ): bool {
		return $request instanceof \WP_REST_Request && 'OPTIONS' !== strtoupper( (string) $request->get_method() ) && self::is_protected_route( (string) $request->get_route() );
	}

	private static function subject( \WP_REST_Request $request ): ?int {
		return null === self::$accepted || ! isset( self::$accepted[ $request ] ) ? null : self::$accepted[ $request ];
	}

	private static function is_challenge( mixed $response ): bool {
		if ( ! $response instanceof \WP_REST_Response ) {
			return false;
		}
		$data = $response->get_data();
		return is_array( $data ) && in_array( $data['code'] ?? null, [ 'rest_oauth_required', 'rest_oauth_error' ], true ) && isset( $response->get_headers()['WWW-Authenticate'] );
	}

	private static function challenge( SiteProfile $site, ?string $error ): \WP_REST_Response {
		$description = ( new OAuthResponses( $site->transport() ) )->challenge( $site->protected_resource_metadata_url(), SiteProfile::GRANTED_SCOPES, $error );
		$body = null === $error
			? [
				'code'    => 'rest_oauth_required',
				'message' => 'OAuth authentication required.',
			]
			: [
				'code'    => 'rest_oauth_error',
				'message' => 'The access token is invalid or expired.',
				'data'    => [ 'status' => 401 ],
			];
		$response = new \WP_REST_Response( $body, 401 );
		foreach ( $description['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}
}
