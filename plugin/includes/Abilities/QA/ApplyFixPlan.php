<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\QA;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Validates a confirmation token, snapshots affected posts, and applies (or stubs) a fix plan.
 * Contract decision: keep output_schema aligned to the handler response shape.
 *
 * @stonewright-status stable
 */
final class ApplyFixPlan extends AbilityKernel {

	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/qa-apply-fix-plan';
	}

	public function label(): string {
		return __( 'QA: Apply Fix Plan', 'stonewright' );
	}

	public function description(): string {
		return __( 'Validates a confirmation token, snapshots affected posts, and applies a fix plan from SuggestFixes.', 'stonewright' );
	}

	public function category(): string {
		return 'qa';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'plan'               => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
				],
				'confirmation_token' => [ 'type' => 'string' ],
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
			],
			'required'             => [ 'plan' ],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'           => [ 'type' => 'boolean' ],
				'applied'      => [ 'type' => 'integer' ],
				'skipped'      => [ 'type' => 'integer' ],
				'audit_log_id' => [ 'type' => 'integer' ],
				'status'       => [ 'type' => 'string' ],
			],
			'required'   => [ 'ok', 'applied', 'skipped', 'audit_log_id', 'status' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		if ( ! empty( $args['post_id'] ) ) {
			return Permissions::edit_post( (int) $args['post_id'] );
		}
		return Permissions::edit_posts();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				// Structured confirmation-token gate: returns null (pass) or a WP_Error with the
				// original structured code (stonewright_confirmation_required, _invalid, _expired, etc.).
				$verify_args = array_filter(
					$args,
					static fn( string $k ) => 'confirmation_token' !== $k,
					ARRAY_FILTER_USE_KEY
				);
				$token_error = $this->confirmation_token_error( $args, $verify_args );
				if ( null !== $token_error ) {
					return $token_error;
				}

				$post_id = ! empty( $args['post_id'] ) ? (int) $args['post_id'] : null;

				// Snapshot if a post_id is provided.
				if ( $post_id ) {
					Backup::snapshot_post( $post_id );
				}

				return [
					'ok'           => false,
					'applied'      => 0,
					'skipped'      => count( $args['plan'] ),
					'audit_log_id' => 0,
					'status'       => 'not_implemented',
				];
			}
		);
	}
}
