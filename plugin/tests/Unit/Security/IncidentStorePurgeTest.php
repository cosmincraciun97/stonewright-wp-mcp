<?php
/**
 * Deleting the whole audit log also deletes the incidents that point into it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\AuditEvent;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Security\IncidentStore
 */
final class IncidentStorePurgeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
		IncidentStore::reset_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		IncidentStore::reset_for_tests();
	}

	/** @return array<string, mixed> */
	private function failure( string $ability, string $code ): array {
		return AuditEvent::normalize(
			$ability,
			[ '_meta' => [ 'error_code' => $code, 'error_message' => 'Synthetic failure.', 'resource_type' => 'post' ] ],
			'error'
		);
	}

	public function test_purge_all_removes_every_incident_in_every_state_and_reports_how_many(): void {
		IncidentStore::observe( $this->failure( 'stonewright/skills-save', 'stonewright_skill_write_conflict' ) );
		IncidentStore::observe( $this->failure( 'stonewright/skills-save', 'stonewright_skill_write_conflict' ) );
		IncidentStore::observe( $this->failure( 'stonewright/theme-file-patch', 'stonewright_spec_invalid' ) );
		self::assertSame( 2, array_sum( IncidentStore::counts() ) );

		self::assertSame( 2, IncidentStore::purge_all() );

		self::assertSame( [ 'open' => 0, 'observing' => 0, 'resolved' => 0, 'suppressed' => 0 ], IncidentStore::counts() );
		self::assertSame( [], IncidentStore::recent( 50 ) );
		self::assertSame( 0, IncidentStore::purge_all(), 'A second purge finds nothing.' );
	}

	public function test_a_cause_that_recurs_after_a_purge_starts_a_new_incident(): void {
		IncidentStore::observe( $this->failure( 'stonewright/skills-save', 'stonewright_skill_write_conflict' ) );
		IncidentStore::purge_all();

		$again = IncidentStore::observe( $this->failure( 'stonewright/skills-save', 'stonewright_skill_write_conflict' ) );

		self::assertIsArray( $again );
		self::assertSame( 1, (int) $again['occurrence_count'] );
		self::assertSame( 0, (int) $again['reopened_count'] );
	}
}
