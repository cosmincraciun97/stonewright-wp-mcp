<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\IncidentActions;

/** @covers \Stonewright\WpMcp\Security\IncidentActions */
final class IncidentActionsTest extends TestCase {

	public function test_ranks_relevant_open_incidents_and_returns_compact_contract(): void {
		$rows = [
			$this->row( 'site', 'open', 'critical', 20, '2026-08-12 10:03:00' ),
			$this->row( 'elementor-v3', 'observing', 'error', 4, '2026-08-12 10:02:00' ),
			$this->row( 'elementor-v3', 'open', 'high', 3, '2026-08-12 10:01:00' ),
			$this->row( 'elementor-v3', 'resolved', 'critical', 99, '2026-08-12 10:04:00' ),
		];

		$actions = IncidentActions::rank( $rows, 'elementor', 3 );

		self::assertCount( 3, $actions );
		self::assertStringContainsString( 'elementor-v3', $actions[0]['ability'] );
		self::assertSame( 'open', $actions[0]['state'] );
		self::assertSame(
			[ 'incident_id', 'state', 'ability', 'error_code', 'occurrences', 'repair', 'next_tool', 'required_verifier', 'retry_policy', 'learning_policy' ],
			array_keys( $actions[0] )
		);
		self::assertSame( 'repair_then_retry_once', $actions[0]['retry_policy'] );
		self::assertSame( 'promote_only_after_verified_repair', $actions[0]['learning_policy'] );
		self::assertNotEmpty( $actions[0]['repair'] );
		self::assertNotContains( 'resolved', array_column( $actions, 'state' ) );
	}

	/** @return array<string, mixed> */
	private function row( string $family, string $state, string $severity, int $count, string $last_seen ): array {
		return [
			'incident_id'       => hash( 'sha256', $family . $state . $last_seen ),
			'state'             => $state,
			'severity'          => $severity,
			'ability_name'      => 'stonewright/' . $family . '-write',
			'ability_family'    => $family,
			'root_error_code'   => 'stonewright_' . str_replace( '-', '_', $family ) . '_invalid',
			'occurrence_count'  => $count,
			'last_seen'         => $last_seen,
			'expected_verifier' => 'stonewright/' . $family . '-verify',
			'remediation_code'  => 'stonewright/' . $family . '-schema',
		];
	}
}
