<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Core\AgentInstructions;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\TokenSurfaceBudgets;

/**
 * Every agent is told when to record a correction: on connect and in the
 * default compact task-start, not only in the full response.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\WorkflowPreflight
 * @covers \Stonewright\WpMcp\Core\AgentInstructions
 */
final class TaskStartLearningTriggerTest extends TestCase {

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

	public function test_connect_instructions_tell_the_agent_to_record_a_correction(): void {
		$summary = AgentInstructions::server_bootstrap_summary();

		self::assertStringContainsString( 'stonewright-learning-record', $summary );
		self::assertStringContainsString( 'corrects', $summary );
		self::assertStringContainsString( 'verified:true', $summary );
	}

	public function test_default_compact_task_start_carries_the_trigger(): void {
		foreach (
			[
				[ 'Update an existing post title and excerpt.', 'wordpress' ],
				[ 'Implement a responsive Elementor landing-page hero from a supplied design image.', 'elementor' ],
			] as [ $task, $surface ]
		) {
			// No responseMode: the default is compact.
			$start = ( new TaskStart() )->execute( [ 'task' => $task, 'surface' => $surface, 'intent' => 'write' ] );

			self::assertIsArray( $start );
			self::assertSame( 'compact', $start['response_mode'] );
			$line = (string) ( $start['context']['learning'] ?? '' );
			self::assertStringContainsString( 'stonewright-learning-record', $line, $surface );
			self::assertStringContainsString( 'verified:true', $line, $surface );
		}
	}

	public function test_the_trigger_leaves_the_compact_visual_contract_floor_as_it_was(): void {
		$start = ( new TaskStart() )->execute(
			[
				'task'    => 'Implement a responsive Elementor landing-page hero from a supplied design image.',
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $start );
		// The compact payload keeps two of the nine floor rules inside its byte cap; the trigger takes none of them.
		$floor = $start['context']['visual_quality_contract']['anti_slop_floor'] ?? [];
		self::assertSame( [ 'contrast.text', 'contrast.focus' ], array_column( $floor, 'id' ) );
		$bytes = strlen( (string) wp_json_encode( $start ) );
		self::assertLessThan( 3750, $bytes );
		self::assertLessThan( TokenSurfaceBudgets::TASK_START_VISUAL_MAX_TOKENS, (int) ceil( $bytes / 4 ) );
	}
}
