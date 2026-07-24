<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/middleware.php
 * Source SHA-256: a92bcba92cb7677fa8a6927426d7e2d603cd345930fdfb249de7fab13e154a06
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth;

use League\OAuth2\Server\Exception\OAuthServerException;
use Stonewright\WpMcp\OAuth\Endpoints\Discovery;
use Stonewright\WpMcp\Security\Permissions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Bearer authentication for the dedicated OAuth MCP route.
 */
final class Middleware {

	public static function register(): void {
		add_filter( 'rest_authentication_errors', [ self::class, 'authenticate' ], 20 );
		add_filter( 'rest_pre_dispatch', [ self::class, 'challenge_unauthenticated' ], 10, 3 );
	}

	public static function challenge_unauthenticated(
		mixed $result,
		mixed $server,
		WP_REST_Request $request
	): mixed {
		unset( $server );
		if ( null !== $result || ! self::is_mcp_route( $request->get_route() ) ) {
			return $result;
		}
		if ( self::has_bearer_authorization( self::get_authorization_header() ) ) {
			return null;
		}

		$response = new WP_REST_Response(
			[
				'code'    => 'rest_oauth_required',
				'message' => 'OAuth authentication required.',
			],
			401
		);
		$response->header( 'WWW-Authenticate', self::www_authenticate_header() );
		return $response;
	}

	public static function www_authenticate_header( ?string $error = null ): string {
		$value = 'Bearer resource_metadata="' . Discovery::protected_resource_metadata_url() . '"';
		if ( null !== $error ) {
			$value .= ', error="' . $error . '"';
		}
		return $value . ', scope="mcp"';
	}

	public static function send_www_authenticate( string $error ): void {
		if ( ! headers_sent() ) {
			header( 'WWW-Authenticate: ' . self::www_authenticate_header( $error ) );
		}
	}

	public static function authenticate( mixed $result ): mixed {
		if ( null !== $result ) {
			return $result;
		}

		$authorization = self::get_authorization_header();
		if ( ! self::has_bearer_authorization( $authorization ) || ! self::request_targets_mcp_route() ) {
			return null;
		}

		try {
			$server    = ServerFactory::resource_server();
			$validated = $server->validateAuthenticatedRequest(
				Bridge::psr7_from_globals()->withHeader(
					'Authorization',
					self::normalize_bearer_authorization( $authorization )
				)
			);
			if ( Bootstrap::resource_identifier() !== $validated->getAttribute( 'oauth_client_id' ) ) {
				self::send_www_authenticate( 'invalid_token' );
				return new WP_Error(
					'rest_oauth_error',
					'Token audience does not match this resource.',
					[ 'status' => 401 ]
				);
			}
			if ( ! self::has_mcp_scope( $validated->getAttribute( 'oauth_scopes' ) ) ) {
				self::send_www_authenticate( 'insufficient_scope' );
				return new WP_Error( 'rest_oauth_error', 'Token is missing the required mcp scope.', [ 'status' => 403 ] );
			}

			$user_id = (int) $validated->getAttribute( 'oauth_user_id' );
			if ( $user_id <= 0 ) {
				self::send_www_authenticate( 'invalid_token' );
				return new WP_Error( 'rest_oauth_error', 'Invalid token subject.', [ 'status' => 401 ] );
			}

			wp_set_current_user( $user_id );
			if ( ! self::current_user_can_access_mcp() ) {
				self::send_www_authenticate( 'insufficient_scope' );
				return new WP_Error(
					'rest_oauth_error',
					'User is no longer allowed to use Stonewright MCP.',
					[ 'status' => 403 ]
				);
			}
			return true;
		} catch ( OAuthServerException $exception ) {
			self::send_www_authenticate( 'invalid_token' );
			return new WP_Error(
				'rest_oauth_error',
				$exception->getMessage(),
				[ 'status' => $exception->getHttpStatusCode() ]
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return new WP_Error( 'rest_oauth_error', 'Authentication failed.', [ 'status' => 500 ] );
		}
	}

	public static function is_mcp_route( string $route ): bool {
		return '/mcp/stonewright-oauth' === $route || str_starts_with( $route, '/mcp/stonewright-oauth/' );
	}

	public static function get_authorization_header(): string {
		$authorization = trim( (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' ) );
		if ( '' !== $authorization ) {
			return $authorization;
		}
		return trim( (string) ( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
	}

	public static function has_bearer_authorization( string $authorization ): bool {
		return 1 === preg_match( '/^\s*Bearer\s+\S/i', $authorization );
	}

	public static function normalize_bearer_authorization( string $authorization ): string {
		$matches = [];
		if ( 1 !== preg_match( '/^\s*Bearer\s+(.+?)\s*$/i', $authorization, $matches ) ) {
			return $authorization;
		}
		return 'Bearer ' . $matches[1];
	}

	public static function has_mcp_scope( mixed $scopes ): bool {
		if ( is_string( $scopes ) ) {
			$parts  = preg_split( '/\s+/', trim( $scopes ) );
			$scopes = false === $parts ? [] : $parts;
		}
		return is_array( $scopes ) && in_array( 'mcp', $scopes, true );
	}

	public static function current_user_can_access_mcp(): bool {
		return Permissions::manage_options();
	}

	public static function request_targets_mcp_route(): bool {
		$rest_route = $_GET['rest_route'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_string( $rest_route ) && self::is_mcp_route( '/' . ltrim( $rest_route, '/' ) ) ) {
			return true;
		}

		$request_uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		if ( '' === $request_uri ) {
			return false;
		}
		$path = parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return false;
		}
		$path = rawurldecode( $path );

		$mcp_path = parse_url( rest_url( 'mcp/stonewright-oauth' ), PHP_URL_PATH );
		if ( is_string( $mcp_path ) && self::path_matches_prefix( $path, $mcp_path ) ) {
			return true;
		}

		$rest_prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$rest_prefix = trim( $rest_prefix, '/' );
		if ( '' === $rest_prefix ) {
			return false;
		}
		return 1 === preg_match(
			'#/' . preg_quote( $rest_prefix, '#' ) . '/mcp/stonewright-oauth(?:/|$)#',
			$path
		);
	}

	public static function path_matches_prefix( string $path, string $prefix ): bool {
		$prefix = rtrim( $prefix, '/' );
		return '' !== $prefix && ( $path === $prefix || str_starts_with( $path, $prefix . '/' ) );
	}
}
