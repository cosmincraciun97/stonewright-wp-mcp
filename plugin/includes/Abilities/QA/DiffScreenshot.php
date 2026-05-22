<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Companion\CompanionContract;
use Stonewright\WpMcp\QA\QaArtifactStore;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\CompanionClient;

/**
 * Pixel-diff two screenshots and return a mismatch ratio.
 * Contract decision: diff accepts artifact_ids (full paths from prior /screenshot
 * calls) rather than URLs — composable with ScreenshotPage output.
 *
 * @stonewright-status stable
 */
final class DiffScreenshot extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-diff-screenshot';
	}

	public function label(): string {
		return __( 'QA: Diff Screenshots', 'stonewright' );
	}

	public function description(): string {
		return __( 'Pixel-diffs a reference artifact against an actual screenshot artifact and returns a pass/fail ratio.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'reference_artifact_id' => [ 'type' => 'string' ],
				'actual_artifact_id'    => [ 'type' => 'string' ],
				'threshold'             => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'default' => 0.1 ],
				'ignore_regions'        => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'x'      => [ 'type' => 'integer' ],
							'y'      => [ 'type' => 'integer' ],
							'width'  => [ 'type' => 'integer' ],
							'height' => [ 'type' => 'integer' ],
						],
						'required'   => [ 'x', 'y', 'width', 'height' ],
					],
				],
			],
			'required'             => [ 'reference_artifact_id', 'actual_artifact_id' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'              => [ 'type' => 'boolean' ],
				'needs_reference' => [ 'type' => 'boolean' ],
				'diff_ratio'      => [ 'type' => 'number' ],
				'passed'          => [ 'type' => 'boolean' ],
				'threshold'       => [ 'type' => 'number' ],
				'diff_url'        => [ 'type' => 'string' ],
				'request_id'      => [ 'type' => 'string' ],
				'mismatch_regions' => [ 'type' => 'array' ],
			],
			'required'   => [ 'ok', 'needs_reference' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$request_id    = wp_generate_uuid4();
				$artifact_path = QaArtifactStore::reserve( $request_id );

				$body = [
					'request_id'             => $request_id,
					'reference_artifact_id'  => $args['reference_artifact_id'],
					'actual_artifact_id'     => $args['actual_artifact_id'],
					'artifact_path'          => rtrim( $artifact_path, '/' ),
					'threshold'              => $args['threshold'] ?? 0.1,
					'ignore_regions'         => $args['ignore_regions'] ?? [],
				];

				// Validate request payload before sending
				$req_check = CompanionContract::validate( 'diff', 'request', $body );
				if ( is_wp_error( $req_check ) ) {
					return $req_check;
				}

				$result = CompanionClient::post( '/diff', $body );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				// Validate response payload after parsing
				$resp_check = CompanionContract::validate( 'diff', 'response', $result );
				if ( is_wp_error( $resp_check ) ) {
					return $resp_check;
				}

				// needs_reference: honest about no-baseline case
				if ( true === ( $result['needs_reference'] ?? false ) ) {
					return $this->ok( [ 'needs_reference' => true ] );
				}

				$diff_url = ! empty( $result['diff_url'] )
					? ( QaArtifactStore::url_for( (string) $result['diff_url'] ) ?: $result['diff_url'] )
					: '';

				return $this->ok( array_merge( $result, [ 'diff_url' => $diff_url ] ) );
			}
		);
	}
}
