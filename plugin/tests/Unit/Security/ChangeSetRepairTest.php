<?php
/**
 * The repair chain: a write that passes repair_of is audited with its lineage, and
 * a verified repair resolves the incident the repaired change opened.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\ChangeSet;
use Stonewright\WpMcp\Security\IncidentStore;

require_once __DIR__ . '/ChangeSetAssertions.php';

/**
 * @covers \Stonewright\WpMcp\Abilities\AbilityKernel
 * @covers \Stonewright\WpMcp\Security\AuditEvent
 * @covers \Stonewright\WpMcp\Security\AuditLog
 * @covers \Stonewright\WpMcp\Security\IncidentStore
 */
final class ChangeSetRepairTest extends TestCase {
	use ChangeSetAssertions;

	private const HASH_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const HASH_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	private AbilityKernel $writer;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		IncidentStore::reset_for_tests();

		$this->writer = new class() extends AbilityKernel {
			public function name(): string        { return 'stonewright/test-change-writer'; }
			public function label(): string       { return 'Test change writer'; }
			public function description(): string { return 'Records a write with a change set.'; }
			public function category(): string    { return 'test'; }

			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static function ( array $args ) {
						$receipt = [
							'change_set_id'       => (string) $args['change_set_id'],
							'before_hash'         => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
							'after_hash'          => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
							'verification_status' => (string) ( $args['verification'] ?? 'verified' ),
							'rollback_status'     => (string) ( $args['rollback'] ?? 'not_needed' ),
						];
						if ( ! empty( $args['fail'] ) ) {
							$receipt['verification_status'] = 'failed';
							return new \WP_Error(
								'stonewright_readback_mismatch',
								'Readback did not match the plan.',
								[
									'status'              => 500,
									'verification_status' => 'failed',
									'rollback_status'     => $receipt['rollback_status'],
									'write_receipt'       => $receipt,
								]
							);
						}
						return [
							'ok'                  => true,
							'post_id'             => (int) $args['post_id'],
							'verification_status' => $receipt['verification_status'],
							'write_receipt'       => $receipt,
						];
					}
				);
			}

			protected function change_set_inputs( array $args, array|\WP_Error $result, string $status ): ?array {
				$data    = $result instanceof \WP_Error ? (array) $result->get_error_data() : $result;
				$receipt = (array) ( $data['write_receipt'] ?? [] );
				return [
					'change_set_id' => (string) ( $receipt['change_set_id'] ?? '' ),
					'planned'       => [ ChangeSet::entry( 'element', 'hero', 'update_element', 0 ) ],
					'applied'       => $result instanceof \WP_Error ? [] : [ ChangeSet::entry( 'element', 'hero', 'update_element', 0 ) ],
					'missing'       => $result instanceof \WP_Error ? [ ChangeSet::entry( 'element', 'hero', 'update_element', 0 ) ] : [],
					'before_hash'   => (string) ( $receipt['before_hash'] ?? '' ),
					'after_hash'    => (string) ( $receipt['after_hash'] ?? '' ),
					'verification'  => [ 'status' => (string) ( $receipt['verification_status'] ?? '' ) ],
				] + ChangeSet::lineage_input( $args );
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		IncidentStore::reset_for_tests();
	}

	/** @return array<string, mixed> The last audit row the recorder inserted. */
	private function last_row(): array {
		$rows = $GLOBALS['stonewright_test_wpdb_inserts'];
		self::assertNotEmpty( $rows, 'The write must have been audited.' );
		return end( $rows )['data'];
	}

	/** @return array<string, mixed> */
	private function only_incident(): array {
		$incidents = IncidentStore::recent();
		self::assertCount( 1, $incidents );
		return $incidents[0];
	}

	private function record_failure( string $change_set_id, int $post_id = 42, string $rollback = 'succeeded' ): void {
		$result = $this->writer->execute( [ 'post_id' => $post_id, 'change_set_id' => $change_set_id, 'fail' => true, 'rollback' => $rollback ] );
		self::assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_a_write_returns_its_change_set_in_the_result_and_in_the_error_data(): void {
		$ok = $this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-ok', 'repair_of' => 'cs-earlier' ] );
		self::assertIsArray( $ok );
		self::assertSame( 'cs-ok', $ok['change_set']['change_set_id'] );
		self::assertSame( 'cs-earlier', $ok['change_set']['repair_of'] );
		self::assertSame( 'verified', $ok['change_set']['verification']['status'] );
		self::assertValidChangeSet( $ok['change_set'] );

		$error = $this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-bad', 'fail' => true ] );
		self::assertInstanceOf( \WP_Error::class, $error );
		$data = (array) $error->get_error_data();
		self::assertSame( 'cs-bad', $data['change_set']['change_set_id'] );
		self::assertSame( 'failed', $data['change_set']['verification']['status'] );
		self::assertCount( 1, $data['change_set']['missing'] );
		self::assertValidChangeSet( $data['change_set'] );
	}

	public function test_the_audit_row_carries_the_change_set_lineage(): void {
		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A', 'supersedes' => 'cs-old' ] );
		$row = $this->last_row();

		self::assertSame( 'cs-B', $row['change_set_id'] );
		self::assertSame( 'cs-A', $row['repair_of'] );
		$details = json_decode( (string) $row['redacted_details'], true );
		self::assertSame( 'cs-old', $details['supersedes'] );
		self::assertSame( 'cs-A', $details['repair_of'] );
	}

	public function test_a_failed_repair_attempt_still_records_what_it_repairs(): void {
		$this->record_failure( 'cs-A' );
		$result = $this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A', 'fail' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'cs-A', $this->last_row()['repair_of'] );
		self::assertSame( 'cs-B', $this->last_row()['change_set_id'] );
		$incident = $this->only_incident();
		self::assertSame( 'open', $incident['state'], 'The failed repair is a second occurrence of the same cause.' );
		self::assertSame( 2, $incident['occurrence_count'] );
	}

	public function test_a_verified_repair_resolves_the_incident_of_the_change_it_repairs_with_the_receipt(): void {
		$this->record_failure( 'cs-A' );
		$incident = $this->only_incident();
		self::assertContains( $incident['state'], [ 'observing', 'open' ] );
		self::assertSame( 'cs-A', $incident['last_change_set_id'] );
		self::assertNotSame( '', $incident['incident_id'] );

		$result = $this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A' ] );
		self::assertIsArray( $result );

		$resolved = $this->only_incident();
		self::assertSame( 'resolved', $resolved['state'] );
		self::assertSame( $incident['incident_id'], $resolved['incident_id'] );
		self::assertSame( $this->last_row()['event_id'], $resolved['resolution_event_id'], 'The repair row is the resolution event.' );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $resolved['repair_receipt_id'] );
		self::assertSame( 'verified', $resolved['repair_phase'] );
		self::assertNotSame( '', $resolved['resolved_at'] );

		$stored     = $GLOBALS['wpdb']->incident_rows[ $incident['incident_id'] ];
		$resolution = json_decode( (string) $stored['resolution_json'], true );
		self::assertSame( 'cs-B', $resolution['change_set_id'] );
		self::assertSame( 'cs-A', $resolution['repair_of'] );
		self::assertSame( self::HASH_B, $resolution['after_sha256'] );
		self::assertSame( 'verified', $resolution['verification_status'] );
	}

	public function test_a_successful_row_gets_no_incident_id_even_when_it_closes_an_incident(): void {
		$this->record_failure( 'cs-A' );
		$failure = $this->last_row();
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $failure['incident_id'], 'A failed row belongs to an incident.' );

		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A' ] );
		$success = $this->last_row();

		self::assertSame( 'SUCCESS', $success['outcome'] );
		self::assertSame( '', $success['incident_id'] );
		self::assertSame( '', $success['root_error_code'] );
		self::assertSame( '', $success['remediation_code'] );
		$details = json_decode( (string) $success['redacted_details'], true );
		foreach ( [ 'incident_id', 'error_code', 'error_message', 'root_error_code', 'remediation_code' ] as $key ) {
			self::assertArrayNotHasKey( $key, $details );
		}
	}

	public function test_a_plain_success_never_gets_an_incident_id(): void {
		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-plain' ] );
		$row = $this->last_row();

		self::assertSame( 'SUCCESS', $row['outcome'] );
		self::assertSame( '', $row['incident_id'] );
		self::assertSame( '', $row['repair_of'] );
		self::assertSame( [], IncidentStore::recent() );

		$event = AuditEvent::normalize( 'stonewright/test-change-writer', [ 'post_id' => 42, '_meta' => [ 'change_set_id' => 'cs-plain', 'repair_of' => 'cs-A', 'verification_status' => 'verified' ] ], 'ok' );
		self::assertSame( '', $event['incident_id'] );
		self::assertSame( 'cs-A', $event['repair_of'] );
	}

	public function test_a_repair_that_does_not_verify_leaves_the_incident_open(): void {
		$this->record_failure( 'cs-A' );
		$before = $this->only_incident();

		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A', 'verification' => 'unchanged' ] );
		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-C', 'repair_of' => 'cs-A', 'verification' => 'pending' ] );
		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-D', 'repair_of' => 'cs-A', 'dry_run' => true, 'verification' => 'planned' ] );

		$after = $this->only_incident();
		self::assertSame( $before['state'], $after['state'] );
		self::assertSame( '', $after['resolution_event_id'] );
	}

	public function test_a_verified_write_without_repair_of_does_not_resolve_anything(): void {
		$this->record_failure( 'cs-A' );
		$before = $this->only_incident();

		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B' ] );
		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-A' ] );

		self::assertSame( $before['state'], $this->only_incident()['state'] );
	}

	public function test_a_repair_of_something_else_or_on_another_resource_leaves_the_incident_open(): void {
		$this->record_failure( 'cs-A', 42 );
		$before = $this->only_incident();

		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-unrelated' ] );
		$this->writer->execute( [ 'post_id' => 43, 'change_set_id' => 'cs-C', 'repair_of' => 'cs-A' ] );

		self::assertSame( $before['state'], $this->only_incident()['state'] );
	}

	public function test_an_incident_whose_rollback_failed_is_not_closed_by_a_repair(): void {
		$this->record_failure( 'cs-A', 42, 'failed' );
		$incident = $this->only_incident();
		self::assertNotSame( 'resolved', $incident['state'] );

		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A' ] );

		self::assertNotSame( 'resolved', $this->only_incident()['state'], 'A failed rollback needs an operator, not a lineage link.' );
	}

	public function test_repeating_the_verified_repair_keeps_the_resolution(): void {
		$this->record_failure( 'cs-A' );
		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A' ] );
		$first = $this->only_incident();

		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A' ] );
		$second = $this->only_incident();

		self::assertSame( 'resolved', $second['state'] );
		self::assertSame( $first['repair_receipt_id'], $second['repair_receipt_id'] );
	}

	public function test_an_identical_retry_is_a_repair_of_its_own_change_set(): void {
		$this->record_failure( 'cs-A' );

		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-A', 'repair_of' => 'cs-A' ] );

		self::assertSame( 'resolved', $this->only_incident()['state'] );
		self::assertSame( 'cs-A', $this->last_row()['repair_of'] );
	}

	public function test_a_later_failure_with_the_same_cause_reopens_the_resolved_incident(): void {
		$this->record_failure( 'cs-A' );
		$this->writer->execute( [ 'post_id' => 42, 'change_set_id' => 'cs-B', 'repair_of' => 'cs-A' ] );
		self::assertSame( 'resolved', $this->only_incident()['state'] );

		$this->record_failure( 'cs-C' );

		$reopened = $this->only_incident();
		self::assertSame( 'open', $reopened['state'] );
		self::assertSame( 1, $reopened['reopened_count'] );
	}
}
