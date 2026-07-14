<?php
declare( strict_types=1 );

/**
 * Reproducible MCP surface and task-start token estimate.
 *
 * The estimate uses compact JSON byte length divided by four. It is intended
 * for before/after regression tracking; exact tokenizer counts remain client
 * and model specific.
 *
 * Usage: cd plugin && composer tokens:measure
 */

require_once dirname( __DIR__ ) . '/tests/bootstrap.php';

use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Abilities\System\ToolProfile;
use Stonewright\WpMcp\Core\AbilityRegistry;

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

$full_rows      = stonewright_visible_rows( false );
$essential_rows = stonewright_visible_rows( true );
$full_tools     = stonewright_tool_documents( $full_rows );
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
	'method'                  => 'compact JSON UTF-8 bytes / 4; rounded up',
	'generated_with_php'      => PHP_VERSION,
	'all_registered_abilities' => count( AbilityRegistry::list() ),
	'surfaces'                => [
		'full'      => [
			'tool_count' => count( $full_tools ),
			'tools_list' => stonewright_token_metrics( $full_tools ),
		],
		'essential' => [
			'tool_count' => count( $essential_tools ),
			'tools_list' => stonewright_token_metrics( $essential_tools ),
		],
		'default_20' => stonewright_profile_metrics( 'elementor-design', 20, $full_rows ),
		'strict_12'  => stonewright_profile_metrics( 'low-tools', 12, $full_rows ),
	],
	'task_start'              => [
		'non_visual_compact' => stonewright_token_metrics( $nonvisual_bootstrap ),
		'visual_compact'     => stonewright_token_metrics( $visual_bootstrap ),
	],
];
$report['budgets'] = [
	'default_max_20_tools'          => $report['surfaces']['default_20']['tool_count'] <= 20,
	'strict_max_12_tools'           => $report['surfaces']['strict_12']['tool_count'] <= 12,
	'non_visual_task_start_lt_700'  => $report['task_start']['non_visual_compact']['estimated_tokens'] < 700,
	'visual_task_start_lt_1200'     => $report['task_start']['visual_compact']['estimated_tokens'] < 1200,
];

echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
exit( in_array( false, $report['budgets'], true ) ? 1 : 0 );
