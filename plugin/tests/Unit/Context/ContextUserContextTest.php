<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\ContextBootstrap;
use Stonewright\WpMcp\Abilities\System\TaskStart;
use Stonewright\WpMcp\Context\ContextBuilder;
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

	public function test_visual_quality_contract_includes_anti_slop_floor(): void {
		$built = ContextBuilder::build( 'Build a landing page from a screenshot', 'elementor', 'write' );

		self::assertArrayHasKey( 'anti_slop_floor', $built['visual_quality_contract'] );
		$ids = array_column( $built['visual_quality_contract']['anti_slop_floor'], 'id' );
		self::assertContains( 'contrast.text', $ids );
		self::assertContains( 'overflow.horizontal', $ids );
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
