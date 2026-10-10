<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Converts WP_Error into the Stonewright ability error envelope.
 */
final class ErrorEnvelope {

	/**
	 * Keys that are safe to forward to callers in error.data.
	 * Allowlist semantics: unknown keys are silently stripped.
	 *
	 * @var array<int, string>
	 */
	private const SAFE_ERROR_DATA_KEYS = [
		'status',
		'failed_index',
		'post_type',
		'ability',
		'nonce_sha8',
		'errors',
		'widget',
		'violations',
		'cause',
		'repair',
		'retryable',
		'schema_requests',
		'schema_request',
		'cause_code',
		'setting',
		'widget_type',
		'execution_status',
		'offending_key',
		'offending_keys',
		'gated_tool',
		'gated_mcp_tool',
		'approval_flow',
		'reason',
	];

	/**
	 * Keys copied into the WP_Error message so MCP transports that only
	 * surface `error.message` still expose the repair payload.
	 *
	 * @var list<string>
	 */
	private const AGENT_VISIBLE_MESSAGE_KEYS = [
		'schema_requests',
		'schema_request',
		'cause_code',
		'setting',
		'widget_type',
		'offending_key',
		'offending_keys',
		'gated_mcp_tool',
	];

	/**
	 * Errors of Rescue: a write it undid, an undo that failed, and an undo of code that waits for an administrator.
	 * MCP clients receive only the message, so the code and a few plain fields are copied into it. Nothing else of
	 * the error data is.
	 *
	 * @var list<string>
	 */
	private const RESCUE_ERROR_CODES = [
		'stonewright_rescue_write_rolled_back',
		'stonewright_rescue_rollback_failed',
		'stonewright_rescue_approval_required',
	];

	/** Longest approval URL copied into a message. */
	private const APPROVAL_URL_MAX = 400;

	/**
	 * Data fields copied into the message of a Rescue error: short identifiers and status words.
	 *
	 * @var list<string>
	 */
	private const RESCUE_MESSAGE_KEYS = [
		'change_set_id',
		'incident_id',
		'rollback_status',
		'site_status',
		'original_error_code',
	];

	/**
	 * Note on security: keys such as spec, args, confirmation_token, token,
	 * password, api_key, and secret are implicitly blocked because they are not
	 * in SAFE_ERROR_DATA_KEYS. The allowlist approach means any new key added to
	 * WP_Error data is stripped by default — additions must be explicitly allowed.
	 */

	/**
	 * @return array{error: array{code: string, message: string, data?: array<string, mixed>}}
	 */
	/**
	 * Append compact agent-repair fields onto the error message.
	 *
	 * WordPress MCP adapters typically return only `WP_Error::get_error_message()`
	 * as tool text. Batch-mutate `schema_requests` and custom-CSS grant keys
	 * must survive that flattening or agents dead-end on a one-line rejection.
	 */
	public static function with_agent_visible_payload( \WP_Error $error ): \WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || [] === $data ) {
			return $error;
		}

		$payload = [];
		foreach ( self::AGENT_VISIBLE_MESSAGE_KEYS as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$payload[ $key ] = $data[ $key ];
			}
		}
		// A busy or rate-limited call tells the caller whether and when to retry.
		if ( array_key_exists( 'retryable', $data ) ) {
			$payload['retryable'] = (bool) $data['retryable'];
		}
		// A refusal the caller must not retry says it was blocked, not failed.
		if ( false === ( $data['retryable'] ?? null ) && 'blocked' === ( $data['execution_status'] ?? null ) ) {
			$payload['execution_status'] = 'blocked';
		}
		$retry_after = self::retry_after( $data );
		if ( null !== $retry_after ) {
			$payload['retry_after'] = $retry_after;
		}
		// A failed write names its change set so the caller can pass it as repair_of.
		$change_set_id = self::change_set_id( $data );
		if ( '' !== $change_set_id ) {
			$payload['change_set_id'] = $change_set_id;
		}

		if ( in_array( (string) $error->get_error_code(), self::RESCUE_ERROR_CODES, true ) ) {
			$payload = array_merge( [ 'code' => (string) $error->get_error_code() ], $payload, self::rescue_fields( $data ) );
		}

		if ( empty( $payload['schema_requests'] ) && isset( $data['items'] ) && is_array( $data['items'] ) ) {
			$requests = [];
			foreach ( $data['items'] as $item ) {
				$request = is_array( $item ) ? ( $item['error']['data']['schema_request'] ?? null ) : null;
				if ( is_array( $request ) ) {
					$requests[] = $request;
				}
			}
			if ( [] !== $requests ) {
				$payload['schema_requests'] = array_values( $requests );
			}
		}

		if ( [] === $payload ) {
			return $error;
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return $error;
		}

		$message = (string) $error->get_error_message();
		if ( str_contains( $message, $encoded ) ) {
			return $error;
		}

		return new \WP_Error( $error->get_error_code(), rtrim( $message ) . ' ' . $encoded, $data );
	}

	public static function from_wp_error( \WP_Error $error ): array {
		$code    = (string) $error->get_error_code();
		$message = $error->get_error_message();
		$data    = $error->get_error_data();

		$envelope = [
			'error' => [
				'code'    => $code,
				'message' => $message,
			],
		];

		if ( is_array( $data ) && [] !== $data ) {
			$safe = self::filter_safe_data( $data );
			if ( [] !== $safe ) {
				$envelope['error']['data'] = $safe;
			}
		}

		return $envelope;
	}

	/**
	 * Strips all keys not in SAFE_ERROR_DATA_KEYS from a data array.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function filter_safe_data( array $data ): array {
		$allowed = array_flip( self::SAFE_ERROR_DATA_KEYS );
		$out     = [];
		foreach ( $data as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			if ( 'violations' === $key ) {
				$violations = self::filter_violations( $value );
				if ( [] !== $violations ) {
					$out[ $key ] = $violations;
				}
				continue;
			}

			if ( 'widget' === $key && is_scalar( $value ) ) {
				$out[ $key ] = mb_substr( (string) $value, 0, 120 );
				continue;
			}

			if ( in_array( $key, [ 'cause', 'repair' ], true ) && is_scalar( $value ) ) {
				$out[ $key ] = mb_substr( (string) $value, 0, 480 );
				continue;
			}

			if ( 'retryable' === $key ) {
				$out[ $key ] = (bool) $value;
				continue;
			}

			$out[ $key ] = $value;
		}
		$retry_after = self::retry_after( $data );
		if ( null !== $retry_after ) {
			$out['retry_after'] = $retry_after;
		}
		$change_set_id = self::change_set_id( $data );
		if ( '' !== $change_set_id ) {
			$out['change_set_id'] = $change_set_id;
		}
		return $out;
	}

	/**
	 * The plain fields of a Rescue error that the message carries: short strings only.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, string>
	 */
	private static function rescue_fields( array $data ): array {
		$fields = [];
		foreach ( self::RESCUE_MESSAGE_KEYS as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== $data[ $key ] ) {
				$fields[ $key ] = mb_substr( sanitize_text_field( $data[ $key ] ), 0, 96 );
			}
		}
		$url = self::approval_url( $data );
		if ( '' !== $url ) {
			$fields['approval_url'] = $url;
		}
		return $fields;
	}

	/**
	 * The address of the page where an administrator approves an undo: an http or https URL, kept whole or left out.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function approval_url( array $data ): string {
		$url = $data['approval_url'] ?? null;
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > self::APPROVAL_URL_MAX || 1 !== preg_match( '#^https?://[^\s\x00-\x1F\x7F]+$#i', $url ) ) {
			return '';
		}
		$clean = esc_url_raw( $url, [ 'http', 'https' ] );
		return $clean === $url ? $url : '';
	}

	/**
	 * Seconds a caller should wait before retrying, from `retry_after` or
	 * `retry_after_seconds`. Only a positive number is reported, capped at one hour.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function retry_after( array $data ): ?int {
		foreach ( [ 'retry_after', 'retry_after_seconds' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) && (float) $data[ $key ] > 0 ) {
				return min( 3600, (int) ceil( (float) $data[ $key ] ) );
			}
		}
		return null;
	}

	/**
	 * Identifier of the change set a failed write attached to its error data.
	 *
	 * @param array<string, mixed> $data
	 */
	private static function change_set_id( array $data ): string {
		$id = is_array( $data['change_set'] ?? null ) ? ( $data['change_set']['change_set_id'] ?? null ) : null;
		return is_string( $id ) ? mb_substr( sanitize_text_field( $id ), 0, 96 ) : '';
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function filter_violations( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( array_slice( $value, 0, 12 ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$violation = [];
			foreach ( [ 'path', 'code', 'expected' ] as $key ) {
				if ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) {
					$violation[ $key ] = mb_substr( (string) $item[ $key ], 0, 240 );
				}
			}

			if ( array_key_exists( 'got', $item ) ) {
				$violation['got'] = self::safe_violation_value( $item['got'] );
			}

			if ( [] !== $violation ) {
				$out[] = $violation;
			}
		}

		return $out;
	}

	private static function safe_violation_value( mixed $value ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return mb_substr( $value, 0, 240 );
		}

		if ( is_array( $value ) ) {
			return '[array:' . count( $value ) . ']';
		}

		if ( is_object( $value ) ) {
			return '[object:' . get_class( $value ) . ']';
		}

		return '[' . gettype( $value ) . ']';
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'error' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'code'    => [ 'type' => 'string' ],
						'message' => [ 'type' => 'string' ],
						'data'    => [
							'type'                 => 'object',
							'additionalProperties' => true,
							'properties'           => [
								'status' => [ 'type' => 'integer' ],
							],
						],
					],
					'required'             => [ 'code', 'message' ],
				],
			],
			'required'             => [ 'error' ],
		];
	}
}
