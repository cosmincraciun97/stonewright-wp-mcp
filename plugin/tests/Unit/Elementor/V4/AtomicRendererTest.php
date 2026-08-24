<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\V4;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicRenderer;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;

/**
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicRenderer
 */
final class AtomicRendererTest extends TestCase {
	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		AtomicSchemaRepository::invalidate();
	}

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
		$this->assertSame( 'html-v3', $out['settings']['title']['$$type'] );
		$this->assertSame( 'Hello', $out['settings']['title']['value']['content']['value'] );
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
		$this->assertSame( 'html-v3', $out['settings']['paragraph']['$$type'] );
		$this->assertSame( 'Body copy.', $out['settings']['paragraph']['value']['content']['value'] );
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
		$this->assertSame( 'https://cdn.example/hero.jpg', $out['settings']['image']['value']['src']['value']['url']['value'] );
		$this->assertSame( 'Hero shot', $out['settings']['image']['value']['src']['value']['alt']['value'] );
	}

	public function test_renders_button_with_text_and_link_envelopes(): void {
		$out = AtomicRenderer::render_node(
			[
				'type'  => 'Button',
				'props' => [ 'text' => 'Sign up', 'link' => 'https://example.com/join' ],
			]
		);

		$this->assertSame( 'e-button', $out['widgetType'] );
		$this->assertSame( 'html-v3', $out['settings']['text']['$$type'] );
		$this->assertSame( 'Sign up', $out['settings']['text']['value']['content']['value'] );
		$this->assertSame( 'link', $out['settings']['link']['$$type'] );
		$this->assertSame( 'https://example.com/join', $out['settings']['link']['value']['destination']['value'] );
	}

	public function test_renders_divider_and_icon_widget_types(): void {
		$divider = AtomicRenderer::render_node( [ 'type' => 'Divider', 'props' => [] ] );
		$this->assertSame( 'e-divider', $divider['widgetType'] );
		$this->assertSame( 'widget', $divider['elType'] );
		$this->assertSame( [], $divider['settings'] );

		$icon = AtomicRenderer::render_node(
			[
				'type'  => 'Icon',
				'props' => [ 'url' => 'https://example.com/icon.svg' ],
			]
		);
		$this->assertSame( 'e-svg', $icon['widgetType'] );
		$this->assertSame( 'svg-src', $icon['settings']['svg']['$$type'] );
		$this->assertSame( 'https://example.com/icon.svg', $icon['settings']['svg']['value']['url']['value'] );
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

		$this->assertSame( 'e-flexbox', $out['elType'] );
		$this->assertArrayNotHasKey( 'widgetType', $out );
		$this->assertSame( '0.0', $out['version'] );
		$this->assertCount( 2, $out['elements'] );
		$this->assertSame( 'e-heading', $out['elements'][0]['widgetType'] );
		$this->assertSame( 'e-paragraph', $out['elements'][1]['widgetType'] );
		$style_id = $out['settings']['classes']['value'][0];
		$style_props = $out['styles'][ $style_id ]['variants'][0]['props'];
		$this->assertSame( 'string', $style_props['flex-direction']['$$type'] );
		$this->assertSame( 'row', $style_props['flex-direction']['value'] );
		$this->assertSame( 'size', $style_props['gap']['$$type'] );
		$this->assertSame( [ 'unit' => 'px', 'size' => 24.0 ], $style_props['gap']['value'] );
	}

	public function test_collapses_all_container_flavours_into_flexbox_container(): void {
		foreach ( [ 'Section', 'Column', 'Container' ] as $type ) {
			$out = AtomicRenderer::render_node( [ 'type' => $type, 'props' => [], 'children' => [] ] );
			$this->assertSame( 'e-flexbox', $out['elType'], $type );
			$this->assertArrayNotHasKey( 'widgetType', $out, $type );
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

		$this->assertSame( 'e-flexbox', $out['elType'] );
		$inner_col = $out['elements'][0];
		$this->assertSame( 'e-flexbox', $inner_col['elType'] );
		$leaf = $inner_col['elements'][0];
		$this->assertSame( 'e-heading', $leaf['widgetType'] );
		$this->assertSame( 'h3', $leaf['settings']['tag']['value'] );
		$this->assertSame( 'Deep', $leaf['settings']['title']['value']['content']['value'] );
	}

	public function test_unknown_node_type_is_a_structured_error(): void {
		$out = AtomicRenderer::render_node( [ 'type' => 'UnknownXYZ', 'props' => [] ] );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'stonewright_v4_unknown_node', $out->get_error_code() );
	}

	public function test_uncertified_third_party_schema_never_reaches_renderer_validation(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_elementor_v4_atomic_schemas'] = static function ( array $schemas ): array {
			$schemas['e-acme-card'] = self::provider_schema( 'AcmeCard', 'untrusted', 'discovered' );
			return $schemas;
		};
		AtomicSchemaRepository::invalidate();

		$out = AtomicRenderer::render_node( [ 'type' => 'AcmeCard', 'props' => [ 'title' => 'No' ] ] );

		self::assertInstanceOf( \WP_Error::class, $out );
		self::assertSame( 'stonewright_v4_unknown_node', $out->get_error_code() );
	}

	public function test_filtered_provider_cannot_self_assert_certification_to_render(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_elementor_v4_atomic_schemas'] = static function ( array $schemas ): array {
			$schemas['e-certified-card'] = self::provider_schema( 'CertifiedCard', 'trusted', 'certified' );
			return $schemas;
		};
		AtomicSchemaRepository::invalidate();

		$out = AtomicRenderer::render_node( [ 'type' => 'CertifiedCard', 'props' => [ 'title' => 'Blocked' ] ] );

		self::assertInstanceOf( \WP_Error::class, $out );
		self::assertSame( 'stonewright_v4_unknown_node', $out->get_error_code() );
	}

	public function test_missing_type_field_is_a_structured_error(): void {
		$out = AtomicRenderer::render_node( [ 'props' => [ 'text' => 'orphan' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'stonewright_v4_unknown_node', $out->get_error_code() );
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

	public function test_non_array_children_entries_are_rejected(): void {
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

		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'stonewright_v4_invalid_child', $out->get_error_code() );
	}

	public function test_unknown_property_is_never_dropped(): void {
		$out = AtomicRenderer::render_node( [ 'type' => 'Heading', 'props' => [ 'text' => 'A', 'invented' => true ] ] );
		$this->assertInstanceOf( \WP_Error::class, $out );
		$this->assertSame( 'stonewright_v4_unknown_property', $out->get_error_code() );
	}

	/** @return array<string,mixed> */
	private static function provider_schema( string $design_type, string $trust, string $certification ): array {
		return [
			'kind'                   => 'widget',
			'design_types'           => [ $design_type ],
			'version'                => '0.0',
			'props'                  => [ 'title' => [ 'key' => 'title', 'type' => 'string' ] ],
			'source'                 => 'live_runtime',
			'source_plugin'          => 'certified-provider/bootstrap.php',
			'source_version'         => '1.0.0',
			'provider_id'            => 'plugin:certified-provider',
			'provider_trust'         => $trust,
			'provider_certification' => $certification,
			'provenance'             => [ 'certification' => 'stonewright_explicit_certification' ],
		];
	}
}
