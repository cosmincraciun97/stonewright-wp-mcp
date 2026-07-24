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
		$GLOBALS['stonewright_test_posts'][9049] = (object) [
			'ID'   => 9049,
			'meta' => [
				'_elementor_data' => wp_json_encode(
					[
						[
							'id'       => 'legacy-root',
							'elType'   => 'container',
							'settings' => [ 'container_type' => 'flex' ],
							'elements' => [
								[ 'id' => 'atomic', 'elType' => 'widget', 'widgetType' => 'e-paragraph', 'settings' => [], 'elements' => [] ],
							],
						],
					]
				),
			],
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		$GLOBALS['stonewright_test_posts']   = [];
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

	public function test_router_marks_document_not_inspected_without_post_id(): void {
		$out = ArchitectureRouter::describe( 0, 'v3' );

		self::assertFalse( $out['document_inspected'] );
		self::assertSame( 'not_inspected', $out['document_architecture'] );
		self::assertStringContainsString( 'post_id', (string) $out['reason'] );
	}

	public function test_router_names_repair_tools_when_blocked(): void {
		$out = ArchitectureRouter::describe( 0, 'auto' );

		self::assertTrue( $out['write_blocked'] );
		self::assertContains( 'stonewright/elementor-v3-repair-document', $out['repair_tools'] );
	}

	public function test_mixed_document_routes_only_surgical_v3_writes(): void {
		$out = ArchitectureRouter::describe( 9049, 'auto' );

		self::assertSame( 'mixed', $out['document_architecture'] );
		self::assertSame( 'v3-surgical', $out['write_target'] );
		self::assertFalse( $out['write_blocked'] );
		self::assertTrue( $out['surgical_v3_allowed'] );
		self::assertTrue( $out['high_level_write_blocked'] );
		self::assertContains( 'stonewright/elementor-document-health', $out['repair_tools'] );
		self::assertContains( 'stonewright/elementor-v3-batch-mutate', $out['repair_tools'] );
	}
}
