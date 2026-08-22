<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\AuditReconciler;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Security\AuditEvent
 * @covers \Stonewright\WpMcp\Security\AuditReconciler
 * @covers \Stonewright\WpMcp\Security\IncidentStore
 */
final class AuditEventIncidentTest extends TestCase {

	private object $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'];
		// Exercise the option fallback used only by test doubles; the production
		// path is the dedicated atomic lifecycle table.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wptests_';
		};
		$GLOBALS['stonewright_test_options'] = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_event_contract_classifies_and_redacts_without_secret_material(): void {
		$event = AuditEvent::normalize(
			'stonewright/elementor-v3-batch-mutate',
			[
				'post_id' => 42,
				'_meta'  => [
					'root_error_code'    => 'stonewright_write_failed',
					'resource_type'      => 'post',
					'normalized_path'    => '/write//persist/../verify',
					'change_set_id'      => 'change-42',
					'context_token_id'   => 'context-secret',
					'public_message'     => 'Bearer live-token client_secret=private-value',
					'element_id'         => 'hero',
					'failed_action_index' => 2,
				],
			],
			'error'
		);

		self::assertSame( AuditEvent::SCHEMA_VERSION, $event['schema_version'] );
		self::assertSame( AuditEvent::CATEGORY_WRITE, $event['category'] );
		self::assertSame( AuditEvent::OUTCOME_FAILED, $event['outcome'] );
		self::assertSame( 'write/persist/verify', $event['normalized_path'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $event['resource_key_hash'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $event['context_token_id_hash'] );
		self::assertStringNotContainsString( 'live-token', $event['public_message'] );
		self::assertStringNotContainsString( 'private-value', $event['public_message'] );
		self::assertSame( 'hero', $event['redacted_details']['element_id'] );
	}

	public function test_wp_error_redacted_details_include_code_message_target_and_not_only_verification(): void {
		$message = str_repeat( 'x', 600 );
		$event   = AuditEvent::normalize(
			'stonewright/design-validate-spec',
			[
				'post_id'  => 42,
				'password' => '<redacted-fixture>',
				'_meta'    => [
					'error_code'          => 'stonewright_spec_invalid',
					'error_message'       => $message,
					'verification_status' => 'failed',
					'remediation_code'    => 'stonewright_spec_invalid',
					'password'            => '<nested-redacted-fixture>',
				],
			],
			'error'
		);

		$details = $event['redacted_details'];
		self::assertIsArray( $details );
		self::assertSame( 'stonewright_spec_invalid', $details['error_code'] ?? null );
		self::assertSame( 500, mb_strlen( (string) ( $details['error_message'] ?? '' ) ) );
		self::assertSame( '42', (string) ( $details['target_id'] ?? '' ) );
		self::assertSame( 'stonewright_spec_invalid', $details['root_error_code'] ?? $details['error_code'] ?? null );
		self::assertSame( 'stonewright_spec_invalid', $details['remediation_code'] ?? null );
		self::assertArrayHasKey( 'incident_id', $details );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $details['incident_id'] );
		self::assertArrayNotHasKey( 'password', $details );
		foreach ( [ 'error_code', 'error_message', 'root_error_code', 'incident_id', 'target_id', 'remediation_code' ] as $key ) {
			self::assertArrayHasKey( $key, $details );
		}
		self::assertNotEquals( [ 'verification_status' ], array_keys( $details ) );
		self::assertStringNotContainsString( '<redacted-fixture>', wp_json_encode( $details ) );
		self::assertStringNotContainsString( '<nested-redacted-fixture>', wp_json_encode( $details ) );
	}

	public function test_permission_and_safety_blocks_never_create_recurring_incidents(): void {
		$permission = AuditEvent::normalize(
			'stonewright/elementor-v3-batch-mutate',
			[ '_meta' => [ 'root_error_code' => 'stonewright_permission_denied' ] ],
			'blocked'
		);
		$safety = AuditEvent::normalize(
			'stonewright/elementor-v3-batch-mutate',
			[ '_meta' => [ 'root_error_code' => 'stonewright_confirmation_required' ] ],
			'blocked'
		);

		self::assertSame( AuditEvent::CATEGORY_PERMISSION, $permission['category'] );
		self::assertSame( AuditEvent::OUTCOME_BLOCKED, $permission['outcome'] );
		self::assertSame( AuditEvent::CATEGORY_SAFETY, $safety['category'] );
		self::assertNull( IncidentStore::observe( $permission ) );
		self::assertNull( IncidentStore::observe( $safety ) );
		self::assertSame( [], IncidentStore::recent() );
	}

	public function test_input_shape_validation_rejections_do_not_open_incidents(): void {
		foreach (
			[
				'stonewright_elementor_evidence_invalid',
				'stonewright_responsive_scope_violation',
				'stonewright_direction_invalid',
			] as $code
		) {
			$event = AuditEvent::normalize(
				'stonewright/elementor-v3-batch-mutate',
				[
					'post_id' => 42,
					'_meta'   => [
						'root_error_code' => $code,
						'error_code'      => $code,
					],
				],
				'error'
			);
			self::assertNull( IncidentStore::observe( $event ), $code );
		}

		self::assertSame( [], IncidentStore::recent() );
	}

	public function test_failed_events_open_at_threshold_and_verified_change_set_closes_exact_incident(): void {
		$failure = $this->event( 'stonewright_write_failed', 'change-42' );
		self::assertSame( 'observing', IncidentStore::observe( $failure )['state'] );
		self::assertSame( 'open', IncidentStore::observe( $failure )['state'] );

		$wrong_change = $this->event( 'stonewright_verified', 'other-change' );
		$wrong_change['outcome'] = AuditEvent::OUTCOME_SUCCESS;
		self::assertFalse( IncidentStore::resolve( $wrong_change ) );

		$verified = AuditEvent::normalize(
			'stonewright/elementor-post-write-verify',
			[ 'post_id' => 42, '_meta' => [ 'resource_type' => 'post', 'change_set_id' => 'change-42', 'verification_status' => 'passed' ] ],
			'ok'
		);
		self::assertTrue( IncidentStore::resolve( $verified ) );
		self::assertFalse( IncidentStore::resolve( $verified ) );
		self::assertSame( 'resolved', IncidentStore::recent( 1 )[0]['state'] );

		$reopened = IncidentStore::observe( $failure );
		self::assertSame( 'open', $reopened['state'] );
		self::assertSame( 1, $reopened['reopened_count'] );
	}

	public function test_retryable_events_use_a_separate_three_occurrence_threshold(): void {
		$event = $this->event( 'stonewright_busy', 'change-busy', [ 'retryable' => true ] );
		self::assertSame( AuditEvent::OUTCOME_RETRYABLE, $event['outcome'] );
		self::assertSame( 'observing', IncidentStore::observe( $event )['state'] );
		self::assertSame( 'observing', IncidentStore::observe( $event )['state'] );
		self::assertSame( 'open', IncidentStore::observe( $event )['state'] );
	}

	public function test_oauth_server_failures_are_retryable_incidents_not_protocol_blocks(): void {
		$event = AuditEvent::normalize(
			'oauth/token',
			[
				'_meta' => [
					'root_error_code' => 'server_error',
					'resource_type'   => 'oauth_endpoint',
					'resource_ref'    => 'oauth/token',
					'http_status'     => 500,
				],
			],
			'error'
		);

		self::assertSame( AuditEvent::CATEGORY_AUTH, $event['category'] );
		self::assertSame( AuditEvent::OUTCOME_RETRYABLE, $event['outcome'] );
		self::assertSame( 'error', $event['severity_level'] );
		self::assertSame( 'observing', IncidentStore::observe( $event )['state'] );
		self::assertSame( 'observing', IncidentStore::observe( $event )['state'] );
		self::assertSame( 'open', IncidentStore::observe( $event )['state'] );
	}

	public function test_incident_resolution_requires_verified_exact_change_set_and_resource(): void {
		$failure = $this->event( 'stonewright_write_failed', 'change-strict', [ 'normalized_path' => 'settings/title' ] );
		IncidentStore::observe( $failure );
		IncidentStore::observe( $failure );

		$unverified = AuditEvent::normalize(
			'stonewright/elementor-post-write-verify',
			[ 'post_id' => 42, '_meta' => [ 'resource_type' => 'post', 'change_set_id' => 'change-strict', 'normalized_path' => 'settings/title' ] ],
			'ok'
		);
		self::assertFalse( IncidentStore::resolve( $unverified ) );

		$wrong_resource = AuditEvent::normalize(
			'stonewright/elementor-post-write-verify',
			[ 'post_id' => 43, '_meta' => [ 'resource_type' => 'post', 'change_set_id' => 'change-strict', 'verification_status' => 'passed', 'normalized_path' => 'settings/title' ] ],
			'ok'
		);
		self::assertFalse( IncidentStore::resolve( $wrong_resource ) );

		$wrong_path = AuditEvent::normalize(
			'stonewright/elementor-post-write-verify',
			[ 'post_id' => 42, '_meta' => [ 'resource_type' => 'post', 'change_set_id' => 'change-strict', 'verification_status' => 'passed', 'normalized_path' => 'settings/subtitle' ] ],
			'ok'
		);
		self::assertFalse( IncidentStore::resolve( $wrong_path ) );

		$verified = AuditEvent::normalize(
			'stonewright/elementor-post-write-verify',
			[ 'post_id' => 42, '_meta' => [ 'resource_type' => 'post', 'change_set_id' => 'change-strict', 'verification_status' => 'passed', 'normalized_path' => 'settings/title' ] ],
			'ok'
		);
		self::assertTrue( IncidentStore::resolve( $verified ) );
	}

	public function test_legacy_rows_reconcile_to_the_same_taxonomy_and_migration_is_idempotent(): void {
		$auth = AuditReconciler::classify( [ 'id' => 7, 'result_status' => 'auth', 'event_type' => 'auth', 'http_status' => 401, 'ability_name' => 'oauth/token' ] );
		$busy = AuditReconciler::classify( [ 'id' => 8, 'result_status' => 'error', 'error_code' => 'busy', 'operation_class' => 'write' ] );
		$verify = AuditReconciler::classify( [ 'id' => 9, 'result_status' => 'error', 'verification_status' => 'failed' ] );
		$auth_server = AuditReconciler::classify( [ 'id' => 10, 'result_status' => 'error', 'ability_name' => 'oauth/token', 'operation_class' => 'oauth', 'http_status' => 500, 'error_code' => 'server_error' ] );

		self::assertSame( AuditEvent::CATEGORY_AUTH, $auth['category'] );
		self::assertSame( AuditEvent::OUTCOME_BLOCKED, $auth['outcome'] );
		self::assertSame( AuditEvent::CATEGORY_TRANSIENT, $busy['category'] );
		self::assertSame( AuditEvent::OUTCOME_RETRYABLE, $busy['outcome'] );
		self::assertSame( AuditEvent::CATEGORY_VERIFY, $verify['category'] );
		self::assertSame( AuditEvent::OUTCOME_FAILED, $verify['outcome'] );
		self::assertSame( AuditEvent::CATEGORY_AUTH, $auth_server['category'] );
		self::assertSame( AuditEvent::OUTCOME_RETRYABLE, $auth_server['outcome'] );
		self::assertSame( 0, AuditReconciler::maybe_migrate() );
		self::assertSame( 0, AuditReconciler::maybe_migrate() );
		self::assertSame( '0', get_option( AuditReconciler::MIGRATION_OPTION, '0' ) );
	}

	public function test_reconciliation_is_explicit_batched_and_marks_done_only_after_the_last_batch(): void {
		$db = self::migration_wpdb(
			[
				[ 'id' => 1, 'schema_version' => '', 'result_status' => 'error', 'error_code' => 'write_failed' ],
				[ 'id' => 2, 'schema_version' => null, 'result_status' => 'error', 'error_code' => 'write_failed' ],
				[ 'id' => 3, 'schema_version' => '', 'result_status' => 'auth', 'event_type' => 'auth', 'http_status' => 401 ],
			]
		);
		$GLOBALS['wpdb'] = $db;

		$preview = AuditReconciler::preview( 2 );
		self::assertIsArray( $preview );
		self::assertSame( AuditEvent::SCHEMA_VERSION, $preview['schema_version'] );
		self::assertSame( 3, $preview['pending'] );
		self::assertSame( 2, $preview['rows_scanned'] );
		self::assertSame( 2, $preview['batch_size'] );
		self::assertFalse( $preview['complete'] );
		self::assertSame( [ 'error' => 2 ], $preview['legacy_distribution'] );
		self::assertSame( [ AuditEvent::CATEGORY_WRITE => 2 ], $preview['new_distribution']['categories'] );
		self::assertSame( [ AuditEvent::OUTCOME_FAILED => 2 ], $preview['new_distribution']['outcomes'] );
		self::assertSame( 2, $preview['incident_projection']['create_candidates'] );
		self::assertSame( 0, $preview['ambiguous_rows'] );
		self::assertFalse( $preview['contains_raw_rows'] );
		self::assertSame( 0, $db->updates );
		self::assertSame( 0, AuditReconciler::maybe_migrate() );
		self::assertSame( 0, $db->updates );

		self::assertSame( 2, AuditReconciler::migrate( 2 ) );
		self::assertSame( '0', get_option( AuditReconciler::MIGRATION_OPTION, '0' ) );
		self::assertSame( 1, AuditReconciler::migrate( 2 ) );
		self::assertSame( '1', get_option( AuditReconciler::MIGRATION_OPTION, '0' ) );
	}

	public function test_reconciliation_failure_never_marks_the_migration_complete(): void {
		$db = self::migration_wpdb(
			[
				[ 'id' => 10, 'schema_version' => '', 'result_status' => 'error', 'error_code' => 'write_failed' ],
				[ 'id' => 11, 'schema_version' => '', 'result_status' => 'error', 'error_code' => 'write_failed' ],
			],
			11
		);
		$GLOBALS['wpdb'] = $db;

		$result = AuditReconciler::migrate( 10 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_audit_reconcile_write_failed', $result->get_error_code() );
		self::assertSame( '0', get_option( AuditReconciler::MIGRATION_OPTION, '0' ) );
	}

	public function test_permanent_remediation_fixture_executes_all_twenty_two_error_classes(): void {
		$path = STONEWRIGHT_DIR . 'tests/fixtures/incidents/permanent-remediation-22.json';
		$raw = file_get_contents( $path );
		$data = json_decode( is_string( $raw ) ? $raw : '', true );

		self::assertIsArray( $data );
		self::assertSame( '2.0', $data['schema_version'] );
		self::assertCount( 22, $data['cases'] );
		self::assertStringNotContainsString( 'Bearer ', (string) $raw );
		self::assertStringNotContainsString( 'client_secret', (string) $raw );
		$case_names = [];
		foreach ( $data['cases'] as $case ) {
			self::assertIsArray( $case );
			$event = AuditEvent::normalize(
				(string) $case['ability'],
				[ '_meta' => (array) $case['meta'] ],
				(string) $case['status']
			);
			self::assertSame( $case['expected']['category'], $event['category'], (string) $case['case'] );
			self::assertSame( $case['expected']['outcome'], $event['outcome'], (string) $case['case'] );
			$candidate = in_array( $event['outcome'], [ AuditEvent::OUTCOME_FAILED, AuditEvent::OUTCOME_RETRYABLE ], true );
			self::assertSame( $case['expected']['incident_candidate'], $candidate, (string) $case['case'] );
			self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $event['incident_id'] );
			$case_names[] = (string) $case['case'];
		}
		self::assertCount( 22, array_unique( $case_names ) );
	}

	/** @param array<string,mixed> $extra */
	private function event( string $code, string $change_set, array $extra = [] ): array {
		return AuditEvent::normalize(
			'stonewright/elementor-v3-batch-mutate',
			[
				'post_id' => 42,
				'_meta'  => array_merge(
					[
						'root_error_code' => $code,
						'resource_type'   => 'post',
						'change_set_id'   => $change_set,
					],
					$extra
				),
			],
			'error'
		);
	}

	/** @param list<array<string,mixed>> $rows */
	private static function migration_wpdb( array $rows, int $fail_id = 0 ): object {
		return new class( $rows, $fail_id ) {
			public string $prefix = 'wptests_';
			/** @var list<array<string,mixed>> */
			public array $rows;
			public int $updates = 0;
			public int $fail_id;

			/** @param list<array<string,mixed>> $rows */
			public function __construct( array $rows, int $fail_id ) {
				$this->rows = $rows;
				$this->fail_id = $fail_id;
			}

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 ) ?? $query;
				}
				return $query;
			}

			public function get_var( string $query ): int {
				return count( array_filter( $this->rows, static fn ( array $row ): bool => null === ( $row['schema_version'] ?? null ) || '' === (string) ( $row['schema_version'] ?? '' ) ) );
			}

			/** @return list<array<string,mixed>> */
			public function get_results( string $query, string $output ): array {
				$pending = array_values( array_filter( $this->rows, static fn ( array $row ): bool => null === ( $row['schema_version'] ?? null ) || '' === (string) ( $row['schema_version'] ?? '' ) ) );
				preg_match( '/LIMIT\s+(\d+)/i', $query, $matches );
				return array_slice( $pending, 0, max( 1, (int) ( $matches[1] ?? count( $pending ) ) ) );
			}

			public function update( string $table, array $data, array $where ): int|false {
				++$this->updates;
				$id = (int) ( $where['id'] ?? 0 );
				if ( $id === $this->fail_id ) {
					return false;
				}
				foreach ( $this->rows as $index => $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === $id ) {
						$this->rows[ $index ] = array_merge( $row, $data );
						return 1;
					}
				}
				return 0;
			}
		};
	}
}
