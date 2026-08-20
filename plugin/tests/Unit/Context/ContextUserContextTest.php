<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ContextBootstrap;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Design\Direction\DesignDirectionService;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * @covers \Stonewright\WpMcp\Context\ContextBuilder
 * @covers \Stonewright\WpMcp\Context\UserContext
 */
final class ContextUserContextTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		IncidentStore::reset_for_tests();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_memory_enabled'              => false,
			'stonewright_custom_instructions_enabled' => true,
			'stonewright_custom_instructions'         => 'Always use native widgets.',
			'stonewright_user_context_enabled'        => true,
			'stonewright_user_context'                => 'Ship only in-season produce.',
		];
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['wpdb'] = $this->make_wpdb();
	}

	protected function tearDown(): void {
		IncidentStore::reset_for_tests();
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		if ( is_object( $this->original_wpdb ) && property_exists( $this->original_wpdb, 'direction_rows' ) ) {
			$this->original_wpdb->direction_rows = [];
		}
	}

	public function test_custom_instructions_prepend_enabled_user_context(): void {
		$built = ContextBuilder::build( 'Inspect plugins', 'wordpress', 'read' );

		self::assertTrue( $built['user_context']['enabled'] );
		self::assertSame( 'Ship only in-season produce.', $built['user_context']['text'] );
		self::assertStringStartsWith( 'Ship only in-season produce.', $built['custom_instructions']['text'] );
		self::assertStringContainsString( 'Always use native widgets.', $built['custom_instructions']['text'] );
	}

	public function test_disabled_user_context_is_omitted(): void {
		$GLOBALS['stonewright_test_options']['stonewright_user_context_enabled'] = false;

		$built = ContextBuilder::build( 'Inspect plugins', 'wordpress', 'read' );

		self::assertFalse( $built['user_context']['enabled'] );
		self::assertSame( '', $built['user_context']['text'] );
		self::assertSame( 'Always use native widgets.', $built['custom_instructions']['text'] );
	}

	public function test_bootstrap_and_task_start_carry_user_context(): void {
		$bootstrap = ( new ContextBootstrap() )->execute(
			[
				'task'    => 'Inspect plugins',
				'surface' => 'wordpress',
				'intent'  => 'read',
			]
		);
		$start     = ( new TaskStart() )->execute(
			[
				'task'         => 'Inspect plugins',
				'surface'      => 'wordpress',
				'intent'       => 'read',
				'responseMode' => 'full',
			]
		);

		self::assertIsArray( $bootstrap );
		self::assertIsArray( $start );
		self::assertStringStartsWith( 'Ship only in-season produce.', (string) $bootstrap['custom_instructions']['text'] );
		$context = is_array( $start['context'] ?? null ) ? $start['context'] : [];
		$custom  = is_array( $context['custom_instructions'] ?? null ) ? $context['custom_instructions'] : [];
		self::assertStringStartsWith( 'Ship only in-season produce.', (string) ( $custom['text'] ?? '' ) );
	}

	public function test_compact_task_start_includes_custom_instruction_text(): void {
		$start = ( new TaskStart() )->execute(
			[
				'task'    => 'Inspect plugins',
				'surface' => 'wordpress',
				'intent'  => 'read',
			]
		);

		self::assertIsArray( $start );
		self::assertSame( 'compact', $start['response_mode'] ?? null );
		$context = is_array( $start['context'] ?? null ) ? $start['context'] : [];
		$custom  = is_array( $context['custom_instructions'] ?? null ) ? $context['custom_instructions'] : [];
		self::assertTrue( (bool) ( $custom['enabled'] ?? false ) );
		self::assertArrayHasKey( 'text', $custom, 'Compact custom_instructions must carry text, not a presence flag only.' );
		self::assertNotSame( [ 'enabled' => true ], $custom );
		self::assertStringStartsWith( 'Ship only in-season produce.', (string) ( $custom['text'] ?? '' ) );
		self::assertLessThanOrEqual( 400, mb_strlen( (string) $custom['text'] ) );
	}

	public function test_compact_task_start_includes_design_direction_ref_when_active(): void {
		$wpdb = $this->original_wpdb;
		if ( ! is_object( $wpdb ) || ! property_exists( $wpdb, 'direction_rows' ) ) {
			self::markTestSkipped( 'The test wpdb double does not expose direction_rows.' );
		}

		$GLOBALS['wpdb'] = $wpdb;
		$contract        = [
			'identity'  => [ 'name' => 'Quarry' ],
			'readiness' => [ 'ready' => true, 'sync_ready' => false, 'issues' => [] ],
		];
		$hash            = DesignDirectionService::hash( $contract );
		$wpdb->direction_rows = [
			73 => [
				'id'               => 73,
				'slug'             => 'quarry',
				'status'           => 'ready',
				'contract_json'    => (string) wp_json_encode( $contract ),
				'contract_hash'    => $hash,
				'source_type'      => 'manual',
				'source_refs_json' => '[]',
				'revision'         => 3,
				'created_at'       => '2026-07-01 00:00:00',
				'updated_at'       => '2026-07-02 00:00:00',
			],
		];
		$GLOBALS['stonewright_test_options'][ DesignDirectionService::ACTIVE_OPTION ] = 73;

		$built = ContextBuilder::build( 'Inspect plugins', 'wordpress', 'read' );
		self::assertArrayHasKey( 'design_direction_ref', $built );
		self::assertTrue( (bool) $built['design_direction_ref']['active'] );
		self::assertSame( 73, $built['design_direction_ref']['id'] );
		self::assertSame( 'quarry', $built['design_direction_ref']['slug'] );
		self::assertSame( 'Quarry', $built['design_direction_ref']['name'] );
		self::assertSame( $hash, $built['design_direction_ref']['contract_hash'] );
		self::assertSame( 'stonewright-design-direction-brief', $built['design_direction_ref']['tool'] );

		$start = ( new TaskStart() )->execute(
			[
				'task'    => 'Inspect plugins',
				'surface' => 'wordpress',
				'intent'  => 'read',
			]
		);
		self::assertIsArray( $start );
		$ref = is_array( $start['context']['design_direction_ref'] ?? null )
			? $start['context']['design_direction_ref']
			: [];
		self::assertSame(
			[
				'active'        => true,
				'id'            => 73,
				'slug'          => 'quarry',
				'name'          => 'Quarry',
				'contract_hash' => $hash,
				'tool'          => 'stonewright-design-direction-brief',
			],
			$ref
		);
		self::assertArrayNotHasKey( 'contract', $ref );
		self::assertContains( 'read_design_direction_brief', $start['context']['required_actions'] ?? [] );
	}

	public function test_visual_quality_contract_includes_anti_slop_floor(): void {
		$built = ContextBuilder::build( 'Build a landing page from a screenshot', 'elementor', 'write' );

		self::assertArrayHasKey( 'anti_slop_floor', $built['visual_quality_contract'] );
		$ids = array_column( $built['visual_quality_contract']['anti_slop_floor'], 'id' );
		self::assertContains( 'contrast.text', $ids );
		self::assertContains( 'overflow.horizontal', $ids );
	}

	public function test_compact_task_start_includes_anti_slop_floor_for_visual_profiles(): void {
		$task    = 'Build a landing page from a screenshot';
		$built   = ContextBuilder::build( $task, 'elementor', 'write' );
		$expected = $built['visual_quality_contract']['anti_slop_floor'];

		$start = ( new TaskStart() )->execute(
			[
				'task'    => $task,
				'surface' => 'elementor',
				'intent'  => 'write',
			]
		);

		self::assertIsArray( $start );
		self::assertTrue( (bool) ( $start['fast_path']['task_profile']['visual'] ?? false ) );
		$contract = is_array( $start['context']['visual_quality_contract'] ?? null )
			? $start['context']['visual_quality_contract']
			: [];
		self::assertArrayHasKey( 'anti_slop_floor', $contract );
		$floor = $contract['anti_slop_floor'];
		self::assertIsArray( $floor );
		self::assertCount( count( $expected ), $floor );
		foreach ( $floor as $index => $rule ) {
			self::assertIsArray( $rule );
			self::assertArrayHasKey( 'id', $rule );
			self::assertArrayHasKey( 'summary', $rule );
			self::assertArrayHasKey( 'severity', $rule );
			self::assertArrayHasKey( 'guidance', $rule );
			self::assertSame( $expected[ $index ], $rule );
		}
	}

	public function test_context_bootstrap_output_schema_lists_design_direction_ref(): void {
		$schema = ( new ContextBootstrap() )->output_schema();

		self::assertArrayHasKey( 'properties', $schema );
		self::assertArrayHasKey( 'design_direction_ref', $schema['properties'] );
		self::assertSame( 'object', $schema['properties']['design_direction_ref']['type'] ?? null );
	}

	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';

			public function get_var( string $query ): string {
				return 'table_exists';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}
		};
	}
}
