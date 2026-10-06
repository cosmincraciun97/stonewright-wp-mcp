<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * Incident identity and closure: one cause is one incident, successful rows
 * never join one, non-write incidents close after a quiet week, and write
 * incidents still need a verified repair. The daily retention run performs the
 * quiet closure whether or not a retention window is configured.
 *
 * @covers \Stonewright\WpMcp\Security\AuditEvent
 * @covers \Stonewright\WpMcp\Security\IncidentStore
 * @covers \Stonewright\WpMcp\Security\AuditLog
 */
final class IncidentIdentityAndClosureTest extends TestCase {

	/** A read incident: it involves no writes, verification, or rollback. */
	private const QUIET_META = [
		'operation_kind' => 'read',
		'error_code'     => 'stonewright_example_report_unavailable',
		'resource_type'  => 'report',
	];

	/** A write incident: it closes only through a verified repair. */
	private const WRITE_META = [
		'error_code'      => 'stonewright_example_write_failed',
		'resource_type'   => 'post',
		'resource_ref'    => '41',
		'normalized_path' => 'content/title',
		'change_set_id'   => 'change-b',
	];

	protected function setUp(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 3;
		$GLOBALS['stonewright_test_transients']      = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_transients']   = [];
		AuditLog::reset_request_state();
		IncidentStore::reset_for_tests();
	}

	public function test_successful_rows_never_get_an_incident_id(): void {
		$this->record(
			'stonewright/example-content-update',
			[ 'resource_type' => 'post', 'resource_ref' => '41', 'change_set_id' => 'change-a', 'verification_status' => 'verified' ],
			'ok'
		);

		$row = $this->last_row();
		self::assertSame( AuditEvent::OUTCOME_SUCCESS, $row['outcome'] );
		self::assertSame( '', $row['incident_id'] );
		self::assertArrayNotHasKey( 'incident_id', json_decode( (string) $row['redacted_details'], true ) );
		self::assertSame( [], IncidentStore::recent() );
	}

	public function test_one_cause_is_one_incident_across_resources_paths_and_categories(): void {
		$this->record( 'stonewright/example-content-update', [ 'error_code' => 'stonewright_example_write_failed', 'resource_type' => 'post', 'resource_ref' => '41', 'normalized_path' => 'content/title' ] );
		$first = $this->last_row()['incident_id'];
		$this->record( 'stonewright/example-content-update', [ 'error_code' => 'stonewright_example_write_failed', 'resource_type' => 'post', 'resource_ref' => '42', 'normalized_path' => 'content/excerpt' ] );
		$second = $this->last_row()['incident_id'];
		$this->record( 'stonewright/example-content-update', [ 'error_code' => 'stonewright_example_write_failed', 'resource_type' => 'post', 'resource_ref' => '43', 'verification_status' => 'failed' ] );
		$third = $this->last_row()['incident_id'];

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first );
		self::assertSame( $first, $second );
		self::assertSame( $first, $third );
		$incidents = IncidentStore::recent();
		self::assertCount( 1, $incidents );
		self::assertSame( 3, $incidents[0]['occurrence_count'] );
		self::assertSame( 'open', $incidents[0]['state'] );

		$this->record( 'stonewright/example-content-update', [ 'error_code' => 'stonewright_example_write_failed', 'resource_type' => 'term', 'resource_ref' => '41' ] );
		self::assertNotSame( $first, $this->last_row()['incident_id'] );
		$this->record( 'stonewright/example-content-update', [ 'error_code' => 'stonewright_example_other_failure', 'resource_type' => 'post', 'resource_ref' => '41' ] );
		self::assertNotSame( $first, $this->last_row()['incident_id'] );
		self::assertCount( 3, IncidentStore::recent() );
	}

	public function test_non_write_incident_closes_after_seven_quiet_days_and_counts_reopenings(): void {
		$meta = [ 'operation_kind' => 'read', 'error_code' => 'stonewright_example_report_unavailable', 'resource_type' => 'report' ];
		$this->record( 'stonewright/example-report-get', $meta );
		$this->record( 'stonewright/example-report-get', $meta );
		$incident_id = $this->last_row()['incident_id'];
		self::assertSame( 'open', IncidentStore::get( $incident_id )['state'] );
		self::assertSame( AuditEvent::CATEGORY_READ, IncidentStore::get( $incident_id )['category'] );

		$this->age( $incident_id, 6 );
		self::assertSame( 0, IncidentStore::close_quiet() );
		self::assertSame( 'open', IncidentStore::get( $incident_id )['state'] );

		$last_seen = $this->age( $incident_id, 8 );
		self::assertSame( 1, IncidentStore::close_quiet() );
		$closed = IncidentStore::get( $incident_id );
		self::assertSame( 'resolved', $closed['state'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $last_seen . ' UTC' ) + 7 * DAY_IN_SECONDS ), $closed['resolved_at'] );

		$this->record( 'stonewright/example-report-get', $meta );
		$reopened = IncidentStore::get( $incident_id );
		self::assertSame( 'open', $reopened['state'] );
		self::assertSame( 1, $reopened['reopened_count'] );

		// A recurrence after a quiet week counts as a reopening even before a sweep ran.
		$this->age( $incident_id, 9 );
		$this->record( 'stonewright/example-report-get', $meta );
		$again = IncidentStore::get( $incident_id );
		self::assertSame( 'open', $again['state'] );
		self::assertSame( 2, $again['reopened_count'] );
	}

	public function test_write_incident_stays_open_until_a_verified_repair(): void {
		$meta = [
			'error_code'      => 'stonewright_example_write_failed',
			'resource_type'   => 'post',
			'resource_ref'    => '41',
			'normalized_path' => 'content/title',
			'change_set_id'   => 'change-b',
		];
		$this->record( 'stonewright/example-content-update', $meta );
		$this->record( 'stonewright/example-content-update', $meta );
		$incident_id = $this->last_row()['incident_id'];
		self::assertSame( AuditEvent::CATEGORY_WRITE, IncidentStore::get( $incident_id )['category'] );

		$this->age( $incident_id, 30 );
		self::assertSame( 0, IncidentStore::close_quiet() );
		$open = IncidentStore::get( $incident_id );
		self::assertSame( 'open', $open['state'] );

		$resolved = IncidentStore::record_verified_repair(
			[
				'incident_id'         => $incident_id,
				'repair_receipt_id'   => hash( 'sha256', 'synthetic-receipt' ),
				'resolution_event_id' => '11111111-1111-4111-8111-111111111111',
				'verification_status' => 'verified',
				'effect_verified'     => true,
				'change_set_id'       => 'change-b',
				'resource_key_hash'   => $open['resource_key_hash'],
				'normalized_path'     => 'content/title',
				'evidence'            => [ 'after_sha256' => hash( 'sha256', 'after' ), 'verifier' => '' ],
				'version_token'       => $open['version_token'],
			]
		);
		self::assertIsArray( $resolved );
		self::assertSame( 'resolved', $resolved['state'] );
	}

	public function test_scheduled_run_closes_quiet_incidents_when_no_retention_is_configured(): void {
		$quiet = $this->open_incident( 'stonewright/example-report-get', self::QUIET_META );
		$write = $this->open_incident( 'stonewright/example-content-update', self::WRITE_META );
		$this->age( $quiet, 8 );
		$this->age( $write, 30 );

		$statements = $this->run_scheduled_retention();

		self::assertSame( 'resolved', IncidentStore::get( $quiet )['state'] );
		self::assertSame( 'open', IncidentStore::get( $write )['state'] );
		self::assertSame( [], $statements, 'Without a retention window no row is deleted.' );
		self::assertFalse( get_option( AuditLog::RETENTION_RECEIPT_OPTION, false ) );
	}

	public function test_scheduled_run_closes_quiet_incidents_and_applies_retention_when_it_is_configured(): void {
		$GLOBALS['stonewright_test_options'][ AuditLog::RETENTION_OPTION ] = 30;
		$quiet = $this->open_incident( 'stonewright/example-report-get', self::QUIET_META );
		$write = $this->open_incident( 'stonewright/example-content-update', self::WRITE_META );
		$this->age( $quiet, 8 );
		$this->age( $write, 30 );

		$statements = $this->run_scheduled_retention();

		self::assertSame( 'resolved', IncidentStore::get( $quiet )['state'] );
		self::assertSame( 'open', IncidentStore::get( $write )['state'] );
		self::assertCount( 2, $statements );
		self::assertStringStartsWith( 'DELETE FROM wptests_stonewright_audit_log WHERE created_at <', $statements[0] );
		self::assertStringStartsWith( 'DELETE FROM wptests_stonewright_incidents', $statements[1] );
		self::assertSame( 'completed', get_option( AuditLog::RETENTION_RECEIPT_OPTION )['status'] );
	}

	public function test_quiet_incidents_close_even_when_the_audit_row_deletion_fails(): void {
		$GLOBALS['stonewright_test_options'][ AuditLog::RETENTION_OPTION ] = 30;
		$quiet = $this->open_incident( 'stonewright/example-report-get', self::QUIET_META );
		$this->age( $quiet, 8 );

		$this->run_scheduled_retention( false );

		self::assertSame( 'failed', get_option( AuditLog::RETENTION_RECEIPT_OPTION )['status'] );
		self::assertSame( 'resolved', IncidentStore::get( $quiet )['state'] );
	}

	public function test_a_throttled_run_leaves_quiet_incidents_for_the_scheduled_run(): void {
		$quiet = $this->open_incident( 'stonewright/example-report-get', self::QUIET_META );
		$this->age( $quiet, 8 );
		set_transient( 'stonewright_audit_retention_ran', 1, DAY_IN_SECONDS );

		AuditLog::enforce_retention();
		self::assertSame( 'open', IncidentStore::get( $quiet )['state'] );

		AuditLog::enforce_retention( true );
		self::assertSame( 'resolved', IncidentStore::get( $quiet )['state'] );
	}

	public function test_an_unforced_run_without_retention_sweeps_once_a_day(): void {
		$first = $this->open_incident( 'stonewright/example-report-get', self::QUIET_META );
		$this->age( $first, 8 );
		AuditLog::enforce_retention();
		self::assertSame( 'resolved', IncidentStore::get( $first )['state'] );

		$second = $this->open_incident( 'stonewright/example-report-list', [ 'error_code' => 'stonewright_example_list_unavailable' ] + self::QUIET_META );
		$this->age( $second, 8 );
		AuditLog::enforce_retention();
		self::assertSame( 'open', IncidentStore::get( $second )['state'] );
	}

	/** @param array<string, mixed> $meta */
	private function open_incident( string $ability, array $meta ): string {
		$this->record( $ability, $meta );
		$this->record( $ability, $meta );
		return (string) $this->last_row()['incident_id'];
	}

	/**
	 * Run the scheduled retention job against a wpdb that serves the shared test
	 * incident rows and records every statement sent through query().
	 *
	 * @return list<string>
	 */
	private function run_scheduled_retention( int|false $query_result = 0 ): array {
		$inner    = $GLOBALS['wpdb'];
		$recorder = new class( $inner, $query_result ) extends \wpdb {
			/** @var list<string> */
			public array $statements = [];

			public function __construct( private object $inner, private int|false $query_result ) {
				$this->prefix = (string) $inner->prefix;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $this->inner->prepare( $query, ...$args );
			}

			public function query( string $query ): int|false {
				$this->statements[] = $query;
				return $this->query_result;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->inner->get_results( $query, $output );
			}

			public function get_row( string $query, string $output = 'OBJECT' ): array|object|null {
				return $this->inner->get_row( $query, $output );
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
				return $this->inner->update( $table, $data, $where, $format, $where_format );
			}
		};

		$GLOBALS['wpdb'] = $recorder;
		try {
			AuditLog::run_scheduled_retention();
		} finally {
			$GLOBALS['wpdb'] = $inner;
		}

		return $recorder->statements;
	}

	/** @param array<string, mixed> $meta */
	private function record( string $ability, array $meta, string $status = 'error' ): void {
		AuditLog::reset_request_state();
		AuditLog::record( $ability, [ '_meta' => $meta ], $status );
	}

	/** @return array<string, mixed> */
	private function last_row(): array {
		foreach ( array_reverse( $GLOBALS['stonewright_test_wpdb_inserts'] ) as $insert ) {
			if ( str_contains( (string) $insert['table'], 'stonewright_audit_log' ) ) {
				return $insert['data'];
			}
		}
		self::fail( 'No audit row recorded.' );
	}

	/** Move an incident's last activity into the past and return the new last_seen. */
	private function age( string $incident_id, int $days ): string {
		$when = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$GLOBALS['wpdb']->incident_rows[ $incident_id ]['last_seen']  = $when;
		$GLOBALS['wpdb']->incident_rows[ $incident_id ]['updated_at'] = $when;
		return $when;
	}
}
