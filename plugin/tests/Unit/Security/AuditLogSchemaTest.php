<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Security\AuditLog
 * @covers \Stonewright\WpMcp\Security\IncidentStore
 */
final class AuditLogSchemaTest extends TestCase {

	private mixed $original_wpdb;

	/** @var list<string> */
	private const AUDIT_COLUMNS = [
		'id',
		'ability_name',
		'user_id',
		'args_hash',
		'sanitized_args',
		'result_status',
		'ip_hash',
		'ua_hash',
		'request_id',
		'correlation_id',
		'operation_id',
		'parent_event_id',
		'attempt',
		'idempotency_key',
		'terminal_idempotency_key',
		'lifecycle_phase',
		'is_terminal',
		'terminal_owner',
		'parent_request_id',
		'event_type',
		'operation_class',
		'resource_type',
		'resource_ref',
		'change_set_id',
		'repair_of',
		'execution_status',
		'verification_status',
		'effect_verified',
		'rollback_status',
		'before_sha256',
		'after_sha256',
		'changed_bytes',
		'validator_summary',
		'smoke_summary',
		'error_code',
		'cause_key',
		'duration_ms',
		'backend',
		'site_fingerprint',
		'mode',
		'severity',
		'event_id',
		'schema_version',
		'category',
		'outcome',
		'severity_level',
		'root_error_code',
		'resource_key_hash',
		'normalized_path',
		'cause_fingerprint',
		'strategy_fingerprint',
		'transaction_id',
		'context_token_id_hash',
		'expected_verifier',
		'remediation_code',
		'retryable',
		'retry_after_seconds',
		'incident_id',
		'redacted_details',
		'created_at',
	];

	/** @var list<string> */
	private const INCIDENT_COLUMNS = [
		'id',
		'incident_id',
		'state',
		'category',
		'outcome',
		'severity',
		'ability_name',
		'ability_family',
		'root_error_code',
		'resource_type',
		'resource_key_hash',
		'normalized_path',
		'cause_fingerprint',
		'strategy_fingerprint',
		'expected_verifier',
		'remediation_code',
		'occurrence_count',
		'generation',
		'updated_at',
		'reopened_count',
		'first_seen',
		'last_seen',
		'resolved_at',
		'last_event_id',
		'correlation_id',
		'last_idempotency_key',
		'resolution_event_id',
		'last_change_set_id',
		'repair_phase',
		'learning_status',
		'learning_memory_key',
		'repair_receipt_id',
		'learned_at',
		'evidence_json',
		'resolution_json',
		'schema_version',
	];

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
		AuditLog::reset_schema_health_cache_for_tests();
		IncidentStore::reset_schema_health_cache_for_tests();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
		AuditLog::reset_schema_health_cache_for_tests();
		IncidentStore::reset_schema_health_cache_for_tests();
	}

	public function test_audit_maybe_install_skips_dbdelta_when_version_and_schema_ok(): void {
		update_option( 'stonewright_audit_schema_version', 3 );
		$GLOBALS['wpdb'] = $this->make_skip_wpdb( self::AUDIT_COLUMNS );

		AuditLog::maybe_install_table();

		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( 3, (int) get_option( 'stonewright_audit_schema_version', 0 ) );
	}

	public function test_audit_table_of_version_2_is_upgraded_to_carry_repair_of(): void {
		update_option( 'stonewright_audit_schema_version', 2 );
		$GLOBALS['wpdb'] = $this->make_skip_wpdb( self::AUDIT_COLUMNS );

		AuditLog::maybe_install_table();

		self::assertGreaterThan( 0, $GLOBALS['wpdb']->charset_calls, 'A version 2 table must go through dbDelta to gain repair_of.' );
		self::assertSame( 3, (int) get_option( 'stonewright_audit_schema_version', 0 ) );
	}

	public function test_audit_schema_without_repair_of_is_not_reported_healthy(): void {
		update_option( 'stonewright_audit_schema_version', 3 );
		$GLOBALS['wpdb'] = $this->make_skip_wpdb( array_values( array_diff( self::AUDIT_COLUMNS, [ 'repair_of' ] ) ) );

		self::assertFalse( AuditLog::table_schema_ok() );
		AuditLog::maybe_install_table();

		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls, 'Init trusts a current version; it does not look at the columns.' );

		AuditLog::repair_table();

		self::assertGreaterThan( 0, $GLOBALS['wpdb']->charset_calls, 'A missing repair_of column must trigger the install step on a repair.' );
	}

	public function test_init_with_current_version_issues_no_show_columns(): void {
		update_option( 'stonewright_audit_schema_version', 3 );
		update_option( 'stonewright_incident_schema_version', 2 );
		$GLOBALS['wpdb'] = $this->make_skip_wpdb( self::AUDIT_COLUMNS );

		AuditLog::maybe_install_table();
		IncidentStore::maybe_install_table();

		self::assertSame( [], $GLOBALS['wpdb']->queries );
	}

	public function test_a_schema_error_on_insert_triggers_one_repair_and_one_retry(): void {
		$wpdb                = $this->make_skip_wpdb( self::AUDIT_COLUMNS );
		$wpdb->insert_errors = [ "Unknown column 'event_type' in 'field list'" ];
		$GLOBALS['wpdb']     = $wpdb;

		self::assertTrue( AuditLog::record( 'stonewright/schema-retry', [ 'note' => 'first' ] ) );

		self::assertSame( 2, $wpdb->inserts, 'The failed insert is retried once.' );
		self::assertSame( 1, $wpdb->charset_calls, 'The table is repaired once.' );

		// A later failure in the same request does not repair again.
		$wpdb->insert_errors = [ "Unknown column 'event_type' in 'field list'", "Unknown column 'event_type' in 'field list'" ];
		AuditLog::record( 'stonewright/schema-retry', [ 'note' => 'second' ] );

		self::assertSame( 1, $wpdb->charset_calls, 'One repair per request.' );
	}

	public function test_incident_maybe_install_skips_dbdelta_when_version_and_schema_ok(): void {
		update_option( 'stonewright_incident_schema_version', 2 );
		$GLOBALS['wpdb'] = $this->make_skip_wpdb( self::INCIDENT_COLUMNS );

		IncidentStore::maybe_install_table();

		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( 2, (int) get_option( 'stonewright_incident_schema_version', 0 ) );
	}

	public function test_audit_maybe_install_bumps_version_after_healthy_schema(): void {
		delete_option( 'stonewright_audit_schema_version' );
		$GLOBALS['wpdb'] = $this->make_skip_wpdb( self::AUDIT_COLUMNS );

		AuditLog::maybe_install_table();

		self::assertGreaterThan( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( 3, (int) get_option( 'stonewright_audit_schema_version', 0 ) );
	}

	/**
	 * @param list<string> $columns
	 */
	private function make_skip_wpdb( array $columns ): object {
		return new class( $columns ) {
			public $prefix = 'wp_';
			public $last_error = '';
			public $insert_id = 0;
			public int $charset_calls = 0;
			public int $inserts = 0;
			/** @var list<string> Schema errors the next inserts fail with, one each. */
			public array $insert_errors = [];
			/** @var list<string> */
			public array $queries = [];
			/** @var list<string> */
			private array $columns;

			/** @param list<string> $columns */
			public function __construct( array $columns ) {
				$this->columns = $columns;
			}

			public function get_charset_collate(): string {
				++$this->charset_calls;
				return '';
			}

			/** @return list<string> */
			public function get_col( string $query, int $x = 0 ): array {
				$this->queries[] = $query;
				return $this->columns;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<int, string>   $format
			 */
			public function insert( string $table, array $data, array $format = [] ): int|false {
				++$this->inserts;
				$error = array_shift( $this->insert_errors );
				if ( null !== $error ) {
					$this->last_error = $error;
					return false;
				}
				$this->last_error = '';
				$this->insert_id  = $this->inserts;
				return 1;
			}
		};
	}
}
