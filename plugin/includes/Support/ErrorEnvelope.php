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
		return $out;
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
