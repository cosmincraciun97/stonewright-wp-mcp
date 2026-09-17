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

	public function test_verifies_template_query_post_type_and_pagination_from_widget(): void {
		$tree = [
			self::container_with_loop(
				'parent-a',
				'widget-a',
				'loop-grid',
				77,
				[
					'template_id'      => 77,
					'query_post_type'  => 'project',
					'posts_per_page'   => 6,
					'pagination_type'  => 'numbers',
					'columns'          => 3,
				]
			),
		];

		$result = LoopReadbackVerifier::verify(
			$tree,
			[
				'tree_hash'        => TreeHasher::hash( $tree ),
				'parent_id'        => 'parent-a',
				'widget_id'        => 'widget-a',
				'widget_type'      => 'loop-grid',
				'template_id'      => 77,
				'template_control' => 'template_id',
				'settings'         => [
					'template_id'     => 77,
					'query_post_type' => 'project',
					'posts_per_page'  => 6,
					'pagination_type' => 'numbers',
					'columns'         => 3,
				],
				'render_probe'     => static function ( array $widget ): bool {
					$settings = is_array( $widget['settings'] ?? null ) ? $widget['settings'] : [];
					return 77 === (int) ( $settings['template_id'] ?? 0 )
						&& 'project' === (string) ( $settings['query_post_type'] ?? '' )
						&& 'numbers' === (string) ( $settings['pagination_type'] ?? '' );
				},
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['verified'] );
		self::assertContains( 'render', $result['checks'] );
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

	/**
	 * @param array<string, mixed> $settings
	 */
	private static function container_with_loop(
		string $parent_id,
		string $widget_id,
		string $widget_type,
		int $template_id,
		array $settings = []
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
					'settings'   => $settings + [
						'template_id' => $template_id,
						'columns'     => 3,
					],
					'elements'   => [],
				],
			],
		];
	}
}
