<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Runtime;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\ProtectedElementorWriteGuard;

/**
 * Executes short, guarded PHP snippets inside the loaded WordPress runtime.
 *
 * @stonewright-status stable
 */
final class PhpExecute extends AbilityKernel {
	use ConfirmationGuard;

	private const DEFAULT_MAX_OUTPUT_BYTES = 16384;
	private const MIN_MAX_OUTPUT_BYTES     = 1024;
	private const MAX_MAX_OUTPUT_BYTES     = 65536;

	public function name(): string {
		return 'stonewright/php-execute';
	}

	public function label(): string {
		return __( 'Execute PHP in WordPress runtime', 'stonewright' );
	}

	public function description(): string {
		return __( 'Executes a short PHP body inside WordPress for compact runtime inspection or plugin API work. Raw Elementor document writes are never allowed: use typed Elementor abilities and their schema_request repair path. Requires manage_options, is audited and output-limited, and requires confirmation in production-safe mode.', 'stonewright' );
	}

	public function category(): string {
		return 'runtime';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'code' ],
			'properties'           => [
				'code'               => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'PHP source body to run inside WordPress. Do not include secrets unless the site owner explicitly asks.',
				],
				'timeout_seconds'    => [
					'type'        => 'integer',
					'default'     => 30,
					'minimum'     => 1,
					'maximum'     => 30,
					'description' => 'Best-effort PHP time limit. Database, option, and file changes are not rolled back automatically.',
				],
				'return_mode'        => [
					'type'        => 'string',
					'enum'        => [ 'auto', 'result', 'stdout' ],
					'default'     => 'auto',
					'description' => 'Selects the primary payload. stdout and result metadata are always returned.',
				],
				'max_output_bytes'   => [
					'type'        => 'integer',
					'default'     => self::DEFAULT_MAX_OUTPUT_BYTES,
					'minimum'     => self::MIN_MAX_OUTPUT_BYTES,
					'maximum'     => self::MAX_MAX_OUTPUT_BYTES,
					'description' => 'Maximum bytes returned for stdout and for the normalized result payload.',
				],
				'confirmation_token' => [
					'type'        => 'string',
					'description' => 'Required in production-safe mode because runtime PHP may mutate WordPress state.',
				],
				'read_only'          => [
					'type'        => 'boolean',
					'default'     => false,
					'description' => 'When true, block WordPress mutation APIs (update_*/delete_*/wp_insert_*/$wpdb writes) and only allow inspection. Prefer this for Elementor document reads.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                 => [ 'type' => 'boolean' ],
				'stdout'             => [ 'type' => 'string' ],
				'result'             => [ 'type' => [ 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ] ],
				'result_type'        => [ 'type' => 'string' ],
				'primary'            => [ 'type' => [ 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ] ],
				'return_mode'        => [ 'type' => 'string' ],
				'timeout_seconds'    => [ 'type' => 'integer' ],
				'elapsed_ms'         => [ 'type' => 'integer' ],
				'memory_delta_bytes' => [ 'type' => 'integer' ],
				'stdout_bytes'       => [ 'type' => 'integer' ],
				'result_bytes'       => [ 'type' => 'integer' ],
				'stdout_truncated'   => [ 'type' => 'boolean' ],
				'result_truncated'   => [ 'type' => 'boolean' ],
				'max_output_bytes'   => [ 'type' => 'integer' ],
			],
			'required'   => [ 'ok', 'stdout', 'result', 'result_type', 'primary', 'return_mode', 'timeout_seconds', 'elapsed_ms', 'memory_delta_bytes', 'stdout_bytes', 'result_bytes', 'stdout_truncated', 'result_truncated', 'max_output_bytes' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['code'] ) || ! is_string( $args['code'] ) || '' === trim( $args['code'] ) ) {
			return $this->error( 'php_execute_missing_code', __( 'A non-empty PHP code string is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		return $this->audit(
			$args,
			function ( array $runtime_args ): array|\WP_Error {
				$verify_args = array_filter(
					$runtime_args,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $runtime_args, $verify_args );
				if ( $token_error instanceof \WP_Error ) {
					return $token_error;
				}

				$read_only = (bool) ( $runtime_args['read_only'] ?? false );
				$guard     = ProtectedElementorWriteGuard::inspect(
					(string) $runtime_args['code'],
					$read_only
				);
				if ( $guard instanceof \WP_Error ) {
					return $guard;
				}

				return $this->execute_code(
					self::normalise_code( (string) $runtime_args['code'] ),
					self::normalise_timeout( $runtime_args['timeout_seconds'] ?? 30 ),
					self::normalise_return_mode( $runtime_args['return_mode'] ?? 'auto' ),
					self::normalise_max_output_bytes( $runtime_args['max_output_bytes'] ?? self::DEFAULT_MAX_OUTPUT_BYTES ),
					$read_only
				);
			}
		);
	}

	/** @return array<int, string> */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'code' ] );
	}

	protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
		$data = $result instanceof \WP_Error ? (array) $result->get_error_data() : $result;
		return [
			'code_sha256'      => hash( 'sha256', (string) ( $args['code'] ?? '' ) ),
			'duration_ms'      => $elapsed_ms,
			'result_status'    => $result instanceof \WP_Error ? 'error' : 'ok',
			'result_type'      => (string) ( $data['result_type'] ?? ( $result instanceof \WP_Error ? 'WP_Error' : 'unknown' ) ),
			'stdout_bytes'     => (int) ( $data['stdout_bytes'] ?? 0 ),
			'result_bytes'     => (int) ( $data['result_bytes'] ?? 0 ),
			'stdout_truncated' => (bool) ( $data['stdout_truncated'] ?? false ),
			'result_truncated' => (bool) ( $data['result_truncated'] ?? false ),
		];
	}

	private function execute_code( string $code, int $timeout_seconds, string $return_mode, int $max_output_bytes, bool $read_only = false ): array|\WP_Error {
		$started_ns          = hrtime( true );
		$memory_before       = memory_get_usage( true );
		$original_time_limit = self::current_time_limit();
		$buffer_level        = ob_get_level();
		$write_guards        = ProtectedElementorWriteGuard::install( $read_only );

		self::apply_time_limit( $timeout_seconds );
		ob_start();
		try {
			$result = self::evaluate( $code );
			$stdout = self::close_execution_buffers( $buffer_level );
		} catch ( \Throwable $throwable ) {
			$stdout         = self::close_execution_buffers( $buffer_level );
			$stdout_payload = self::limit_string( $stdout, $max_output_bytes );

			return $this->error(
				'php_execute_failed',
				sprintf(
					/* translators: 1: PHP exception class, 2: exception message. */
					__( 'PHP execution failed with %1$s: %2$s', 'stonewright' ),
					get_class( $throwable ),
					$throwable->getMessage()
				),
				[
					'status'           => 500,
					'exception_class'  => get_class( $throwable ),
					'exception_line'   => $throwable->getLine(),
					'stdout'           => $stdout_payload['value'],
					'stdout_bytes'     => $stdout_payload['bytes'],
					'stdout_truncated' => $stdout_payload['truncated'],
					'result_bytes'     => 0,
					'result_truncated' => false,
					'elapsed_ms'       => self::elapsed_ms( $started_ns ),
					'timeout_seconds'  => $timeout_seconds,
					'read_only'        => $read_only,
				]
			);
		} finally {
			ProtectedElementorWriteGuard::uninstall( $write_guards );
			self::restore_time_limit( $original_time_limit );
		}

		$stdout_payload = self::limit_string( $stdout, $max_output_bytes );
		$result_payload = self::limit_result( $result, $max_output_bytes );
		$primary        = match ( $return_mode ) {
			'stdout' => $stdout_payload['value'],
			'result' => $result_payload['value'],
			default  => null !== $result_payload['value'] ? $result_payload['value'] : $stdout_payload['value'],
		};

		return [
			'ok'                 => true,
			'stdout'             => $stdout_payload['value'],
			'result'             => $result_payload['value'],
			'result_type'        => get_debug_type( $result ),
			'primary'            => $primary,
			'return_mode'        => $return_mode,
			'timeout_seconds'    => $timeout_seconds,
			'elapsed_ms'         => self::elapsed_ms( $started_ns ),
			'memory_delta_bytes' => memory_get_usage( true ) - $memory_before,
			'stdout_bytes'       => $stdout_payload['bytes'],
			'result_bytes'       => $result_payload['bytes'],
			'stdout_truncated'   => $stdout_payload['truncated'],
			'result_truncated'   => $result_payload['truncated'],
			'max_output_bytes'   => $max_output_bytes,
		];
	}

	private static function normalise_code( string $code ): string {
		$code = trim( $code );
		if ( str_starts_with( $code, '<?php' ) ) {
			$code = substr( $code, 5 );
		} elseif ( str_starts_with( $code, '<?' ) ) {
			$code = substr( $code, 2 );
		}
		return trim( $code );
	}

	private static function normalise_timeout( mixed $value ): int {
		$seconds = is_numeric( $value ) ? (int) $value : 30;
		return max( 1, min( 30, $seconds ) );
	}

	private static function normalise_return_mode( mixed $value ): string {
		$mode = is_string( $value ) ? strtolower( trim( $value ) ) : 'auto';
		return in_array( $mode, [ 'auto', 'result', 'stdout' ], true ) ? $mode : 'auto';
	}

	private static function normalise_max_output_bytes( mixed $value ): int {
		$bytes = is_numeric( $value ) ? (int) $value : self::DEFAULT_MAX_OUTPUT_BYTES;
		return max( self::MIN_MAX_OUTPUT_BYTES, min( self::MAX_MAX_OUTPUT_BYTES, $bytes ) );
	}

	/** @return array{value:string,bytes:int,truncated:bool} */
	private static function limit_string( string $value, int $max_bytes ): array {
		$bytes = strlen( $value );
		return [
			'value'     => $bytes > $max_bytes ? substr( $value, 0, $max_bytes ) : $value,
			'bytes'     => $bytes,
			'truncated' => $bytes > $max_bytes,
		];
	}

	/** @return array{value:mixed,bytes:int,truncated:bool} */
	private static function limit_result( mixed $result, int $max_bytes ): array {
		$normalized = self::normalise_result( $result );
		$encoded    = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$encoded    = false === $encoded ? serialize( $normalized ) : $encoded;
		$bytes      = strlen( $encoded );
		if ( $bytes <= $max_bytes ) {
			return [ 'value' => $normalized, 'bytes' => $bytes, 'truncated' => false ];
		}

		return [
			'value'     => sprintf( '[truncated result type=%s bytes=%d sha256=%s]', get_debug_type( $result ), $bytes, hash( 'sha256', $encoded ) ),
			'bytes'     => $bytes,
			'truncated' => true,
		];
	}

	private static function normalise_result( mixed $value, int $depth = 0 ): mixed {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}
		if ( $depth >= 8 ) {
			return '[maximum result depth reached]';
		}
		if ( is_array( $value ) ) {
			$out   = [];
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= 500 ) {
					$out['_stonewright_truncated_items'] = count( $value ) - $count;
					break;
				}
				$out[ $key ] = self::normalise_result( $item, $depth + 1 );
				++$count;
			}
			return $out;
		}
		if ( $value instanceof \JsonSerializable ) {
			return self::normalise_result( $value->jsonSerialize(), $depth + 1 );
		}
		if ( $value instanceof \stdClass ) {
			return self::normalise_result( get_object_vars( $value ), $depth + 1 );
		}
		if ( is_object( $value ) ) {
			return '[object ' . get_class( $value ) . ']';
		}
		if ( is_resource( $value ) ) {
			return '[resource ' . get_resource_type( $value ) . ']';
		}
		return '[' . get_debug_type( $value ) . ']';
	}

	private static function close_execution_buffers( int $initial_level ): string {
		$stdout = '';
		while ( ob_get_level() > $initial_level ) {
			$stdout = (string) ob_get_clean() . $stdout;
		}
		return $stdout;
	}

	private static function current_time_limit(): int {
		$value = ini_get( 'max_execution_time' );
		return false === $value || ! is_numeric( $value ) ? 0 : (int) $value;
	}

	private static function apply_time_limit( int $seconds ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function restore_time_limit( int $seconds ): void {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $seconds ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function elapsed_ms( int $started_ns ): int {
		return (int) floor( ( hrtime( true ) - $started_ns ) / 1_000_000 );
	}

	private static function evaluate( string $code ): mixed {
		global $wpdb;

		return eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}
