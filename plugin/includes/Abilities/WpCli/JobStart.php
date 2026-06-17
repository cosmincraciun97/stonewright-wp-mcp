<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WpCli;

use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;

/**
 * Starts a tokenized WP-CLI background job through the local companion.
 *
 * @stonewright-status stable
 */
final class JobStart extends WpCliAbility {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wp-cli-job-start';
	}

	public function label(): string {
		return __( 'Start WP-CLI background job', 'stonewright' );
	}

	public function description(): string {
		return __( 'Starts a tokenized WP-CLI command or batch in the companion background queue so long plugin, import, cache, media, or content operations do not block the MCP request.', 'stonewright' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array_merge(
				$this->common_input_properties(),
				[
					'command'            => [
						'type'        => 'array',
						'minItems'    => 1,
						'items'       => [ 'type' => 'string' ],
						'description' => 'Single WP-CLI argv token array after wp.',
					],
					'commands'           => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => 100,
						'items'       => [
							'type'     => 'array',
							'minItems' => 1,
							'items'    => [ 'type' => 'string' ],
						],
						'description' => 'Batch WP-CLI argv token arrays after wp.',
					],
					'parseJson'          => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Parse stdout as JSON when the command uses --format=json.',
					],
					'stopOnError'        => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Stop batch jobs after the first failed command.',
					],
					'responseMode'       => [
						'type'        => 'string',
						'enum'        => [ 'full', 'summary' ],
						'default'     => 'summary',
						'description' => 'Use summary to omit stdout/stderr bodies in completed job results.',
					],
					'confirmation_token' => [
						'type'        => 'string',
						'description' => 'Required in production-safe mode because WP-CLI jobs may write WordPress state.',
					],
				]
			),
		];
	}

	public function output_schema(): array {
		return self::job_output_schema();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( empty( $args['command'] ) && empty( $args['commands'] ) ) {
			return $this->error( 'wp_cli_invalid_job', __( 'A command or commands array is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$request_args = array_filter(
					$a,
					static fn( string $key ): bool => 'confirmation_token' !== $key,
					ARRAY_FILTER_USE_KEY
				);

				$token_error = $this->confirmation_token_error( $a, $request_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				if ( ! isset( $request_args['responseMode'] ) ) {
					$request_args['responseMode'] = 'summary';
				}

				return $this->companion_post( '/wp-cli/job-start', $request_args );
			}
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function job_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'job_id'        => [ 'type' => 'string' ],
				'status'        => [ 'type' => 'string' ],
				'kind'          => [ 'type' => 'string' ],
				'command_count' => [ 'type' => 'integer' ],
				'started_at'    => [ 'type' => 'string' ],
				'completed_at'  => [ 'type' => [ 'string', 'null' ] ],
				'duration_ms'   => [ 'type' => 'integer' ],
				'result'        => [ 'type' => [ 'object', 'array', 'null' ] ],
				'error'         => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'job_id', 'status', 'kind', 'command_count', 'started_at', 'completed_at', 'duration_ms', 'result' ],
		];
	}
}
