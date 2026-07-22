<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\ArchitectureRouter;

/**
 * @covers \Stonewright\WpMcp\Elementor\ArchitectureRouter
 */
final class ArchitectureRouterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_elementor_version'] = static fn (): string => '4.1.0';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters'] = [];
	}

	public function test_ambiguous_block_instructs_agent_to_pass_post_id(): void {
		$out = ArchitectureRouter::describe( 0, 'auto' );

		self::assertTrue( $out['write_blocked'] );
		self::assertSame( 'none', $out['write_target'] );
		self::assertStringContainsString( 'post_id', $out['reason'] );
		self::assertStringContainsString( 'task-start', $out['reason'] );
	}

	public function test_explicit_v3_request_stays_unblocked_on_v4_runtime(): void {
		$out = ArchitectureRouter::describe( 0, 'v3' );

		self::assertFalse( $out['write_blocked'] );
		self::assertSame( 'v3', $out['write_target'] );
	}
}
