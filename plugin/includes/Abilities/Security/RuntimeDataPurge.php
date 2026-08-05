<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\RuntimeDataPurger;

/** Explicit count-only plan and destructive reset of Stonewright runtime history. */
final class RuntimeDataPurge extends AbilityKernel {

	private const ACKNOWLEDGEMENT = 'erase_runtime_history';

	public function name(): string {
		return 'stonewright/security-runtime-data-purge';
	}

	public function label(): string {
		return __( 'Purge Stonewright runtime history', 'stonewright' );
	}

	public function description(): string {
		return __( 'Previews count-only audit, memory, incident, and recurring-error state, then clears only an exactly reviewed state while retaining one cleanup receipt.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'action' => [ 'type' => 'string', 'enum' => [ 'dry_run', 'apply' ], 'default' => 'dry_run' ],
				'scopes' => [
					'type'        => 'array',
					'minItems'    => 1,
					'uniqueItems' => true,
					'default'     => RuntimeDataPurger::SCOPES,
					'items'       => [ 'type' => 'string', 'enum' => RuntimeDataPurger::SCOPES ],
				],
				'expected_state_hash' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'approved_plan_hash'  => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'acknowledgement'     => [ 'type' => 'string', 'enum' => [ self::ACKNOWLEDGEMENT ] ],
				'confirmation_token'  => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		$counts = [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'integer' ] ];
		$preview = [
			'type'       => 'object',
			'properties' => [
				'schema_version'          => [ 'type' => 'string' ],
				'scopes'                  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
				'counts'                  => $counts,
				'total'                   => [ 'type' => 'integer' ],
				'state_hash'              => [ 'type' => 'string' ],
				'plan_hash'               => [ 'type' => 'string' ],
				'scope_hashes'            => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'string' ] ],
				'scope_watermarks'         => [ 'type' => 'object', 'additionalProperties' => [ 'type' => 'object' ] ],
				'audit_support_hash'       => [ 'type' => 'string' ],
				'audit_journal_hash'       => [ 'type' => 'string' ],
				'audit_degraded_hash'      => [ 'type' => 'string' ],
				'contains_raw_rows'       => [ 'type' => 'boolean' ],
				'cleanup_receipt_retained'=> [ 'type' => 'boolean' ],
			],
		];
		return [
			'type'       => 'object',
			'properties' => [
				'ok'                  => [ 'type' => 'boolean' ],
				'action'              => [ 'type' => 'string' ],
				'preview'             => $preview,
				'after'               => $preview,
				'effect_verified'     => [ 'type' => 'boolean' ],
				'verification_status' => [ 'type' => 'string' ],
				'audit_receipt'       => [
					'type'       => 'object',
					'properties' => [
						'recorded'              => [ 'type' => 'boolean' ],
						'retained_event_count'  => [ 'type' => 'integer' ],
						'contains_runtime_rows' => [ 'type' => 'boolean' ],
					],
				],
			],
			'required'   => [ 'ok', 'action', 'preview', 'after', 'effect_verified', 'verification_status', 'audit_receipt' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		$action = sanitize_key( (string) ( $args['action'] ?? 'dry_run' ) );
		$scopes = RuntimeDataPurger::normalize_scopes( (array) ( $args['scopes'] ?? RuntimeDataPurger::SCOPES ) );
		if ( ! in_array( $action, [ 'dry_run', 'apply' ], true ) ) {
			return new \WP_Error( 'stonewright_runtime_data_purge_action_invalid', __( 'Runtime-data purge action must be dry_run or apply.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$preview = RuntimeDataPurger::preview( $scopes );
		if ( $preview instanceof \WP_Error ) {
			return $preview;
		}
		if ( 'dry_run' === $action ) {
			return self::response( 'dry_run', $preview, $preview, false, 0 );
		}
		if ( self::ACKNOWLEDGEMENT !== (string) ( $args['acknowledgement'] ?? '' ) ) {
			return new \WP_Error( 'stonewright_runtime_data_purge_acknowledgement_required', __( 'Apply requires the exact destructive-operation acknowledgement.', 'stonewright' ), [ 'status' => 400 ] );
		}
		$verify_args = [
			'action'              => 'apply',
			'scopes'              => $scopes,
			'expected_state_hash' => strtolower( (string) ( $args['expected_state_hash'] ?? '' ) ),
			'approved_plan_hash'  => strtolower( (string) ( $args['approved_plan_hash'] ?? '' ) ),
			'acknowledgement'     => self::ACKNOWLEDGEMENT,
		];
		$review_error = RuntimeDataPurger::validate_reviewed( $preview, $verify_args['expected_state_hash'], $verify_args['approved_plan_hash'] );
		if ( $review_error instanceof \WP_Error ) {
			return $review_error;
		}
		if ( Permissions::is_production_safe() ) {
			$token = (string) ( $args['confirmation_token'] ?? '' );
			if ( '' === $token ) {
				return new \WP_Error( 'stonewright_confirmation_required', __( 'Production-safe mode requires a confirmation_token.', 'stonewright' ), [ 'status' => 403 ] );
			}
			$verified = ConfirmationToken::verify_or_error( $token, $this->name(), $verify_args );
			if ( $verified instanceof \WP_Error ) {
				return $verified;
			}
		}

		$result = RuntimeDataPurger::purge( $scopes, $verify_args['expected_state_hash'], $verify_args['approved_plan_hash'], $preview, Permissions::is_production_safe() ? $this->name() : '' );
		if ( $result instanceof \WP_Error ) {
			AuditLog::record(
				$this->name(),
				[
					'action' => 'apply',
					'scopes' => $scopes,
					'_meta'  => [ 'error_code' => $result->get_error_code(), 'operation_class' => 'runtime_data_purge' ],
				],
				'error'
			);
			return $result;
		}

		$recorded = AuditLog::record(
			$this->name(),
			[
				'action' => 'apply',
				'scopes' => $scopes,
				'_meta'  => [
					'operation_class'    => 'runtime_data_purge',
					'execution_status'   => 'applied',
					'verification_status'=> 'passed',
					'changed_bytes'      => 0,
				],
			],
			'ok'
		);
		if ( ! $recorded ) {
			return new \WP_Error(
				'stonewright_runtime_data_purge_receipt_failed',
				__( 'Runtime history was cleared, but its mandatory cleanup receipt could not be persisted.', 'stonewright' ),
				[ 'status' => 500, 'cleanup_performed' => true ]
			);
		}

		$after_receipt = RuntimeDataPurger::preview( $scopes );
		if ( $after_receipt instanceof \WP_Error ) {
			return $after_receipt;
		}
		$expected_total = in_array( 'audit', $scopes, true ) ? 1 : 0;
		$effect_verified = $expected_total === (int) $after_receipt['total'];
		return self::response( 'apply', $result['before'], $after_receipt, $effect_verified, 1 );
	}

	/** @param array<string,mixed> $preview @param array<string,mixed> $after @return array<string,mixed> */
	private static function response( string $action, array $preview, array $after, bool $effect_verified, int $retained_events ): array {
		$verified = 'dry_run' === $action || $effect_verified;
		return [
			'ok'                  => $verified,
			'action'              => $action,
			'preview'             => $preview,
			'after'               => $after,
			'effect_verified'     => $verified,
			'verification_status' => 'dry_run' === $action ? 'not_applicable' : ( $verified ? 'passed' : 'failed' ),
			'audit_receipt'       => [
				'recorded'              => $retained_events > 0,
				'retained_event_count'  => $retained_events,
				'contains_runtime_rows' => false,
			],
		];
	}
}
