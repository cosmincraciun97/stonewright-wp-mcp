<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicRenderer;

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicRenderer
 */
final class AtomicRendererTest extends TestCase {

	public function test_renders_heading_with_typed_envelope_props(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'  => 'Heading',
				'props' => [ 'text' => 'Hello', 'level' => 2 ],
			]
		);

		$this->assertSame( 'widget', $out['elType'] );
		$this->assertSame( 'e-heading', $out['widgetType'] );
		$this->assertNotEmpty( $out['id'] );
		$this->assertSame( 'string', $out['settings']['title']['$$type'] );
		$this->assertSame( 'Hello', $out['settings']['title']['value'] );
		$this->assertSame( 'string', $out['settings']['tag']['$$type'] );
		$this->assertSame( 'h2', $out['settings']['tag']['value'] );
		$this->assertSame( [], $out['elements'] );
		$this->assertArrayNotHasKey( '__unsupported', $out );
	}

	public function test_renders_text_editor_paragraph_prop(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'  => 'TextEditor',
				'props' => [ 'text' => 'Body copy.' ],
			]
		);

		$this->assertSame( 'e-paragraph', $out['widgetType'] );
		$this->assertSame( 'widget', $out['elType'] );
		$this->assertSame( 'string', $out['settings']['paragraph']['$$type'] );
		$this->assertSame( 'Body copy.', $out['settings']['paragraph']['value'] );
	}

	public function test_renders_image_with_image_and_alt_envelopes(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'  => 'Image',
				'props' => [ 'url' => 'https://cdn.example/hero.jpg', 'alt' => 'Hero shot' ],
			]
		);

		$this->assertSame( 'e-image', $out['widgetType'] );
		$this->assertSame( 'image', $out['settings']['image']['$$type'] );
		$this->assertSame( 'https://cdn.example/hero.jpg', $out['settings']['image']['value']['src'] );
		$this->assertSame( 'string', $out['settings']['alt']['$$type'] );
		$this->assertSame( 'Hero shot', $out['settings']['alt']['value'] );
	}

	public function test_renders_button_with_text_and_link_envelopes(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'  => 'Button',
				'props' => [ 'text' => 'Sign up', 'link' => 'https://example.com/join' ],
			]
		);

		$this->assertSame( 'e-button', $out['widgetType'] );
		$this->assertSame( 'string', $out['settings']['text']['$$type'] );
		$this->assertSame( 'Sign up', $out['settings']['text']['value'] );
		$this->assertSame( 'link', $out['settings']['link']['$$type'] );
		$this->assertSame( 'https://example.com/join', $out['settings']['link']['value']['href'] );
	}

	public function test_renders_divider_and_icon_widget_types(): void {
		$divider = AtomicRenderer::render_node( [ 'type' => 'Divider', 'props' => [] ] );
		$this->assertSame( 'e-divider', $divider['widgetType'] );
		$this->assertSame( 'widget', $divider['elType'] );
		$this->assertSame( [], $divider['settings'] );

		$icon = AtomicRenderer::render_node(
			[
				'type'  => 'Icon',
				'props' => [ 'svg' => '<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>' ],
			]
		);
		$this->assertSame( 'e-svg', $icon['widgetType'] );
		$this->assertSame( 'svg', $icon['settings']['svg']['$$type'] );
		$this->assertStringContainsString( '<svg', $icon['settings']['svg']['value'] );
	}

	public function test_renders_section_as_container_with_children(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'     => 'Section',
				'props'    => [ 'direction' => 'row', 'gap' => '24px' ],
				'children' => [
					[ 'type' => 'Heading',    'props' => [ 'text' => 'A', 'level' => 1 ] ],
					[ 'type' => 'TextEditor', 'props' => [ 'text' => 'B' ] ],
				],
			]
		);

		$this->assertSame( 'container', $out['elType'] );
		$this->assertSame( 'e-flexbox', $out['widgetType'] );
		$this->assertCount( 2, $out['elements'] );
		$this->assertSame( 'e-heading', $out['elements'][0]['widgetType'] );
		$this->assertSame( 'e-paragraph', $out['elements'][1]['widgetType'] );
		$this->assertSame( 'string', $out['settings']['flex-direction']['$$type'] );
		$this->assertSame( 'row', $out['settings']['flex-direction']['value'] );
		$this->assertSame( 'size', $out['settings']['gap']['$$type'] );
		$this->assertSame( '24px', $out['settings']['gap']['value'] );
	}

	public function test_collapses_all_container_flavours_into_flexbox_container(): void {
		foreach ( [ 'Section', 'Column', 'Container' ] as $type ) {
			$out = AtomicRenderer::render_node( [ 'type' => $type, 'props' => [], 'children' => [] ] );
			$this->assertSame( 'container', $out['elType'], $type );
			$this->assertSame( 'e-flexbox', $out['widgetType'], $type );
		}
	}

	public function test_recurses_into_deeply_nested_children(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'     => 'Container',
				'children' => [
					[
						'type'     => 'Column',
						'children' => [
							[
								'type'  => 'Heading',
								'props' => [ 'text' => 'Deep', 'level' => 3 ],
							],
						],
					],
				],
			]
		);

		$this->assertSame( 'e-flexbox', $out['widgetType'] );
		$inner_col = $out['elements'][0];
		$this->assertSame( 'e-flexbox', $inner_col['widgetType'] );
		$leaf = $inner_col['elements'][0];
		$this->assertSame( 'e-heading', $leaf['widgetType'] );
		$this->assertSame( 'h3', $leaf['settings']['tag']['value'] );
		$this->assertSame( 'Deep', $leaf['settings']['title']['value'] );
	}

	public function test_unknown_node_type_emits_unsupported_marker(): void {
		$out = AtomicRenderer::render_node( [ 'type' => 'UnknownXYZ', 'props' => [] ] );

		$this->assertArrayHasKey( '__unsupported', $out );
		$this->assertSame( 'UnknownXYZ', $out['__unsupported'] );
		// Fallback shape is still a usable element so the tree doesn't break.
		$this->assertSame( 'widget', $out['elType'] );
		$this->assertSame( 'e-paragraph', $out['widgetType'] );
		$this->assertNotEmpty( $out['id'] );
		$this->assertSame( '', $out['settings']['paragraph']['value'] );
	}

	public function test_missing_type_field_is_treated_as_unsupported(): void {
		$out = AtomicRenderer::render_node( [ 'props' => [ 'text' => 'orphan' ] ] );

		$this->assertArrayHasKey( '__unsupported', $out );
		$this->assertSame( '', $out['__unsupported'] );
	}

	public function test_partial_props_emit_only_present_keys(): void {
		$only_text = AtomicRenderer::render_node(
			[
				'type'  => 'Heading',
				'props' => [ 'text' => 'No level' ],
			]
		);
		$this->assertArrayHasKey( 'title', $only_text['settings'] );
		$this->assertArrayNotHasKey( 'tag', $only_text['settings'] );

		$only_level = AtomicRenderer::render_node(
			[
				'type'  => 'Heading',
				'props' => [ 'level' => 4 ],
			]
		);
		$this->assertArrayHasKey( 'tag', $only_level['settings'] );
		$this->assertSame( 'h4', $only_level['settings']['tag']['value'] );
		$this->assertArrayNotHasKey( 'title', $only_level['settings'] );

		$image_no_alt = AtomicRenderer::render_node(
			[
				'type'  => 'Image',
				'props' => [ 'url' => 'https://x/y.png' ],
			]
		);
		$this->assertArrayHasKey( 'image', $image_no_alt['settings'] );
		$this->assertArrayNotHasKey( 'alt', $image_no_alt['settings'] );
	}

	public function test_ids_are_unique_across_a_rendered_tree(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'     => 'Section',
				'children' => [
					[ 'type' => 'Heading',    'props' => [ 'text' => 'A' ] ],
					[ 'type' => 'TextEditor', 'props' => [ 'text' => 'B' ] ],
					[ 'type' => 'Divider' ],
				],
			]
		);

		$ids = array_merge( [ $out['id'] ], array_column( $out['elements'], 'id' ) );
		$this->assertCount( 4, $ids );
		$this->assertSame( $ids, array_unique( $ids ), 'Element ids must be unique within a tree.' );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{7}$/', $id );
		}
	}

	public function test_non_array_children_entries_are_skipped(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'     => 'Section',
				'children' => [
					'garbage',
					[ 'type' => 'Heading', 'props' => [ 'text' => 'OK' ] ],
					null,
				],
			]
		);

		$this->assertCount( 1, $out['elements'] );
		$this->assertSame( 'e-heading', $out['elements'][0]['widgetType'] );
	}
}
