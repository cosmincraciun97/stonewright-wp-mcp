<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;

/**
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 * @covers \Stonewright\WpMcp\Security\AuditLog
 */
final class ErrorPatternsTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_two_matching_errors_group_as_recurring(): void {
		$args = [
			'_meta' => [
				'error_code'    => 'validation_failed',
				'error_message' => 'Spec rejected: missing sections array for layout.',
			],
		];

		ErrorPatterns::observe( 'stonewright/design-apply', 'error', $args );
		ErrorPatterns::observe( 'stonewright/design-apply', 'error', $args );

		$recurring = ErrorPatterns::recurring();
		self::assertNotEmpty( $recurring );
		self::assertSame( 2, $recurring[0]['count'] );
		self::assertSame( 'stonewright/design-apply', $recurring[0]['ability'] );
		self::assertStringContainsString( 'Spec rejected', $recurring[0]['message'] );
		self::assertNotEmpty( $recurring[0]['last_seen'] );
	}

	public function test_ok_status_is_ignored(): void {
		ErrorPatterns::observe( 'stonewright/memory-save', 'ok', [] );
		self::assertSame( [], ErrorPatterns::recurring() );
	}

	public function test_dismiss_hides_pattern(): void {
		$args = [ '_meta' => [ 'error_code' => 'x', 'error_message' => 'boom once twice' ] ];
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		$sig = ErrorPatterns::signature( 'stonewright/php-execute', $args );
		self::assertTrue( ErrorPatterns::dismiss( $sig ) );
		self::assertSame( [], ErrorPatterns::recurring() );
	}

	public function test_learning_write_failure_is_logged(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'sw-ep-log-' );
		self::assertNotFalse( $log_file );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix     = 'wp_';
			public string $last_error = 'Unknown column topic';
			public int $insert_id    = 0;

			public function get_var( string $query ): mixed {
				return null;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $data
			 * @return false Always fails (broken table fixture).
			 */
			public function insert( string $table, array $data, array $format = [] ): bool {
				return false;
			}
		};

		$args = [
			'error_code' => 'stonewright_demo_failure',
			'message'    => 'Demo failed',
		];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		ini_set( 'error_log', (string) $previous_log );
		$log = (string) file_get_contents( $log_file );
		@unlink( $log_file );

		self::assertStringContainsString( 'error_pattern_learning_write_failed', $log );
		self::assertStringContainsString( 'learning-audit-error-', $log );
	}

	public function test_audit_log_records_error_pattern_throws(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'sw-al-log-' );
		self::assertNotFalse( $log_file );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file );

		// Second error promotes a learning row; make put_typed's SELECT throw so
		// AuditLog's catch logs error_patterns_observe_threw instead of swallowing.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			public int $insert_id    = 1;

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				return 1;
			}

			public function get_var( string $query ): mixed {
				throw new \RuntimeException( 'simulated observe failure via get_var' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}
		};

		$args = [ 'error_code' => 'x', 'message' => 'boom' ];
		AuditLog::record( 'stonewright/demo-ability', $args, 'error' );
		AuditLog::record( 'stonewright/demo-ability', $args, 'error' );

		ini_set( 'error_log', (string) $previous_log );
		$log = (string) file_get_contents( $log_file );
		@unlink( $log_file );

		self::assertStringContainsString( 'error_patterns_observe_threw', $log );
		self::assertStringContainsString( 'simulated observe failure', $log );
	}
}
