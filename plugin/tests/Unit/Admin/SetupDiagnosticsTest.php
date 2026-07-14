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
		self::assertLessThanOrEqual( 20, $report['versions']['tool_count'] );
	}
}
