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
				'bootstrap_tool_count'         => 12,
				'bootstrap_token_estimate'     => 3000,
				'essential_tool_count'         => 20,
				'default_tool_count'           => 20,
				'strict_tool_count'            => 12,
				'non_visual_task_start_tokens' => 799,
				'visual_task_start_tokens'     => 1199,
			]
		);

		self::assertTrue( TokenSurfaceBudgets::all_pass( $budgets ) );
		self::assertTrue( $budgets['bootstrap_max_12_tools'] );
		self::assertTrue( $budgets['bootstrap_max_8_tools'] );
		self::assertTrue( $budgets['bootstrap_max_3500_tokens'] );
		self::assertTrue( $budgets['bootstrap_max_2500_tokens'] );
		self::assertTrue( $budgets['essential_max_30_tools'] );
		self::assertTrue( $budgets['default_max_20_tools'] );
		self::assertTrue( $budgets['strict_max_12_tools'] );
		self::assertTrue( $budgets['non_visual_task_start_lt_800'] );
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
		self::assertFalse( $budgets['non_visual_task_start_lt_800'] );
		self::assertFalse( $budgets['non_visual_task_start_lt_700'] );
		self::assertFalse( $budgets['visual_task_start_lt_1200'] );
	}

	public function test_bootstrap_tool_count_over_limit_fails_on_its_own(): void {
		$budgets = TokenSurfaceBudgets::evaluate( $this->passing_metrics( [ 'bootstrap_tool_count' => TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS + 1 ] ) );

		self::assertFalse( $budgets['bootstrap_max_12_tools'] );
		self::assertTrue( $budgets['bootstrap_max_3500_tokens'] );
		self::assertFalse( TokenSurfaceBudgets::all_pass( $budgets ) );
	}

	public function test_bootstrap_token_estimate_over_limit_fails_on_its_own(): void {
		$budgets = TokenSurfaceBudgets::evaluate( $this->passing_metrics( [ 'bootstrap_token_estimate' => TokenSurfaceBudgets::BOOTSTRAP_MAX_TOKENS + 1 ] ) );

		self::assertTrue( $budgets['bootstrap_max_12_tools'] );
		self::assertFalse( $budgets['bootstrap_max_3500_tokens'] );
		self::assertFalse( TokenSurfaceBudgets::all_pass( $budgets ) );
	}

	public function test_bootstrap_limits_are_inclusive(): void {
		$budgets = TokenSurfaceBudgets::evaluate(
			$this->passing_metrics(
				[
					'bootstrap_tool_count'     => TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS,
					'bootstrap_token_estimate' => TokenSurfaceBudgets::BOOTSTRAP_MAX_TOKENS,
				]
			)
		);

		self::assertTrue( $budgets['bootstrap_max_12_tools'] );
		self::assertTrue( $budgets['bootstrap_max_3500_tokens'] );
	}

	public function test_measure_script_checks_the_bootstrap_profile(): void {
		$report = $this->run_measure_script( [] );

		self::assertSame( 0, $report['code'], $report['raw'] );
		self::assertTrue( $report['json']['ok'], $report['raw'] );
		$budgets = $report['json']['budgets'];
		self::assertArrayHasKey( 'bootstrap_max_12_tools', $budgets );
		self::assertArrayHasKey( 'bootstrap_max_3500_tokens', $budgets );
		self::assertTrue( $budgets['bootstrap_max_12_tools'] );
		self::assertTrue( $budgets['bootstrap_max_3500_tokens'] );

		$bootstrap = $report['json']['surfaces']['bootstrap'];
		self::assertGreaterThan( 0, $bootstrap['tool_count'] );
		self::assertLessThanOrEqual( TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS, $bootstrap['tool_count'] );
		self::assertGreaterThan( 0, $bootstrap['tools_list']['estimated_tokens'] );
		self::assertLessThanOrEqual( TokenSurfaceBudgets::BOOTSTRAP_MAX_TOKENS, $bootstrap['tools_list']['estimated_tokens'] );
	}

	public function test_over_budget_fixture_reports_the_two_bootstrap_limits_separately(): void {
		$report = $this->run_measure_script( [ '--fixture=over-budget' ] );

		self::assertSame( 1, $report['code'], $report['raw'] );
		$budgets = $report['json']['budgets'];
		self::assertFalse( $budgets['bootstrap_max_12_tools'] );
		self::assertFalse( $budgets['bootstrap_max_3500_tokens'] );
		self::assertGreaterThan( TokenSurfaceBudgets::BOOTSTRAP_MAX_TOOLS, $report['json']['metrics']['bootstrap_tool_count'] );
		self::assertGreaterThan( TokenSurfaceBudgets::BOOTSTRAP_MAX_TOKENS, $report['json']['metrics']['bootstrap_token_estimate'] );
	}

	/**
	 * @param array<string, int> $override
	 * @return array<string, int>
	 */
	private function passing_metrics( array $override ): array {
		return array_merge(
			[
				'bootstrap_tool_count'         => 11,
				'bootstrap_token_estimate'     => 3178,
				'essential_tool_count'         => 20,
				'default_tool_count'           => 20,
				'strict_tool_count'            => 12,
				'non_visual_task_start_tokens' => 799,
				'visual_task_start_tokens'     => 1199,
			],
			$override
		);
	}

	/**
	 * @param list<string> $arguments
	 * @return array{code:int, raw:string, json:array<string, mixed>}
	 */
	private function run_measure_script( array $arguments ): array {
		$script = dirname( __DIR__, 3 ) . '/bin/measure-token-surface.php';
		self::assertFileExists( $script );

		$cmd    = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $arguments ) );
		$output = [];
		$code   = 0;
		exec( $cmd . ' 2>&1', $output, $code );
		$raw  = implode( "\n", $output );
		$json = json_decode( $raw, true );

		return [
			'code' => $code,
			'raw'  => $raw,
			'json' => is_array( $json ) ? $json : [],
		];
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
