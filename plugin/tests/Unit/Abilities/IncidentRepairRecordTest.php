<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\IncidentRepairRecord;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\IncidentStore;

/** @covers \Stonewright\WpMcp\Abilities\Security\IncidentRepairRecord */
final class IncidentRepairRecordTest extends TestCase {

	private object $original_wpdb;
	private object $db;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'];
		$this->db = $this->database();
		$GLOBALS['wpdb'] = $this->db;
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_memory_enabled' => true,
			'stonewright_mode'           => 'development',
		];
		$GLOBALS['stonewright_test_user_can_callback'] = static fn(): bool => true;
		Memory::reset_schema_health_cache_for_tests();
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		Memory::reset_schema_health_cache_for_tests();
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_user_can_callback'] );
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_schema_and_permission_contract_are_write_gated(): void {
		$ability = new IncidentRepairRecord();
		$schema  = $ability->input_schema();

		self::assertSame( 'stonewright/incident-repair-record', $ability->name() );
		self::assertSame( [ 'incident_id', 'resolution_event_id', 'repair_recipe' ], $schema['required'] );
		self::assertArrayHasKey( 'confirmation_token', $schema['properties'] );
		self::assertTrue( $ability->permission_callback( [] ) );
	}

	public function test_rejects_resolution_event_missing_from_persisted_audit(): void {
		$this->seed_open_incident();
		$ability = new IncidentRepairRecord();

		$result = $ability->execute( [
			'incident_id'         => $this->incident_id(),
			'resolution_event_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
			'repair_recipe'       => 'Read schema, replace rejected field, then verify readback.',
		] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_repair_event_not_found', $result->get_error_code() );
		self::assertSame( 'open', IncidentStore::get( $this->incident_id() )['state'] );
	}

	public function test_persisted_correlated_events_promote_one_verified_lesson(): void {
		$this->seed_open_incident();
		$this->db->audit_events = [
			'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' => $this->failure_row(),
			'dddddddd-dddd-4ddd-8ddd-dddddddddddd' => $this->success_row(),
		];
		$ability = new IncidentRepairRecord();
		$args = [
			'incident_id'         => $this->incident_id(),
			'resolution_event_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
			'repair_recipe'       => 'Read schema, replace rejected field, then verify readback.',
		];

		$result = $ability->execute( $args );

		self::assertIsArray( $result );
		self::assertTrue( $result['verified'] );
		self::assertSame( 'resolved', $result['incident_state'] );
		self::assertSame( 'promoted', $result['learning_status'] );
		self::assertMatchesRegularExpression( '/^verified-repair-[a-f0-9]{16}$/', $result['memory_key'] );
		self::assertCount( 1, $this->db->memory_rows );

		$again = $ability->execute( $args );
		self::assertIsArray( $again );
		self::assertSame( $result['memory_key'], $again['memory_key'] );
		self::assertCount( 1, $this->db->memory_rows );
	}

	private function seed_open_incident(): void {
		$failure = [
			'incident_id'          => $this->incident_id(),
			'event_id'             => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			'outcome'              => AuditEvent::OUTCOME_FAILED,
			'category'             => AuditEvent::CATEGORY_VALIDATION,
			'severity_level'       => 'high',
			'ability'              => 'stonewright/example-write',
			'ability_family'       => 'example',
			'root_error_code'      => 'stonewright_example_invalid',
			'resource_type'        => 'post',
			'resource_key_hash'    => hash( 'sha256', 'resource' ),
			'normalized_path'      => 'example/settings/title',
			'cause_fingerprint'    => hash( 'sha256', 'cause' ),
			'strategy_fingerprint' => hash( 'sha256', 'strategy' ),
			'expected_verifier'    => 'stonewright/example-verify',
			'change_set_id'        => 'change-set-a',
		];
		IncidentStore::observe( $failure );
		$failure['event_id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
		IncidentStore::observe( $failure );
	}

	/** @return array<string, mixed> */
	private function failure_row(): array {
		return [
			'event_id'            => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
			'ability_name'        => 'stonewright/example-write',
			'outcome'             => AuditEvent::OUTCOME_FAILED,
			'category'            => AuditEvent::CATEGORY_VALIDATION,
			'change_set_id'       => 'change-set-a',
			'resource_key_hash'   => hash( 'sha256', 'resource' ),
			'normalized_path'     => 'example/settings/title',
			'created_at'          => gmdate( 'Y-m-d H:i:s', time() - 60 ),
		];
	}

	/** @return array<string, mixed> */
	private function success_row(): array {
		return [
			'event_id'            => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
			'ability_name'        => 'stonewright/example-verify',
			'outcome'             => AuditEvent::OUTCOME_SUCCESS,
			'category'            => AuditEvent::CATEGORY_VERIFY,
			'verification_status' => 'verified',
			'effect_verified'     => 1,
			'change_set_id'       => 'change-set-a',
			'resource_key_hash'   => hash( 'sha256', 'resource' ),
			'normalized_path'     => 'example/settings/title',
			'after_sha256'        => hash( 'sha256', 'after' ),
			'created_at'          => gmdate( 'Y-m-d H:i:s', time() + 60 ),
		];
	}

	private function incident_id(): string {
		return hash( 'sha256', 'incident' );
	}

	private function database(): object {
		return new class() {
			public string $prefix = 'wptests_';
			public string $last_error = '';
			public int $insert_id = 0;
			/** @var array<string, array<string, mixed>> */
			public array $audit_events = [];
			/** @var array<int, array<string, mixed>> */
			public array $memory_rows = [];
			/** @var array<int, mixed> */
			private array $args = [];

			public function get_charset_collate(): string { return ''; }
			/** @return list<string> */
			public function get_col( string $query, int $column = 0 ): array {
				return [ 'id', 'scope', 'type', 'name', 'memory_key', 'value_json', 'confidence', 'topic', 'version_fingerprint', 'expires_at', 'status', 'precedence', 'created_by', 'created_at', 'updated_at', 'last_retrieved_at' ];
			}
			public function prepare( string $query, mixed ...$args ): string { $this->args = $args; return $query; }
			public function get_var( string $query ): mixed {
				if ( str_contains( $query, 'SELECT id FROM' ) ) {
					foreach ( $this->memory_rows as $row ) {
						if ( (string) $row['scope'] === (string) ( $this->args[0] ?? '' ) && (string) $row['memory_key'] === (string) ( $this->args[1] ?? '' ) ) return $row['id'];
					}
				}
				return null;
			}
			/** @return list<array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'stonewright_audit_log' ) ) {
					$event = $this->audit_events[ (string) ( $this->args[0] ?? '' ) ] ?? null;
					return is_array( $event ) ? [ $event ] : [];
				}
				return [];
			}
			/** @return array<string, mixed>|null */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				$id = (int) ( $this->args[0] ?? 0 );
				return $this->memory_rows[ $id ] ?? null;
			}
			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $formats = [] ): int {
				if ( str_contains( $table, 'stonewright_memory' ) ) {
					++$this->insert_id;
					$data['id'] = $this->insert_id;
					$data['created_at'] = '2026-08-12 10:02:00';
					$data['updated_at'] = '2026-08-12 10:02:00';
					$data['last_retrieved_at'] = null;
					$this->memory_rows[ $this->insert_id ] = $data;
				}
				return 1;
			}
			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $formats = [], array $where_formats = [] ): int {
				$id = (int) ( $where['id'] ?? 0 );
				if ( isset( $this->memory_rows[ $id ] ) ) $this->memory_rows[ $id ] = array_merge( $this->memory_rows[ $id ], $data );
				return 1;
			}
		};
	}
}
