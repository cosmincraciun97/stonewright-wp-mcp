<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/endpoints/register.php
 * Source SHA-256: dd78f4e17883bbb85208a912bb6f29c3ca65a159ef8d862a51ab2a0423583cc5
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Endpoints;

use Stonewright\WpMcp\OAuth\ClientValidation;
use Stonewright\WpMcp\OAuth\OAuthRateLimiter;
use Stonewright\WpMcp\OAuth\Repositories\ClientRepository;
use Stonewright\WpMcp\Security\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 7591 dynamic client registration.
 */
final class Register {

	public static function register(): void {
		register_rest_route(
			'stonewright/v1',
			'/oauth/register',
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

	public static function is_self_test_request( WP_REST_Request $request ): bool {
		$token = trim( (string) $request->get_header( 'x-stonewright-self-test' ) );
		if ( '' === $token ) {
			return false;
		}

		$key   = 'stonewright_oauth_selftest_' . hash( 'sha256', $token );
		$found = get_transient( $key );
		delete_transient( $key );
		return '1' === $found || 1 === $found;
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$client_ip = OAuthRateLimiter::client_ip();
		ClientValidation::prune_dead_clients();
		$self_test = self::is_self_test_request( $request );
		if ( '' !== $client_ip && ! $self_test ) {
			$registration = ClientValidation::registration_rate_limit( $client_ip );
			if ( ! $registration['allowed'] ) {
				return self::rate_limited( 'Too many registrations', $registration['retry_after'] );
			}
		}
		if (
			'' !== $client_ip
			&& ! $self_test
			&& ClientValidation::client_count_for_ip( $client_ip ) >= ClientValidation::MAX_CLIENTS_PER_IP
		) {
			return self::rate_limited( 'Too many registered clients from this address', HOUR_IN_SECONDS );
		}
		if ( ClientValidation::active_client_count() >= ClientValidation::max_clients_per_site() ) {
			return self::rate_limited( 'Client cap reached', MINUTE_IN_SECONDS, 503, 'temporarily_unavailable' );
		}

		$body        = $request->get_json_params();
		$client_name = sanitize_text_field( trim( (string) ( $body['client_name'] ?? '' ) ) );
		if ( '' === $client_name || strlen( $client_name ) > 191 ) {
			return new WP_Error( 'invalid_request', 'client_name must be 1..191 chars', [ 'status' => 400 ] );
		}

		$redirect_uris = $body['redirect_uris'] ?? null;
		if ( ! is_array( $redirect_uris ) || [] === $redirect_uris ) {
			return new WP_Error( 'invalid_request', 'redirect_uris must be a non-empty array', [ 'status' => 400 ] );
		}
		if ( count( $redirect_uris ) > 5 ) {
			return new WP_Error( 'invalid_request', 'Max 5 redirect_uris', [ 'status' => 400 ] );
		}

		$dev_mode   = ClientValidation::local_redirects_allowed();
		$clean_uris = [];
		foreach ( $redirect_uris as $uri ) {
			$uri = is_string( $uri ) ? trim( $uri ) : '';
			if (
				'' === $uri
				|| strlen( $uri ) > ClientValidation::MAX_REDIRECT_URI_LENGTH
				|| ! ClientValidation::is_allowed_redirect_uri( $uri, $dev_mode )
			) {
				return new WP_Error( 'invalid_redirect_uri', sprintf( 'redirect_uri not allowed: %s', esc_html( $uri ) ), [ 'status' => 400 ] );
			}
			$clean_uris[] = $uri;
		}

		$clean_uris = array_values( array_unique( $clean_uris ) );
		$client_id  = ( new ClientRepository() )->create( $client_name, $clean_uris, $client_ip );

		return new WP_REST_Response(
			[
				'client_id'                  => $client_id,
				'client_name'                => $client_name,
				'redirect_uris'              => $clean_uris,
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => [ 'authorization_code', 'refresh_token' ],
				'response_types'             => [ 'code' ],
			],
			201
		);
	}

	private static function rate_limited( string $message, int $retry_after, int $status = 429, string $code = 'rate_limited' ): WP_REST_Response {
		$response = new WP_REST_Response(
			[
				'error'             => $code,
				'error_description' => $message,
			],
			$status
		);
		$response->header( 'Retry-After', (string) max( 1, min( 86400, $retry_after ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'X-Stonewright-Correlation-ID', AuditLog::request_id() );
		return $response;
	}
}
