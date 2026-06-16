<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;

/**
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry
 */
final class AbilityRegistryEssentialModeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities' => [],
			'stonewright_essential_tools_mode' => false,
			'stonewright_essential_extra_abilities' => [],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_essential_mode_is_default_when_option_is_unset(): void {
		unset( $GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] );

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/workflow-preflight', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/wp-cli-batch-run', $names );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $names );
		self::assertNotContains( 'stonewright/sandbox-write', $names );
		self::assertLessThan( 60, count( $names ) );
	}

	public function test_essential_mode_filters_to_compact_fast_path(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/workflow-preflight', $names );
		self::assertContains( 'stonewright/tool-profile', $names );
		self::assertContains( 'stonewright/elementor-v3-build-page-from-spec', $names );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $names );
		self::assertContains( 'stonewright/content-bulk-upsert-posts', $names );
		self::assertContains( 'stonewright/wp-cli-run', $names );
		self::assertNotContains( 'stonewright/sandbox-write', $names );
		self::assertLessThan( 60, count( $names ) );
	}

	public function test_essential_mode_keeps_explicit_extras_visible(): void {
		$GLOBALS['stonewright_test_options']['stonewright_essential_tools_mode'] = true;
		$GLOBALS['stonewright_test_options']['stonewright_essential_extra_abilities'] = [ 'stonewright/sandbox-write' ];

		$names = array_column( AbilityRegistry::enabled_abilities(), 'name' );

		self::assertContains( 'stonewright/sandbox-write', $names );
	}
}
