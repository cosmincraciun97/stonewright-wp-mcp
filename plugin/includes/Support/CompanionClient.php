<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Companion\CompanionContract;

/**
 * Thin wrapper around wp_safe_remote_post for companion HTTP calls.
 *
 * The base URL is ALWAYS resolved from the trusted `stonewright_companion_url`
 * option — there is no per-call URL override. This guarantees the companion
 * bearer token in `stonewright_companion_token` can never be relayed to a
 * caller-supplied origin.
 *
 * `wp_safe_remote_post()` is used instead of `wp_remote_post()` so the
 * outbound request is subject to WordPress's HTTP safety net (no redirects,
 * private-IP filter respects `http_request_host_is_external`). Loopback
 * targets (127.0.0.1, ::1, localhost) are allowed by WordPress by default.
 *
 * Version checking:
 *   On the first call per request the companion's /health endpoint is queried
 *   and the contract_version is cached in transient `stonewright_companion_contract_version`
 *   (TTL 5 min). On major-version mismatch every companion call short-circuits with
 *   WP_Error('stonewright_companion_version_mismatch').
 */
final class CompanionClient {

	private const VERSION_TRANSIENT = 'stonewright_companion_contract_version';
	private const VERSION_TTL       = 5 * MINUTE_IN_SECONDS;

	/**
	 * POST to the companion service.
	 *
	 * @param string               $path e.g. '/wp-cli/status'
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function post( string $path, array $body ): array|\WP_Error {
		// Version gate: check companion contract_version before any companion call.
		$version_check = self::check_version();
		if ( is_wp_error( $version_check ) ) {
			return $version_check;
		}

		$base  = rtrim( (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' ), '/' );
		$token = (string) get_option( 'stonewright_companion_token', '' );

		$encoded = wp_json_encode( $body );
		if ( $encoded === false ) {
			return new \WP_Error(
				'stonewright_companion_encode_failed',
				__( 'Failed to JSON-encode companion request body.', 'stonewright' ),
				[ 'body' => $body, 'json_error' => json_last_error_msg() ]
			);
		}

		$response = wp_safe_remote_post(
			$base . $path,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => $encoded,
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error( 'stonewright_companion_parse', 'Companion response is not valid JSON.', [ 'raw' => $raw ] );
		}

		if ( $code >= 400 ) {
			$msg = $data['error'] ?? $data['message'] ?? "Companion returned HTTP $code";
			return new \WP_Error( 'stonewright_companion_error', $msg, [ 'code' => $code, 'body' => $data ] );
		}

		return (array) $data;
	}

	/**
	 * GET the companion's /health endpoint and validate the contract version.
	 *
	 * The result is cached in a transient (TTL 5 min). On major-version mismatch
	 * returns WP_Error('stonewright_companion_version_mismatch').
	 *
	 * @return true|\WP_Error
	 */
	public static function check_version(): bool|\WP_Error {
		$cached = get_transient( self::VERSION_TRANSIENT );
		if ( $cached !== false ) {
			// Already validated this version within the TTL window — skip re-check.
			return CompanionContract::validate_version( (string) $cached );
		}

		$base  = rtrim( (string) get_option( 'stonewright_companion_url', 'http://127.0.0.1:8765' ), '/' );
		$token = (string) get_option( 'stonewright_companion_token', '' );

		$response = wp_safe_remote_post(
			$base . '/health',
			[
				'method'  => 'GET',
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
				],
				'timeout' => 5,
			]
		);

		if ( is_wp_error( $response ) ) {
			// Companion unreachable — do not block the call; version mismatch
			// can only be detected when the companion is up.
			return true;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || ! isset( $data['contract_version'] ) ) {
			// Old companion without contract_version — treat as compatible for now.
			return true;
		}

		$version = (string) $data['contract_version'];
		set_transient( self::VERSION_TRANSIENT, $version, self::VERSION_TTL );

		return CompanionContract::validate_version( $version );
	}
}
