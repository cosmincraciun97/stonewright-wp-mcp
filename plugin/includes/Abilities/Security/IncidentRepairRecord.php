<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Security;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Security\VerifiedRepairReceipt;

/** Records learning only after persisted audit events prove a repair. */
final class IncidentRepairRecord extends AbilityKernel {

	public function name(): string {
		return 'stonewright/incident-repair-record';
	}

	public function label(): string {
		return __( 'Record verified incident repair', 'stonewright' );
	}

	public function description(): string {
		return __( 'Correlates persisted failure and verifier audit events, resolves the matching incident, and promotes one scrubbed reusable repair lesson.', 'stonewright' );
	}

	public function category(): string {
		return 'security';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'incident_id', 'resolution_event_id', 'repair_recipe' ],
			'properties'           => [
				'incident_id'         => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'resolution_event_id' => [ 'type' => 'string', 'format' => 'uuid' ],
				'repair_recipe'       => [ 'type' => 'string', 'minLength' => 24, 'maxLength' => 500 ],
				'repair_scope'        => [ 'type' => 'string', 'maxLength' => 190 ],
				'confirmation_token'  => [ 'type' => 'string' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'verified', 'incident_id', 'repair_receipt_id', 'incident_state', 'learning_status', 'memory_key' ],
			'properties' => [
				'ok'                      => [ 'type' => 'boolean' ],
				'verified'                => [ 'type' => 'boolean' ],
				'incident_id'             => [ 'type' => 'string' ],
				'repair_receipt_id'       => [ 'type' => 'string' ],
				'incident_state'          => [ 'type' => 'string' ],
				'learning_status'         => [ 'type' => 'string' ],
				'memory_key'              => [ 'type' => 'string' ],
				'verified_repair_receipt' => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $input ): array|\WP_Error {
				if ( ! get_option( 'stonewright_memory_enabled', true ) ) {
					return $this->error( 'memory_disabled', __( 'Memory is disabled on this site.', 'stonewright' ) );
				}

				$incident_id = strtolower( trim( (string) ( $input['incident_id'] ?? '' ) ) );
				$event_id    = strtolower( trim( (string) ( $input['resolution_event_id'] ?? '' ) ) );
				$incident    = IncidentStore::get( $incident_id );
				if ( null === $incident ) {
					return $this->error( 'incident_not_found', __( 'Incident was not found.', 'stonewright' ), [ 'status' => 404 ] );
				}

				$failure = AuditLog::find_event( (string) ( $incident['last_event_id'] ?? '' ) );
				$success = AuditLog::find_event( $event_id );
				if ( null === $failure || null === $success ) {
					return $this->error( 'repair_event_not_found', __( 'Persisted failure and verifier events are both required.', 'stonewright' ), [ 'status' => 404 ] );
				}

				$receipt = VerifiedRepairReceipt::from_events(
					$incident,
					self::receipt_event( $failure ),
					self::receipt_event( $success ),
					(string) ( $input['repair_recipe'] ?? '' )
				);
				if ( $receipt instanceof \WP_Error ) {
					return $receipt;
				}
				if ( isset( $input['repair_scope'] ) && '' !== trim( (string) $input['repair_scope'] ) ) {
					$receipt['repair_scope'] = mb_substr( sanitize_text_field( (string) $input['repair_scope'] ), 0, 190 );
				}

				$confirmation_args = [
					'incident_id'         => $incident_id,
					'resolution_event_id' => $event_id,
					'repair_recipe'       => (string) $receipt['repair_recipe'],
					'repair_scope'        => (string) $receipt['repair_scope'],
				];
				if ( Permissions::is_production_safe() ) {
					$verified = ConfirmationToken::verify_or_error( (string) ( $input['confirmation_token'] ?? '' ), $this->name(), $confirmation_args );
					if ( $verified instanceof \WP_Error ) {
						return $verified;
					}
				}

				$resolved = IncidentStore::record_verified_repair( $receipt );
				if ( $resolved instanceof \WP_Error ) {
					return $resolved;
				}

				$memory_key = 'verified-repair-' . substr( (string) $receipt['repair_receipt_id'], 0, 16 );
				$memory_id  = Memory::put_typed(
					'feedback',
					'verified-repairs',
					$memory_key,
					'Verified repair: ' . (string) ( $incident['root_error_code'] ?? 'incident' ),
					[
						'correction'             => (string) $receipt['repair_recipe'],
						'lesson'                 => (string) $receipt['repair_recipe'],
						'source'                 => 'verified-repair',
						'state'                  => 'promoted_learning',
						'incident_hash'          => (string) $receipt['incident_id'],
						'resolution_event_hash'  => hash( 'sha256', (string) $receipt['resolution_event_id'] ),
						'ability_family'         => (string) ( $incident['ability_family'] ?? '' ),
						'error_code'             => (string) ( $incident['root_error_code'] ?? '' ),
						'verifier'               => (string) ( $receipt['evidence']['verifier'] ?? '' ),
						'verified_at'            => current_time( 'mysql', true ),
					],
					1.0,
					[ 'topic' => (string) ( $incident['root_error_code'] ?? 'verified repair' ), 'status' => 'active', 'precedence' => 650 ]
				);
				if ( 0 === $memory_id ) {
					return $this->error( 'repair_learning_write_failed', __( 'Incident resolved, but verified learning could not be stored.', 'stonewright' ), [ 'status' => 500 ] );
				}
				$readback = Memory::get_by_id( $memory_id );
				if ( null === $readback || $memory_key !== (string) ( $readback['memory_key'] ?? '' ) ) {
					return $this->error( 'repair_learning_readback_failed', __( 'Verified learning write could not be confirmed.', 'stonewright' ), [ 'status' => 500 ] );
				}
				if ( ! IncidentStore::mark_learning_promoted( $incident_id, $memory_key, (string) $receipt['repair_receipt_id'] ) ) {
					return $this->error( 'repair_learning_link_failed', __( 'Verified learning could not be linked to its incident.', 'stonewright' ), [ 'status' => 500 ] );
				}

				return [
					'ok'                      => true,
					'verified'                => true,
					'incident_id'             => $incident_id,
					'repair_receipt_id'       => (string) $receipt['repair_receipt_id'],
					'incident_state'          => 'resolved',
					'learning_status'         => 'promoted',
					'memory_key'              => $memory_key,
					'verified_repair_receipt' => $receipt,
				];
			}
		);
	}

	/** @return list<string> */
	protected function audit_redacted_keys(): array {
		return array_values( array_unique( array_merge( parent::audit_redacted_keys(), [ 'repair_recipe' ] ) ) );
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private static function receipt_event( array $row ): array {
		return [
			'event_id'            => (string) ( $row['event_id'] ?? '' ),
			'ability'             => (string) ( $row['ability_name'] ?? '' ),
			'outcome'             => (string) ( $row['outcome'] ?? '' ),
			'verification_status' => (string) ( $row['verification_status'] ?? '' ),
			'effect_verified'     => 1 === (int) ( $row['effect_verified'] ?? 0 ),
			'change_set_id'       => (string) ( $row['change_set_id'] ?? '' ),
			'resource_key_hash'   => (string) ( $row['resource_key_hash'] ?? '' ),
			'normalized_path'     => (string) ( $row['normalized_path'] ?? '' ),
			'after_sha256'        => (string) ( $row['after_sha256'] ?? '' ),
			'recorded_at'         => (string) ( $row['created_at'] ?? '' ),
		];
	}
}
