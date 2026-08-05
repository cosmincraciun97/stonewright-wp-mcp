<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Security;

use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Support\Json;

/**
 * Count-only planning and explicit deletion for Stonewright runtime history.
 *
 * The planner never returns row bodies. Apply compares the complete state
 * fingerprint from the reviewed dry run before issuing any DELETE statement.
 */
final class RuntimeDataPurger {

	public const SCHEMA_VERSION = '1.0';

	/** @var list<string> */
	public const SCOPES = [ 'audit', 'memory', 'incidents', 'error_patterns' ];

	/**
	 * @param list<string> $scopes
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function preview( array $scopes ): array|\WP_Error {
		$scopes = self::normalize_scopes( $scopes );
		if ( [] === $scopes ) {
			return new \WP_Error(
				'stonewright_runtime_data_purge_scope_invalid',
				__( 'Choose at least one supported runtime-data scope.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$audit     = self::table_state( AuditLog::table_name() );
		$memory    = self::table_state( Memory::table_name() );
		$incidents = self::table_state( IncidentStore::table_name() );
		if ( $audit instanceof \WP_Error || $memory instanceof \WP_Error || $incidents instanceof \WP_Error ) {
			return $audit instanceof \WP_Error ? $audit : ( $memory instanceof \WP_Error ? $memory : $incidents );
		}

		$error_patterns = self::option_array( ErrorPatterns::OPTION_KEY );
		$journal        = self::option_array( AuditReconciler::JOURNAL_OPTION );
		$fallback       = self::option_array( IncidentStore::OPTION_KEY );
		$degraded       = get_option( 'stonewright_audit_degraded', null );
		$oauth_transients = self::oauth_audit_transient_state();
		if ( $oauth_transients instanceof \WP_Error ) {
			return $oauth_transients;
		}

		$state = [
			'audit' => [
				'table'            => $audit,
				'journal_hash'     => Json::hash( $journal ),
				'degraded_hash'    => Json::hash( $degraded ),
				'oauth_transients' => $oauth_transients,
			],
			'memory' => [
				'table' => $memory,
			],
			'incidents' => [
				'table'         => $incidents,
				'fallback_hash' => Json::hash( $fallback ),
			],
			'error_patterns' => [
				'option_hash' => Json::hash( $error_patterns ),
			],
		];
		$selected_state = array_intersect_key( $state, array_fill_keys( $scopes, true ) );
		$scope_hashes = [];
		foreach ( $selected_state as $scope => $scope_state ) {
			$scope_hashes[ $scope ] = Json::hash( $scope_state );
		}
		$audit_support_hash = Json::hash(
			[
				'journal_hash'     => $state['audit']['journal_hash'],
				'degraded_hash'    => $state['audit']['degraded_hash'],
				'oauth_transients' => $state['audit']['oauth_transients'],
			]
		);
		$counts = [
			'audit_events'          => in_array( 'audit', $scopes, true ) ? (int) $audit['count'] : 0,
			'audit_journal_entries' => in_array( 'audit', $scopes, true ) ? count( $journal ) : 0,
			'audit_degraded_markers'=> in_array( 'audit', $scopes, true ) && null !== $degraded && false !== $degraded ? 1 : 0,
			'oauth_audit_transients'=> in_array( 'audit', $scopes, true ) ? array_sum( array_column( $oauth_transients, 'count' ) ) : 0,
			'memory_entries'        => in_array( 'memory', $scopes, true ) ? (int) $memory['count'] : 0,
			'incidents'             => in_array( 'incidents', $scopes, true ) ? (int) $incidents['count'] + count( $fallback ) : 0,
			'error_patterns'        => in_array( 'error_patterns', $scopes, true ) ? count( $error_patterns ) : 0,
		];
		$state_hash = Json::hash(
			[
				'schema_version' => self::SCHEMA_VERSION,
				'scopes'         => $scopes,
				'state'          => $selected_state,
			]
		);

		return [
			'schema_version'   => self::SCHEMA_VERSION,
			'scopes'           => $scopes,
			'counts'           => $counts,
			'total'            => array_sum( $counts ),
			'state_hash'       => $state_hash,
			'plan_hash'        => Json::hash( [ 'state_hash' => $state_hash, 'counts' => $counts, 'scopes' => $scopes ] ),
			'scope_hashes'     => $scope_hashes,
			'scope_watermarks' => [
				'audit' => [
					'table_max_id'          => (int) $audit['max_id'],
					'oauth_value_max_id'    => (int) $oauth_transients['value']['max_id'],
					'oauth_timeout_max_id'  => (int) $oauth_transients['timeout']['max_id'],
				],
				'memory'    => [ 'table_max_id' => (int) $memory['max_id'] ],
				'incidents' => [ 'table_max_id' => (int) $incidents['max_id'] ],
			],
			'audit_support_hash' => $audit_support_hash,
			'audit_journal_hash' => (string) $state['audit']['journal_hash'],
			'audit_degraded_hash'=> (string) $state['audit']['degraded_hash'],
			'contains_raw_rows'=> false,
			'cleanup_receipt_retained' => in_array( 'audit', $scopes, true ),
		];
	}

	/**
	 * @param array<string, mixed> $preview
	 */
	public static function validate_reviewed( array $preview, string $expected_state_hash, string $approved_plan_hash ): ?\WP_Error {
		if ( ! self::valid_hash( $expected_state_hash ) || ! hash_equals( (string) ( $preview['state_hash'] ?? '' ), strtolower( $expected_state_hash ) ) ) {
			return new \WP_Error(
				'stonewright_runtime_data_purge_state_conflict',
				__( 'Runtime history changed after the reviewed dry run.', 'stonewright' ),
				[ 'status' => 409, 'current_state_hash' => $preview['state_hash'] ?? '', 'retryable' => true ]
			);
		}
		if ( ! self::valid_hash( $approved_plan_hash ) || ! hash_equals( (string) ( $preview['plan_hash'] ?? '' ), strtolower( $approved_plan_hash ) ) ) {
			return new \WP_Error(
				'stonewright_runtime_data_purge_plan_conflict',
				__( 'Runtime history purge does not match the reviewed plan.', 'stonewright' ),
				[ 'status' => 409, 'current_plan_hash' => $preview['plan_hash'] ?? '', 'retryable' => true ]
			);
		}
		return null;
	}

	/**
	 * @param list<string>        $scopes
	 * @param array<string,mixed> $reviewed_preview
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function purge( array $scopes, string $expected_state_hash, string $approved_plan_hash, array $reviewed_preview = [], string $confirmation_ability = '' ): array|\WP_Error {
		$current = self::preview( $scopes );
		if ( $current instanceof \WP_Error ) {
			return $current;
		}
		$reviewed = [] !== $reviewed_preview ? $reviewed_preview : $current;
		$review_error = self::validate_reviewed( $reviewed, $expected_state_hash, $approved_plan_hash );
		if ( $review_error instanceof \WP_Error ) {
			return $review_error;
		}
		if ( ! hash_equals( (string) $reviewed['state_hash'], (string) $current['state_hash'] )
			&& ! self::is_expected_confirmation_delta( $reviewed, $current, $confirmation_ability ) ) {
			return new \WP_Error(
				'stonewright_runtime_data_purge_state_conflict',
				__( 'Runtime history changed after the reviewed dry run.', 'stonewright' ),
				[ 'status' => 409, 'current_state_hash' => $current['state_hash'], 'retryable' => true ]
			);
		}
		$preview = $current;
		$watermarks = (array) ( $current['scope_watermarks'] ?? [] );

		$scopes = self::normalize_scopes( $scopes );
		$errors = [];
		if ( in_array( 'audit', $scopes, true ) ) {
			self::delete_table_rows( AuditLog::table_name(), 'audit', (int) ( $watermarks['audit']['table_max_id'] ?? 0 ), $errors );
			delete_option( AuditReconciler::JOURNAL_OPTION );
			delete_option( 'stonewright_audit_degraded' );
			self::delete_oauth_audit_transients( (array) ( $watermarks['audit'] ?? [] ), $errors );
		}
		if ( in_array( 'memory', $scopes, true ) ) {
			self::delete_table_rows( Memory::table_name(), 'memory', (int) ( $watermarks['memory']['table_max_id'] ?? 0 ), $errors );
		}
		if ( in_array( 'incidents', $scopes, true ) ) {
			self::delete_table_rows( IncidentStore::table_name(), 'incidents', (int) ( $watermarks['incidents']['table_max_id'] ?? 0 ), $errors );
			delete_option( IncidentStore::OPTION_KEY );
		}
		if ( in_array( 'error_patterns', $scopes, true ) ) {
			delete_option( ErrorPatterns::OPTION_KEY );
		}

		$after = self::preview( $scopes );
		if ( $after instanceof \WP_Error ) {
			return $after;
		}
		if ( [] !== $errors || 0 !== (int) $after['total'] ) {
			return new \WP_Error(
				'stonewright_runtime_data_purge_partial_failure',
				__( 'Runtime history was only partially cleared. Inspect the bounded failure list before retrying.', 'stonewright' ),
				[
					'status'           => 500,
					'failed_scopes'    => array_values( array_unique( $errors ) ),
					'remaining_counts' => $after['counts'],
					'cleanup_performed'=> true,
				]
			);
		}

		return [
			'before' => $preview,
			'after'  => $after,
		];
	}

	/** @param list<string> $scopes @return list<string> */
	public static function normalize_scopes( array $scopes ): array {
		$out = [];
		foreach ( $scopes as $scope ) {
			$scope = is_scalar( $scope ) ? sanitize_key( (string) $scope ) : '';
			if ( in_array( $scope, self::SCOPES, true ) && ! in_array( $scope, $out, true ) ) {
				$out[] = $scope;
			}
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/** @return array{exists:bool,count:int,max_id:int}|\WP_Error */
	private static function table_state( string $table ): array|\WP_Error {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new \WP_Error( 'stonewright_runtime_data_purge_unavailable', __( 'Runtime history maintenance requires a WordPress database connection.', 'stonewright' ), [ 'status' => 503 ] );
		}
		$like = method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : addcslashes( $table, '_%\\' );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( ! is_string( $found ) || $found !== $table ) {
			return [ 'exists' => false, 'count' => 0, 'max_id' => 0 ];
		}
		$count  = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- owned internal table.
		$max_id = $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- owned internal table.
		if ( null === $count || false === $count || null === $max_id || false === $max_id ) {
			return new \WP_Error( 'stonewright_runtime_data_purge_read_failed', __( 'Could not read one runtime-history table.', 'stonewright' ), [ 'status' => 500 ] );
		}
		return [ 'exists' => true, 'count' => max( 0, (int) $count ), 'max_id' => max( 0, (int) $max_id ) ];
	}

	/** @return array<array-key, mixed> */
	private static function option_array( string $name ): array {
		$value = get_option( $name, [] );
		return is_array( $value ) ? $value : [];
	}

	/** @return array{value:array{count:int,max_id:int},timeout:array{count:int,max_id:int}}|\WP_Error */
	private static function oauth_audit_transient_state(): array|\WP_Error {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new \WP_Error( 'stonewright_runtime_data_purge_unavailable', __( 'Runtime history maintenance requires the WordPress options table.', 'stonewright' ), [ 'status' => 503 ] );
		}
		$out = [];
		foreach ( [ 'value' => '_transient_stonewright_oauth_audit_%', 'timeout' => '_transient_timeout_stonewright_oauth_audit_%' ] as $kind => $pattern ) {
			$count_sql = "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- options table is WordPress-owned.
			$max_sql   = "SELECT COALESCE(MAX(option_id), 0) FROM {$wpdb->options} WHERE option_name LIKE %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- options table is WordPress-owned.
			$count      = $wpdb->get_var( $wpdb->prepare( $count_sql, $pattern ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL template and value are prepared immediately above.
			$max_id     = $wpdb->get_var( $wpdb->prepare( $max_sql, $pattern ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL template and value are prepared immediately above.
			$out[ $kind ] = [
				'count'  => null === $count || false === $count ? 0 : max( 0, (int) $count ),
				'max_id' => null === $max_id || false === $max_id ? 0 : max( 0, (int) $max_id ),
			];
		}
		return $out;
	}

	/** @param list<string> $errors */
	private static function delete_table_rows( string $table, string $scope, int $max_id, array &$errors ): void {
		global $wpdb;
		$state = self::table_state( $table );
		if ( $state instanceof \WP_Error ) {
			$errors[] = $scope;
			return;
		}
		if ( ! $state['exists'] || $max_id < 1 ) {
			return;
		}
		$sql = "DELETE FROM {$table} WHERE id <= %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- owned internal table with a reviewed numeric watermark.
		$result = $wpdb->query( $wpdb->prepare( $sql, $max_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared immediately above; explicit bounded purge.
		if ( false === $result ) {
			$errors[] = $scope;
		}
	}

	/** @param list<string> $errors */
	private static function delete_oauth_audit_transients( array $watermarks, array &$errors ): void {
		global $wpdb;
		$patterns = [
			'oauth_value_max_id'   => '_transient_stonewright_oauth_audit_%',
			'oauth_timeout_max_id' => '_transient_timeout_stonewright_oauth_audit_%',
		];
		foreach ( $patterns as $watermark_key => $pattern ) {
			$max_id = (int) ( $watermarks[ $watermark_key ] ?? 0 );
			if ( $max_id < 1 ) {
				continue;
			}
			$sql = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id <= %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- options table is WordPress-owned; deletion is bounded by reviewed watermark.
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $pattern, $max_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL template and values are prepared immediately above.
				$errors[] = 'audit';
			}
		}
	}

	private static function valid_hash( string $hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', strtolower( $hash ) );
	}

	/** @param array<string,mixed> $reviewed @param array<string,mixed> $current */
	private static function is_expected_confirmation_delta( array $reviewed, array $current, string $ability ): bool {
		if ( '' === $ability || ! in_array( 'audit', (array) ( $reviewed['scopes'] ?? [] ), true ) ) {
			return false;
		}
		if ( (string) ( $reviewed['audit_journal_hash'] ?? '' ) !== (string) ( $current['audit_journal_hash'] ?? '' ) ) {
			return false;
		}
		if ( (int) ( $reviewed['counts']['oauth_audit_transients'] ?? 0 ) !== (int) ( $current['counts']['oauth_audit_transients'] ?? 0 ) ) {
			return false;
		}
		$degraded_unchanged = (string) ( $reviewed['audit_degraded_hash'] ?? '' ) === (string) ( $current['audit_degraded_hash'] ?? '' );
		$degraded_cleared = 1 === (int) ( $reviewed['counts']['audit_degraded_markers'] ?? 0 )
			&& 0 === (int) ( $current['counts']['audit_degraded_markers'] ?? 0 );
		if ( ! $degraded_unchanged && ! $degraded_cleared ) {
			return false;
		}
		$reviewed_hashes = (array) ( $reviewed['scope_hashes'] ?? [] );
		$current_hashes  = (array) ( $current['scope_hashes'] ?? [] );
		foreach ( $reviewed_hashes as $scope => $hash ) {
			if ( 'audit' !== $scope && (string) $hash !== (string) ( $current_hashes[ $scope ] ?? '' ) ) {
				return false;
			}
		}
		$before_count = (int) ( $reviewed['counts']['audit_events'] ?? 0 );
		$after_count  = (int) ( $current['counts']['audit_events'] ?? 0 );
		return $after_count === $before_count + 1 && self::latest_confirmation_is_valid_for( $ability );
	}

	private static function latest_confirmation_is_valid_for( string $ability ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return false;
		}
		$table = AuditLog::table_name();
		$row = $wpdb->get_row( "SELECT ability_name, sanitized_args FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- owned table, fixed read.
		if ( ! is_array( $row ) || 'security.confirmation_token' !== (string) ( $row['ability_name'] ?? '' ) ) {
			return false;
		}
		$args = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		return is_array( $args ) && 'valid' === (string) ( $args['result'] ?? '' ) && $ability === (string) ( $args['ability'] ?? '' );
	}
}
