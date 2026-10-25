<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * The two lesson migrations run on a plugin upgrade and in the daily job, never on init,
 * and a migration that keeps failing gives up instead of scanning the memory table forever.
 *
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 * @covers \Stonewright\WpMcp\Security\AuditLog::run_scheduled_retention
 */
final class ErrorPatternsMigrationSchedulingTest extends TestCase {

	private mixed $saved_wpdb = null;
	private string $log_file  = '';
	private string $saved_log = '';

	protected function setUp(): void {
		$this->saved_wpdb                            = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$this->log_file                              = (string) tempnam( sys_get_temp_dir(), 'sw-log' );
		$this->saved_log                             = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->saved_log );
		@unlink( $this->log_file );
		$GLOBALS['wpdb']                             = $this->saved_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	public function test_init_registers_no_migration_callback(): void {
		$hooks = self::method_source( PluginRegistration::class, 'register_hooks' );

		self::assertStringNotContainsString( 'migrate_legacy_audit_lessons', $hooks );
		self::assertStringNotContainsString( 'return_unapproved_draft_lessons', $hooks );
		self::assertStringNotContainsString( 'run_pending_migrations', $hooks );
	}

	public function test_version_change_runs_pending_migrations_once(): void {
		$upgrade = self::method_source( PluginRegistration::class, 'maybe_upgrade' );

		self::assertStringContainsString( 'ErrorPatterns::run_pending_migrations()', $upgrade );
		self::assertLessThan(
			strpos( $upgrade, "update_option( 'stonewright_version'" ),
			strpos( $upgrade, 'ErrorPatterns::run_pending_migrations()' ),
			'a run that fails before the version is stored runs again'
		);
		self::assertLessThan(
			strpos( $upgrade, 'return;' ),
			strpos( $upgrade, "get_option( 'stonewright_version'" ),
			'the version is compared first, so a request without a version change skips the migrations'
		);

		$store           = $this->store( [ $this->legacy_row( 1 ), $this->draft_row( 2 ) ] );
		$GLOBALS['wpdb'] = $store;

		$first = ErrorPatterns::run_pending_migrations();

		self::assertSame( 1, $first['legacy']['migrated'] );
		self::assertSame( 1, $first['draft']['returned'] );
		self::assertSame( '1', get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION ) );
		self::assertSame( '1', get_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION ) );

		$scans = $store->scans;
		$again = ErrorPatterns::run_pending_migrations();

		self::assertTrue( $again['legacy']['already_done'] );
		self::assertTrue( $again['draft']['already_done'] );
		self::assertSame( $scans, $store->scans, 'the second run reads nothing' );
	}

	public function test_the_daily_job_runs_the_migrations_after_the_schema_repair_and_before_retention(): void {
		$daily = self::method_source( AuditLog::class, 'run_scheduled_retention' );

		$repair    = (int) strpos( $daily, 'StoreSchemaRepair::repair_unhealthy()' );
		$migration = (int) strpos( $daily, 'ErrorPatterns::run_pending_migrations()' );
		$retention = (int) strpos( $daily, 'self::enforce_retention(' );

		self::assertGreaterThan( 0, $repair );
		self::assertGreaterThan( $repair, $migration );
		self::assertGreaterThan( $migration, $retention );
		self::assertSame( 2, substr_count( $daily, 'catch ( \Throwable' ), 'each of the two maintenance steps is guarded on its own' );
	}

	public function test_a_failing_schema_repair_or_migration_cannot_skip_the_days_retention(): void {
		update_option( AuditLog::RETENTION_OPTION, 30, false );
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';
			/** @var list<string> */
			public array $statements = [];

			public function get_charset_collate(): string {
				return '';
			}

			/** @return list<string> */
			public function get_col( string $query, int $x = 0 ): array {
				throw new \RuntimeException( 'Synthetic schema failure' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $query, mixed $output = null ): array {
				throw new \RuntimeException( 'Synthetic scan failure' );
			}

			public function query( string $query ): int {
				$this->statements[] = $query;
				return 0;
			}
		};

		// Nothing here may throw: the repair and the migrations fail inside their own guard.
		AuditLog::run_scheduled_retention();

		self::assertSame( 3, substr_count( (string) file_get_contents( $this->log_file ), '"level":"warning"' ), 'the repair and each migration log their failure' );
		self::assertNotSame( [], preg_grep( '/^DELETE FROM .*stonewright_audit_log/', $GLOBALS['wpdb']->statements ), 'retention still ran' );
	}

	public function test_a_done_flag_skips_the_memory_scan(): void {
		$store           = $this->store( [ $this->legacy_row( 1 ), $this->draft_row( 2 ) ] );
		$GLOBALS['wpdb'] = $store;
		update_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, '1', true );
		update_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION, '1', true );

		$result = ErrorPatterns::run_pending_migrations();

		self::assertSame( 0, $store->scans );
		self::assertTrue( $result['legacy']['already_done'] );
		self::assertTrue( $result['draft']['already_done'] );
		self::assertSame( [], $store->updates );
	}

	public function test_a_failed_attempt_is_counted_and_the_next_run_scans_again(): void {
		$store                = $this->store( [ $this->legacy_row( 1 ), $this->draft_row( 2 ) ] );
		$store->fail_updates  = true;
		$GLOBALS['wpdb']      = $store;
		$before               = time();

		$first = ErrorPatterns::run_pending_migrations();

		self::assertSame( 1, $first['legacy']['write_failed'] );
		self::assertSame( 1, $first['draft']['failed'] );
		foreach ( [ ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, ErrorPatterns::DRAFT_LESSON_RETURN_OPTION ] as $option ) {
			$state = get_option( $option );
			self::assertIsArray( $state );
			self::assertSame( 1, $state['attempts'] );
			self::assertGreaterThanOrEqual( $before, $state['last'] );
			self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ $option ], $option . ' stays autoloaded' );
		}

		$scans = $store->scans;
		ErrorPatterns::run_pending_migrations();

		self::assertGreaterThan( $scans, $store->scans, 'a migration that has not given up scans again' );
		self::assertSame( 2, get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION )['attempts'] );
	}

	public function test_a_later_success_replaces_the_attempt_record_with_done(): void {
		$store                = $this->store( [ $this->legacy_row( 1 ), $this->draft_row( 2 ) ] );
		$store->fail_updates  = true;
		$GLOBALS['wpdb']      = $store;
		ErrorPatterns::run_pending_migrations();

		$store->fail_updates = false;
		ErrorPatterns::run_pending_migrations();

		self::assertSame( '1', get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION ) );
		self::assertSame( '1', get_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION ) );
	}

	public function test_migration_gives_up_after_five_failed_attempts(): void {
		$store                = $this->store( [ $this->legacy_row( 1 ), $this->draft_row( 2 ) ] );
		$store->fail_updates  = true;
		$GLOBALS['wpdb']      = $store;

		for ( $run = 0; $run < ErrorPatterns::MIGRATION_MAX_ATTEMPTS; ++$run ) {
			self::assertNotSame( 'abandoned', get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, '' ), 'run ' . $run );
			ErrorPatterns::run_pending_migrations();
		}

		self::assertSame( 'abandoned', get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION ) );
		self::assertSame( 'abandoned', get_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION ) );
		self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION ] );
		self::assertTrue( $GLOBALS['stonewright_test_option_autoload'][ ErrorPatterns::DRAFT_LESSON_RETURN_OPTION ] );

		$log = (string) file_get_contents( $this->log_file );
		self::assertSame( 2, substr_count( $log, 'stonewright.lesson_migration_abandoned' ), 'one warning per migration' );
		self::assertSame( 1, preg_match( '/"migration":"legacy_audit_lessons","failed":1/', $log ) );
		self::assertSame( 1, preg_match( '/"migration":"draft_lessons","failed":1/', $log ) );

		$scans = $store->scans;
		$sixth = ErrorPatterns::run_pending_migrations();

		self::assertSame( $scans, $store->scans, 'no sixth scan' );
		self::assertTrue( $sixth['legacy']['already_done'] );
		self::assertTrue( $sixth['draft']['already_done'] );
		self::assertSame( 2, substr_count( (string) file_get_contents( $this->log_file ), 'stonewright.lesson_migration_abandoned' ), 'no second warning' );
	}

	public function test_deleting_an_abandoned_flag_arms_the_migration_again(): void {
		$store           = $this->store( [ $this->legacy_row( 1 ), $this->draft_row( 2 ) ] );
		$GLOBALS['wpdb'] = $store;
		update_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION, 'abandoned', true );
		update_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION, 'abandoned', true );

		delete_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION );
		delete_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION );
		$result = ErrorPatterns::run_pending_migrations();

		self::assertSame( 1, $result['legacy']['migrated'] );
		self::assertSame( 1, $result['draft']['returned'] );
		self::assertSame( '1', get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION ) );
	}

	public function test_a_migration_that_throws_counts_as_a_failed_attempt(): void {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_results( string $query, mixed $output = null ): array {
				throw new \RuntimeException( 'Synthetic scan failure' );
			}
		};

		$result = ErrorPatterns::run_pending_migrations();

		self::assertFalse( $result['legacy']['already_done'] );
		self::assertSame( 1, get_option( ErrorPatterns::LEGACY_LESSON_MIGRATION_OPTION )['attempts'] );
		self::assertSame( 1, get_option( ErrorPatterns::DRAFT_LESSON_RETURN_OPTION )['attempts'] );
	}

	private static function method_source( string $class, string $method ): string {
		$reflection = new ReflectionMethod( $class, $method );
		$lines      = file( (string) $reflection->getFileName() );
		self::assertIsArray( $lines );

		return implode(
			'',
			array_slice(
				$lines,
				$reflection->getStartLine() - 1,
				$reflection->getEndLine() - $reflection->getStartLine() + 1
			)
		);
	}

	/** @return array<string, mixed> */
	private function legacy_row( int $id ): array {
		return $this->row(
			$id,
			'feedback',
			'audit',
			'learning-audit-error-' . $id,
			'stale',
			[
				'correction' => 'Unresolved incident: unknown error',
				'lesson'     => 'Unresolved incident: unknown error',
				'source'     => 'audit-error',
				'state'      => 'unresolved_incident',
			]
		);
	}

	/** @return array<string, mixed> */
	private function draft_row( int $id ): array {
		return $this->row( $id, 'reference', 'audit', 'draft-lesson-' . $id, 'active', [ 'source' => 'error-pattern-draft' ] );
	}

	/**
	 * @param array<string, mixed> $value
	 * @return array<string, mixed>
	 */
	private function row( int $id, string $type, string $scope, string $key, string $status, array $value ): array {
		return [
			'id'                  => $id,
			'type'                => $type,
			'scope'               => $scope,
			'memory_key'          => $key,
			'name'                => 'Synthetic ' . $key,
			'value_json'          => (string) json_encode( $value ),
			'confidence'          => '1.0000',
			'topic'               => '',
			'version_fingerprint' => '',
			'expires_at'          => null,
			'status'              => $status,
			'precedence'          => 0,
			'created_at'          => '2026-10-01 09:00:00',
			'updated_at'          => '2026-10-01 09:00:00',
			'last_retrieved_at'   => null,
		];
	}

	/**
	 * A memory store that lists rows by type, counts every listing, and can refuse every write.
	 *
	 * @param list<array<string, mixed>> $rows
	 */
	private function store( array $rows ): object {
		return new class( $rows ) {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			public int $scans         = 0;
			public bool $fail_updates = false;
			public int $insert_id     = 100;
			/** @var list<array<string, mixed>> */
			public array $updates = [];
			/** @var array<int, mixed> */
			private array $args = [];

			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {}

			public function prepare( string $query, mixed ...$args ): string {
				$this->args = $args;
				return $query;
			}

			/** @return list<array<string, mixed>> */
			public function get_results( string $query, mixed $output = null ): array {
				++$this->scans;
				$type   = (string) ( $this->args[0] ?? '' );
				$limit  = (int) ( $this->args[1] ?? PHP_INT_MAX );
				$offset = (int) ( $this->args[2] ?? 0 );
				$out    = array_values( array_filter( $this->rows, static fn( array $row ): bool => (string) $row['type'] === $type ) );

				return array_slice( $out, $offset, $limit );
			}

			public function get_var( string $query ): mixed {
				$scope = (string) ( $this->args[0] ?? '' );
				$key   = (string) ( $this->args[1] ?? '' );
				foreach ( $this->rows as $row ) {
					if ( (string) $row['scope'] === $scope && (string) $row['memory_key'] === $key ) {
						return (int) $row['id'];
					}
				}
				return null;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, mixed $output = null ): ?array {
				$id = (int) ( $this->args[0] ?? 0 );
				foreach ( $this->rows as $row ) {
					if ( (int) $row['id'] === $id ) {
						return $row;
					}
				}
				return null;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
				if ( $this->fail_updates ) {
					$this->last_error = 'Synthetic write failure';
					return false;
				}
				$id = (int) ( $where['id'] ?? 0 );
				foreach ( $this->rows as $index => $row ) {
					if ( (int) $row['id'] === $id ) {
						$this->updates[]      = $data;
						$this->rows[ $index ] = array_merge( $row, $data );
						return 1;
					}
				}
				return 0;
			}
		};
	}
}
