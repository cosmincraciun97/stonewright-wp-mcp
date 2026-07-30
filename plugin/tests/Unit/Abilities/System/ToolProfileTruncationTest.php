<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ToolProfile;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\ToolProfile
 */
final class ToolProfileTruncationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_disabled_abilities'        => [],
			'stonewright_essential_tools_mode'      => true,
			'stonewright_essential_extra_abilities' => [],
			'stonewright_last_tool_profile'         => '',
			'stonewright_mcp_surface'               => 'essential',
		];
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_user_caps']      = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
	}

	public function test_resolve_names_dropped_tools_when_over_cap(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'    => 'resolve',
				'profile'   => 'elementor-design',
				'max_tools' => 12,
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['degraded'] );
		self::assertCount( 12, $result['tools'] );
		self::assertNotEmpty( $result['truncated_tools'] );
		self::assertNotEmpty( $result['truncation_hint'] );
		self::assertSame(
			$result['profile_tool_count'],
			count( $result['recommended_tools'] ) + count( $result['truncated_tools'] )
		);
		self::assertSame( [], array_intersect( $result['recommended_tools'], $result['truncated_tools'] ) );
	}

	public function test_resolve_under_cap_is_not_degraded(): void {
		$result = ( new ToolProfile() )->execute(
			[
				'action'    => 'resolve',
				'profile'   => 'elementor-design',
				'max_tools' => 200,
			]
		);

		self::assertIsArray( $result );
		self::assertFalse( $result['degraded'] );
		self::assertSame( [], $result['truncated_tools'] );
		self::assertSame( '', $result['truncation_hint'] );
	}
}
