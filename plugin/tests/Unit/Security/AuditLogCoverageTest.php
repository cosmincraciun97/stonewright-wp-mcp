<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditLog;

/**
 * @covers \Stonewright\WpMcp\Security\AuditLog
 */
final class AuditLogCoverageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		AuditLog::reset_request_state();
		$GLOBALS['stonewright_test_current_user_id'] = 3;
		$GLOBALS['wpdb'] = $this->make_wpdb( true );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		AuditLog::reset_request_state();
	}

	public function test_record_checks_insert_result(): void {
		self::assertTrue( AuditLog::record( 'stonewright/test', [ 'a' => 1 ], 'ok' ) );
		self::assertTrue( AuditLog::was_audited() );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
	}

	public function test_record_surfaces_insert_failure(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( false );
		AuditLog::reset_request_state();
		self::assertFalse( AuditLog::record( 'stonewright/test', [ 'a' => 1 ], 'ok' ) );
	}

	public function test_rest_mutation_dedupes_when_ability_already_audited(): void {
		AuditLog::begin_request();
		AuditLog::record( 'stonewright/learning-record', [ 'topic' => 'x' ], 'ok' );
		self::assertTrue( AuditLog::record_rest_mutation( '/stonewright/v1/abilities/run', 'POST', [ 'name' => 'x' ], 'ok' ) );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
	}

	public function test_terminal_event_is_persisted_once_for_the_same_idempotency_key(): void {
		AuditLog::begin_request( '22222222-2222-4222-8222-222222222222' );
		$args = [
			'_meta' => [
				'idempotency_key' => 'finalizer:change-42:serialized',
				'lifecycle_phase' => 'terminal',
				'terminal_owner'  => 'block-finalizer-result',
			],
		];

		self::assertTrue( AuditLog::record( 'stonewright/blocks-finalizer-result', $args, 'ok' ) );
		self::assertTrue( AuditLog::record( 'stonewright/blocks-finalizer-result', $args, 'ok' ) );
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		$row = $GLOBALS['wpdb']->inserts[0]['data'];
		self::assertSame( '22222222-2222-4222-8222-222222222222', $row['correlation_id'] );
		self::assertSame( hash( 'sha256', 'finalizer:change-42:serialized' ), $row['idempotency_key'] );
		self::assertSame( $row['idempotency_key'], $row['terminal_idempotency_key'] );
		self::assertSame( 1, $row['is_terminal'] );
		self::assertSame( 'block-finalizer-result', $row['terminal_owner'] );
	}

	public function test_progress_events_may_share_a_correlation_key_without_terminal_deduplication(): void {
		$args = [ '_meta' => [ 'idempotency_key' => 'workflow:42', 'lifecycle_phase' => 'progress' ] ];
		self::assertTrue( AuditLog::record( 'stonewright/workflow-progress', $args, 'ok' ) );
		self::assertTrue( AuditLog::record( 'stonewright/workflow-progress', $args, 'ok' ) );
		self::assertCount( 2, $GLOBALS['wpdb']->inserts );
		self::assertNull( $GLOBALS['wpdb']->inserts[0]['data']['terminal_idempotency_key'] );
		self::assertNull( $GLOBALS['wpdb']->inserts[1]['data']['terminal_idempotency_key'] );
	}

	public function test_rest_mutation_records_when_not_audited(): void {
		AuditLog::begin_request();
		self::assertTrue(
			AuditLog::record_rest_mutation(
				'/stonewright/v1/settings',
				'POST',
				[ 'mode' => 'development' ],
				'ok'
			)
		);
		self::assertCount( 1, $GLOBALS['wpdb']->inserts );
		self::assertStringContainsString( 'rest:POST', (string) $GLOBALS['wpdb']->inserts[0]['data']['ability_name'] );
	}

	public function test_redacts_passwords_and_tokens(): void {
		AuditLog::begin_request();
		AuditLog::record(
			'stonewright/test',
			[
				'password' => 'secret',
				'nested'   => [ 'application_password' => 'ap' ],
				'ok_field' => 'visible',
			],
			'ok'
		);
		$encoded = (string) $GLOBALS['wpdb']->inserts[0]['data']['sanitized_args'];
		self::assertStringNotContainsString( 'secret', $encoded );
		self::assertStringContainsString( 'visible', $encoded );
		self::assertStringContainsString( '[redacted]', $encoded );
	}

	public function test_count_and_blocked_status(): void {
		$GLOBALS['wpdb']->row_count = 51;
		self::assertSame( 51, AuditLog::count( [ 'status' => 'blocked' ] ) );
		AuditLog::begin_request();
		AuditLog::record( 'stonewright/test', [], 'blocked' );
		self::assertSame( 'blocked', $GLOBALS['wpdb']->inserts[0]['data']['result_status'] );
	}

	public function test_effect_metadata_is_materialized_for_incident_filters(): void {
		AuditLog::record(
			'stonewright/theme-file-patch',
			[
				'_meta' => [
					'operation_class'     => 'theme_file_write',
					'resource_type'       => 'theme_file',
					'resource_ref'        => 'functions.php',
					'execution_status'    => 'ok',
					'verification_status' => 'failed',
					'rollback_status'     => 'succeeded',
					'before_sha256'       => str_repeat( 'a', 64 ),
					'after_sha256'        => str_repeat( 'b', 64 ),
					'changed_bytes'       => 140000,
				],
			],
			'error'
		);

		$row = $GLOBALS['wpdb']->inserts[0]['data'];
		self::assertSame( 'incident', $row['event_type'] );
		self::assertSame( 'theme_file_write', $row['operation_class'] );
		self::assertSame( 'functions.php', $row['resource_ref'] );
		self::assertSame( 'failed', $row['verification_status'] );
		self::assertSame( 'succeeded', $row['rollback_status'] );
		self::assertSame( 140000, $row['changed_bytes'] );
	}

	public function test_kernel_materializes_nested_error_receipt_without_losing_the_wp_error(): void {
		$ability = new class() extends \Stonewright\WpMcp\Abilities\AbilityKernel {
			public function name(): string { return 'stonewright/test-receipt-error'; }
			public function label(): string { return 'Test receipt'; }
			public function description(): string { return 'Synthetic receipt audit test.'; }
			public function category(): string { return 'test'; }
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn (): \WP_Error => new \WP_Error(
						'stonewright_readback_mismatch',
						'Readback failed.',
						[
							'status' => 500,
							'write_receipt' => [
								'transaction_id' => 'transaction-a',
								'change_set_id' => 'change-a',
								'before_hash' => str_repeat( 'a', 64 ),
								'after_hash' => str_repeat( 'b', 64 ),
								'readback_hash' => str_repeat( 'c', 64 ),
								'verification_status' => 'failed',
								'rollback_status' => 'succeeded',
								'root_error_code' => 'stonewright_readback_mismatch',
								'root_error_path' => 'verify.readback',
								'retryable' => false,
							],
						]
					)
				);
			}
		};

		$result = $ability->execute( [ 'post_id' => 77 ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		$row = $GLOBALS['wpdb']->inserts[0]['data'];
		self::assertSame( 'transaction-a', $row['transaction_id'] );
		self::assertSame( 'change-a', $row['change_set_id'] );
		self::assertSame( str_repeat( 'a', 64 ), $row['before_sha256'] );
		self::assertSame( str_repeat( 'b', 64 ), $row['after_sha256'] );
		self::assertSame( 'failed', $row['verification_status'] );
		self::assertSame( 'succeeded', $row['rollback_status'] );
		self::assertSame( 'stonewright_readback_mismatch', $row['root_error_code'] );
	}

	public function test_recorded_wp_error_details_are_not_only_verification_status(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		AuditLog::record(
			'stonewright/content-update-page',
			[
				'post_id'  => 19,
				'password' => 'sentinel-private-example-secret',
				'_meta'    => [
					'error_code'          => 'stonewright_confirmation_required',
					'error_message'       => str_repeat( 'e', 540 ),
					'verification_status' => 'failed',
					'target_id'           => 19,
					'remediation_code'    => 'stonewright_confirmation_required',
				],
			],
			'error'
		);

		$row     = $GLOBALS['wpdb']->inserts[0]['data'];
		$details = json_decode( (string) ( $row['redacted_details'] ?? '' ), true );
		self::assertIsArray( $details );
		self::assertSame( 'stonewright_confirmation_required', $details['error_code'] ?? null );
		self::assertSame( 500, mb_strlen( (string) ( $details['error_message'] ?? '' ) ) );
		self::assertSame( '19', (string) ( $details['target_id'] ?? $row['resource_ref'] ?? '' ) );
		self::assertSame( 'stonewright_confirmation_required', $details['remediation_code'] ?? $row['remediation_code'] ?? null );
		self::assertSame( 'development', $row['mode'] ?? null );
		self::assertArrayNotHasKey( 'password', $details );
		self::assertNotEquals( [ 'verification_status' ], array_keys( $details ) );
		self::assertStringNotContainsString( 'sentinel-private-example-secret', (string) wp_json_encode( $details ) );
	}

	public function test_recent_and_count_filter_by_error_code(): void {
		AuditLog::recent( 20, 1, [ 'error_code' => 'stonewright_spec_invalid' ] );
		self::assertStringContainsString( 'error_code = %s', (string) $GLOBALS['wpdb']->last_query );
		self::assertStringContainsString( 'correlation_id, idempotency_key, lifecycle_phase, is_terminal, terminal_owner', (string) $GLOBALS['wpdb']->last_query );
		AuditLog::count( [ 'error_code' => 'stonewright_spec_invalid' ] );
		self::assertStringContainsString( 'error_code = %s', (string) $GLOBALS['wpdb']->last_query );
	}

	public function test_retention_deletes_only_expired_rows_and_persists_a_bounded_receipt(): void {
		$GLOBALS['stonewright_test_options']['stonewright_audit_retention_days'] = 7;
		$GLOBALS['wpdb']->query_result = 4;
		$receipt = AuditLog::enforce_retention( true, 1787520000 );

		self::assertSame( 4, $receipt['deleted_rows'] );
		self::assertSame( 7, $receipt['retention_days'] );
		self::assertSame( '2026-08-16 21:20:00', $receipt['cutoff_utc'] );
		self::assertStringContainsString( 'DELETE FROM wp_stonewright_audit_log WHERE created_at < %s LIMIT 5000', $GLOBALS['wpdb']->last_query );
		self::assertSame( [ '2026-08-16 21:20:00' ], $GLOBALS['wpdb']->last_prepared_args );
		self::assertSame( $receipt, get_option( 'stonewright_audit_retention_receipt' ) );
		self::assertStringNotContainsString( 'password', (string) wp_json_encode( $receipt ) );
	}

	public function test_zero_retention_disables_automatic_deletion(): void {
		$GLOBALS['stonewright_test_options']['stonewright_audit_retention_days'] = 0;
		$receipt = AuditLog::enforce_retention( true, 1787520000 );

		self::assertSame( 0, $receipt['deleted_rows'] );
		self::assertSame( 'disabled', $receipt['status'] );
		self::assertSame( '', $GLOBALS['wpdb']->last_query );
	}

	private function make_wpdb( bool $insert_ok ): object {
		return new class( $insert_ok ) {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public int $row_count = 0;
			public string $last_query = '';
			/** @var list<mixed> */
			public array $last_prepared_args = [];
			public int $query_result = 0;
			private bool $insert_ok;
			/** @var array<int, array{table:string,data:array<string,mixed>}> */
			public array $inserts = [];

			public function __construct( bool $insert_ok ) {
				$this->insert_ok = $insert_ok;
				if ( ! $insert_ok ) {
					$this->last_error = 'insert failed';
				}
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_query = $query;
				$this->last_prepared_args = $args;
				return $query;
			}

			public function query( string $query ): int|false {
				$this->last_query = $query;
				return $this->query_result;
			}

			public function get_var( string $query = '' ): int|string|null {
				$this->last_query = $query;
				return $this->row_count;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$this->last_query = $query;
				return [];
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int|false {
				if ( ! $this->insert_ok ) {
					return false;
				}
				$this->inserts[] = [ 'table' => $table, 'data' => $data ];
				return 1;
			}
		};
	}
}
