<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Design\Motion\ElementorV3MotionApplier;
use Stonewright\WpMcp\Design\Motion\MotionPlanVerifier;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Applies a compiled elementor-v3 motion plan through
 * stonewright/elementor-v3-batch-mutate with live-schema evidence.
 *
 * @stonewright-status stable
 */
final class MotionApplyElementorV3 extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/design-motion-apply-elementor-v3';
	}

	public function label(): string {
		return __( 'Design motion apply (Elementor V3)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry-run or apply a compiled Elementor V3 motion plan via sparse batch-mutate settings patches validated against live widget schema evidence.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'plan', 'targets', 'evidence' ],
			'properties'           => [
				'post_id'               => [ 'type' => 'integer', 'minimum' => 1 ],
				'plan'                  => [ 'type' => 'object' ],
				'targets'               => [
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 100,
					'items'    => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'target_id', 'element_id', 'widget_type' ],
						'properties'           => [
							'target_id'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 128 ],
							'element_id'  => [ 'type' => 'string', 'minLength' => 7, 'maxLength' => 12 ],
							'widget_type' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ],
						],
					],
				],
				'evidence'              => [
					'type'        => 'object',
					'description' => 'target_id => complete evidence copied from the current stonewright/elementor-schema response.',
					'additionalProperties' => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'control_key', 'value', 'capability', 'semantic_effect', 'schema_hash', 'runtime_fingerprint', 'source_plugin', 'source_version' ],
						'properties'           => [
							'control_key'        => [ 'type' => 'string', 'minLength' => 1 ],
							'value'              => [ 'type' => 'string', 'minLength' => 1 ],
							'capability'         => [ 'type' => 'string', 'enum' => [ 'entrance_animations', 'motion_effects' ] ],
							'semantic_effect'    => [ 'type' => 'string', 'minLength' => 1 ],
							'schema_hash'        => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
							'runtime_fingerprint'=> [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
							'source_plugin'      => [ 'type' => 'string' ],
							'source_version'     => [ 'type' => 'string' ],
						],
					],
				],
				'capability_digest'     => [ 'type' => 'object' ],
				'direction'             => [ 'type' => 'object' ],
				'dry_run'               => [ 'type' => 'boolean', 'default' => true ],
				'expected_tree_hash'    => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'confirmation_token'    => [ 'type' => 'string' ],
				'stonewright_context_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                 => [ 'type' => 'boolean' ],
				'post_id'            => [ 'type' => 'integer' ],
				'dry_run'            => [ 'type' => 'boolean' ],
				'plan_hash'          => [ 'type' => 'string' ],
				'resolved_targets'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'touched_element_ids'=> [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'mutation_result'    => [ 'type' => 'object' ],
			],
			'required'   => [ 'ok', 'post_id', 'dry_run', 'plan_hash' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$plan    = is_array( $args['plan'] ?? null ) ? $args['plan'] : [];
		if ( '' === (string) ( $plan['plan_hash'] ?? '' ) ) {
			return $this->error( 'motion_plan_missing_hash', 'A compiled plan with plan_hash is required.', [ 'status' => 400 ] );
		}
		$verified = MotionPlanVerifier::verify(
			$plan,
			is_array( $args['capability_digest'] ?? null ) ? $args['capability_digest'] : null,
			is_array( $args['direction'] ?? null ) ? $args['direction'] : null
		);
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		if ( ! get_post( $post_id ) ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
		}

		$built = ElementorV3MotionApplier::build_operations(
			array_values( (array) ( $args['targets'] ?? [] ) ),
			(array) ( $args['evidence'] ?? [] ),
			$plan,
			is_array( $args['capability_digest'] ?? null ) ? $args['capability_digest'] : null
		);
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$dry_run = ! empty( $args['dry_run'] );
		if ( $dry_run ) {
			// Local preview: patches are compiled and schema-validated, the
			// kernel dry-run happens with expected_tree_hash at write time.
			return [
				'ok'                 => true,
				'post_id'            => $post_id,
				'dry_run'            => true,
				'plan_hash'          => (string) $plan['plan_hash'],
				'resolved_targets'   => $built['resolved'],
				'touched_element_ids'=> $built['touched_element_ids'],
				'mutation_result'    => [
					'planned_operations' => $built['operations'],
					'next_step'          => 'Re-run with dry_run=false and expected_tree_hash from stonewright/elementor-v3-get-page-structure.',
				],
			];
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $args['expected_tree_hash'] ?? '' ) ) ) {
			return $this->error( 'motion_expected_tree_hash_required', 'A current expected_tree_hash is required before an Elementor motion write.', [ 'status' => 409 ] );
		}

		// Production-safe mode demands a confirmation token signed over this
		// request before the consolidated kernel write.
		$token_error = $this->confirmation_token_error( $args, $args );
		if ( null !== $token_error ) {
			return $token_error;
		}

		$mutation = ( new BatchMutate() )->execute(
			[
				'post_id'            => $post_id,
				'operations'         => $built['operations'],
				'dry_run'            => false,
				'expected_tree_hash' => (string) ( $args['expected_tree_hash'] ?? '' ),
				'confirmation_token' => (string) ( $args['confirmation_token'] ?? '' ),
			]
		);
		if ( is_wp_error( $mutation ) ) {
			return $mutation;
		}

		return [
			'ok'                 => true,
			'post_id'            => $post_id,
			'dry_run'            => false,
			'plan_hash'          => (string) $plan['plan_hash'],
			'resolved_targets'   => $built['resolved'],
			'touched_element_ids'=> $built['touched_element_ids'],
			'mutation_result'    => $mutation,
		];
	}
}
