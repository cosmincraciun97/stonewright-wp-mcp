<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Renderers;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\V4\AtomicSchemaRepository;
use Stonewright\WpMcp\Renderers\ElementorV4SpecRenderer;

/**
 * @covers \Stonewright\WpMcp\Renderers\ElementorV4SpecRenderer
 * @covers \Stonewright\WpMcp\Elementor\V4\AtomicRenderer
 */
final class ElementorV4SpecRendererTest extends TestCase {

	protected function setUp(): void {
		AtomicSchemaRepository::invalidate();
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		AtomicSchemaRepository::invalidate();
	}

	/** @param array<string, mixed> $section @return array<string, mixed> */
	private function spec( array $section ): array {
		$section += [
			'id'     => 'hero',
			'blocks' => [ [ 'type' => 'heading', 'text' => 'Hello', 'level' => 1 ] ],
		];
		return [ 'page' => [ 'title' => 'Synthetic page' ], 'sections' => [ $section ] ];
	}

	/** @param array<string, mixed> $element @return array<string, mixed> */
	private function style_props( array $element, string $breakpoint = 'desktop' ): array {
		$style_id = $element['settings']['classes']['value'][0];
		foreach ( $element['styles'][ $style_id ]['variants'] as $variant ) {
			if ( $breakpoint === $variant['meta']['breakpoint'] ) {
				return $variant['props'];
			}
		}
		self::fail( 'No ' . $breakpoint . ' variant.' );
	}

	public function test_section_styling_is_rendered_through_typed_envelopes(): void {
		$tree = ElementorV4SpecRenderer::render(
			$this->spec(
				[
					'width'      => 'full',
					'layout'     => 'stack',
					'gap'        => '24px',
					'padding'    => [ 'top' => '60px', 'bottom' => '60px', 'left' => '0', 'right' => '0' ],
					'background' => [ 'color' => '#f5f5f5' ],
				]
			)
		);

		self::assertIsArray( $tree );
		$props = $this->style_props( $tree[0] );
		self::assertSame( [ '$$type' => 'string', 'value' => 'column' ], $props['flex-direction'] );
		self::assertSame( [ '$$type' => 'size', 'value' => [ 'unit' => 'px', 'size' => 24.0 ] ], $props['gap'] );
		self::assertSame( [ '$$type' => 'size', 'value' => [ 'unit' => '%', 'size' => 100.0 ] ], $props['width'] );
		self::assertSame(
			[
				'$$type' => 'dimensions',
				'value'  => [
					'block-start'  => [ '$$type' => 'size', 'value' => [ 'unit' => 'px', 'size' => 60.0 ] ],
					'inline-end'   => [ '$$type' => 'size', 'value' => [ 'unit' => 'px', 'size' => 0 ] ],
					'block-end'    => [ '$$type' => 'size', 'value' => [ 'unit' => 'px', 'size' => 60.0 ] ],
					'inline-start' => [ '$$type' => 'size', 'value' => [ 'unit' => 'px', 'size' => 0 ] ],
				],
			],
			$props['padding']
		);
		self::assertSame(
			[ '$$type' => 'background', 'value' => [ 'color' => [ '$$type' => 'color', 'value' => '#f5f5f5' ] ] ],
			$props['background']
		);
		self::assertSame( 0, $props['padding']['value']['inline-end']['value']['size'], 'A zero size must be an integer so Elementor accepts it.' );
	}

	public function test_a_section_without_layout_stacks_its_children(): void {
		$tree = ElementorV4SpecRenderer::render( $this->spec( [] ) );

		self::assertIsArray( $tree );
		self::assertSame( 'column', $this->style_props( $tree[0] )['flex-direction']['value'] );
	}

	public function test_explicit_direction_wins_over_layout_and_alignment_is_rendered(): void {
		$tree = ElementorV4SpecRenderer::render(
			$this->spec( [ 'layout' => 'stack', 'direction' => 'row-reverse', 'justify_content' => 'space-between', 'align_items' => 'center', 'z_index' => 3 ] )
		);

		self::assertIsArray( $tree );
		$props = $this->style_props( $tree[0] );
		self::assertSame( 'row-reverse', $props['flex-direction']['value'] );
		self::assertSame( [ '$$type' => 'string', 'value' => 'space-between' ], $props['justify-content'] );
		self::assertSame( [ '$$type' => 'string', 'value' => 'center' ], $props['align-items'] );
		self::assertSame( [ '$$type' => 'number', 'value' => 3 ], $props['z-index'] );
	}

	public function test_viewport_map_becomes_breakpoint_variants(): void {
		$tree = ElementorV4SpecRenderer::render(
			$this->spec( [ 'layout' => [ 'desktop' => 'row', 'tablet' => 'stack', 'mobile' => 'stack' ] ] )
		);

		self::assertIsArray( $tree );
		self::assertSame( 'row', $this->style_props( $tree[0], 'desktop' )['flex-direction']['value'] );
		self::assertSame( 'column', $this->style_props( $tree[0], 'tablet' )['flex-direction']['value'] );
		self::assertSame( 'column', $this->style_props( $tree[0], 'mobile' )['flex-direction']['value'] );
	}

	public function test_row_column_and_card_blocks_render_nested_styling(): void {
		$tree = ElementorV4SpecRenderer::render(
			$this->spec(
				[
					'blocks' => [
						[
							'type'    => 'card',
							'padding' => [ 'top' => 16, 'right' => 16, 'bottom' => 16, 'left' => 16 ],
							'blocks'  => [ [ 'type' => 'paragraph', 'text' => 'Inside the card' ] ],
						],
					],
				]
			)
		);

		self::assertIsArray( $tree );
		$card = $tree[0]['elements'][0];
		self::assertSame( 'e-flexbox', $card['elType'] );
		self::assertSame( 'column', $this->style_props( $card )['flex-direction']['value'] );
		self::assertSame( 16.0, $this->style_props( $card )['padding']['value']['block-start']['value']['size'] );
		self::assertSame( 'e-paragraph', $card['elements'][0]['widgetType'] );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function unsupported_section_properties(): array {
		return [
			'background image'    => [ [ 'background' => [ 'color' => '#fff', 'image' => 'https://example.test/bg.jpg' ] ], 'background.image' ],
			'background overlay'  => [ [ 'background' => [ 'overlay' => 'rgba(0,0,0,0.4)' ] ], 'background.overlay' ],
			'margin'              => [ [ 'margin' => '0 auto' ], 'margin' ],
			'css classes'         => [ [ 'css_classes' => 'hero-wrap' ], 'css_classes' ],
			'hide on'             => [ [ 'hide_on' => [ 'mobile' ] ], 'hide_on' ],
			'sticky'              => [ [ 'sticky' => 'top' ], 'sticky' ],
			'boxed width'         => [ [ 'width' => 'boxed' ], 'width' ],
			'narrow width'        => [ [ 'width' => 'narrow' ], 'width' ],
			'grid layout'         => [ [ 'layout' => 'grid' ], 'layout' ],
		];
	}

	/**
	 * @dataProvider unsupported_section_properties
	 * @param array<string, mixed> $section
	 */
	public function test_unsupported_section_properties_are_refused_with_their_path( array $section, string $property ): void {
		$result = ElementorV4SpecRenderer::render( $this->spec( $section ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_unsupported_property', $result->get_error_code() );
		$path = 'sections.0.' . $property;
		self::assertStringContainsString( $path, $result->get_error_message() );
		self::assertStringContainsString( explode( '.', $property )[ count( explode( '.', $property ) ) - 1 ], $result->get_error_message() );
		self::assertSame( explode( '.', $path ), array_map( 'strval', $result->get_error_data()['path'] ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_section_values(): array {
		return [
			'padding unit'     => [ [ 'padding' => [ 'top' => '5vmin' ] ], 'sections.0.padding.top' ],
			'negative padding' => [ [ 'padding' => [ 'left' => '-4px' ] ], 'sections.0.padding.left' ],
			'css injection'    => [ [ 'background' => [ 'color' => 'red;}body{display:none' ] ], 'sections.0.background.color' ],
			'justify value'    => [ [ 'justify_content' => 'banana' ], 'sections.0.justify_content' ],
		];
	}

	/**
	 * @dataProvider invalid_section_values
	 * @param array<string, mixed> $section
	 */
	public function test_invalid_section_values_are_refused_with_their_path( array $section, string $path ): void {
		$result = ElementorV4SpecRenderer::render( $this->spec( $section ) );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringContainsString( $path, $result->get_error_message() );
	}

	public function test_style_properties_on_leaf_blocks_are_refused_not_dropped(): void {
		$result = ElementorV4SpecRenderer::render(
			$this->spec( [ 'blocks' => [ [ 'type' => 'heading', 'text' => 'Hi', 'level' => 2, 'color' => '#ff0000' ] ] ] )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_unsupported_property', $result->get_error_code() );
		self::assertStringContainsString( 'sections.0.blocks.0.color', $result->get_error_message() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unrenderable_block_types(): array {
		return [ 'spacer' => [ 'spacer' ], 'list' => [ 'list' ], 'video' => [ 'video' ], 'embed' => [ 'embed' ], 'slider' => [ 'slider' ] ];
	}

	/** @dataProvider unrenderable_block_types */
	public function test_unrenderable_block_types_name_the_node_path_and_type( string $type ): void {
		$result = ElementorV4SpecRenderer::render(
			$this->spec(
				[
					'blocks' => [
						[ 'type' => 'heading', 'text' => 'Fine', 'level' => 2 ],
						[ 'type' => $type, 'url' => 'https://example.test/x' ],
					],
				]
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_v4_unknown_node', $result->get_error_code() );
		$message = $result->get_error_message();
		self::assertStringContainsString( 'sections.0.blocks.1', $message );
		self::assertStringContainsString( '"' . $type . '"', $message );
		self::assertStringContainsString( 'heading', $message );
		self::assertSame( [ 'sections', 0, 'blocks', 1 ], $result->get_error_data()['path'] );
		self::assertSame( $type, $result->get_error_data()['received'] );
	}

	public function test_unknown_node_inside_a_nested_row_reports_the_full_path(): void {
		$result = ElementorV4SpecRenderer::render(
			$this->spec(
				[
					'blocks' => [
						[ 'type' => 'row', 'blocks' => [ [ 'type' => 'column', 'blocks' => [ [ 'type' => 'video', 'url' => 'https://example.test/v' ] ] ] ] ],
					],
				]
			)
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringContainsString( 'sections.0.blocks.0.blocks.0.blocks.0', $result->get_error_message() );
	}
}
