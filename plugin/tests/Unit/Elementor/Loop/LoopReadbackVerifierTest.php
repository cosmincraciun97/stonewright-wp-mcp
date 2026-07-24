<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Loop;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Loop\LoopReadbackVerifier;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;

/**
 * @covers \Stonewright\WpMcp\Elementor\Loop\LoopReadbackVerifier
 */
final class LoopReadbackVerifierTest extends TestCase {

	public function test_verifies_exact_loop_linkage_and_settings(): void {
		$tree = [ self::container_with_loop( 'parent-a', 'widget-a', 'loop-grid', 77 ) ];

		$result = LoopReadbackVerifier::verify(
			$tree,
			[
				'tree_hash'       => TreeHasher::hash( $tree ),
				'parent_id'       => 'parent-a',
				'widget_id'       => 'widget-a',
				'widget_type'     => 'loop-grid',
				'template_id'     => 77,
				'template_control'=> 'template_id',
				'settings'        => [ 'columns' => 3 ],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['verified'] );
		self::assertSame( [ 'hash', 'parent', 'widget_type', 'template', 'settings' ], $result['checks'] );
	}

	/**
	 * @dataProvider mismatch_provider
	 * @param array<string, mixed> $changes
	 */
	public function test_reports_exact_failed_invariant( array $changes, string $failed_invariant ): void {
		$tree     = [ self::container_with_loop( 'parent-a', 'widget-a', 'loop-grid', 77 ) ];
		$expected = array_merge(
			[
				'tree_hash'        => TreeHasher::hash( $tree ),
				'parent_id'        => 'parent-a',
				'widget_id'        => 'widget-a',
				'widget_type'      => 'loop-grid',
				'template_id'      => 77,
				'template_control' => 'template_id',
				'settings'         => [ 'columns' => 3 ],
			],
			$changes
		);

		$result = LoopReadbackVerifier::verify( $tree, $expected );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_loop_readback_mismatch', $result->get_error_code() );
		self::assertSame( $failed_invariant, $result->get_error_data()['failed_invariant'] );
	}

	/** @return array<string, array{0:array<string,mixed>,1:string}> */
	public static function mismatch_provider(): array {
		return [
			'hash'        => [ [ 'tree_hash' => str_repeat( '0', 64 ) ], 'hash' ],
			'parent'      => [ [ 'parent_id' => 'other-parent' ], 'parent' ],
			'widget type' => [ [ 'widget_type' => 'loop-carousel' ], 'widget_type' ],
			'template'    => [ [ 'template_id' => 99 ], 'template' ],
			'setting'     => [ [ 'settings' => [ 'columns' => 4 ] ], 'settings' ],
		];
	}

	/** @return array<string, mixed> */
	private static function container_with_loop(
		string $parent_id,
		string $widget_id,
		string $widget_type,
		int $template_id
	): array {
		return [
			'id'       => $parent_id,
			'elType'   => 'container',
			'settings' => [ 'container_type' => 'flex' ],
			'elements' => [
				[
					'id'         => $widget_id,
					'elType'     => 'widget',
					'widgetType' => $widget_type,
					'settings'   => [
						'template_id' => $template_id,
						'columns'     => 3,
					],
					'elements'   => [],
				],
			],
		];
	}
}
