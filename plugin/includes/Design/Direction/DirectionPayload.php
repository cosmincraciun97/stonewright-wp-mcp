<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

use WP_Error;

/**
 * Size guard for untrusted direction payloads.
 *
 * Validation walks a contract key by key, so an unbounded payload is work an
 * attacker gets for free. Callers check the encoded size first and reject
 * anything past the documented ceiling before the validator sees it.
 */
final class DirectionPayload {

	/** @var string Structured error code for a payload past the documented ceiling. */
	public const TOO_LARGE_CODE = 'stonewright_direction_payload_too_large';

	/**
	 * Returns an error when the encoded payload is larger than the ceiling.
	 *
	 * An unencodable payload is rejected too: it cannot be hashed, stored, or
	 * compared, so accepting it would break the contract-hash guarantee.
	 *
	 * @param array<string,mixed> $payload    Untrusted payload.
	 * @param int                 $max_bytes  Ceiling in bytes.
	 * @param string              $field      Field name reported to the caller.
	 */
	public static function size_error( array $payload, int $max_bytes, string $field = 'contract' ): ?WP_Error {
		$encoded = wp_json_encode( $payload );

		if ( ! is_string( $encoded ) ) {
			return new WP_Error(
				DirectionContract::ERROR_CODE,
				sprintf(
					/* translators: %s: payload field name. */
					__( 'The %s payload could not be encoded.', 'stonewright' ),
					$field
				),
				[ 'status' => 400 ]
			);
		}

		$bytes = strlen( $encoded );

		if ( $bytes <= $max_bytes ) {
			return null;
		}

		return new WP_Error(
			self::TOO_LARGE_CODE,
			sprintf(
				/* translators: 1: payload field name, 2: maximum size in bytes. */
				__( 'The %1$s payload is larger than the %2$d byte limit.', 'stonewright' ),
				$field,
				$max_bytes
			),
			[
				'status'    => 413,
				'field'     => $field,
				'bytes'     => $bytes,
				'max_bytes' => $max_bytes,
			]
		);
	}

	/**
	 * Bytes an encoded payload occupies, or null when it cannot be encoded.
	 *
	 * @param array<string,mixed> $payload Untrusted payload.
	 */
	public static function size( array $payload ): ?int {
		$encoded = wp_json_encode( $payload );

		return is_string( $encoded ) ? strlen( $encoded ) : null;
	}
}
