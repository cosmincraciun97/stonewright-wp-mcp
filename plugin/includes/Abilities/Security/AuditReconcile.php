<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\AuditReconciler;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Explicit, count-only reconciliation for audit rows created before schema v2.
 *
 * @stonewright-status stable
 */
final class AuditReconcile extends AbilityKernel {

	public function name(): string {
		return 'stonewright/security-audit-reconcile';
	}

	public function label(): string {
		return __( 'Reconcile legacy audit events', 'stonewright' );
	}

	public function description(): string {
		return __( 'Previews or explicitly applies the idempotent, count-only migration of legacy audit events into the permanent taxonomy and incident lifecycle.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action' => [
					'type'    => 'string',
					'enum'    => [ 'dry_run', 'apply' ],
					'default' => 'dry_run',
				],
				'limit' => [
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 5000,
					'default' => 1000,
				],
				'confirmation_token' => [
					'type' => 'string',
				],
			],
		];
	}

	public function output_schema(): array {
		$distribution = [
			'type'                 => 'object',
			'additionalProperties' => [ 'type' => 'integer' ],
		];
		$preview = [
			'type'       => 'object',
			'properties' => [
				'schema_version'      => [ 'type' => 'string' ],
				'pending'             => [ 'type' => 'integer' ],
				'rows_scanned'        => [ 'type' => 'integer' ],
				'batch_size'          => [ 'type' => 'integer' ],
				'complete'            => [ 'type' => 'boolean' ],
				'legacy_distribution' => $distribution,
				'new_distribution'    => [
					'type'       => 'object',
					'properties' => [
						'categories' => $distribution,
						'outcomes'   => $distribution,
					],
				],
				'incident_projection' => [
					'type'       => 'object',
					'properties' => [
						'create_candidates' => [ 'type' => 'integer' ],
						'close_candidates'  => [ 'type' => 'integer' ],
						'unchanged'         => [ 'type' => 'integer' ],
					],
				],
				'ambiguous_rows'    => [ 'type' => 'integer' ],
				'contains_raw_rows' => [ 'type' => 'boolean' ],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'action'              => [ 'type' => 'string' ],
				'schema_version'      => [ 'type' => 'string' ],
				'preview'             => $preview,
				'after'               => $preview,
				'migrated'            => [ 'type' => 'integer' ],
				'effect_verified'     => [ 'type' => 'boolean' ],
				'verification_status' => [ 'type' => 'string' ],
				'audit_receipt'       => [
					'type'       => 'object',
					'properties' => [
						'recorded'       => [ 'type' => 'boolean' ],
						'ability'        => [ 'type' => 'string' ],
						'action'         => [ 'type' => 'string' ],
						'pending_before' => [ 'type' => 'integer' ],
						'migrated'       => [ 'type' => 'integer' ],
						'pending_after'  => [ 'type' => 'integer' ],
					],
				],
			],
			'required'   => [ 'ok', 'action', 'schema_version', 'preview', 'migrated', 'effect_verified', 'verification_status', 'audit_receipt' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$action = isset( $args['action'] ) && is_scalar( $args['action'] ) ? sanitize_key( (string) $args['action'] ) : 'dry_run';
		$limit  = max( 1, min( 5000, (int) ( $args['limit'] ?? 1000 ) ) );
		if ( ! in_array( $action, [ 'dry_run', 'apply' ], true ) ) {
			return new \WP_Error( 'stonewright_audit_reconcile_action_invalid', __( 'Audit reconciliation action must be dry_run or apply.', 'stonewright' ), [ 'status' => 400 ] );
		}

		$preview = AuditReconciler::preview( $limit );
		if ( $preview instanceof \WP_Error ) {
			return $preview;
		}
		if ( 'dry_run' === $action ) {
			return $this->response( 'dry_run', $preview, $preview, 0, false );
		}

		$token = isset( $args['confirmation_token'] ) && is_scalar( $args['confirmation_token'] ) ? (string) $args['confirmation_token'] : '';
		$token_args = [
			'action' => 'apply',
			'limit'  => $limit,
		];
		return $this->audit(
			$args,
			function () use ( $limit, $token, $token_args, $preview ): array|\WP_Error {
				$migrated = AuditReconciler::migrate( $limit, $token, $token_args );
				if ( $migrated instanceof \WP_Error ) {
					return $migrated;
				}
				$after = AuditReconciler::preview( $limit );
				if ( $after instanceof \WP_Error ) {
					return $after;
				}
				return $this->response( 'apply', $preview, $after, $migrated, true );
			}
		);
	}

	/** @param array<string,mixed> $preview @param array<string,mixed> $after @return array<string,mixed> */
	private function response( string $action, array $preview, array $after, int $migrated, bool $recorded ): array {
		$pending_before = (int) ( $preview['pending'] ?? 0 );
		$pending_after  = (int) ( $after['pending'] ?? 0 );
		$verified       = 'dry_run' === $action || max( 0, $pending_before - $pending_after ) === $migrated;
		return [
			'ok'                  => $verified,
			'action'              => $action,
			'schema_version'      => AuditEvent::SCHEMA_VERSION,
			'preview'             => $preview,
			'after'               => $after,
			'migrated'            => $migrated,
			'effect_verified'     => $verified,
			'verification_status' => $verified ? ( 'dry_run' === $action ? 'not_applicable' : 'passed' ) : 'failed',
			'audit_receipt'       => [
				'recorded'       => $recorded,
				'ability'        => $this->name(),
				'action'         => $action,
				'pending_before' => $pending_before,
				'migrated'       => $migrated,
				'pending_after'  => $pending_after,
			],
		];
	}
}
