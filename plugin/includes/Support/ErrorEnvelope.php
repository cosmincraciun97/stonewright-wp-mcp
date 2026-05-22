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
			if ( isset( $allowed[ $key ] ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
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
