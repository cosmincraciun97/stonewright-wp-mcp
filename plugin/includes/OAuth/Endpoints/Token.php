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
		$client_ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		if ( '' !== $client_ip && ! ClientValidation::within_endpoint_rate_limit( 'token', $client_ip ) ) {
			return new WP_REST_Response( [ 'error' => 'temporarily_unavailable' ], 429 );
		}

		$resource = (string) $request->get_param( 'resource' );
		if ( ! Bootstrap::resource_request_allowed( $resource, Bootstrap::resource_identifier() ) ) {
			return new WP_REST_Response(
				[
					'error'             => 'invalid_target',
					'error_description' => 'The requested resource is not served here.',
				],
				400
			);
		}

		try {
			$server   = ServerFactory::authorization_server();
			$response = $server->respondToAccessTokenRequest( Bridge::to_psr7( $request ), Bridge::new_psr7_response() );
			$body     = $request->get_body_params();
			$client_id = (string) ( $body['client_id'] ?? '' );
			if ( '' !== $client_id ) {
				( new ClientRepository() )->touchLastUsed( $client_id );
			}
			return Bridge::from_psr7( $response );
		} catch ( OAuthServerException $exception ) {
			return Bridge::from_psr7( $exception->generateHttpResponse( Bridge::new_psr7_response() ) );
		} catch ( \Exception $exception ) {
			return Bridge::from_psr7(
				OAuthServerException::serverError( 'Internal server error', $exception )
					->generateHttpResponse( Bridge::new_psr7_response() )
			);
		}
	}
}
