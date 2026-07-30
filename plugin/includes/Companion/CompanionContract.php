<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Companion;

use Stonewright\WpMcp\Companion\Contracts\Health;

/**
 * Runtime contract validator for companion request/response payloads.
 *
 * Usage:
 *   $result = CompanionContract::validate( 'health', 'response', $payload );
 *   if ( is_wp_error( $result ) ) { return $result; }
 *
 * Returns true on success or a WP_Error with code
 * 'stonewright_companion_contract_violation' on failure:
 *   ['status' => 502, 'endpoint' => $endpoint, 'direction' => $direction, 'errors' => $details]
 *
 * Version mismatch:
 *   Call validate_version( $received_version ) after reading /health. On major
 *   version mismatch returns WP_Error('stonewright_companion_version_mismatch').
 */
final class CompanionContract {

	/**
	 * Our expected contract major version.
	 * Must match CONTRACT_MAJOR in companion/src/contracts/version.ts.
	 */
	private const EXPECTED_CONTRACT_MAJOR = 1;

	/**
	 * Full expected version string (must match CONTRACT_VERSION in version.ts).
	 *
	 * @var string
	 */
	public const EXPECTED_CONTRACT_VERSION = '1.0.0';

	/**
	 * Map endpoint → direction → [required[], properties[]].
	 *
	 * @return array<string, array<string, array{required: array<int, string>, properties: array<string, array<string, mixed>>}>>
	 */
	private static function schema_map(): array {
		return [
			'health' => [
				'request'  => [ 'required' => Health::REQUEST_REQUIRED, 'properties' => Health::REQUEST_PROPERTIES ],
				'response' => [ 'required' => Health::RESPONSE_REQUIRED, 'properties' => Health::RESPONSE_PROPERTIES ],
			],
		];
	}

	/**
	 * Validate a request or response payload against the contract schema.
	 *
	 * @param string               $endpoint  One of: health.
	 * @param string               $direction 'request' or 'response'.
	 * @param array<string, mixed> $payload
	 * @return bool|\WP_Error
	 */
	public static function validate( string $endpoint, string $direction, array $payload ): bool|\WP_Error {
		$map = self::schema_map();

		if ( ! isset( $map[ $endpoint ] ) ) {
			return new \WP_Error(
				'stonewright_companion_contract_violation',
				sprintf( 'Unknown companion endpoint: %s', $endpoint ),
				[ 'status' => 502, 'endpoint' => $endpoint, 'direction' => $direction, 'errors' => [ "Unknown endpoint '$endpoint'" ] ]
			);
		}

		if ( ! isset( $map[ $endpoint ][ $direction ] ) ) {
			return new \WP_Error(
				'stonewright_companion_contract_violation',
				sprintf( "Invalid contract direction '%s' for endpoint '%s'", $direction, $endpoint ),
				[ 'status' => 502, 'endpoint' => $endpoint, 'direction' => $direction, 'errors' => [ "Invalid direction '$direction'" ] ]
			);
		}

		$schema   = $map[ $endpoint ][ $direction ];
		$required = $schema['required'];
		$props    = $schema['properties'];
		$errors   = [];

		// Check required fields
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $payload ) ) {
				$errors[] = "Missing required field: {$field}";
			}
		}

		// Type-check present fields
		foreach ( $payload as $key => $value ) {
			if ( ! isset( $props[ $key ] ) ) {
				// Additional properties: warn but do not fail (companion may add fields)
				continue;
			}
			$expected_type = $props[ $key ]['type'] ?? null;
			if ( $expected_type !== null && ! self::matches_type( $expected_type, $value ) ) {
				$errors[] = "Field '{$key}': expected {$expected_type}, got " . gettype( $value );
			}
		}

		if ( [] !== $errors ) {
			return new \WP_Error(
				'stonewright_companion_contract_violation',
				sprintf( 'Companion contract violation on %s %s: %s', $endpoint, $direction, implode( '; ', $errors ) ),
				[ 'status' => 502, 'endpoint' => $endpoint, 'direction' => $direction, 'errors' => $errors ]
			);
		}

		return true;
	}

	/**
	 * Validate the companion version string received from /health.
	 *
	 * Short-circuits on major-version mismatch.
	 *
	 * @return bool|\WP_Error
	 */
	public static function validate_version( string $received ): bool|\WP_Error {
		$parts = explode( '.', $received, 3 );
		$received_major = (int) ( $parts[0] ?? 0 );

		if ( $received_major !== self::EXPECTED_CONTRACT_MAJOR ) {
			return new \WP_Error(
				'stonewright_companion_version_mismatch',
				sprintf(
					'Companion contract major version mismatch: expected %d.x, received %s.',
					self::EXPECTED_CONTRACT_MAJOR,
					$received
				),
				[
					'status'   => 502,
					'expected' => self::EXPECTED_CONTRACT_VERSION,
					'received' => $received,
				]
			);
		}

		return true;
	}

	/**
	 * @param string|array<int, string> $type
	 */
	private static function matches_type( string|array $type, mixed $value ): bool {
		if ( is_array( $type ) ) {
			foreach ( $type as $t ) {
				if ( self::matches_single_type( $t, $value ) ) {
					return true;
				}
			}
			return false;
		}
		return self::matches_single_type( $type, $value );
	}

	private static function matches_single_type( string $type, mixed $value ): bool {
		return match ( $type ) {
			'object'  => is_array( $value ) || $value instanceof \stdClass,
			'array'   => is_array( $value ),
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'null'    => null === $value,
			default   => true,
		};
	}
}
