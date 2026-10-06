<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ErrorPatterns;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * The Audit Log page counts what it shows: the Auth view lists authentication
 * problems only, incident totals are not capped, and the recurring-errors
 * panel leaves out patterns that stopped occurring.
 *
 * @covers \Stonewright\WpMcp\Security\AuditLog
 * @covers \Stonewright\WpMcp\Security\IncidentStore
 * @covers \Stonewright\WpMcp\Security\ErrorPatterns
 */
final class AuditCountersTest extends TestCase {

	private mixed $saved_wpdb = null;

	protected function setUp(): void {
		$this->saved_wpdb                    = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                     = $this->saved_wpdb;
		$GLOBALS['stonewright_test_options'] = [];
		IncidentStore::reset_for_tests();
	}

	public function test_auth_view_counts_authentication_problems_only(): void {
		$recorder        = new class() {
			public string $prefix = 'wp_';
			/** @var list<array{0:string,1:list<mixed>}> */
			public array $prepared = [];

			public function prepare( string $query, mixed ...$args ): string {
				$this->prepared[] = [ $query, $args ];
				return $query;
			}

			public function get_var( string $query ): string {
				return '0';
			}
		};
		$GLOBALS['wpdb'] = $recorder;

		AuditLog::count( [ 'view' => 'auth' ] );

		self::assertNotEmpty( $recorder->prepared );
		[ $query, $args ] = $recorder->prepared[0];
		self::assertStringContainsString( 'category = %s', $query );
		self::assertStringContainsString( 'outcome <> %s', $query );
		self::assertContains( AuditEvent::CATEGORY_AUTH, $args );
		self::assertContains( AuditEvent::OUTCOME_SUCCESS, $args );
	}

	public function test_incident_totals_include_every_incident(): void {
		for ( $i = 0; $i < 501; $i++ ) {
			$id = hash( 'sha256', 'incident-' . $i );
			$GLOBALS['wpdb']->incident_rows[ $id ] = [
				'incident_id' => $id,
				'state'       => 'open',
				'last_seen'   => gmdate( 'Y-m-d H:i:s' ),
			];
		}

		self::assertSame( 501, IncidentStore::counts()['open'] );
	}

	public function test_recurring_panel_leaves_out_patterns_that_stopped(): void {
		update_option(
			ErrorPatterns::OPTION_KEY,
			[
				'fresh' => [
					'signature'  => 'fresh',
					'ability'    => 'stonewright/example-a',
					'error_code' => 'stonewright_example_a',
					'count'      => 3,
					'first_seen' => gmdate( 'c', time() - 2 * DAY_IN_SECONDS ),
					'last_seen'  => gmdate( 'c', time() - DAY_IN_SECONDS ),
					'dismissed'  => false,
				],
				'stale' => [
					'signature'  => 'stale',
					'ability'    => 'stonewright/example-b',
					'error_code' => 'stonewright_example_b',
					'count'      => 9,
					'first_seen' => gmdate( 'c', time() - 50 * DAY_IN_SECONDS ),
					'last_seen'  => gmdate( 'c', time() - 40 * DAY_IN_SECONDS ),
					'dismissed'  => false,
				],
			]
		);

		self::assertSame( [ 'stonewright_example_a' ], array_column( ErrorPatterns::recurring(), 'error_code' ) );
	}
}
