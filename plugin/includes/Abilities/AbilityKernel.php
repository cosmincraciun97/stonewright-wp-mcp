<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities;

use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Base class abilities extend so they only have to implement
 * `name()`, `input_schema()`, etc. The kernel handles audit logging,
 * default meta, and convenience helpers.
 */
abstract class AbilityKernel implements Ability {

	abstract public function name(): string;

	abstract public function label(): string;

	abstract public function description(): string;

	abstract public function category(): string;

	/**
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok' => [ 'type' => 'boolean' ],
			],
			'required'   => [ 'ok' ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function meta(): array {
		return [];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::read();
	}

	/**
	 * Convenience: wrap a write ability with audit-log recording.
	 *
	 * On WP_Error results, stamps error_code + error_message into `_meta` so
	 * ErrorPatterns can form actionable recurring-error signatures. Ability
	 * authors must never embed secret input values in WP_Error messages.
	 *
	 * @param array<string, mixed> $args
	 * @param callable             $callback
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function audit( array $args, callable $callback ) {
		$started_ns = hrtime( true );
		// A typed Elementor writer may use a narrow legacy response shape. Reset
		// the request-local receipt here and attach the common transaction contract
		// below so every write surface returns the same machine-readable evidence.
		\Stonewright\WpMcp\Support\ElementorData::clear_write_context();
		$result     = $callback( $args );
		$elementor_receipt = \Stonewright\WpMcp\Support\ElementorData::last_elementor_write_receipt();
		if ( $result instanceof \WP_Error && [] !== $elementor_receipt ) {
			$data = $result->get_error_data();
			$data = is_array( $data ) ? $data : [];
			if ( ! isset( $data['write_receipt'] ) ) {
				$data['write_receipt'] = $elementor_receipt;
				$result->add_data( $data, $result->get_error_code() );
			}
		} elseif ( is_array( $result ) && [] !== $elementor_receipt && ! isset( $result['write_receipt'] ) ) {
			$result['write_receipt'] = $elementor_receipt;
		}
		$status     = 'ok';
		if ( $result instanceof \WP_Error ) {
			$code   = (string) $result->get_error_code();
			$data   = $result->get_error_data();
			$http   = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			$execution = is_array( $data ) ? (string) ( $data['execution_status'] ?? '' ) : '';
			$status = ( 403 === $http || 'blocked' === $execution || self::is_blocked_error_code( $code ) )
				? 'blocked'
				: 'error';
		} elseif ( is_array( $result ) ) {
			// Mutation success requires effect verification when the ability reports it.
			if ( array_key_exists( 'effect_verified', $result ) && true !== $result['effect_verified'] ) {
				$status = 'error';
			}
			if ( isset( $result['verification_status'] ) && in_array( (string) $result['verification_status'], [ 'failed', 'missing' ], true ) ) {
				$status = 'error';
					}
				}
		$sanitized  = $this->sanitize_for_audit( $args );
		$metadata   = $this->audit_metadata(
			$args,
			$result,
			(int) floor( ( hrtime( true ) - $started_ns ) / 1_000_000 )
		);
		if ( $result instanceof \WP_Error ) {
			$message                   = preg_replace( '/\s+/', ' ', trim( (string) $result->get_error_message() ) ) ?? '';
			$metadata['error_code']    = sanitize_key( (string) $result->get_error_code() );
			$metadata['error_message'] = mb_substr( $message, 0, 200 );
			$data                      = $result->get_error_data();
			if ( is_array( $data ) ) {
				foreach ( [ 'execution_status', 'verification_status', 'rollback_status', 'before_sha256', 'after_sha256', 'cause_key', 'cause_fingerprint', 'strategy_fingerprint', 'resource_type', 'resource_ref', 'resource_key_hash', 'normalized_path', 'operation_class', 'change_set_id', 'transaction_id', 'retryable', 'retry_after_seconds', 'root_error_code', 'root_error_path', 'failed_action_index', 'element_id', 'setting_path', 'expected_type', 'actual_type', 'schema_version', 'remediation_code', 'rule_id', 'http_status' ] as $effect_key ) {
					if ( isset( $data[ $effect_key ] ) && is_scalar( $data[ $effect_key ] ) ) {
						$metadata[ $effect_key ] = $data[ $effect_key ];
					}
				}
				$metadata = self::merge_receipt_metadata( $metadata, is_array( $data['write_receipt'] ?? null ) ? $data['write_receipt'] : [] );
			}
		} elseif ( is_array( $result ) ) {
			foreach ( [ 'execution_status', 'verification_status', 'rollback_status', 'before_sha256', 'after_sha256', 'changed_bytes', 'effect_verified', 'operation_class', 'resource_type', 'resource_ref', 'resource_key_hash', 'normalized_path', 'cause_fingerprint', 'strategy_fingerprint', 'change_set_id', 'transaction_id', 'retryable', 'retry_after_seconds', 'root_error_code', 'root_error_path', 'failed_action_index', 'element_id', 'setting_path', 'expected_type', 'actual_type', 'schema_version', 'remediation_code', 'category', 'outcome' ] as $effect_key ) {
				if ( array_key_exists( $effect_key, $result ) && ( is_scalar( $result[ $effect_key ] ) || null === $result[ $effect_key ] ) ) {
					$metadata[ $effect_key ] = $result[ $effect_key ];
				}
			}
			$metadata = self::merge_receipt_metadata( $metadata, is_array( $result['write_receipt'] ?? null ) ? $result['write_receipt'] : [] );
		}
		if ( [] !== $metadata ) {
			$sanitized['_meta'] = $metadata;
		}
		AuditLog::record( $this->name(), $sanitized, $status );
		if ( 'ok' === $status && is_array( $result ) && isset( $result['verified_repair_receipt'] ) ) {
			\Stonewright\WpMcp\Security\ErrorPatterns::observe_verified_repair( $this->name(), $result );
		}
		return $result;
	}

	/**
	 * Production-safe confirmation-token gate for write execute paths.
	 *
	 * Returns null when the mode is not production-safe or the token verifies.
	 * Returns a WP_Error when the token is missing or invalid.
	 *
	 * @param array<string, mixed>      $args        Full ability args (used to extract confirmation_token).
	 * @param array<string, mixed>|null $verify_args Args signed when the token was issued. Defaults to $args;
	 *                                               ConfirmationToken strips confirmation_token before hashing.
	 */
	protected function require_production_safe_token( array $args, ?array $verify_args = null ): ?\WP_Error {
		if ( ! Permissions::is_production_safe() ) {
			return null;
		}

		$token = isset( $args['confirmation_token'] ) ? (string) $args['confirmation_token'] : '';
		if ( '' === $token ) {
			return new \WP_Error(
				'stonewright_confirmation_required',
				__( 'Production-safe mode requires a confirmation_token.', 'stonewright' ),
				[ 'status' => 403 ]
			);
		}

		$result = ConfirmationToken::verify_or_error( $token, $this->name(), $verify_args ?? $args );
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return null;
	}

	/**
	 * Audit wrapper that requires a production-safe confirmation token before
	 * the write callback runs. Read abilities must keep using audit().
	 *
	 * @param array<string, mixed> $args
	 * @param callable             $callback
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function audit_write( array $args, callable $callback ) {
		return $this->audit(
			$args,
			function ( array $args ) use ( $callback ) {
				$token_error = $this->require_production_safe_token( $args );
				if ( $token_error instanceof \WP_Error ) {
					return $token_error;
				}
				return $callback( $args );
			}
		);
	}

	private static function is_blocked_error_code( string $code ): bool {
		foreach ( [ 'forbidden', 'blocked', 'permission', 'confirmation_required', 'grant_required', 'approval_required', 'read_only', 'raw_elementor', 'architecture_mismatch', 'migration_has_loss', 'rule_violation' ] as $marker ) {
			if ( str_contains( $code, $marker ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Flatten only the safe scalar receipt contract into audit metadata. Hash
	 * names are mapped to the audit schema; nested raw payloads never enter it.
	 *
	 * @param array<string,mixed> $metadata
	 * @param array<string,mixed> $receipt
	 * @return array<string,mixed>
	 */
	private static function merge_receipt_metadata( array $metadata, array $receipt ): array {
		$map = [
			'transaction_id'      => 'transaction_id',
			'change_set_id'       => 'change_set_id',
			'architecture'        => 'architecture',
			'before_hash'         => 'before_sha256',
			'planned_hash'        => 'planned_sha256',
			'after_hash'          => 'after_sha256',
			'readback_hash'       => 'readback_sha256',
			'verification_status' => 'verification_status',
			'rollback_status'     => 'rollback_status',
			'root_error_code'     => 'root_error_code',
			'root_error_path'     => 'root_error_path',
			'retryable'           => 'retryable',
			'retry_after_seconds' => 'retry_after_seconds',
		];
		foreach ( $map as $receipt_key => $metadata_key ) {
			if ( array_key_exists( $receipt_key, $receipt ) && ( is_scalar( $receipt[ $receipt_key ] ) || null === $receipt[ $receipt_key ] ) ) {
				$metadata[ $metadata_key ] = $receipt[ $receipt_key ];
			}
		}
		return $metadata;
	}

	/**
	 * Add safe, compact result metadata without logging the result payload.
	 *
	 * @param array<string, mixed>          $args
	 * @param array<string, mixed>|\WP_Error $result
	 * @return array<string, scalar|null>
	 */
	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		return [];
	}

	/**
	 * Keys whose values must never appear in audit logs even partially.
	 * Subclasses that accept user-supplied source / secret material may call
	 * parent and merge additional keys, but must never remove these defaults.
	 *
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return [
			'confirmation_token',
			'checkpoint_token',
			'token',
			'password',
			'user_pass',
			'api_key',
			'secret',
			'app_password',
			'application_password',
		];
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	protected function sanitize_for_audit( array $args ): array {
		$redacted = array_flip( $this->audit_redacted_keys() );
		$out      = [];
		foreach ( $args as $key => $value ) {
			if ( isset( $redacted[ $key ] ) ) {
				$out[ $key ] = $this->redact_value( $value );
				continue;
			}

			if ( is_scalar( $value ) ) {
				$out[ $key ] = is_string( $value ) ? mb_substr( $value, 0, 500 ) : $value;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = '[array:' . count( $value ) . ']';
			} else {
				$out[ $key ] = '[' . gettype( $value ) . ']';
			}
		}
		return $out;
	}

	/**
	 * Render a redacted marker for an audit-sensitive value of any type.
	 * Strings include length + truncated sha256 so operators can correlate
	 * audit events without leaking content. Arrays/objects include the
	 * count or class name so the marker still carries some shape, but no
	 * element of the value itself ever reaches the audit log.
	 */
	private function redact_value( mixed $value ): string {
		if ( is_string( $value ) ) {
			$len    = mb_strlen( $value );
			$digest = mb_substr( hash( 'sha256', $value ), 0, 8 );
			return "[redacted, length={$len}, sha256={$digest}]";
		}

		if ( is_array( $value ) ) {
			return '[redacted, type=array, length=' . count( $value ) . ']';
		}

		if ( is_object( $value ) ) {
			return '[redacted, type=object, class=' . get_class( $value ) . ']';
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return '[redacted, type=' . gettype( $value ) . ']';
		}

		if ( null === $value ) {
			return '[redacted, type=NULL]';
		}

		return '[redacted, type=' . gettype( $value ) . ']';
	}

	protected function ok( array $payload = [] ): array {
		return array_merge( [ 'ok' => true ], $payload );
	}

	protected function error( string $code, string $message, array $data = [] ): \WP_Error {
		return new \WP_Error( 'stonewright_' . $code, $message, $data );
	}
}
