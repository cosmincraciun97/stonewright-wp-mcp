<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Gutenberg;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Renderer;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;

/**
 * `card` and `column` are documented design spec blocks that hold nested
 * blocks. Both Gutenberg renderers keep that content instead of dropping it
 * with an unsupported_node diagnostic.
 *
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer
 * @covers \Stonewright\WpMcp\Renderers\GutenbergSpecRenderer
 */
final class RendererCardColumnTest extends TestCase {

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @return array<string, mixed>
	 */
	private static function spec( array $blocks ): array {
		return [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Cards' ],
			'sections' => [ [ 'id' => 's1', 'name' => 'Cards', 'blocks' => $blocks ] ],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private static function texts( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			$text = trim( strip_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
			if ( '' !== $text ) {
				$out[] = $text;
			}
			$out = array_merge( $out, self::texts( (array) ( $block['innerBlocks'] ?? [] ) ) );
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 * @return list<string>
	 */
	private static function names( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			$out[] = (string) $block['blockName'];
			$out   = array_merge( $out, self::names( (array) ( $block['innerBlocks'] ?? [] ) ) );
		}
		return $out;
	}

	/**
	 * @param array<int, array<string, mixed>> $blocks
	 */
	private static function serialized( array $blocks ): string {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}

	// ---------------------------------------------------------------------
	// Gutenberg\Renderer (gutenberg-apply-to-post, gutenberg-render-blocks)
	// ---------------------------------------------------------------------

	public function test_renderer_keeps_the_content_of_a_card(): void {
		$diagnostics = [];
		$blocks      = Renderer::render(
			self::spec( [ [ 'type' => 'card', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'inner' ] ] ] ] ),
			$diagnostics
		);

		self::assertSame( [], $diagnostics );
		self::assertSame( [ 'inner' ], self::texts( $blocks ) );
		self::assertSame( [ 'core/group', 'core/group', 'core/paragraph' ], self::names( $blocks ) );
		self::assertStringContainsString( 'inner', self::serialized( $blocks ) );
	}

	public function test_renderer_keeps_the_content_of_a_column_inside_a_row(): void {
		$diagnostics = [];
		$blocks      = Renderer::render(
			self::spec(
				[
					[
						'type'   => 'row',
						'blocks' => [
							[ 'type' => 'column', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'c' ] ] ],
							[ 'type' => 'column', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'd' ] ] ],
						],
					],
				]
			),
			$diagnostics
		);

		self::assertSame( [], $diagnostics );
		self::assertSame( [ 'c', 'd' ], self::texts( $blocks ) );
	}

	public function test_renderer_keeps_a_column_that_sits_inside_a_card(): void {
		$diagnostics = [];
		$blocks      = Renderer::render(
			self::spec(
				[
					[
						'type'   => 'card',
						'blocks' => [
							[ 'type' => 'heading', 'level' => 3, 'text' => 'Title' ],
							[ 'type' => 'column', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'body' ] ] ],
						],
					],
				]
			),
			$diagnostics
		);

		self::assertSame( [], $diagnostics );
		self::assertSame( [ 'Title', 'body' ], self::texts( $blocks ) );
	}

	// ---------------------------------------------------------------------
	// Renderers\GutenbergSpecRenderer (design-spec-to-gutenberg, blueprints)
	// ---------------------------------------------------------------------

	public function test_spec_renderer_keeps_the_content_of_a_card(): void {
		$diagnostics = [];
		$blocks      = GutenbergSpecRenderer::render(
			self::spec( [ [ 'type' => 'card', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'inner' ] ] ] ] ),
			$diagnostics
		);

		self::assertIsArray( $blocks );
		self::assertSame( [], $diagnostics );
		self::assertSame( [ 'inner' ], self::texts( $blocks ) );
	}

	public function test_spec_renderer_wraps_a_card_in_a_row_into_a_column(): void {
		$diagnostics = [];
		$blocks      = GutenbergSpecRenderer::render(
			self::spec(
				[
					[
						'type'   => 'row',
						'blocks' => [
							[ 'type' => 'card', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'one' ] ] ],
							[ 'type' => 'card', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'two' ] ] ],
						],
					],
				]
			),
			$diagnostics
		);

		self::assertIsArray( $blocks );
		self::assertSame( [], $diagnostics );
		self::assertSame( [ 'one', 'two' ], self::texts( $blocks ) );
		$row = $blocks[0]['innerBlocks'][0];
		self::assertSame( 'core/columns', $row['blockName'] );
		self::assertSame( [ 'core/column', 'core/column' ], array_column( $row['innerBlocks'], 'blockName' ) );
	}

	public function test_spec_renderer_does_not_leave_a_bare_column_inside_a_card(): void {
		$diagnostics = [];
		$blocks      = GutenbergSpecRenderer::render(
			self::spec(
				[
					[
						'type'   => 'card',
						'blocks' => [ [ 'type' => 'column', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'body' ] ] ] ],
					],
				]
			),
			$diagnostics
		);

		self::assertIsArray( $blocks );
		self::assertSame( [], $diagnostics );
		self::assertSame( [ 'body' ], self::texts( $blocks ) );
		self::assertNotContains( 'core/column', self::names( $blocks ) );
	}

	public function test_spec_renderer_keeps_a_column_outside_a_row_as_a_group(): void {
		$diagnostics = [];
		$blocks      = GutenbergSpecRenderer::render(
			self::spec( [ [ 'type' => 'column', 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'c' ] ] ] ] ),
			$diagnostics
		);

		self::assertIsArray( $blocks );
		self::assertSame( [], $diagnostics );
		self::assertSame( [ 'c' ], self::texts( $blocks ) );
	}
}
