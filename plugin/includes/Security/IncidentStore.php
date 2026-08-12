<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Support\Json;

/**
 * First-class lifecycle store for recurring operational incidents.
 *
 * A row starts as `observing`, becomes `open` only after the category-specific
 * threshold, and can be resolved only by a correlated verified event. The
 * option fallback exists solely for unit doubles that do not implement wpdb's
 * row/update methods; production always uses the dedicated table.
 */
final class IncidentStore {

	public const TABLE = 'stonewright_incidents';
	public const OPTION_KEY = 'stonewright_incident_fallback';
	public const OBSERVING_THRESHOLD = 2;
	public const RETRYABLE_THRESHOLD = 3;

	/** @var array<string, array<string, mixed>> */
	private static array $fallback = [];

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function maybe_install_table(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			incident_id CHAR(64) NOT NULL,
			state VARCHAR(24) NOT NULL DEFAULT 'observing',
			category VARCHAR(32) NOT NULL DEFAULT 'WRITE',
			outcome VARCHAR(24) NOT NULL DEFAULT 'FAILED',
			severity VARCHAR(16) NOT NULL DEFAULT 'error',
			ability_name VARCHAR(190) NOT NULL DEFAULT '',
			ability_family VARCHAR(96) NOT NULL DEFAULT '',
			root_error_code VARCHAR(190) NOT NULL DEFAULT '',
			resource_type VARCHAR(96) NOT NULL DEFAULT '',
			resource_key_hash CHAR(64) NOT NULL DEFAULT '',
			normalized_path VARCHAR(255) NOT NULL DEFAULT '',
			cause_fingerprint CHAR(64) NOT NULL DEFAULT '',
			strategy_fingerprint CHAR(64) NOT NULL DEFAULT '',
			expected_verifier VARCHAR(190) NOT NULL DEFAULT '',
			remediation_code VARCHAR(190) NOT NULL DEFAULT '',
			occurrence_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			reopened_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			resolved_at DATETIME NULL,
			last_event_id CHAR(36) NOT NULL DEFAULT '',
			resolution_event_id CHAR(36) NOT NULL DEFAULT '',
			last_change_set_id VARCHAR(96) NOT NULL DEFAULT '',
			repair_phase VARCHAR(24) NOT NULL DEFAULT 'none',
			learning_status VARCHAR(24) NOT NULL DEFAULT 'none',
			learning_memory_key VARCHAR(190) NOT NULL DEFAULT '',
			repair_receipt_id CHAR(64) NOT NULL DEFAULT '',
			learned_at DATETIME NULL,
			evidence_json LONGTEXT NULL,
			resolution_json LONGTEXT NULL,
			schema_version VARCHAR(16) NOT NULL DEFAULT '2.0',
			PRIMARY KEY (id),
			UNIQUE KEY incident_id_idx (incident_id),
			KEY state_idx (state),
			KEY category_idx (category),
			KEY ability_idx (ability_name),
			KEY cause_idx (cause_fingerprint),
			KEY last_seen_idx (last_seen)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** @param array<string, mixed> $event */
	public static function observe( array $event ): ?array {
		$outcome = (string) ( $event['outcome'] ?? AuditEvent::OUTCOME_FAILED );
		if ( in_array( $outcome, [ AuditEvent::OUTCOME_SUCCESS, AuditEvent::OUTCOME_BLOCKED ], true ) ) {
			return null;
		}

		$incident_id = self::safe_hash( $event['incident_id'] ?? '' );
		if ( '' === $incident_id ) {
			return null;
		}
		$now       = gmdate( 'Y-m-d H:i:s' );
		$existing  = self::find( $incident_id );
		$threshold = AuditEvent::OUTCOME_RETRYABLE === $outcome ? self::RETRYABLE_THRESHOLD : self::OBSERVING_THRESHOLD;
		$details   = is_array( $event['redacted_details'] ?? null ) ? $event['redacted_details'] : [];
		$delta     = max( 1, min( 10000, (int) ( $details['coalesced_count'] ?? 1 ) ) );
		$count     = $delta + (int) ( $existing['occurrence_count'] ?? 0 );
		$state     = self::state_for( $event, $count, (string) ( $existing['state'] ?? '' ) );
		$row       = self::row_from_event( $event, $incident_id, $existing, $count, $state, $now );

		if ( null !== $existing && in_array( (string) ( $existing['state'] ?? '' ), [ 'resolved', 'suppressed' ], true ) ) {
			$row['reopened_count'] = (int) ( $existing['reopened_count'] ?? 0 ) + 1;
			$row['resolved_at']     = null;
			$row['resolution_event_id'] = '';
			$row['resolution_json']  = '';
			$row['state']            = 'open';
			$row['repair_phase']     = 'proposed';
			$row['repair_receipt_id'] = '';
			if ( 'promoted' === (string) ( $existing['learning_status'] ?? '' ) ) {
				$row['learning_status'] = 'stale';
			}
		}

		self::persist( $row, null !== $existing );
		return self::public_row( $row );
	}

	/** @return array<string, mixed>|null */
	public static function get( string $incident_id ): ?array {
		$incident_id = self::safe_hash( $incident_id );
		if ( '' === $incident_id ) {
			return null;
		}
		$row = self::find( $incident_id );
		return null === $row ? null : self::public_row( $row );
	}

	/**
	 * Persist a receipt that has already been validated against audit events.
	 *
	 * @param array<string, mixed> $receipt
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function record_verified_repair( array $receipt ): array|\WP_Error {
		$incident_id = self::safe_hash( $receipt['incident_id'] ?? '' );
		$receipt_id  = self::safe_hash( $receipt['repair_receipt_id'] ?? '' );
		$row         = '' === $incident_id ? null : self::find( $incident_id );
		if ( null === $row || '' === $receipt_id ) {
			return self::uncorrelated_error();
		}

		if ( 'resolved' === (string) ( $row['state'] ?? '' ) && hash_equals( (string) ( $row['repair_receipt_id'] ?? '' ), $receipt_id ) ) {
			return self::public_row( $row );
		}

		if ( ! in_array( (string) ( $row['state'] ?? '' ), [ 'open', 'observing' ], true )
			|| 'verified' !== strtolower( (string) ( $receipt['verification_status'] ?? '' ) )
			|| true !== ( $receipt['effect_verified'] ?? false )
			|| ! self::receipt_correlates( $row, $receipt ) ) {
			return self::uncorrelated_error();
		}

		$row['state']               = 'resolved';
		$row['resolved_at']         = gmdate( 'Y-m-d H:i:s' );
		$row['resolution_event_id'] = self::safe_text( $receipt['resolution_event_id'] ?? '', 36 );
		$row['repair_receipt_id']    = $receipt_id;
		$row['repair_phase']         = 'verified';
		$row['resolution_json']      = Json::encode( [
			'verification_status' => 'verified',
			'event_id'            => $row['resolution_event_id'],
			'change_set_id'       => self::safe_text( $receipt['change_set_id'] ?? '', 96 ),
			'after_sha256'        => self::safe_hash( is_array( $receipt['evidence'] ?? null ) ? ( $receipt['evidence']['after_sha256'] ?? '' ) : '' ),
		] );
		self::persist( $row, true );
		return self::public_row( $row );
	}

	public static function mark_learning_promoted( string $incident_id, string $memory_key, string $receipt_id ): bool {
		$row        = self::find( self::safe_hash( $incident_id ) );
		$receipt_id = self::safe_hash( $receipt_id );
		$memory_key = self::safe_text( $memory_key, 190 );
		if ( null === $row || 'resolved' !== (string) ( $row['state'] ?? '' ) || '' === $receipt_id || '' === $memory_key ) {
			return false;
		}
		if ( ! hash_equals( (string) ( $row['repair_receipt_id'] ?? '' ), $receipt_id ) ) {
			return false;
		}
		$row['learning_status']     = 'promoted';
		$row['learning_memory_key'] = $memory_key;
		$row['learned_at']          = gmdate( 'Y-m-d H:i:s' );
		self::persist( $row, true );
		return true;
	}

	public static function mark_learning_stale( string $incident_id ): bool {
		$row = self::find( self::safe_hash( $incident_id ) );
		if ( null === $row || '' === (string) ( $row['learning_memory_key'] ?? '' ) ) {
			return false;
		}
		$row['learning_status'] = 'stale';
		self::persist( $row, true );
		return true;
	}

	/** @param array<string, mixed> $event */
	public static function resolve( array $event ): bool {
		if ( AuditEvent::OUTCOME_SUCCESS !== (string) ( $event['outcome'] ?? '' ) ) {
			return false;
		}
		$incident_id = self::safe_hash( $event['incident_id'] ?? '' );
		$row = '' !== $incident_id ? self::find( $incident_id ) : null;
		// A verified closure normally comes from a different ability than the
		// failing write. Change-set correlation is the stable transaction key;
		// resource/path checks below still prevent an unrelated success from
		// closing the incident.
		if ( null === $row ) {
			$row = self::find_correlated( $event );
		}
		if ( null === $row || ! in_array( (string) ( $row['state'] ?? '' ), [ 'open', 'observing' ], true ) || ! self::correlates( $row, $event ) ) {
			return false;
		}

		$row['state']               = 'resolved';
		$row['resolved_at']         = gmdate( 'Y-m-d H:i:s' );
		$row['resolution_event_id'] = self::safe_text( $event['event_id'] ?? '', 36 );
		$row['resolution_json']     = Json::encode( [
			'verification_status' => 'verified',
			'event_id'            => $row['resolution_event_id'],
			'change_set_id'       => self::safe_text( $event['change_set_id'] ?? '', 96 ),
			'after_sha256'        => self::safe_hash( $event['after_sha256'] ?? '' ),
		] );
		self::persist( $row, true );
		return true;
	}

	/** @return list<array<string, mixed>> */
	public static function recent( int $limit = 50, array $filters = [] ): array {
		$limit = max( 1, min( 500, $limit ) );
		global $wpdb;
		if ( self::db_available() ) {
			$table = self::table_name();
			$where = [];
			$params = [];
			foreach ( [ 'state', 'category', 'ability_name', 'root_error_code', 'normalized_path' ] as $key ) {
				$value = isset( $filters[ $key ] ) ? sanitize_text_field( (string) $filters[ $key ] ) : '';
				if ( '' !== $value ) {
					$where[] = $key . ' = %s';
					$params[] = $value;
				}
			}
			$sql = "SELECT * FROM {$table}" . ( [] === $where ? '' : ' WHERE ' . implode( ' AND ', $where ) ) . ' ORDER BY last_seen DESC LIMIT %d';
			$params[] = $limit;
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table/columns are internal allowlists.
			return is_array( $rows ) ? array_map( [ self::class, 'public_row' ], $rows ) : [];
		}

		$stored = self::$fallback;
		if ( [] === $stored && function_exists( 'get_option' ) ) {
			$option = get_option( self::OPTION_KEY, [] );
			$stored = is_array( $option ) ? $option : [];
		}
		$rows = array_values( $stored );
		usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) ( $b['last_seen'] ?? '' ), (string) ( $a['last_seen'] ?? '' ) ) );
		return array_slice( array_values( array_filter( $rows, static function ( array $row ) use ( $filters ): bool {
			foreach ( [ 'state', 'category', 'ability_name', 'root_error_code', 'normalized_path' ] as $key ) {
				if ( '' !== (string) ( $filters[ $key ] ?? '' ) && (string) ( $row[ $key ] ?? '' ) !== (string) $filters[ $key ] ) {
					return false;
				}
			}
			return true;
		} ) ), 0, $limit );
	}

	/** @return array<string, int> */
	public static function counts(): array {
		$counts = [ 'open' => 0, 'observing' => 0, 'resolved' => 0, 'suppressed' => 0 ];
		foreach ( self::recent( 500 ) as $row ) {
			$state = (string) ( $row['state'] ?? '' );
			if ( isset( $counts[ $state ] ) ) {
				++$counts[ $state ];
			}
		}
		return $counts;
	}

	public static function reset_for_tests(): void {
		self::$fallback = [];
		if ( function_exists( 'delete_option' ) ) {
			delete_option( self::OPTION_KEY );
		}
	}

	/** @return array<string, mixed>|null */
	private static function find( string $incident_id ): ?array {
		global $wpdb;
		if ( self::db_available() ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE incident_id = %s LIMIT 1', $incident_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name internal.
			return is_array( $row ) ? $row : null;
		}
		$stored = self::$fallback;
		if ( [] === $stored && function_exists( 'get_option' ) ) {
			$stored = get_option( self::OPTION_KEY, [] );
			$stored = is_array( $stored ) ? $stored : [];
		}
		return isset( $stored[ $incident_id ] ) && is_array( $stored[ $incident_id ] ) ? $stored[ $incident_id ] : null;
	}

	/** @param array<string, mixed> $event @param array<string, mixed>|null $existing @return array<string, mixed> */
	private static function row_from_event( array $event, string $incident_id, ?array $existing, int $count, string $state, string $now ): array {
		return [
			'id'                   => (int) ( $existing['id'] ?? 0 ),
			'incident_id'          => $incident_id,
			'state'                => $state,
			'category'             => self::safe_text( $event['category'] ?? AuditEvent::CATEGORY_INCIDENT, 32 ),
			'outcome'              => self::safe_text( $event['outcome'] ?? AuditEvent::OUTCOME_FAILED, 24 ),
			'severity'             => self::safe_text( $event['severity_level'] ?? 'error', 16 ),
			'ability_name'         => self::safe_text( $event['ability'] ?? '', 190 ),
			'ability_family'       => self::safe_text( $event['ability_family'] ?? '', 96 ),
			'root_error_code'      => self::safe_text( $event['root_error_code'] ?? '', 190 ),
			'resource_type'        => self::safe_text( $event['resource_type'] ?? '', 96 ),
			'resource_key_hash'    => self::safe_hash( $event['resource_key_hash'] ?? '' ),
			'normalized_path'      => self::safe_text( $event['normalized_path'] ?? '', 255 ),
			'cause_fingerprint'    => self::safe_hash( $event['cause_fingerprint'] ?? '' ),
			'strategy_fingerprint' => self::safe_hash( $event['strategy_fingerprint'] ?? '' ),
			'expected_verifier'    => self::safe_text( $event['expected_verifier'] ?? '', 190 ),
			'remediation_code'     => self::safe_text( $event['remediation_code'] ?? '', 190 ),
			'occurrence_count'     => $count,
			'reopened_count'       => (int) ( $existing['reopened_count'] ?? 0 ),
			'first_seen'           => (string) ( $existing['first_seen'] ?? $now ),
			'last_seen'            => $now,
			'resolved_at'          => $existing['resolved_at'] ?? null,
			'last_event_id'        => self::safe_text( $event['event_id'] ?? '', 36 ),
			'resolution_event_id'  => (string) ( $existing['resolution_event_id'] ?? '' ),
			'last_change_set_id'   => self::safe_text( $event['change_set_id'] ?? '', 96 ),
			'repair_phase'         => 'open' === $state && in_array( (string) ( $existing['repair_phase'] ?? '' ), [ '', 'none' ], true )
				? 'proposed'
				: (string) ( $existing['repair_phase'] ?? 'none' ),
			'learning_status'      => (string) ( $existing['learning_status'] ?? 'none' ),
			'learning_memory_key'  => (string) ( $existing['learning_memory_key'] ?? '' ),
			'repair_receipt_id'    => (string) ( $existing['repair_receipt_id'] ?? '' ),
			'learned_at'           => $existing['learned_at'] ?? null,
			'evidence_json'        => Json::encode( [
				'public_message'   => self::safe_text( $event['public_message'] ?? '', 500 ),
				'redacted_details' => is_array( $event['redacted_details'] ?? null ) ? $event['redacted_details'] : [],
				'verification_status' => self::safe_text( $event['verification_status'] ?? '', 32 ),
				'rollback_status'     => self::safe_text( $event['rollback_status'] ?? '', 32 ),
				'retry_after'      => max( 0, (int) ( $event['retry_after_seconds'] ?? 0 ) ),
			] ),
			'resolution_json'     => (string) ( $existing['resolution_json'] ?? '' ),
			'schema_version'      => AuditEvent::SCHEMA_VERSION,
		];
	}

	/** @param array<string, mixed> $event */
	private static function state_for( array $event, int $count, string $old_state ): string {
		if ( in_array( $old_state, [ 'resolved', 'suppressed' ], true ) ) {
			return 'open';
		}
		if ( 'critical' === (string) ( $event['severity_level'] ?? '' ) ) {
			return 'open';
		}
		$threshold = AuditEvent::OUTCOME_RETRYABLE === (string) ( $event['outcome'] ?? '' ) ? self::RETRYABLE_THRESHOLD : self::OBSERVING_THRESHOLD;
		return $count >= $threshold ? 'open' : 'observing';
	}

	/** @param array<string, mixed> $row @param array<string, mixed> $event */
	private static function correlates( array $row, array $event ): bool {
		$row_change_set   = (string) ( $row['last_change_set_id'] ?? '' );
		$event_change_set = (string) ( $event['change_set_id'] ?? '' );
		if ( '' === $row_change_set || '' === $event_change_set || ! hash_equals( $row_change_set, $event_change_set ) ) {
			return false;
		}

		$row_resource   = self::safe_hash( $row['resource_key_hash'] ?? '' );
		$event_resource = self::safe_hash( $event['resource_key_hash'] ?? '' );
		if ( '' === $row_resource || '' === $event_resource || ! hash_equals( $row_resource, $event_resource ) ) {
			return false;
		}
		if ( (string) ( $row['normalized_path'] ?? '' ) !== (string) ( $event['normalized_path'] ?? '' ) ) {
			return false;
		}

		$verification = strtolower( (string) ( $event['verification_status'] ?? '' ) );
		if ( ! in_array( $verification, [ 'passed', 'verified' ], true ) ) {
			return false;
		}
		$expected_verifier = (string) ( $row['expected_verifier'] ?? '' );
		if ( '' !== $expected_verifier && $expected_verifier !== (string) ( $event['ability'] ?? '' ) ) {
			return false;
		}
		return true;
	}

	/** @param array<string, mixed> $row @param array<string, mixed> $receipt */
	private static function receipt_correlates( array $row, array $receipt ): bool {
		$change_set = self::safe_text( $receipt['change_set_id'] ?? '', 96 );
		$resource   = self::safe_hash( $receipt['resource_key_hash'] ?? '' );
		$path       = self::safe_text( $receipt['normalized_path'] ?? '', 255 );
		if ( '' === $change_set || '' === $resource || '' === $path ) {
			return false;
		}
		if ( ! hash_equals( (string) ( $row['last_change_set_id'] ?? '' ), $change_set )
			|| ! hash_equals( (string) ( $row['resource_key_hash'] ?? '' ), $resource )
			|| (string) ( $row['normalized_path'] ?? '' ) !== $path ) {
			return false;
		}
		$evidence = is_array( $receipt['evidence'] ?? null ) ? $receipt['evidence'] : [];
		$expected = (string) ( $row['expected_verifier'] ?? '' );
		return '' === $expected || $expected === (string) ( $evidence['verifier'] ?? '' );
	}

	private static function uncorrelated_error(): \WP_Error {
		return new \WP_Error(
			'stonewright_repair_uncorrelated',
			__( 'Verified repair receipt does not match the open incident.', 'stonewright' )
		);
	}

	/** @param array<string,mixed> $event @return array<string,mixed>|null */
	private static function find_correlated( array $event ): ?array {
		$rows = array_merge( self::recent( 500, [ 'state' => 'open' ] ), self::recent( 500, [ 'state' => 'observing' ] ) );
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && self::correlates( $row, $event ) ) {
				return $row;
			}
		}
		return null;
	}

	private static function db_available(): bool {
		global $wpdb;
		// Anonymous wpdb-shaped doubles must use the option fallback. Treating a
		// skill/mock row as an incident row can let a successful audit overwrite
		// the primary mutation recorded by a unit harness.
		return $wpdb instanceof \wpdb;
	}

	/** @param array<string, mixed> $row */
	private static function persist( array $row, bool $update ): void {
		global $wpdb;
		if ( self::db_available() ) {
			$table = self::table_name();
			$data = $row;
			unset( $data['id'] );
			if ( $update ) {
				$wpdb->update( $table, $data, [ 'incident_id' => $row['incident_id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- owned lifecycle table.
			} else {
				$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- owned lifecycle table.
			}
			return;
		}
		self::$fallback[ (string) $row['incident_id'] ] = $row;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, self::$fallback, false );
		}
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private static function public_row( array $row ): array {
		return [
			'incident_id'          => (string) ( $row['incident_id'] ?? '' ),
			'state'                => (string) ( $row['state'] ?? 'observing' ),
			'category'             => (string) ( $row['category'] ?? '' ),
			'outcome'              => (string) ( $row['outcome'] ?? '' ),
			'severity'             => (string) ( $row['severity'] ?? '' ),
			'ability_name'         => (string) ( $row['ability_name'] ?? '' ),
			'ability_family'       => (string) ( $row['ability_family'] ?? '' ),
			'root_error_code'      => (string) ( $row['root_error_code'] ?? '' ),
			'resource_type'        => (string) ( $row['resource_type'] ?? '' ),
			'resource_key_hash'    => (string) ( $row['resource_key_hash'] ?? '' ),
			'normalized_path'      => (string) ( $row['normalized_path'] ?? '' ),
			'cause_fingerprint'    => (string) ( $row['cause_fingerprint'] ?? '' ),
			'strategy_fingerprint' => (string) ( $row['strategy_fingerprint'] ?? '' ),
			'expected_verifier'    => (string) ( $row['expected_verifier'] ?? '' ),
			'remediation_code'     => (string) ( $row['remediation_code'] ?? '' ),
			'occurrence_count'     => (int) ( $row['occurrence_count'] ?? 0 ),
			'reopened_count'       => (int) ( $row['reopened_count'] ?? 0 ),
			'first_seen'           => (string) ( $row['first_seen'] ?? '' ),
			'last_seen'            => (string) ( $row['last_seen'] ?? '' ),
			'resolved_at'          => (string) ( $row['resolved_at'] ?? '' ),
			'last_event_id'        => (string) ( $row['last_event_id'] ?? '' ),
			'resolution_event_id'  => (string) ( $row['resolution_event_id'] ?? '' ),
			'last_change_set_id'   => (string) ( $row['last_change_set_id'] ?? '' ),
			'repair_phase'         => (string) ( $row['repair_phase'] ?? 'none' ),
			'learning_status'      => (string) ( $row['learning_status'] ?? 'none' ),
			'learning_memory_key'  => (string) ( $row['learning_memory_key'] ?? '' ),
			'repair_receipt_id'    => (string) ( $row['repair_receipt_id'] ?? '' ),
			'learned_at'           => (string) ( $row['learned_at'] ?? '' ),
			'schema_version'       => (string) ( $row['schema_version'] ?? AuditEvent::SCHEMA_VERSION ),
		];
	}

	private static function safe_hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function safe_text( mixed $value, int $length ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $length ) : '';
	}
}
