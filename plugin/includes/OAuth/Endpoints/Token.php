<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/endpoints/token.php
 * Source SHA-256: 40e81e90d1957a052288b3a8cf4c742e6fcacc1175bbd1e5ff0773e52ca17a75
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Endpoints;

use League\OAuth2\Server\Exception\OAuthServerException;
use Stonewright\WpMcp\OAuth\Bootstrap;
use Stonewright\WpMcp\OAuth\Bridge;
use Stonewright\WpMcp\OAuth\ClientValidation;
use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use Stonewright\WpMcp\OAuth\ServerFactory;
use Stonewright\WpMcp\Security\AuditLog;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth access-token endpoint.
 */
final class Token {

	public static function register(): void {
		register_rest_route(
			'stonewright/v1',
			'/oauth/token',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'allow_public_oauth' ],
				'callback'            => [ self::class, 'handle' ],
			]
		);
	}

	public static function allow_public_oauth(): bool {
		return true;
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$client_ip = \Stonewright\WpMcp\OAuth\OAuthRateLimiter::client_ip();
		$body      = $request->get_body_params();
		$client_id = is_array( $body ) ? (string) ( $body['client_id'] ?? '' ) : '';
		$endpoint  = ClientValidation::endpoint_rate_limit( 'token', $client_ip, $client_id );
		if ( ! $endpoint['allowed'] ) {
			return self::rate_limited( $endpoint['retry_after'] );
		}
		$is_refresh       = 'refresh_token' === (string) ( $body['grant_type'] ?? '' );
		$refresh_reserved = false;
		if ( $is_refresh ) {
			$refresh = ClientValidation::refresh_rate_limit( $client_ip, $client_id );
			if ( ! $refresh['allowed'] ) {
				return self::rate_limited( $refresh['retry_after'] );
			}
			$refresh_reserved = true;
		}

		$resource = (string) $request->get_param( 'resource' );
		if ( ! Bootstrap::resource_request_allowed( $resource, Bootstrap::resource_identifier() ) ) {
			return self::annotate( new WP_REST_Response(
				[
					'error'             => 'invalid_target',
					'error_description' => 'The requested resource is not served here.',
				],
				400
			) );
		}

		try {
			$server   = ServerFactory::authorization_server();
			$response = $server->respondToAccessTokenRequest( Bridge::to_psr7( $request ), Bridge::new_psr7_response() );
			if ( '' !== $client_id ) {
				try {
					( new ClientRepository() )->touchLastUsed( $client_id );
				} catch ( \Throwable $exception ) {
					// Usage metadata must never suppress a token already issued by the
					// authorization server; pruning can recover on a later request.
					unset( $exception );
				}
			}
			$wp_response = Bridge::from_psr7( $response );
			if ( $refresh_reserved && $wp_response->get_status() < 400 ) {
				ClientValidation::release_refresh_rate_limit( $client_ip, $client_id );
			}
			return self::annotate( $wp_response );
		} catch ( OAuthServerException $exception ) {
			$refresh_rejection = self::refresh_rejection_response( $exception, is_array( $body ) ? $body : [] );
			if ( null !== $refresh_rejection ) {
				$auth = ClientValidation::auth_failure_rate_limit( $client_ip, $client_id );
				if ( ! $auth['allowed'] ) {
					return self::rate_limited( $auth['retry_after'] );
				}
				return self::annotate( $refresh_rejection );
			}
			$response = Bridge::from_psr7( $exception->generateHttpResponse( Bridge::new_psr7_response() ) );
			if ( $response->get_status() >= 400 && $response->get_status() < 500 ) {
				$auth = ClientValidation::auth_failure_rate_limit( $client_ip, $client_id );
				if ( ! $auth['allowed'] ) {
					return self::rate_limited( $auth['retry_after'] );
				}
			}
			return self::annotate( $response );
		} catch ( \Exception $exception ) {
			return self::annotate( Bridge::from_psr7(
				OAuthServerException::serverError( 'Internal server error', $exception )
					->generateHttpResponse( Bridge::new_psr7_response() )
			) );
		}
	}

	private static function rate_limited( int $retry_after ): WP_REST_Response {
		$response = new WP_REST_Response( [ 'error' => 'temporarily_unavailable', 'reason' => 'rate_limited' ], 429 );
		$response->header( 'Retry-After', (string) max( 1, min( 86400, $retry_after ) ) );
		return self::annotate( $response );
	}

	private static function refresh_rejection_response( OAuthServerException $exception, array $body ): ?WP_REST_Response {
		$grant_type    = is_scalar( $body['grant_type'] ?? null ) ? (string) $body['grant_type'] : '';
		$refresh_token = is_scalar( $body['refresh_token'] ?? null ) ? trim( (string) $body['refresh_token'] ) : '';
		$error_type   = method_exists( $exception, 'getErrorType' ) ? (string) $exception->getErrorType() : '';
		if ( 'refresh_token' !== $grant_type || '' === $refresh_token || ! in_array( $error_type, [ 'invalid_request', 'invalid_grant' ], true ) ) {
			return null;
		}

		$diagnostic = strtolower( trim( (string) $exception->getMessage() . ' ' . ( method_exists( $exception, 'getHint' ) ? (string) $exception->getHint() : '' ) ) );
		$reason = str_contains( $diagnostic, 'expired' )
			? 'refresh_token_expired'
			: ( str_contains( $diagnostic, 'revok' ) || str_contains( $diagnostic, 'already' ) ? 'refresh_token_revoked' : 'refresh_token_invalid' );

		return new WP_REST_Response(
			[
				'error'             => 'invalid_grant',
				'error_description' => 'The refresh token is no longer valid.',
				'reason'            => $reason,
			],
			400
		);
	}

	private static function annotate( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		if ( $response->get_status() >= 400 ) {
			$response->header( 'X-Stonewright-Correlation-ID', AuditLog::request_id() );
		}
		return $response;
	}
}
