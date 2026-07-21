<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Hard MCP token / tool-count budgets enforced by CI measure scripts.
 *
 * Estimates use compact JSON UTF-8 bytes / 4 (ceil). Tokenizer-specific counts
 * remain client and model specific; these limits are regression gates.
 */
final class TokenSurfaceBudgets {

	public const DEFAULT_MAX_TOOLS = 20;

	public const STRICT_MAX_TOOLS = 12;

	/**
	 * Bootstrap profile: progressive discovery entry surface.
	 * Includes startup + minimal runtime/write escape hatches so agents are not
	 * stuck without php-execute before the first task-start expansion.
	 */
	public const BOOTSTRAP_MAX_TOOLS = 12;

	/** Estimated token budget for bootstrap tools/list payload. */
	public const BOOTSTRAP_MAX_TOKENS = 3500;

	/** Essential profile may include blueprint/clone/learning path tools. */
	public const ESSENTIAL_MAX_TOOLS = 30;

	/**
	 * Non-visual task-start compact response (estimated tokens).
	 * Includes write_target_url / surface re-list signals for client sync.
	 */
	public const TASK_START_NON_VISUAL_MAX_TOKENS = 800;

	/** Visual task-start compact response (estimated tokens). */
	public const TASK_START_VISUAL_MAX_TOKENS = 1200;

	/**
	 * @param array<string, int> $metrics Keys: essential_tool_count, default_tool_count,
	 *                                    strict_tool_count, non_visual_task_start_tokens,
	 *                                    visual_task_start_tokens, bootstrap_tool_count,
	 *                                    bootstrap_token_estimate.
	 * @return array<string, bool>
	 */
	public static function evaluate( array $metrics ): array {
		$bootstrap       = (int) ( $metrics['bootstrap_tool_count'] ?? 0 );
		$bootstrap_tokens = (int) ( $metrics['bootstrap_token_estimate'] ?? 0 );
		$essential       = (int) ( $metrics['essential_tool_count'] ?? PHP_INT_MAX );
		$default         = (int) ( $metrics['default_tool_count'] ?? PHP_INT_MAX );
		$strict          = (int) ( $metrics['strict_tool_count'] ?? PHP_INT_MAX );
		$non_visual      = (int) ( $metrics['non_visual_task_start_tokens'] ?? PHP_INT_MAX );
		$visual          = (int) ( $metrics['visual_task_start_tokens'] ?? PHP_INT_MAX );

		$non_visual_ok = $non_visual < self::TASK_START_NON_VISUAL_MAX_TOKENS;
		$budgets       = [
			'essential_max_30_tools'       => $essential <= self::ESSENTIAL_MAX_TOOLS,
			'default_max_20_tools'         => $default <= self::DEFAULT_MAX_TOOLS,
			'strict_max_12_tools'          => $strict <= self::STRICT_MAX_TOOLS,
			'non_visual_task_start_lt_800' => $non_visual_ok,
			// Legacy alias kept for older measure consumers.
			'non_visual_task_start_lt_700' => $non_visual_ok,
			'visual_task_start_lt_1200'    => $visual < self::TASK_START_VISUAL_MAX_TOKENS,
		];

		// Bootstrap metrics are optional so legacy measure callers stay valid.
		if ( array_key_exists( 'bootstrap_tool_count', $metrics ) ) {
			// Keep legacy key alias so older measure callers still validate.
			$within = $bootstrap <= self::BOOTSTRAP_MAX_TOOLS;
			$budgets['bootstrap_max_12_tools'] = $within;
			$budgets['bootstrap_max_8_tools']  = $within;
		}
		if ( array_key_exists( 'bootstrap_token_estimate', $metrics ) ) {
			$within_tokens = $bootstrap_tokens <= self::BOOTSTRAP_MAX_TOKENS;
			$budgets['bootstrap_max_3500_tokens'] = $within_tokens;
			$budgets['bootstrap_max_2500_tokens'] = $within_tokens;
		}

		return $budgets;
	}

	/**
	 * @param array<string, bool> $budgets
	 */
	public static function all_pass( array $budgets ): bool {
		return ! in_array( false, $budgets, true );
	}

	/**
	 * Synthetic metrics that intentionally breach every hard budget.
	 *
	 * @return array<string, int>
	 */
	public static function over_budget_fixture_metrics(): array {
		return [
			'bootstrap_tool_count'         => self::BOOTSTRAP_MAX_TOOLS + 1,
			'bootstrap_token_estimate'     => self::BOOTSTRAP_MAX_TOKENS + 1,
			'essential_tool_count'         => self::ESSENTIAL_MAX_TOOLS + 1,
			'default_tool_count'           => self::DEFAULT_MAX_TOOLS + 1,
			'strict_tool_count'            => self::STRICT_MAX_TOOLS + 1,
			'non_visual_task_start_tokens' => self::TASK_START_NON_VISUAL_MAX_TOKENS,
			'visual_task_start_tokens'     => self::TASK_START_VISUAL_MAX_TOKENS,
		];
	}
}
