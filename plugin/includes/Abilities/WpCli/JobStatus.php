<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\WpCli;

/**
 * Polls a guarded WP-CLI background job through the local companion.
 *
 * @stonewright-status stable
 */
final class JobStatus extends WpCliAbility {

	public function name(): string {
		return 'stonewright/wp-cli-job-status';
	}

	public function label(): string {
		return __( 'Get WP-CLI background job status', 'stonewright' );
	}

	public function description(): string {
		return __( 'Polls a Stonewright companion WP-CLI background job and returns compact status plus the completed result when available.', 'stonewright' );
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'jobId'  => [
					'type'        => 'string',
					'description' => 'Job id returned by stonewright/wp-cli-job-start.',
				],
				'job_id' => [
					'type'        => 'string',
					'description' => 'Snake-case job id alias.',
				],
			],
		];
	}

	public function output_schema(): array {
		return JobStart::job_output_schema();
	}

	public function execute( array $args ): array|\WP_Error {
		if ( empty( $args['jobId'] ) && empty( $args['job_id'] ) ) {
			return $this->error( 'wp_cli_missing_job_id', __( 'A jobId is required.', 'stonewright' ), [ 'status' => 400 ] );
		}

		return $this->companion_post( '/wp-cli/job-status', $args );
	}
}
