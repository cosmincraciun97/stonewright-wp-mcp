<?php
/**
 * Dynamic client registration (RFC 7591).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Authorization\WordPress;

use Stonewright\WpMcp\Authorization\Exchange\RegistrationCoordinator;
use Stonewright\WpMcp\Authorization\Model\OAuthFault;
use Stonewright\WpMcp\Authorization\Ports\Clock;
use Stonewright\WpMcp\Support\Logger;

/**
 * Registers public clients from a JSON body and answers 201 with client_id,
 * client_name, redirect_uris, token_endpoint_auth_method, grant_types and
 * response_types (then any other accepted metadata). No secret is issued. An omitted
 * token_endpoint_auth_method registers "none", the only method this server supports,
 * and the response says so (RFC 7591 section 3.2.1). Errors are
 * invalid_redirect_uri, invalid_client_metadata or invalid_request with HTTP 400.
 *
 * Diagnostics self-test: a request carrying X-Stonewright-Self-Test with a token whose
 * sha256 the setup diagnostics stored in a transient registers normally, is marked as a
 * self-test, and is removed again before the response. The transient is used once.
 */
final class RegistrationEndpoint {

	public const MAXIMUM_BYTES = 32768;
	public const SELF_TEST_HEADER = 'x-stonewright-self-test';
	public const SELF_TEST_TRANSIENT = 'stonewright_oauth_selftest_';
	public const SELF_TEST_LIFETIME = 60;

	private const DESCRIPTIONS = [
		'invalid_redirect_uri'    => 'Every redirect URI must use HTTPS or be a loopback HTTP callback, without a fragment or user information.',
		'invalid_client_metadata' => 'The client metadata is invalid or asks for an unsupported grant type, response type, scope or authentication method.',
		'invalid_request'         => 'The registration must be one JSON object without repeated members.',
		'server_error'            => 'The registration could not be stored.',
	];

	public function __construct( private ClientStore $clients, private SiteProfile $site, private Clock $clock ) {}

	/** @return array<string, mixed> */
	public static function policy(): array {
		return [
			'authentication_methods'        => [ 'none' ],
			'omitted_authentication_method' => 'none',
			'grant_types'                   => [ 'authorization_code', 'refresh_token' ],
			'response_types'                => [ 'code' ],
			'scopes_supported'              => SiteProfile::SUPPORTED_SCOPES,
			'native_clients'                => true,
			'maximum_redirects'             => 10,
			'maximum_text_bytes'            => 1024,
		];
	}

	public function handle( OAuthRequest $request ): OAuthReply {
		$self_test = $this->self_test( $request );
		try {
			$result = ( new RegistrationCoordinator( $this->clients, self::policy(), self::MAXIMUM_BYTES, $this->site->link_transport() ) )->register( $request->body );
		} catch ( OAuthFault $fault ) {
			return self::failure( 'server_error' === $fault->error() ? 500 : 400, $fault->error() );
		} catch ( \Throwable $failure ) {
			Logger::warning( 'oauth_registration_failed', [ 'error_class' => get_class( $failure ) ] );
			return self::failure( 500, 'server_error' );
		}
		$body = self::ordered( $result['body'] );
		$client_id = (string) $body['client_id'];
		if ( $self_test ) {
			// Marked first, so a client that cannot be removed now is found and pruned later.
			foreach ( [ 'mark_self_test', 'forget' ] as $step ) {
				try {
					if ( 'mark_self_test' === $step ) {
						$this->clients->mark_self_test( $client_id, $this->clock->now() + self::SELF_TEST_LIFETIME );
					} else {
						$this->clients->forget( $client_id );
					}
				} catch ( \Throwable $failure ) {
					Logger::warning( 'oauth_self_test_cleanup_failed', [ 'step' => $step, 'error_class' => get_class( $failure ) ] );
				}
			}
		}
		return new OAuthReply( 201, $body, self::headers(), [ 'client_id' => $client_id ] );
	}

	/** Whether the request is a one-use diagnostics self-test; consumes its marker. */
	private function self_test( OAuthRequest $request ): bool {
		$token = (string) $request->header( self::SELF_TEST_HEADER );
		if ( ! preg_match( '/^[0-9a-f]{32}$/D', $token ) ) {
			return false;
		}
		$hash = hash( 'sha256', $token );
		$stored = get_transient( self::SELF_TEST_TRANSIENT . $hash );
		if ( ! is_string( $stored ) || ! hash_equals( $hash, $stored ) ) {
			return false;
		}
		delete_transient( self::SELF_TEST_TRANSIENT . $hash );
		return true;
	}

	/**
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private static function ordered( array $body ): array {
		$ordered = [];
		foreach ( [ 'client_id', 'client_name', 'redirect_uris', 'token_endpoint_auth_method', 'grant_types', 'response_types' ] as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$ordered[ $key ] = $body[ $key ];
			}
		}
		return $ordered + $body;
	}

	private static function failure( int $status, string $error ): OAuthReply {
		return new OAuthReply(
			$status,
			[
				'error'             => $error,
				'error_description' => self::DESCRIPTIONS[ $error ] ?? 'The registration was refused.',
			],
			self::headers()
		);
	}

	/** @return array<string, string> */
	private static function headers(): array {
		return [
			'Cache-Control' => 'no-store',
			'Pragma'        => 'no-cache',
		];
	}
}
