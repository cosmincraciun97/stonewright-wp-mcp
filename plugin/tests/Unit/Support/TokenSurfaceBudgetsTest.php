<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * @covers \Stonewright\WpMcp\Support\TokenSurfaceBudgets
 */
final class TokenSurfaceBudgetsTest extends TestCase {

	public function test_passing_metrics_all_true(): void {
		$budgets = TokenSurfaceBudgets::evaluate(
			[
				'bootstrap_tool_count'         => 8,
				'bootstrap_token_estimate'     => 2000,
				'essential_tool_count'         => 20,
				'default_tool_count'           => 20,
				'strict_tool_count'            => 12,
				'non_visual_task_start_tokens' => 699,
				'visual_task_start_tokens'     => 1199,
			]
		);

		self::assertTrue( TokenSurfaceBudgets::all_pass( $budgets ) );
		self::assertTrue( $budgets['bootstrap_max_8_tools'] );
		self::assertTrue( $budgets['bootstrap_max_2500_tokens'] );
		self::assertTrue( $budgets['essential_max_30_tools'] );
		self::assertTrue( $budgets['default_max_20_tools'] );
		self::assertTrue( $budgets['strict_max_12_tools'] );
		self::assertTrue( $budgets['non_visual_task_start_lt_700'] );
		self::assertTrue( $budgets['visual_task_start_lt_1200'] );
	}

	public function test_over_budget_fixture_fails(): void {
		$budgets = TokenSurfaceBudgets::evaluate( TokenSurfaceBudgets::over_budget_fixture_metrics() );

		self::assertFalse( TokenSurfaceBudgets::all_pass( $budgets ) );
		self::assertFalse( $budgets['bootstrap_max_8_tools'] );
		self::assertFalse( $budgets['bootstrap_max_2500_tokens'] );
		self::assertFalse( $budgets['essential_max_30_tools'] );
		self::assertFalse( $budgets['default_max_20_tools'] );
		self::assertFalse( $budgets['strict_max_12_tools'] );
		self::assertFalse( $budgets['non_visual_task_start_lt_700'] );
		self::assertFalse( $budgets['visual_task_start_lt_1200'] );
	}

	public function test_measure_script_exits_nonzero_for_over_budget_fixture(): void {
		$script = dirname( __DIR__, 3 ) . '/bin/measure-token-surface.php';
		self::assertFileExists( $script );

		$php    = PHP_BINARY;
		$cmd    = escapeshellarg( $php ) . ' ' . escapeshellarg( $script ) . ' --fixture=over-budget';
		$output = [];
		$code   = 0;
		exec( $cmd . ' 2>&1', $output, $code );

		self::assertSame( 1, $code, implode( "\n", $output ) );
		$joined = implode( "\n", $output );
		self::assertStringContainsString( '"ok": false', $joined );
		self::assertStringContainsString( 'essential_max_30_tools', $joined );
	}
}
