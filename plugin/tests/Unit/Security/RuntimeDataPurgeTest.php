<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Security\RuntimeDataPurge;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\RuntimeDataPurger;

/** @covers \Stonewright\WpMcp\Abilities\Security\RuntimeDataPurge @covers \Stonewright\WpMcp\Security\RuntimeDataPurger */
final class RuntimeDataPurgeTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = self::database();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode'                       => 'development',
			'stonewright_error_patterns'             => [ 'a' => [ 'count' => 2 ] ],
			'stonewright_incident_fallback'           => [ 'b' => [ 'state' => 'open' ] ],
			'stonewright_audit_reconcile_journal_v2' => [ [ 'status' => 'applied' ] ],
			'stonewright_audit_degraded'              => [ 'reason' => 'write_failed' ],
		];
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_dry_run_is_count_only_and_does_not_write(): void {
		$result = ( new RuntimeDataPurge() )->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( 'dry_run', $result['action'] );
		self::assertFalse( $result['preview']['contains_raw_rows'] );
		self::assertSame( 3, $result['preview']['counts']['audit_events'] );
		self::assertSame( 4, $result['preview']['counts']['memory_entries'] );
		self::assertSame( 3, $result['preview']['counts']['incidents'] );
		self::assertSame( 2, $result['preview']['counts']['oauth_audit_transients'] );
		self::assertSame( 13, $result['preview']['scope_watermarks']['audit']['table_max_id'] );
		self::assertSame( [], $GLOBALS['wpdb']->deletes );
	}

	public function test_apply_requires_the_reviewed_state_and_plan(): void {
		$result = ( new RuntimeDataPurge() )->execute(
			[
				'action'              => 'apply',
				'scopes'              => RuntimeDataPurger::SCOPES,
				'expected_state_hash' => str_repeat( 'a', 64 ),
				'approved_plan_hash'  => str_repeat( 'b', 64 ),
				'acknowledgement'     => 'erase_runtime_history',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_runtime_data_purge_state_conflict', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['wpdb']->deletes );
	}

	public function test_apply_clears_selected_state_and_retains_one_receipt(): void {
		$dry = ( new RuntimeDataPurge() )->execute( [] );
		$args = [
			'action'              => 'apply',
			'scopes'              => RuntimeDataPurger::SCOPES,
			'expected_state_hash' => $dry['preview']['state_hash'],
			'approved_plan_hash'  => $dry['preview']['plan_hash'],
			'acknowledgement'     => 'erase_runtime_history',
		];
		$result = ( new RuntimeDataPurge() )->execute( $args );

		self::assertIsArray( $result );
		self::assertTrue( $result['effect_verified'] );
		self::assertSame( 1, $result['after']['counts']['audit_events'] );
		self::assertSame( 0, $result['after']['counts']['memory_entries'] );
		self::assertSame( 0, $result['after']['counts']['incidents'] );
		self::assertSame( 0, $result['after']['counts']['error_patterns'] );
		self::assertTrue( $result['audit_receipt']['recorded'] );
		self::assertArrayNotHasKey( 'stonewright_error_patterns', $GLOBALS['stonewright_test_options'] );
		self::assertArrayNotHasKey( 'stonewright_incident_fallback', $GLOBALS['stonewright_test_options'] );
	}

	public function test_production_safe_apply_requires_a_matching_confirmation(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$dry = ( new RuntimeDataPurge() )->execute( [] );
		$args = [
			'action'              => 'apply',
			'scopes'              => RuntimeDataPurger::SCOPES,
			'expected_state_hash' => $dry['preview']['state_hash'],
			'approved_plan_hash'  => $dry['preview']['plan_hash'],
			'acknowledgement'     => 'erase_runtime_history',
		];
		$missing = ( new RuntimeDataPurge() )->execute( $args );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'stonewright_confirmation_required', $missing->get_error_code() );

		$token_args = $args;
		$token_args['scopes'] = RuntimeDataPurger::normalize_scopes( RuntimeDataPurger::SCOPES );
		$result = ( new RuntimeDataPurge() )->execute(
			$args + [ 'confirmation_token' => ConfirmationToken::issue( 'stonewright/security-runtime-data-purge', $token_args ) ]
		);
		self::assertIsArray( $result );
		self::assertTrue( $result['effect_verified'] );
	}

	public function test_permission_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		self::assertFalse( ( new RuntimeDataPurge() )->permission_callback( [] ) );
	}

	public function test_concurrent_row_above_reviewed_watermark_is_never_deleted(): void {
		$preview = RuntimeDataPurger::preview( [ 'memory' ] );
		self::assertIsArray( $preview );
		$GLOBALS['wpdb']->inject_concurrent_row_for = 'wptests_stonewright_memory';

		$result = RuntimeDataPurger::purge(
			[ 'memory' ],
			$preview['state_hash'],
			$preview['plan_hash'],
			$preview
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_runtime_data_purge_partial_failure', $result->get_error_code() );
		self::assertSame( 1, $result->get_error_data()['remaining_counts']['memory_entries'] );
		self::assertStringContainsString( 'WHERE id <= 14', $GLOBALS['wpdb']->delete_queries[0] );
	}

	private static function database(): object {
		return new class() {
			public string $prefix = 'wptests_';
			public string $options = 'wptests_options';
			/** @var array<string,int> */
			public array $counts = [
				'wptests_stonewright_audit_log' => 3,
				'wptests_stonewright_memory'    => 4,
				'wptests_stonewright_incidents' => 2,
			];
			/** @var list<string> */
			public array $deletes = [];
			public int $oauth_value_transients = 1;
			public int $oauth_timeout_transients = 1;
			public string $inject_concurrent_row_for = '';
			/** @var list<string> */
			public array $delete_queries = [];
			/** @var list<array<string,mixed>> */
			public array $audit_rows = [];

			public function prepare( string $query, mixed ...$args ): string {
				foreach ( $args as $arg ) {
					if ( str_contains( $query, '%s' ) ) {
						$query = preg_replace( '/%s/', "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
					} else {
						$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 ) ?? $query;
					}
				}
				return $query;
			}

			public function esc_like( string $value ): string {
				return $value;
			}

			public function get_var( string $query ): mixed {
				if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $match ) ) {
					return array_key_exists( $match[1], $this->counts ) ? $match[1] : null;
				}
				foreach ( $this->counts as $table => $count ) {
					if ( str_contains( $query, $table ) ) {
						return str_contains( $query, 'MAX(id)' ) ? $count + 10 : $count;
					}
				}
				if ( str_contains( $query, '_transient_timeout_stonewright_oauth_audit_' ) ) {
					return str_contains( $query, 'MAX(option_id)' ) ? 31 : $this->oauth_timeout_transients;
				}
				if ( str_contains( $query, '_transient_stonewright_oauth_audit_' ) ) {
					return str_contains( $query, 'MAX(option_id)' ) ? 30 : $this->oauth_value_transients;
				}
				return 0;
			}

			public function query( string $query ): int|false {
				if ( preg_match( '/DELETE FROM (wptests_stonewright_[a-z_]+)/', $query, $match ) ) {
					$this->deletes[] = $match[1];
					$this->delete_queries[] = $query;
					$deleted = $this->counts[ $match[1] ] ?? 0;
					if ( $this->inject_concurrent_row_for === $match[1] ) {
						$this->counts[ $match[1] ] = 1;
						$this->inject_concurrent_row_for = '';
					} else {
						$this->counts[ $match[1] ] = 0;
					}
					return $deleted;
				}
				if ( str_contains( $query, 'wptests_options' ) ) {
					$this->deletes[] = 'oauth_transients';
					if ( str_contains( $query, '_transient_timeout_stonewright_oauth_audit_' ) ) {
						$this->oauth_timeout_transients = 0;
					} else {
						$this->oauth_value_transients = 0;
					}
					return 1;
				}
				return 0;
			}

			public function get_row( string $query, string $output = 'OBJECT' ): array|object|null {
				unset( $query, $output );
				$row = end( $this->audit_rows );
				return is_array( $row ) ? $row : null;
			}

			/** @param array<string,mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int|false {
				unset( $format );
				if ( isset( $this->counts[ $table ] ) ) {
					++$this->counts[ $table ];
				}
				if ( str_contains( $table, 'stonewright_audit_log' ) ) {
					$this->audit_rows[] = $data;
				}
				return 1;
			}
		};
	}
}
