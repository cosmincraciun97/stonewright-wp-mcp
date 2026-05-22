<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\QA\ResponsiveCheck;
use Stonewright\WpMcp\Abilities\QA\DiffScreenshot;
use Stonewright\WpMcp\QA\ReferenceArtifacts;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Composite QA ability:
 *   1. Resolve a labelled reference image per viewport.
 *   2. Capture current screenshots at the same viewports.
 *   3. Pixel-diff each (current vs reference) against threshold.
 *   4. Return a single pass/fail summary plus per-viewport detail.
 */
final class VerifyAgainstReference extends AbilityKernel {

	public function name(): string {
		return 'stonewright/qa-verify-against-reference';
	}

	public function label(): string {
		return __( 'QA: Verify against reference', 'stonewright' );
	}

	public function description(): string {
		return __( 'Capture responsive screenshots and pixel-diff each against a labelled reference baseline. Returns pass/fail + per-viewport mismatch ratios.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'post_id'         => [ 'type' => 'integer', 'minimum' => 1 ],
				'url'             => [ 'type' => 'string', 'format' => 'uri' ],
				'reference_label' => [ 'type' => 'string' ],
				'threshold'       => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 1, 'default' => 0.01 ],
				'design_spec'     => [ 'type' => 'object' ],
			],
			'required' => [ 'reference_label' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'passed'  => [ 'type' => 'boolean' ],
				'summary' => [ 'type' => 'string' ],
				'results' => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
			],
			'required'   => [ 'passed', 'results' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$ref = ReferenceArtifacts::resolve( (string) $args['reference_label'] );
				if ( null === $ref ) {
					return $this->error( 'reference_not_found', __( 'Reference label not registered.', 'stonewright' ) );
				}
				$threshold = (float) ( $args['threshold'] ?? 0.01 );

				$screens = ( new ResponsiveCheck() )->execute( array_intersect_key(
					$args,
					array_flip( [ 'post_id', 'url', 'design_spec' ] )
				) );
				if ( is_wp_error( $screens ) ) {
					return $screens;
				}

				$diffs  = [];
				$passed = true;
				foreach ( $screens as $shot ) {
					$diff = ( new DiffScreenshot() )->execute(
						[
							'reference_artifact_id' => $ref['artifact_id'] ?? '',
							'actual_artifact_id'    => $shot['artifact_id'] ?? '',
							'threshold'             => $threshold,
						]
					);
					if ( is_wp_error( $diff ) ) {
						return $diff;
					}
					$vp_passed = ! empty( $diff['passed'] );
					$passed    = $passed && $vp_passed;
					$diffs[]   = [
						'viewport'   => $shot['viewport'] ?? [],
						'diff_ratio' => $diff['diff_ratio'] ?? null,
						'diff_url'   => $diff['diff_url'] ?? '',
						'passed'     => $vp_passed,
					];
				}

				return [
					'passed'  => $passed,
					'summary' => $passed
						? __( 'All viewports within threshold.', 'stonewright' )
						: __( 'One or more viewports exceeded the pixel-diff threshold.', 'stonewright' ),
					'results' => $diffs,
				];
			}
		);
	}
}
