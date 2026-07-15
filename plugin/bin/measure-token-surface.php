<?php
/**
 * Reproducible MCP surface and task-start token estimate.
 *
 * The estimate uses compact JSON byte length divided by four. It is intended
 * for before/after regression tracking; exact tokenizer counts remain client
 * and model specific.
 *
 * Hard budgets (CI fails when any is false):
 * - essential mode tools ≤ 20
 * - default profile cap ≤ 20 tools
 * - strict/low-tools cap ≤ 12 tools
 * - task-start non-visual compact < 700 estimated tokens
 * - task-start visual compact < 1200 estimated tokens
 *
 * Usage:
 *   cd plugin && composer tokens:measure
 *   php bin/measure-token-surface.php --fixture=over-budget   # dry-run exit 1
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/tests/bootstrap.php';

use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

$fixture = null;
foreach ( array_slice( $argv ?? [], 1 ) as $arg ) {
	if ( str_starts_with( $arg, '--fixture=' ) ) {
		$fixture = substr( $arg, strlen( '--fixture=' ) );
	}
}

if ( 'over-budget' === $fixture ) {
	$metrics = TokenSurfaceBudgets::over_budget_fixture_metrics();
	$budgets = TokenSurfaceBudgets::evaluate( $metrics );
	$report  = [
		'ok'      => false,
		'fixture' => 'over-budget',
		'method'  => 'fixture; no live surface measurement',
		'metrics' => $metrics,
		'budgets' => $budgets,
		'limits'  => [
			'essential_max_tools'              => TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS,
			'default_max_tools'                => TokenSurfaceBudgets::DEFAULT_MAX_TOOLS,
			'strict_max_tools'                 => TokenSurfaceBudgets::STRICT_MAX_TOOLS,
			'task_start_non_visual_max_tokens' => TokenSurfaceBudgets::TASK_START_NON_VISUAL_MAX_TOKENS,
			'task_start_visual_max_tokens'     => TokenSurfaceBudgets::TASK_START_VISUAL_MAX_TOKENS,
		],
	];
	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( TokenSurfaceBudgets::all_pass( $budgets ) ? 0 : 1 );
}

$GLOBALS['stonewright_test_user_caps'] = [
	'read'           => true,
	'manage_options' => true,
];
$GLOBALS['stonewright_test_user_logged_in']  = true;
$GLOBALS['stonewright_test_current_user_id'] = 1;
$GLOBALS['stonewright_test_options']         = [
	'stonewright_mode'               => 'development',
	'stonewright_disabled_abilities' => [],
];

/** @return array{bytes:int,estimated_tokens:int} */
function stonewright_token_metrics( mixed $value ): array {
	$json  = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$bytes = false === $json ? 0 : strlen( $json );
	return [
		'bytes'            => $bytes,
		'estimated_tokens' => (int) ceil( $bytes / 4 ),
	];
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function stonewright_tool_documents( array $rows ): array {
	return array_values(
		array_map(
			static fn( array $row ): array => [
				'name'        => (string) $row['mcp_tool_name'],
				'description' => (string) $row['description'],
				'inputSchema' => $row['input_schema'],
			],
			array_filter( $rows, static fn( array $row ): bool => (bool) $row['enabled'] )
		)
	);
}

/** @return list<array<string, mixed>> */
function stonewright_visible_rows( bool $essential ): array {
	$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = $essential;
	return AbilityRegistry::enabled_abilities();
}

/**
 * @param list<array<string, mixed>> $all_rows
 * @return array<string, mixed>
 * @throws RuntimeException When the tool profile ability returns WP_Error.
 */
function stonewright_profile_metrics( string $profile, int $max_tools, array $all_rows ): array {
	$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = false;
	$result = ( new ToolProfile() )->execute(
		[
			'profile'   => $profile,
			'max_tools' => $max_tools,
		]
	);
	if ( $result instanceof WP_Error ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$selected = array_fill_keys( $result['recommended_tools'], true );
	$rows     = array_values(
		array_filter(
			$all_rows,
			static fn( array $row ): bool => isset( $selected[ $row['name'] ] )
		)
	);
	$documents = stonewright_tool_documents( $rows );
	return [
		'profile'            => $profile,
		'max_tools'          => $max_tools,
		'tool_count'         => count( $documents ),
		'profile_tool_count' => $result['profile_tool_count'],
		'under_limit'        => $result['under_limit'],
		'tools_list'         => stonewright_token_metrics( $documents ),
	];
}

$full_rows       = stonewright_visible_rows( false );
$essential_rows  = stonewright_visible_rows( true );
$full_tools      = stonewright_tool_documents( $full_rows );
$essential_tools = stonewright_tool_documents( $essential_rows );

$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;
$nonvisual_bootstrap = ( new TaskStart() )->execute(
	[
		'task'         => 'Update an existing post title and excerpt.',
		'surface'      => 'wordpress',
		'intent'       => 'write',
		'responseMode' => 'compact',
	]
);
$visual_bootstrap = ( new TaskStart() )->execute(
	[
		'task'         => 'Implement a responsive Elementor landing-page hero from a supplied design image.',
		'surface'      => 'elementor',
		'intent'       => 'write',
		'responseMode' => 'compact',
	]
);

$report = [
	'method'                   => 'compact JSON UTF-8 bytes / 4; rounded up',
	'generated_with_php'       => PHP_VERSION,
	'all_registered_abilities' => count( AbilityRegistry::list() ),
	'surfaces'                 => [
		'full'       => [
			'tool_count' => count( $full_tools ),
			'tools_list' => stonewright_token_metrics( $full_tools ),
		],
		'essential'  => [
			'tool_count' => count( $essential_tools ),
			'tools_list' => stonewright_token_metrics( $essential_tools ),
		],
		'default_20' => stonewright_profile_metrics( 'elementor-design', TokenSurfaceBudgets::DEFAULT_MAX_TOOLS, $full_rows ),
		'strict_12'  => stonewright_profile_metrics( 'low-tools', TokenSurfaceBudgets::STRICT_MAX_TOOLS, $full_rows ),
	],
	'task_start'               => [
		'non_visual_compact' => stonewright_token_metrics( $nonvisual_bootstrap ),
		'visual_compact'     => stonewright_token_metrics( $visual_bootstrap ),
	],
	'limits'                   => [
		'essential_max_tools'              => TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS,
		'default_max_tools'                => TokenSurfaceBudgets::DEFAULT_MAX_TOOLS,
		'strict_max_tools'                 => TokenSurfaceBudgets::STRICT_MAX_TOOLS,
		'task_start_non_visual_max_tokens' => TokenSurfaceBudgets::TASK_START_NON_VISUAL_MAX_TOKENS,
		'task_start_visual_max_tokens'     => TokenSurfaceBudgets::TASK_START_VISUAL_MAX_TOKENS,
	],
];

$report['budgets'] = TokenSurfaceBudgets::evaluate(
	[
		'essential_tool_count'         => $report['surfaces']['essential']['tool_count'],
		'default_tool_count'           => $report['surfaces']['default_20']['tool_count'],
		'strict_tool_count'            => $report['surfaces']['strict_12']['tool_count'],
		'non_visual_task_start_tokens' => $report['task_start']['non_visual_compact']['estimated_tokens'],
		'visual_task_start_tokens'     => $report['task_start']['visual_compact']['estimated_tokens'],
	]
);
$report['ok'] = TokenSurfaceBudgets::all_pass( $report['budgets'] );

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
exit( $report['ok'] ? 0 : 1 );
