<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;
use Stonewright\WpMcp\Support\ToolRouting;

/**
 * task-start names the typed tool for the patterns a task mentions, and only
 * while the compact payload still fits its budget.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\WorkflowPreflight
 * @covers \Stonewright\WpMcp\Support\ToolRouting
 */
final class TaskStartRoutingHintTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
	}

	public function test_compact_task_start_names_the_typed_tools_for_the_patterns_in_the_task(): void {
		$start = $this->start( 'Change the site tagline and add a Contact link to the main menu', 'wordpress', 'write' );

		$hint = $start['fast_path']['routing_hint'] ?? null;
		self::assertSame(
			[
				'options' => [ 'stonewright-settings-get', 'stonewright-settings-update' ],
				'menus'   => [ 'stonewright-menu-list', 'stonewright-menu-add-item', 'stonewright-menu-assign-location' ],
			],
			$hint['prefer'] ?? null
		);
		self::assertArrayNotHasKey( 'note', $hint, 'The compact task-start hint carries the tool names only.' );
		self::assertLessThan( TokenSurfaceBudgets::TASK_START_NON_VISUAL_MAX_TOKENS, $this->tokens( $start ) );
	}

	public function test_a_task_without_a_common_pattern_carries_no_hint(): void {
		$start = $this->start( 'Update an existing post title and excerpt.', 'wordpress', 'write' );

		self::assertArrayNotHasKey( 'routing_hint', $start['fast_path'] );
	}

	public function test_full_task_start_carries_the_same_hint(): void {
		$start = $this->start( 'Rename the custom fields on every product', 'wordpress', 'write', 'full' );

		self::assertSame(
			[ 'post_meta' => [ 'stonewright-content-update-post', 'stonewright-content-bulk-upsert-posts' ] ],
			$start['fast_path']['routing_hint']['prefer'] ?? null
		);
	}

	public function test_a_disabled_typed_tool_is_not_named(): void {
		$GLOBALS['stonewright_test_options']['stonewright_disabled_abilities'] = [ 'stonewright/menu-add-item' ];

		$start = $this->start( 'Add a Contact link to the main menu', 'wordpress', 'write' );

		self::assertSame(
			[ 'menus' => [ 'stonewright-menu-list', 'stonewright-menu-assign-location' ] ],
			$start['fast_path']['routing_hint']['prefer'] ?? null
		);
	}

	public function test_the_hint_is_dropped_before_it_could_push_a_compact_payload_past_its_budget(): void {
		// A visual Elementor task already fills most of the compact budget.
		$visual = 'Build a landing page from a screenshot';
		$base   = $this->start( $visual, 'elementor', 'write' );
		self::assertArrayNotHasKey( 'routing_hint', $base['fast_path'] );
		$base_bytes = strlen( (string) wp_json_encode( $base ) );

		$task    = $visual . ' with a new main menu, a new tagline, custom fields and Elementor data';
		$matches = ToolRouting::for_task( $task );
		self::assertCount( 4, $matches );
		$hint_bytes = strlen( (string) wp_json_encode( ToolRouting::hint( $matches, false ) ) );
		// Precondition: this task is close enough to the 3600 byte compact cap that the hint cannot fit.
		self::assertGreaterThanOrEqual( 3600, $base_bytes + $hint_bytes );

		$start = $this->start( $task, 'elementor', 'write' );

		self::assertArrayNotHasKey( 'routing_hint', $start['fast_path'] );
		self::assertLessThan( 3600, strlen( (string) wp_json_encode( $start ) ) );
		// No contract field was trimmed to make room.
		self::assertSame( $base['context']['visual_quality_contract'], $start['context']['visual_quality_contract'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function start( string $task, string $surface, string $intent, string $mode = 'compact' ): array {
		$start = ( new TaskStart() )->execute(
			[
				'task'         => $task,
				'surface'      => $surface,
				'intent'       => $intent,
				'responseMode' => $mode,
			]
		);
		self::assertIsArray( $start );

		return $start;
	}

	/**
	 * @param array<string, mixed> $start
	 */
	private function tokens( array $start ): int {
		return (int) ceil( strlen( (string) wp_json_encode( $start ) ) / 4 );
	}
}
