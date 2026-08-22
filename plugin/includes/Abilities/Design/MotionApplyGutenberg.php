<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Design;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Abilities\Gutenberg\BlocksBatchMutate;
use Stonewright\WpMcp\Design\Motion\GutenbergMotionApplier;
use Stonewright\WpMcp\Design\Motion\MotionPlanVerifier;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Applies a compiled gutenberg-fse motion plan to one post through
 * stonewright/blocks-batch-mutate — one dry run, then one consolidated write.
 *
 * Every kernel gate is inherited by delegation: expected content hash,
 * snapshot, confirmation tokens, finalizer queueing for static blocks,
 * readback, and rollback. This ability adds the motion-specific gates:
 * plan-hash binding, target resolution, and class allowlisting.
 *
 * @stonewright-status stable
 */
final class MotionApplyGutenberg extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/design-motion-apply-gutenberg';
	}

	public function label(): string {
		return __( 'Design motion apply (Gutenberg)', 'stonewright' );
	}

	public function description(): string {
		return __( 'Dry-run or apply a compiled Gutenberg/FSE motion plan to one post via consolidated block-class updates; snapshot, readback, and rollback are enforced.', 'stonewright' );
	}

	public function category(): string {
		return 'design';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'plan', 'targets' ],
			'properties'           => [
				'post_id'               => [ 'type' => 'integer', 'minimum' => 1 ],
				'plan'                  => [ 'type' => 'object', 'description' => 'Output of stonewright/design-motion-plan with renderer=gutenberg-fse.' ],
				'capability_digest'     => [ 'type' => 'object' ],
				'direction'             => [ 'type' => 'object' ],
				'targets'               => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 100,
					'description' => 'Spec-ID to live block path mapping read from the current page structure.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => [ 'target_id', 'path' ],
						'properties'           => [
							'target_id' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 128 ],
							'path'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 0 ], 'minItems' => 1 ],
						],
					],
				],
				'dry_run'               => [ 'type' => 'boolean', 'default' => true ],
				'expected_content_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'confirmation_token'    => [ 'type' => 'string' ],
				'stonewright_context_token' => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'post_id'             => [ 'type' => 'integer' ],
				'dry_run'             => [ 'type' => 'boolean' ],
				'plan_hash'           => [ 'type' => 'string' ],
				'before_hash'         => [ 'type' => 'string' ],
				'after_hash'          => [ 'type' => 'string' ],
				'readback_hash'       => [ 'type' => 'string' ],
				'snapshot_id'         => [ 'type' => 'string' ],
				'applied'             => [ 'type' => 'integer' ],
				'resolved_targets'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'assets'              => [ 'type' => 'object' ],
				'verification_status' => [ 'type' => 'string' ],
				'rollback_status'     => [ 'type' => 'string' ],
				'write_receipt'       => [ 'type' => 'object' ],
				'mutation_result'     => [ 'type' => 'object' ],
				'queued'              => [ 'type' => 'boolean' ],
				'change_ids'          => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'finalizer_url'       => [ 'type' => 'string' ],
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
		$dry_run = ! empty( $args['dry_run'] );

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

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
		}

		$parsed = parse_blocks( (string) $post->post_content );
		$built  = GutenbergMotionApplier::build_operations(
			is_array( $parsed ) ? $parsed : [],
			array_values( (array) ( $args['targets'] ?? [] ) ),
			$plan
		);
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$before_hash = hash( 'sha256', (string) $post->post_content );

		if ( $dry_run ) {
			return [
				'ok'                  => true,
				'post_id'             => $post_id,
				'dry_run'             => true,
				'plan_hash'           => (string) $plan['plan_hash'],
				'before_hash'         => $before_hash,
				'after_hash'          => '',
				'readback_hash'       => '',
				'snapshot_id'         => '',
				'applied'             => 0,
				'resolved_targets'    => $built['resolved'],
				'assets'              => [ 'css' => true, 'js' => $built['runtime_needed'] ],
				'verification_status' => 'planned',
				'rollback_status'     => 'not_needed',
				'write_receipt'       => [
					'operations_planned' => count( $built['operations'] ),
					'expected_content_hash' => $before_hash,
					'plan_hash'             => (string) $plan['plan_hash'],
				],
			];
		}

		if ( [] === $built['operations'] ) {
			return [
				'ok'                  => true,
				'post_id'             => $post_id,
				'dry_run'             => false,
				'plan_hash'           => (string) $plan['plan_hash'],
				'before_hash'         => $before_hash,
				'after_hash'          => $before_hash,
				'readback_hash'       => $before_hash,
				'snapshot_id'         => '',
				'applied'             => 0,
				'resolved_targets'    => $built['resolved'],
				'assets'              => [ 'css' => true, 'js' => $built['runtime_needed'] ],
				'verification_status' => 'no_change',
				'rollback_status'     => 'not_needed',
				'write_receipt'       => [ 'operations_applied' => 0, 'plan_hash' => (string) $plan['plan_hash'] ],
				'mutation_result'     => [],
				'queued'              => false,
				'change_ids'          => [],
				'finalizer_url'       => '',
			];
		}

		// One consolidated write through the batch-mutate kernel: it enforces
		// expected-content hash, snapshot, and readback. Production-safe mode
		// additionally demands a confirmation token signed over this request.
		$token_error = $this->confirmation_token_error( $args, $args );
		if ( null !== $token_error ) {
			return $token_error;
		}

		$mutation = ( new BlocksBatchMutate() )->execute(
			[
				'post_id'               => $post_id,
				'operations'            => $built['operations'],
				'dry_run'               => false,
				'expected_content_hash' => (string) ( $args['expected_content_hash'] ?? '' ),
				'confirmation_token'    => (string) ( $args['confirmation_token'] ?? '' ),
			]
		);
		if ( is_wp_error( $mutation ) ) {
			return $mutation;
		}

		// Static/third-party targets queue through the browser finalizer: a
		// second, explicitly approved operation with its own snapshot and
		// readback. The receipt must say so — never claim a silent write.
		$queued = ! empty( $mutation['queued'] );

		return [
			'ok'                  => true,
			'post_id'             => $post_id,
			'dry_run'             => false,
			'plan_hash'           => (string) $plan['plan_hash'],
			'before_hash'         => (string) ( $mutation['before_hash'] ?? '' ),
			'after_hash'          => (string) ( $mutation['after_hash'] ?? '' ),
			'readback_hash'       => (string) ( $mutation['readback_hash'] ?? '' ),
			'snapshot_id'         => (string) ( $mutation['snapshot_id'] ?? '' ),
			'applied'             => (int) ( $mutation['applied'] ?? 0 ),
			'resolved_targets'    => $built['resolved'],
			'assets'              => [ 'css' => true, 'js' => $built['runtime_needed'] ],
			'verification_status' => $queued ? 'queued_finalizer' : (string) ( $mutation['verification_status'] ?? 'unknown' ),
			'rollback_status'     => (string) ( $mutation['rollback_status'] ?? 'not_needed' ),
			'write_receipt'       => is_array( $mutation['write_receipt'] ?? null ) ? $mutation['write_receipt'] : [],
			'mutation_result'     => $mutation,
			'queued'              => $queued,
			'change_ids'          => array_values( array_map( 'strval', (array) ( $mutation['change_ids'] ?? [] ) ) ),
			'finalizer_url'       => (string) ( $mutation['finalizer_url'] ?? '' ),
		];
	}
}
