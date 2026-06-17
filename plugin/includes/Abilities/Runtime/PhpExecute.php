<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Runtime;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Executes short PHP snippets inside the loaded WordPress runtime.
 *
 * @stonewright-status stable
 */
final class PhpExecute extends AbilityKernel {

	public function name(): string {
		return 'stonewright/php-execute';
	}

	public function label(): string {
		return __( 'Execute PHP in WordPress runtime', 'stonewright' );
	}

	public function description(): string {
		return __( 'Executes PHP inside the loaded WordPress runtime with access to WordPress functions, loaded plugins, $wpdb, and normal PHP runtime APIs. Use for direct runtime inspection, plugin API calls, and compact implementation loops when typed abilities would require many calls.', 'stonewright' );
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
				'code'            => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => 'PHP source body to run inside WordPress. Do not include secrets unless the site owner explicitly asks.',
				],
				'timeout_seconds' => [
					'type'        => 'integer',
					'default'     => 30,
					'minimum'     => 1,
					'maximum'     => 30,
					'description' => 'Best-effort PHP time limit for this request. Database/config/file changes are not rolled back automatically.',
				],
				'return_mode'     => [
					'type'        => 'string',
					'enum'        => [ 'auto', 'result', 'stdout' ],
					'default'     => 'auto',
					'description' => 'Controls the primary payload the agent should inspect. stdout is always captured.',
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
				'result'             => [
					'type' => [ 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ],
				],
				'result_type'        => [ 'type' => 'string' ],
				'primary'            => [
					'type' => [ 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ],
				],
				'return_mode'        => [ 'type' => 'string' ],
				'timeout_seconds'    => [ 'type' => 'integer' ],
				'elapsed_ms'         => [ 'type' => 'integer' ],
				'memory_delta_bytes' => [ 'type' => 'integer' ],
			],
			'required'   => [ 'ok', 'stdout', 'result', 'result_type', 'primary', 'return_mode', 'timeout_seconds', 'elapsed_ms', 'memory_delta_bytes' ],
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
			function ( array $a ): array|\WP_Error {
				$code            = self::normalise_code( (string) $a['code'] );
				$timeout_seconds = self::normalise_timeout( $a['timeout_seconds'] ?? 30 );
				$return_mode     = self::normalise_return_mode( $a['return_mode'] ?? 'auto' );

				return $this->execute_code( $code, $timeout_seconds, $return_mode );
			}
		);
	}

	/**
	 * @return array<int, string>
	 */
	protected function audit_redacted_keys(): array {
		return array_merge( parent::audit_redacted_keys(), [ 'code' ] );
	}

	private function execute_code( string $code, int $timeout_seconds, string $return_mode ): array|\WP_Error {
		$started_ns    = hrtime( true );
		$memory_before = memory_get_usage( true );

		self::apply_time_limit( $timeout_seconds );

		ob_start();
		try {
			$result = self::evaluate( $code );
			$stdout = (string) ob_get_clean();
		} catch ( \Throwable $throwable ) {
			$stdout = '';
			if ( ob_get_level() > 0 ) {
				$stdout = (string) ob_get_clean();
			}

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
					'stdout'           => $stdout,
					'elapsed_ms'       => self::elapsed_ms( $started_ns ),
					'timeout_seconds'  => $timeout_seconds,
				]
			);
		}

		$memory_delta = memory_get_usage( true ) - $memory_before;

		return [
			'ok'                 => true,
			'stdout'             => $stdout,
			'result'             => $result,
			'result_type'        => get_debug_type( $result ),
			'primary'            => 'stdout' === $return_mode ? $stdout : $result,
			'return_mode'        => $return_mode,
			'timeout_seconds'    => $timeout_seconds,
			'elapsed_ms'         => self::elapsed_ms( $started_ns ),
			'memory_delta_bytes' => $memory_delta,
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

	private static function apply_time_limit( int $seconds ): void {
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
