<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Renderers\ElementorV4SpecRenderer;
use Stonewright\WpMcp\Renderers\GutenbergSpecRenderer;

/**
 * Validates the contract that legacy static renderers reject invalid specs
 * and surface unsupported-node diagnostics correctly.
 *
 * Note: ElementorV3SpecRenderer was removed. Elementor V3 rendering is now
 * handled by \Stonewright\WpMcp\Elementor\Renderer (stable IDs, Pro-gating),
 * tested in ElementorRendererTest and ElementorWriterTest.
 *
 * @covers \Stonewright\WpMcp\Renderers\GutenbergSpecRenderer
 * @covers \Stonewright\WpMcp\Renderers\ElementorV4SpecRenderer
 */
final class RendererValidationTest extends TestCase {

	public function test_renderers_reject_invalid_specs(): void {
		$invalid = [ 'page' => [], 'sections' => [] ];

		foreach ( [ GutenbergSpecRenderer::class, ElementorV4SpecRenderer::class ] as $renderer ) {
			$result = $renderer::render( $invalid );

			$this->assertInstanceOf( \WP_Error::class, $result, $renderer );
			$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code(), $renderer );
		}
	}

	public function test_renderers_report_unsupported_nodes(): void {
		$spec = [
			'page'     => [ 'title' => 'Contract page' ],
			'sections' => [
				[
					'id'     => 'hero',
					'blocks' => [
						[
							'type' => 'heading',
							'text' => 'Hello',
						],
						[
							'type'  => 'list',
							'items' => [ 'One' ],
							'text' => 'Nope',
						],
					],
				],
			],
		];

		foreach ( [ GutenbergSpecRenderer::class, ElementorV4SpecRenderer::class ] as $renderer ) {
			$diagnostics = [];
			$result      = $renderer::render( $spec, $diagnostics );

			$this->assertNotInstanceOf( \WP_Error::class, $result, $renderer );
			$this->assertNotEmpty( $diagnostics, $renderer );
			$this->assertContains( 'unsupported_node', array_column( $diagnostics, 'code' ), $renderer );
			$this->assertContains( 'list', array_column( $diagnostics, 'type' ), $renderer );
		}
	}
}
