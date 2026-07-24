<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/endpoints/revoke.php
 * Source SHA-256: 0acaecfa7e258368238753c454b626837023508d824d7db466fa4dc7cba5afc8
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Endpoints;

use Defuse\Crypto\Crypto;
use Stonewright\WpMcp\OAuth\Bridge;
use Stonewright\WpMcp\OAuth\ClientValidation;
use Stonewright\WpMcp\OAuth\Keys;
use Stonewright\WpMcp\OAuth\Repositories\AccessTokenRepository;
use Stonewright\WpMcp\OAuth\Repositories\RefreshTokenRepository;
use Stonewright\WpMcp\OAuth\ServerFactory;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 7009 token revocation.
 */
final class Revoke {

	public static function register(): void {
		register_rest_route(
			'stonewright/v1',
			'/oauth/revoke',
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
		$client_ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( '' !== $client_ip && ! ClientValidation::within_endpoint_rate_limit( 'revoke', $client_ip ) ) {
			return new WP_REST_Response( null, 429 );
		}

		$body  = $request->get_body_params();
		$token = (string) ( $body['token'] ?? '' );
		if ( '' === $token ) {
			return new WP_REST_Response( null, 200 );
		}

		$client_id = (string) ( $body['client_id'] ?? '' );
		self::try_revoke_access( $token, $client_id );
		self::try_revoke_refresh( $token, $client_id );
		return new WP_REST_Response( null, 200 );
	}

	public static function try_revoke_access( string $token, string $client_id ): void {
		try {
			$server    = ServerFactory::resource_server();
			$request   = Bridge::psr7_from_globals()->withHeader( 'Authorization', 'Bearer ' . $token );
			$validated = $server->validateAuthenticatedRequest( $request );
			$jti       = (string) $validated->getAttribute( 'oauth_access_token_id' );
			if ( '' !== $jti ) {
				( new AccessTokenRepository() )->revokeGrantByAccessHash( hash( 'sha256', $jti ), $client_id );
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}

	public static function try_revoke_refresh( string $token, string $client_id ): void {
		if ( ! ctype_xdigit( $token ) ) {
			return;
		}

		try {
			$keys      = Keys::get();
			$decrypted = Crypto::decryptWithPassword( $token, $keys['encryption'] );
			$payload   = json_decode( $decrypted, true );
			if ( ! is_array( $payload ) ) {
				return;
			}

			$jti = $payload['refresh_token_id'] ?? null;
			if ( ! is_string( $jti ) || '' === $jti ) {
				return;
			}
			$access_hash = ( new RefreshTokenRepository() )->accessTokenHashFor( $jti );
			if ( '' !== $access_hash ) {
				( new AccessTokenRepository() )->revokeGrantByAccessHash( $access_hash, $client_id );
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
		}
	}
}
