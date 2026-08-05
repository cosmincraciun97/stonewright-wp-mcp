<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

/**
 * Idempotent migration of pre-v2 audit rows into the permanent taxonomy.
 */
final class AuditReconciler {

	public const MIGRATION_OPTION = 'stonewright_audit_reconciled_v2';
	public const JOURNAL_OPTION   = 'stonewright_audit_reconcile_journal_v2';

	/** @param array<string, mixed> $legacy @return array<string, mixed> */
	public static function classify( array $legacy ): array {
		$status       = strtolower( (string) ( $legacy['result_status'] ?? '' ) );
		$event_type   = strtolower( (string) ( $legacy['event_type'] ?? '' ) );
		$verification = strtolower( (string) ( $legacy['verification_status'] ?? '' ) );
		$rollback     = strtolower( (string) ( $legacy['rollback_status'] ?? '' ) );
		$code         = strtolower( (string) ( $legacy['error_code'] ?? '' ) );
		$operation    = strtolower( (string) ( $legacy['operation_class'] ?? '' ) );
		$ability_raw  = strtolower( (string) ( $legacy['ability_name'] ?? '' ) );
		$http_status  = (int) ( $legacy['http_status'] ?? 0 );
		$is_auth      = 'auth' === $status || 'auth' === $event_type || 'oauth' === $operation || str_starts_with( $ability_raw, 'oauth/' );

		$category = AuditEvent::CATEGORY_WRITE;
		$outcome  = AuditEvent::OUTCOME_SUCCESS;
		$severity = 'info';
		if ( $is_auth && 'ok' === $status ) {
			$category = AuditEvent::CATEGORY_AUTH;
			$outcome  = AuditEvent::OUTCOME_SUCCESS;
		} elseif ( $is_auth ) {
			$category = AuditEvent::CATEGORY_AUTH;
			$outcome  = 429 === $http_status || $http_status >= 500 ? AuditEvent::OUTCOME_RETRYABLE : AuditEvent::OUTCOME_BLOCKED;
			$severity = $http_status >= 500 ? 'error' : 'warning';
		} elseif ( self::permission_code( $code ) ) {
			$category = AuditEvent::CATEGORY_PERMISSION;
			$outcome  = AuditEvent::OUTCOME_BLOCKED;
			$severity = 'warning';
		} elseif ( 'blocked' === $status || 'safety_block' === $event_type || self::safety_code( $code ) ) {
			$category = AuditEvent::CATEGORY_SAFETY;
			$outcome  = AuditEvent::OUTCOME_BLOCKED;
			$severity = 'warning';
		} elseif ( str_contains( $code, 'schema' ) || str_contains( $code, 'validation' ) || str_contains( $code, 'invalid_setting' ) ) {
			$category = AuditEvent::CATEGORY_VALIDATION;
			$outcome  = AuditEvent::OUTCOME_FAILED;
			$severity = 'error';
		} elseif ( 'busy' === $code || str_contains( $code, 'lock' ) || str_contains( $code, 'conflict' ) || str_contains( $operation, 'rate' ) ) {
			$category = AuditEvent::CATEGORY_TRANSIENT;
			$outcome  = AuditEvent::OUTCOME_RETRYABLE;
			$severity = 'warning';
		} elseif ( 'failed' === $rollback || 'succeeded' === $rollback ) {
			$category = AuditEvent::CATEGORY_ROLLBACK;
			$outcome  = 'failed' === $rollback ? AuditEvent::OUTCOME_FAILED : AuditEvent::OUTCOME_SUCCESS;
			$severity = 'failed' === $rollback ? 'critical' : 'info';
		} elseif ( 'failed' === $verification || 'missing' === $verification ) {
			$category = AuditEvent::CATEGORY_VERIFY;
			$outcome  = AuditEvent::OUTCOME_FAILED;
			$severity = 'error';
		} elseif ( 'error' === $status || 'incident' === $event_type ) {
			$category = AuditEvent::CATEGORY_WRITE;
			$outcome  = AuditEvent::OUTCOME_FAILED;
			$severity = 'error';
		}

		$ability       = sanitize_text_field( (string) ( $legacy['ability_name'] ?? '' ) );
		$resource_type = sanitize_text_field( (string) ( $legacy['resource_type'] ?? '' ) );
		$resource_ref  = sanitize_text_field( (string) ( $legacy['resource_ref'] ?? '' ) );
		$path          = self::normalized_path( (string) ( $legacy['normalized_path'] ?? $resource_ref ) );
		$resource_hash = '' !== $resource_type || '' !== $resource_ref ? hash( 'sha256', $resource_type . '|' . $resource_ref ) : '';
		$cause         = self::valid_hash( $legacy['cause_fingerprint'] ?? '' );
		if ( '' === $cause ) {
			$cause = hash( 'sha256', implode( '|', [ strtolower( $ability ), $code, $resource_hash, $path ] ) );
		}
		$strategy = self::valid_hash( $legacy['strategy_fingerprint'] ?? '' );
		if ( '' === $strategy ) {
			$strategy = hash( 'sha256', strtolower( $operation ) );
		}
		$id = (int) ( $legacy['id'] ?? 0 );

		return [
			'schema_version'        => AuditEvent::SCHEMA_VERSION,
			'event_id'              => '' !== (string) ( $legacy['event_id'] ?? '' ) ? sanitize_text_field( (string) $legacy['event_id'] ) : substr( hash( 'sha256', 'legacy-audit|' . $id ), 0, 36 ),
			'category'              => $category,
			'outcome'               => $outcome,
			'severity_level'        => $severity,
			'root_error_code'       => '' !== $code ? $code : '',
			'resource_key_hash'     => $resource_hash,
			'normalized_path'       => $path,
			'cause_fingerprint'     => $cause,
			'strategy_fingerprint'  => $strategy,
			'incident_id'           => hash( 'sha256', implode( '|', [ $category, strtolower( $ability ), $code, $resource_hash, $path, $cause, $strategy ] ) ),
			'retryable'             => AuditEvent::OUTCOME_RETRYABLE === $outcome,
			'retry_after_seconds'   => max( 0, (int) ( $legacy['retry_after_seconds'] ?? 0 ) ),
		];
	}

	public static function maybe_migrate(): int {
		// Schema installation and read paths must never rewrite historical audit
		// rows. Migration is an explicit apply operation via migrate().
		return 0;
	}

	/**
	 * Produce a bounded, read-only migration plan. It returns counts only; no
	 * legacy payload or site-local value leaves the database boundary.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function preview( int $limit = 1000 ): array|\WP_Error {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new \WP_Error( 'stonewright_audit_reconcile_unavailable', __( 'Audit reconciliation requires a WordPress database connection.', 'stonewright' ), [ 'status' => 503 ] );
		}
		$limit   = max( 1, min( 5000, $limit ) );
		$table   = AuditLog::table_name();
		$pending = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE schema_version = '' OR schema_version IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- owned table and fixed predicate.
		if ( null === $pending || false === $pending ) {
			return new \WP_Error( 'stonewright_audit_reconcile_read_failed', __( 'Could not count legacy audit rows.', 'stonewright' ), [ 'status' => 500 ] );
		}
		$count = max( 0, (int) $pending );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE schema_version = '' OR schema_version IS NULL ORDER BY id ASC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- owned table and bounded internal query.
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'stonewright_audit_reconcile_read_failed', __( 'Could not inspect legacy audit rows.', 'stonewright' ), [ 'status' => 500 ] );
		}

		$legacy_distribution = [];
		$categories          = [];
		$outcomes            = [];
		$create_candidates   = 0;
		$close_candidates    = 0;
		$unchanged           = 0;
		$ambiguous           = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$ambiguous;
				continue;
			}
			$legacy_key = sanitize_key( strtolower( (string) ( $row['result_status'] ?? $row['event_type'] ?? 'unspecified' ) ) );
			$legacy_key = '' !== $legacy_key ? $legacy_key : 'unspecified';
			$legacy_distribution[ $legacy_key ] = 1 + (int) ( $legacy_distribution[ $legacy_key ] ?? 0 );
			$classified = self::classify( $row );
			$category   = (string) $classified['category'];
			$outcome    = (string) $classified['outcome'];
			$categories[ $category ] = 1 + (int) ( $categories[ $category ] ?? 0 );
			$outcomes[ $outcome ]     = 1 + (int) ( $outcomes[ $outcome ] ?? 0 );

			if ( in_array( $outcome, [ AuditEvent::OUTCOME_FAILED, AuditEvent::OUTCOME_RETRYABLE ], true ) ) {
				++$create_candidates;
			} elseif ( AuditEvent::OUTCOME_SUCCESS === $outcome
				&& in_array( strtolower( (string) ( $row['verification_status'] ?? '' ) ), [ 'passed', 'verified', 'succeeded' ], true )
				&& '' !== (string) ( $row['change_set_id'] ?? '' )
				&& ( '' !== (string) ( $row['resource_ref'] ?? '' ) || '' !== (string) ( $row['resource_key_hash'] ?? '' ) ) ) {
				++$close_candidates;
			} else {
				++$unchanged;
			}

			$signals = [ 'result_status', 'event_type', 'error_code', 'operation_class', 'ability_name', 'verification_status', 'rollback_status' ];
			$known   = false;
			foreach ( $signals as $signal ) {
				if ( '' !== trim( (string) ( $row[ $signal ] ?? '' ) ) ) {
					$known = true;
					break;
				}
			}
			if ( ! $known ) {
				++$ambiguous;
			}
		}
		ksort( $legacy_distribution );
		ksort( $categories );
		ksort( $outcomes );

		return [
			'schema_version'       => AuditEvent::SCHEMA_VERSION,
			'pending'              => $count,
			'rows_scanned'         => count( $rows ),
			'batch_size'           => min( $count, $limit ),
			'complete'             => 0 === $count,
			'legacy_distribution'  => $legacy_distribution,
			'new_distribution'     => [
				'categories' => $categories,
				'outcomes'   => $outcomes,
			],
			'incident_projection'  => [
				'create_candidates' => $create_candidates,
				'close_candidates'  => $close_candidates,
				'unchanged'         => $unchanged,
			],
			'ambiguous_rows'       => $ambiguous,
			'contains_raw_rows'    => false,
		];
	}

	/** @return int|\WP_Error */
	public static function migrate( int $limit = 1000, string $confirmation_token = '', array $confirmation_args = [] ): int|\WP_Error {
		if ( '1' === (string) get_option( self::MIGRATION_OPTION, '0' ) ) {
			return 0;
		}
		$limit = max( 1, min( 5000, $limit ) );
		if ( 'production-safe' === (string) get_option( 'stonewright_mode', 'development' ) ) {
			$token_args = [] !== $confirmation_args ? $confirmation_args : [ 'limit' => $limit ];
			$verified   = ConfirmationToken::verify_or_error( $confirmation_token, 'stonewright/security-audit-reconcile', $token_args );
			if ( $verified instanceof \WP_Error ) {
				return $verified;
			}
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'update' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new \WP_Error( 'stonewright_audit_reconcile_unavailable', __( 'Audit reconciliation requires a WordPress database connection.', 'stonewright' ), [ 'status' => 503 ] );
		}
		$table = AuditLog::table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE schema_version = '' OR schema_version IS NULL ORDER BY id ASC LIMIT %d", $limit + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name is an internal constant.
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'stonewright_audit_reconcile_read_failed', __( 'Could not read legacy audit rows.', 'stonewright' ), [ 'status' => 500 ] );
		}
		$has_more      = count( $rows ) > $limit;
		$rows          = array_slice( $rows, 0, $limit );
		$migrated      = 0;
		$migrated_ids  = [];
		$pending_before = count( $rows ) + ( $has_more ? 1 : 0 );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'] ) ) {
				continue;
			}
			$data = self::classify( $row );
			$updated = $wpdb->update( $table, $data, [ 'id' => (int) $row['id'] ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- owned migration.
			if ( false === $updated ) {
				self::record_journal( $migrated_ids, $pending_before, $migrated, 'failed' );
				return new \WP_Error(
					'stonewright_audit_reconcile_write_failed',
					__( 'Could not reconcile a legacy audit row. The migration remains incomplete.', 'stonewright' ),
					[ 'status' => 500, 'migrated' => $migrated, 'failed_row_id' => (int) $row['id'] ]
				);
			}
			self::apply_incident_lifecycle( $row, $data );
			$migrated_ids[] = (int) $row['id'];
			++$migrated;
		}
		if ( ! $has_more ) {
			update_option( self::MIGRATION_OPTION, '1', false );
		}
		self::record_journal( $migrated_ids, $pending_before, $migrated, 'applied' );
		return $migrated;
	}

	private static function safety_code( string $code ): bool {
		return str_contains( $code, 'blocked' )
			|| str_contains( $code, 'confirmation' )
			|| str_contains( $code, 'read_only' )
			|| str_contains( $code, 'permission' )
			|| str_contains( $code, 'architecture' )
			|| str_contains( $code, 'raw_elementor' )
			|| 'rule_violation' === $code;
	}

	/** @param array<string,mixed> $legacy @param array<string,mixed> $classified */
	private static function apply_incident_lifecycle( array $legacy, array $classified ): void {
		$event = $classified + [
			'ability'            => sanitize_text_field( (string) ( $legacy['ability_name'] ?? '' ) ),
			'ability_family'     => sanitize_key( (string) ( $legacy['operation_class'] ?? '' ) ),
			'resource_type'      => sanitize_key( (string) ( $legacy['resource_type'] ?? '' ) ),
			'change_set_id'      => sanitize_text_field( (string) ( $legacy['change_set_id'] ?? '' ) ),
			'verification_status'=> sanitize_key( (string) ( $legacy['verification_status'] ?? '' ) ),
			'rollback_status'    => sanitize_key( (string) ( $legacy['rollback_status'] ?? '' ) ),
			'expected_verifier'  => sanitize_text_field( (string) ( $legacy['expected_verifier'] ?? '' ) ),
			'remediation_code'   => sanitize_key( (string) ( $legacy['remediation_code'] ?? '' ) ),
			'public_message'     => '',
			'redacted_details'   => [],
		];
		if ( AuditEvent::OUTCOME_SUCCESS === (string) $classified['outcome'] ) {
			IncidentStore::resolve( $event );
			return;
		}
		IncidentStore::observe( $event );
	}

	/** @param list<int> $row_ids */
	private static function record_journal( array $row_ids, int $pending_before, int $migrated, string $status ): void {
		if ( [] === $row_ids && 0 === $migrated ) {
			return;
		}
		$journal = get_option( self::JOURNAL_OPTION, [] );
		$journal = is_array( $journal ) ? $journal : [];
		$journal[] = [
			'journal_id'     => wp_generate_uuid4(),
			'schema_version' => AuditEvent::SCHEMA_VERSION,
			'row_ids'        => array_values( array_map( 'intval', $row_ids ) ),
			'row_ids_hash'   => hash( 'sha256', implode( ',', $row_ids ) ),
			'pending_before' => max( 0, $pending_before ),
			'migrated'       => max( 0, $migrated ),
			'status'         => in_array( $status, [ 'applied', 'failed' ], true ) ? $status : 'failed',
			'created_at'     => gmdate( 'c' ),
		];
		update_option( self::JOURNAL_OPTION, array_slice( $journal, -20 ), false );
	}

	private static function permission_code( string $code ): bool {
		return str_contains( $code, 'permission' ) || str_contains( $code, 'forbidden' ) || str_contains( $code, 'capability' ) || str_contains( $code, 'unauthorized' );
	}

	private static function valid_hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : '';
	}

	private static function normalized_path( string $value ): string {
		$value = str_replace( '\\', '/', sanitize_text_field( $value ) );
		$value = ltrim( preg_replace( '#/{2,}#', '/', $value ) ?? $value, '/' );
		$parts = array_values( array_filter( explode( '/', $value ), static fn ( string $part ): bool => '' !== $part && '.' !== $part && '..' !== $part ) );
		return mb_substr( implode( '/', array_slice( $parts, -6 ) ), 0, 255 );
	}
}
