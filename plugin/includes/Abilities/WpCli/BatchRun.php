<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WpCli;

use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;

/**
 * Runs multiple tokenized WP-CLI commands through the local companion.
 *
 * @stonewright-status stable
 */
final class BatchRun extends WpCliAbility {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/wp-cli-batch-run';
	}

	public function label(): string {
		return __( 'Batch run WP-CLI commands', 'stonewright' );
	}

	public function description(): string {
		return __( 'Runs multiple tokenized WP-CLI commands through the companion in one request. Use responseMode=summary for token-efficient CPT UI, ACF, post, meta, term, option, and plugin command workflows.', 'stonewright' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'commands' ],
			'properties'           => array_merge(
				$this->common_input_properties(),
				[
					'commands'           => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => 100,
						'items'       => [
							'type'     => 'array',
							'minItems' => 1,
							'items'    => [ 'type' => 'string' ],
						],
						'description' => 'List of WP-CLI argv token arrays after wp, e.g. [["post","create","--post_type=page"],["post","meta","update","42","key","value"]].',
					],
					'parseJson'          => [
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Parse each stdout as JSON when commands use --format=json.',
					],
					'stopOnError'        => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Stop after the first failed command.',
					],
					'responseMode'       => [
						'type'        => 'string',
						'enum'        => [ 'full', 'summary' ],
						'default'     => 'summary',
						'description' => 'Use summary to omit stdout/stderr bodies and return byte counts plus parsed_json.',
					],
					'confirmation_token' => [
						'type'        => 'string',
						'description' => 'Required in production-safe mode because WP-CLI commands may write WordPress state.',
					],
				]
			),
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'        => [ 'type' => 'boolean' ],
				'count'     => [ 'type' => 'integer' ],
				'succeeded' => [ 'type' => 'integer' ],
				'failed'    => [ 'type' => 'integer' ],
				'stopped'   => [ 'type' => 'boolean' ],
				'results'   => [ 'type' => 'array' ],
			],
			'required'   => [ 'ok', 'count', 'succeeded', 'failed', 'stopped', 'results' ],
		];
	}

	public function execute( array $args ): array|\WP_Error {
		if ( empty( $args['commands'] ) || ! is_array( $args['commands'] ) ) {
			return $this->error( 'wp_cli_invalid_commands', __( 'A non-empty commands array is required.', 'stonewright' ), [ 'status' => 400 ] );
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

				return $this->companion_post( '/wp-cli/batch', $request_args );
			}
		);
	}
}
