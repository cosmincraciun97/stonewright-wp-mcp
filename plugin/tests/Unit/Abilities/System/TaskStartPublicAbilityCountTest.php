<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\IncidentStore;

require_once dirname( __DIR__, 3 ) . '/Support/abilities-registry-double.php';

/**
 * task-start reports the abilities WordPress registered, not the classes the plugin ships.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\WorkflowPreflight
 */
final class TaskStartPublicAbilityCountTest extends TestCase {

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_registered_abilities'] = null;
		$GLOBALS['stonewright_test_options']              = [
			'stonewright_essential_tools_mode' => true,
			'stonewright_disabled_abilities'   => [],
		];
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		$GLOBALS['stonewright_test_transients']           = [];
		$GLOBALS['stonewright_test_registered_abilities'] = null;
		$GLOBALS['stonewright_test_options']              = [];
	}

	public function test_public_ability_count_leaves_out_abilities_the_abilities_api_did_not_register(): void {
		$public = array_column( AbilityRegistry::enabled_abilities(), 'name' );
		self::assertNotEmpty( $public );
		self::assertSame( count( $public ), $this->site( $this->start() )['public_ability_count'] );

		$GLOBALS['stonewright_test_registered_abilities'] = array_values( array_diff( $public, array_slice( $public, 0, 2 ) ) );

		$site = $this->site( $this->start() );

		self::assertSame( count( $public ) - 2, $site['public_ability_count'] );
		self::assertSame( count( AbilityRegistry::list() ), $site['ability_count'], 'ability_count still reports the shipped classes.' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function start(): array {
		$start = ( new TaskStart() )->execute(
			[
				'task'         => 'Update an existing post title and excerpt.',
				'surface'      => 'wordpress',
				'intent'       => 'write',
				'responseMode' => 'full',
			]
		);
		self::assertIsArray( $start );

		return $start;
	}

	/**
	 * @param array<string, mixed> $start
	 * @return array<string, mixed>
	 */
	private function site( array $start ): array {
		self::assertIsArray( $start['site'] ?? null );

		return $start['site'];
	}
}
