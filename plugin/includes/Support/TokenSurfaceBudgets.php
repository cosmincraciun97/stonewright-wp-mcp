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

	public const ESSENTIAL_MAX_TOOLS = 20;

	/** Non-visual task-start compact response (estimated tokens). */
	public const TASK_START_NON_VISUAL_MAX_TOKENS = 700;

	/** Visual task-start compact response (estimated tokens). */
	public const TASK_START_VISUAL_MAX_TOKENS = 1200;

	/**
	 * @param array<string, int> $metrics Keys: essential_tool_count, default_tool_count,
	 *                                    strict_tool_count, non_visual_task_start_tokens,
	 *                                    visual_task_start_tokens.
	 * @return array<string, bool>
	 */
	public static function evaluate( array $metrics ): array {
		$essential = (int) ( $metrics['essential_tool_count'] ?? PHP_INT_MAX );
		$default   = (int) ( $metrics['default_tool_count'] ?? PHP_INT_MAX );
		$strict    = (int) ( $metrics['strict_tool_count'] ?? PHP_INT_MAX );
		$non_visual = (int) ( $metrics['non_visual_task_start_tokens'] ?? PHP_INT_MAX );
		$visual     = (int) ( $metrics['visual_task_start_tokens'] ?? PHP_INT_MAX );

		return [
			'essential_max_20_tools'       => $essential <= self::ESSENTIAL_MAX_TOOLS,
			'default_max_20_tools'         => $default <= self::DEFAULT_MAX_TOOLS,
			'strict_max_12_tools'          => $strict <= self::STRICT_MAX_TOOLS,
			'non_visual_task_start_lt_700' => $non_visual < self::TASK_START_NON_VISUAL_MAX_TOKENS,
			'visual_task_start_lt_1200'    => $visual < self::TASK_START_VISUAL_MAX_TOKENS,
		];
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
			'essential_tool_count'         => self::ESSENTIAL_MAX_TOOLS + 1,
			'default_tool_count'           => self::DEFAULT_MAX_TOOLS + 1,
			'strict_tool_count'            => self::STRICT_MAX_TOOLS + 1,
			'non_visual_task_start_tokens' => self::TASK_START_NON_VISUAL_MAX_TOKENS,
			'visual_task_start_tokens'     => self::TASK_START_VISUAL_MAX_TOKENS,
		];
	}
}
