<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Gutenberg\Renderer;

/**
 * Asserts that rendering the same spec twice produces identical output
 * (including nested blocks).
 *
 * @covers \Stonewright\WpMcp\Gutenberg\Renderer
 */
final class GutenbergRendererDeterminismTest extends TestCase {

	public function test_flat_spec_is_deterministic(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Determinism Test' ],
			'sections' => [
				[
					'id'     => 's0',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Title', 'level' => 2 ],
						[ 'type' => 'paragraph', 'text' => 'Body copy.' ],
						[ 'type' => 'spacer', 'height' => 40 ],
						[ 'type' => 'separator' ],
						[ 'type' => 'button', 'text' => 'CTA', 'url' => 'https://example.com' ],
					],
				],
			],
		];

		$diag1 = [];
		$diag2 = [];
		$out1  = Renderer::render( $spec, $diag1 );
		$out2  = Renderer::render( $spec, $diag2 );

		$this->assertSame( $out1, $out2, 'Renderer must be deterministic: same spec → same output.' );
		$this->assertSame( $diag1, $diag2 );
	}

	public function test_nested_columns_is_deterministic(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Nested Columns' ],
			'sections' => [
				[
					'id'     => 's0',
					'blocks' => [
						[
							'type'    => 'columns',
							'columns' => [
								[
									'blocks' => [
										[ 'type' => 'heading', 'text' => 'Col 1', 'level' => 3 ],
									],
								],
								[
									'blocks' => [
										[ 'type' => 'paragraph', 'text' => 'Col 2 body.' ],
									],
								],
							],
						],
					],
				],
			],
		];

		$diag1 = [];
		$diag2 = [];
		$out1  = Renderer::render( $spec, $diag1 );
		$out2  = Renderer::render( $spec, $diag2 );

		$this->assertSame( $out1, $out2 );
		$this->assertSame( $diag1, $diag2 );
	}

	public function test_multi_section_is_deterministic(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Multi Section' ],
			'sections' => [
				[
					'id'     => 's0',
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Section 1', 'level' => 2 ],
						[ 'type' => 'list', 'items' => [ 'A', 'B', 'C' ] ],
					],
				],
				[
					'id'     => 's1',
					'blocks' => [
						[ 'type' => 'quote', 'text' => 'A wise saying.', 'citation' => 'Aristotle' ],
						[ 'type' => 'image', 'url' => 'https://example.com/img.jpg', 'alt' => 'Alt' ],
					],
				],
			],
		];

		$d1 = [];
		$d2 = [];
		$this->assertSame( Renderer::render( $spec, $d1 ), Renderer::render( $spec, $d2 ) );
		$this->assertSame( $d1, $d2 );
	}
}
