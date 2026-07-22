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

	private function make_wpdb( bool $insert_ok ): object {
		return new class( $insert_ok ) {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public int $row_count = 0;
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
				return $query;
			}

			public function get_var( string $query = '' ): int|string|null {
				return $this->row_count;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
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
