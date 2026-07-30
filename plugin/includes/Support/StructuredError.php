<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Builds stable, repair-oriented ability errors.
 *
 * Envelope shape (via WP_Error data + ErrorEnvelope):
 * - code: machine-stable identifier
 * - message: human summary
 * - cause: what went wrong (safe for callers)
 * - repair: concrete next step
 * - retryable: whether a later retry may succeed without code changes
 */
final class StructuredError {

	/**
	 * @param array<string, mixed> $extra Safe extra data keys (status, ability, …).
	 */
	public static function create(
		string $code,
		string $message,
		string $cause,
		string $repair,
		bool $retryable = false,
		array $extra = []
	): \WP_Error {
		$code = sanitize_key( $code );
		if ( '' === $code ) {
			$code = 'stonewright_error';
		}

		$data = array_merge(
			$extra,
			[
				'cause'     => self::clip( $cause ),
				'repair'    => self::clip( $repair ),
				'retryable' => $retryable,
			]
		);

		return new \WP_Error( $code, self::clip( $message, 400 ), $data );
	}

	/**
	 * Array form matching ErrorEnvelope after from_wp_error().
	 *
	 * @param array<string, mixed> $extra
	 * @return array{error: array{code: string, message: string, data: array<string, mixed>}}
	 */
	public static function to_array(
		string $code,
		string $message,
		string $cause,
		string $repair,
		bool $retryable = false,
		array $extra = []
	): array {
		return ErrorEnvelope::from_wp_error(
			self::create( $code, $message, $cause, $repair, $retryable, $extra )
		);
	}

	private static function clip( string $value, int $max = 480 ): string {
		$value = preg_replace( '/\s+/', ' ', trim( $value ) ) ?? '';
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max - 1 ) . '…';
	}
}
