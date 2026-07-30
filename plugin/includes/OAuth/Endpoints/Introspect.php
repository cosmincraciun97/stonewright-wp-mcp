<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/oauth/endpoints/introspect.php
 * Source SHA-256: 288b68299845f68685f9ded8c2068d0b3b560772dd50c4fb3f78a3cb27b32dbd
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\OAuth\Endpoints;

use Stonewright\WpMcp\OAuth\Bridge;
use Stonewright\WpMcp\OAuth\ServerFactory;
use Stonewright\WpMcp\Security\Permissions;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * RFC 7662 token introspection for administrators.
 */
final class Introspect {

	public static function register(): void {
		register_rest_route(
			'stonewright/v1',
			'/oauth/introspect',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'can_introspect' ],
				'callback'            => [ self::class, 'handle' ],
			]
		);
	}

	public static function can_introspect(): bool {
		return Permissions::manage_options();
	}

	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		$body  = $request->get_body_params();
		$token = (string) ( $body['token'] ?? '' );
		if ( '' === $token ) {
			return new WP_REST_Response( [ 'active' => false ], 200 );
		}

		try {
			$server    = ServerFactory::resource_server();
			$fake      = Bridge::psr7_from_globals()->withHeader( 'Authorization', 'Bearer ' . $token );
			$validated = $server->validateAuthenticatedRequest( $fake );
			$user_id   = (string) $validated->getAttribute( 'oauth_user_id' );
			$jti       = (string) $validated->getAttribute( 'oauth_access_token_id' );
			$exp       = 0;
			$iat       = 0;
			$parts     = explode( '.', $token );
			if ( 3 === count( $parts ) ) {
				$json = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
				if ( false !== $json ) {
					$payload = json_decode( $json, true );
					if ( is_array( $payload ) ) {
						$exp = (int) ( $payload['exp'] ?? 0 );
						$iat = (int) ( $payload['iat'] ?? 0 );
					}
				}
			}

			return new WP_REST_Response(
				[
					'active' => true,
					'sub'    => $user_id,
					'scope'  => 'mcp',
					'jti'    => $jti,
					'exp'    => $exp,
					'iat'    => $iat,
				],
				200
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return new WP_REST_Response( [ 'active' => false ], 200 );
		}
	}
}
