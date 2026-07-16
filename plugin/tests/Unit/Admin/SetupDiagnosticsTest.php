<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\SetupDiagnostics;

/**
 * @covers \Stonewright\WpMcp\Admin\SetupDiagnostics
 */
final class SetupDiagnosticsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_enabled'              => true,
			'site_url'                         => 'https://example.test',
			'stonewright_essential_tools_mode' => true,
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_report_is_compact_and_versioned(): void {
		$report = SetupDiagnostics::report();

		self::assertArrayHasKey( 'ready', $report );
		self::assertCount( 5, $report['checks'] );
		self::assertSame( '0.0.0-test', $report['versions']['plugin'] );
		self::assertSame( '1.0.0', $report['versions']['companion_contract'] );
		self::assertLessThanOrEqual( 30, $report['versions']['tool_count'] );
	}

	public function test_tool_budget_passes_at_essential_maximum(): void {
		// Essential profile currently exposes 29 tools (under ESSENTIAL_MAX_TOOLS=30).
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode']    = true;
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [];

		$report = SetupDiagnostics::report();
		$budget = $this->find_check( $report['checks'], 'tool_budget' );

		self::assertSame( 29, $report['versions']['tool_count'] );
		self::assertSame( 'ok', $budget['status'], '29 tools is within ESSENTIAL_MAX_TOOLS and must pass.' );
	}

	public function test_tool_budget_fails_above_essential_maximum(): void {
		// Pad essential profile past 30 (29 base + 2 extras).
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [
			'stonewright/ping',
			'stonewright/site-health',
		];

		$report = SetupDiagnostics::report();
		$budget = $this->find_check( $report['checks'], 'tool_budget' );

		self::assertSame( 31, $report['versions']['tool_count'] );
		self::assertNotSame( 'ok', $budget['status'] );
	}

	/**
	 * @param list<array{id: string, status: string, label: string, detail: string}> $checks
	 * @return array{id: string, status: string, label: string, detail: string}
	 */
	private function find_check( array $checks, string $id ): array {
		foreach ( $checks as $check ) {
			if ( $check['id'] === $id ) {
				return $check;
			}
		}

		self::fail( 'Check not found: ' . $id );
	}
}
