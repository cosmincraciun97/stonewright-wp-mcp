<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities;

use Stonewright\WpMcp\Security\AuditLog;
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
		$result     = $callback( $args );
		$status     = 'ok';
		if ( $result instanceof \WP_Error ) {
			$code   = (string) $result->get_error_code();
			$data   = $result->get_error_data();
			$http   = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			$status = ( 403 === $http || str_contains( $code, 'forbidden' ) || str_contains( $code, 'blocked' ) || str_contains( $code, 'permission' ) )
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
				foreach ( [ 'execution_status', 'verification_status', 'rollback_status', 'before_sha256', 'after_sha256', 'cause_key', 'resource_type', 'operation_class' ] as $effect_key ) {
					if ( isset( $data[ $effect_key ] ) && is_scalar( $data[ $effect_key ] ) ) {
						$metadata[ $effect_key ] = $data[ $effect_key ];
					}
				}
			}
		} elseif ( is_array( $result ) ) {
			foreach ( [ 'execution_status', 'verification_status', 'rollback_status', 'before_sha256', 'after_sha256', 'changed_bytes', 'effect_verified', 'operation_class', 'resource_type' ] as $effect_key ) {
				if ( array_key_exists( $effect_key, $result ) && ( is_scalar( $result[ $effect_key ] ) || null === $result[ $effect_key ] ) ) {
					$metadata[ $effect_key ] = $result[ $effect_key ];
				}
			}
		}
		if ( [] !== $metadata ) {
			$sanitized['_meta'] = $metadata;
		}
		AuditLog::record( $this->name(), $sanitized, $status );
		return $result;
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
