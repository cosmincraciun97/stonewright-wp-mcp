<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Write;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Write\V3MutationCompiler;

/**
 * @covers \Stonewright\WpMcp\Elementor\Write\V3MutationCompiler
 */
final class V3MutationCompilerTest extends TestCase {

	public function test_compiles_widget_under_existing_parent_without_writing(): void {
		$tree = [ self::container( 'parent-a' ) ];

		$result = ( new V3MutationCompiler() )->compile(
			$tree,
			[
				[
					'action'      => 'add_widget',
					'op_id'       => 'headline',
					'parent_id'   => 'parent-a',
					'widget_type' => 'heading',
					'settings'    => [ 'title' => 'Safe title' ],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertSame( 1, $result['applied'] );
		self::assertSame( 0, $result['failed'] );
		self::assertArrayHasKey( 'headline', $result['refs'] );
		self::assertSame( 'heading', $result['tree'][0]['elements'][0]['widgetType'] );
		self::assertSame( 'Safe title', $result['tree'][0]['elements'][0]['settings']['title'] );
	}

	public function test_missing_parent_fails_without_root_fallback(): void {
		$result = ( new V3MutationCompiler() )->compile(
			[ self::container( 'parent-a' ) ],
			[
				[
					'action'      => 'add_widget',
					'parent_id'   => 'missing',
					'widget_type' => 'heading',
					'settings'    => [ 'title' => 'Safe title' ],
				],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_parent_not_found', $result->get_error_code() );
	}

	/** @return array<string, mixed> */
	private static function container( string $id ): array {
		return [
			'id'       => $id,
			'elType'   => 'container',
			'settings' => [ 'container_type' => 'flex' ],
			'elements' => [],
		];
	}
}
