<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * Incidents ≠ lessons: unresolved failures never dual-write correction+lesson.
 *
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 */
final class IncidentLessonSeparationTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['wpdb'] = $this->make_memory_wpdb();
		delete_option( ErrorPatterns::OPTION_KEY );
		delete_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
		delete_option( ErrorPatterns::OPTION_KEY );
		delete_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION );
	}

	public function test_recurring_failure_proposes_remediation_without_learning_row(): void {
		Memory::maybe_install_table();
		$args = [
			'error_code' => 'stonewright_demo_failure',
			'message'    => 'Demo failed',
		];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		$recurring = ErrorPatterns::recurring();
		self::assertNotEmpty( $recurring );
		self::assertSame( 'repair_proposed', $recurring[0]['state'] );
		self::assertNotEmpty( $recurring[0]['repair'] );

		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		$hit  = array_filter(
			$rows,
			static fn( array $r ): bool => str_starts_with( (string) ( $r['memory_key'] ?? '' ), 'learning-audit-error-' )
		);
		self::assertSame( [], array_values( $hit ), 'Unresolved incidents must not auto-create learning-audit-error-* rows' );
	}

	public function test_verified_recipe_promotes_learning_once(): void {
		Memory::maybe_install_table();
		$args = [ 'error_code' => 'stonewright_demo_failure', 'message' => 'Demo failed' ];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		ErrorPatterns::observe_verified_repair(
			'stonewright/demo-ability',
			[
				'effect_verified'     => true,
				'verification_status' => 'verified',
				'repair_recipe'       => 'Read the schema first, then update only the supported field.',
			]
		);

		$rows   = Memory::list_by_type( 'feedback', 50, 0 );
		$active = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => 'active' === (string) ( $row['status'] ?? '' )
			)
		);
		self::assertCount( 1, $active );
		self::assertSame( 'promoted_learning', $active[0]['value']['state'] ?? null );
		self::assertStringContainsString( 'Read the schema first', (string) ( $active[0]['value']['correction'] ?? '' ) );
		// Dual fields allowed only after verification for the concrete recipe.
		self::assertSame( $active[0]['value']['correction'], $active[0]['value']['lesson'] );
	}

	public function test_verified_success_without_recipe_does_not_invent_lesson(): void {
		Memory::maybe_install_table();
		$args = [ 'error_code' => 'stonewright_demo_failure', 'message' => 'Demo failed' ];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		ErrorPatterns::observe_verified_repair(
			'stonewright/demo-ability',
			[
				'effect_verified'     => true,
				'verification_status' => 'verified',
			]
		);

		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		self::assertSame( [], $rows );
	}

	public function test_legacy_generic_audit_lessons_are_superseded_not_deleted(): void {
		Memory::maybe_install_table();
		Memory::put_typed(
			'feedback',
			'audit',
			'learning-audit-error-deadbeef',
			'Recurring error: stonewright/demo',
			[
				'correction' => 'Unresolved incident for stonewright/demo: unknown error Exact remediation: fix it',
				'lesson'     => 'Unresolved incident for stonewright/demo: unknown error Exact remediation: fix it',
				'source'     => 'audit-error',
				'state'      => 'unresolved_incident',
				'cause_key'  => 'stonewright/demo|error|',
			],
			1.0,
			[ 'topic' => 'Recurring error', 'status' => 'stale', 'precedence' => 400 ]
		);
		// User-created learning must stay.
		Memory::put_typed(
			'feedback',
			'user',
			'user-lesson-keep',
			'User lesson',
			[
				'correction' => 'Always validate first',
				'lesson'     => 'Always validate first',
				'source'     => 'user',
				'state'      => 'active',
			],
			1.0,
			[ 'topic' => 'User lesson', 'status' => 'active', 'precedence' => 800 ]
		);

		$result = ErrorPatterns::migrate_legacy_audit_lessons();
		self::assertFalse( $result['already_done'] );
		self::assertSame( 1, $result['migrated'] );

		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		$by_key = [];
		foreach ( $rows as $row ) {
			$by_key[ (string) $row['memory_key'] ] = $row;
		}
		self::assertArrayHasKey( 'learning-audit-error-deadbeef', $by_key );
		self::assertSame( 'superseded', $by_key['learning-audit-error-deadbeef']['value']['state'] ?? null );
		self::assertSame( '', $by_key['learning-audit-error-deadbeef']['value']['correction'] ?? 'x' );
		self::assertSame( 'stale', $by_key['learning-audit-error-deadbeef']['status'] ?? null );
		self::assertNotEmpty( $by_key['learning-audit-error-deadbeef']['value']['legacy_correction'] ?? '' );

		self::assertArrayHasKey( 'user-lesson-keep', $by_key );
		self::assertSame( 'active', $by_key['user-lesson-keep']['status'] ?? null );
		self::assertSame( 'Always validate first', $by_key['user-lesson-keep']['value']['correction'] ?? null );

		// Idempotent.
		$again = ErrorPatterns::migrate_legacy_audit_lessons();
		self::assertTrue( $again['already_done'] );
		self::assertSame( 0, $again['migrated'] );
	}

	public function test_legacy_audit_lesson_migration_processes_rows_after_the_first_page(): void {
		Memory::maybe_install_table();
		Memory::put_typed(
			'feedback',
			'audit',
			'learning-audit-error-oldest',
			'Old recurring error',
			[
				'correction' => 'Unresolved incident: unknown error',
				'lesson'     => 'Unresolved incident: unknown error',
				'source'     => 'audit-error',
				'state'      => 'unresolved_incident',
			],
			1.0,
			[ 'status' => 'stale' ]
		);
		for ( $index = 0; $index < 500; ++$index ) {
			Memory::put_typed(
				'feedback',
				'user',
				'user-feedback-' . $index,
				'User feedback ' . $index,
				[ 'source' => 'user', 'state' => 'active' ],
				1.0,
				[ 'status' => 'active' ]
			);
		}

		$result = ErrorPatterns::migrate_legacy_audit_lessons();

		self::assertSame( 1, $result['migrated'] );
		self::assertSame( 500, $result['skipped'] );
		self::assertSame( 0, $result['write_failed'] );
		self::assertSame( '1', get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, '0' ) );
		$oldest = array_values(
			array_filter(
				$GLOBALS['wpdb']->rows,
				static fn( array $row ): bool => 'learning-audit-error-oldest' === (string) ( $row['memory_key'] ?? '' )
			)
		);
		self::assertSame( 'superseded', json_decode( (string) $oldest[0]['value_json'], true )['state'] ?? null );
	}

	public function test_legacy_audit_lesson_migration_stays_retryable_after_a_write_failure(): void {
		Memory::maybe_install_table();
		Memory::put_typed(
			'feedback',
			'audit',
			'learning-audit-error-write-fails',
			'Recurring error',
			[
				'correction' => 'Unresolved incident: unknown error',
				'lesson'     => 'Unresolved incident: unknown error',
				'source'     => 'audit-error',
				'state'      => 'unresolved_incident',
			],
			1.0,
			[ 'status' => 'stale' ]
		);
		$GLOBALS['wpdb']->fail_update_key = 'learning-audit-error-write-fails';

		$result = ErrorPatterns::migrate_legacy_audit_lessons();

		self::assertSame( 0, $result['migrated'] );
		self::assertSame( 1, $result['write_failed'] );
		self::assertSame( '0', get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, '0' ) );
	}

	/** @return object */
	private function make_memory_wpdb(): object {
		return new class() {
			public string $prefix     = 'wp_';
			public int $insert_id     = 0;
			public string $last_error = '';
			/** @var array<int, array<string, mixed>> */
			public array $rows = [];
			/** @var array<int, mixed> */
			public array $last_prepare_args = [];
			public string $fail_update_key = '';

			public function get_charset_collate(): string {
				return '';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [
					'id', 'scope', 'type', 'name', 'memory_key', 'value_json', 'confidence',
					'topic', 'version_fingerprint', 'expires_at', 'status', 'precedence',
					'created_by', 'created_at', 'updated_at', 'last_retrieved_at',
				];
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_prepare_args = $args;
				return $query;
			}

			public function get_var( string $query ): mixed {
				if ( str_contains( $query, 'SELECT id FROM' ) && str_contains( $query, 'memory_key' ) ) {
					$scope = (string) ( $this->last_prepare_args[0] ?? '' );
					$key   = (string) ( $this->last_prepare_args[1] ?? '' );
					foreach ( $this->rows as $row ) {
						if ( (string) $row['scope'] === $scope && (string) $row['memory_key'] === $key ) {
							return (int) $row['id'];
						}
					}
					return null;
				}
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				$row               = $data;
				$row['id']         = $this->insert_id;
				$row['created_at'] = $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' );
				$row['updated_at'] = $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' );
				$this->rows[]      = $row;
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
				$id = (int) ( $where['id'] ?? 0 );
				foreach ( $this->rows as $i => $row ) {
					if ( (int) $row['id'] === $id ) {
						if ( $this->fail_update_key === (string) ( $row['memory_key'] ?? '' ) ) {
							$this->last_error = 'Synthetic write failure';
							return false;
						}
						$this->rows[ $i ] = array_merge( $row, $data, [ 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
						return 1;
					}
				}
				return 0;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$type = null;
				$limit = PHP_INT_MAX;
				$offset = 0;
				if ( str_contains( $query, 'WHERE type' ) ) {
					$type = (string) ( $this->last_prepare_args[0] ?? '' );
					$limit = (int) ( $this->last_prepare_args[1] ?? PHP_INT_MAX );
					$offset = (int) ( $this->last_prepare_args[2] ?? 0 );
				} elseif ( str_contains( $query, 'LIMIT' ) ) {
					$limit = (int) ( $this->last_prepare_args[0] ?? PHP_INT_MAX );
					$offset = (int) ( $this->last_prepare_args[1] ?? 0 );
				}
				$out = [];
				foreach ( array_reverse( $this->rows ) as $row ) {
					if ( null !== $type && (string) ( $row['type'] ?? '' ) !== $type ) {
						continue;
					}
					$out[] = $row;
				}
				return array_slice( $out, $offset, $limit );
			}
		};
	}
}
