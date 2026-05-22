<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Design\FigmaToSpec as FigmaToSpecAbility;
use Stonewright\WpMcp\Design\FigmaToSpec;
use Stonewright\WpMcp\DesignSpec\Validator;

/**
 * @covers \Stonewright\WpMcp\Design\FigmaToSpec
 * @covers \Stonewright\WpMcp\Abilities\Design\FigmaToSpec
 */
final class FigmaToSpecTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'edit_posts' => true ];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	// ─────────────────────────────────────────────────────────────────────
	// Helper-level tests (8+ cases)
	// ─────────────────────────────────────────────────────────────────────

	public function test_empty_frame_emits_minimal_valid_spec(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'   => '0:1',
				'type' => 'FRAME',
				'name' => 'Empty Page',
			]
		);

		$this->assertSame( '1.0.0', $spec['version'] );
		$this->assertSame( 'Empty Page', $spec['page']['title'] );
		$this->assertCount( 1, $spec['sections'] );
		$this->assertSame( [], $spec['sections'][0]['blocks'] );

		// Must survive the bundled schema validator.
		$this->assertNotInstanceOf( \WP_Error::class, Validator::validate( $spec ) );
	}

	public function test_single_text_child_becomes_heading_when_large(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'         => '0:2',
						'type'       => 'TEXT',
						'name'       => 'Title',
						'characters' => 'Hello world',
						'style'      => [ 'fontSize' => 48, 'fontFamily' => 'Inter', 'fontWeight' => 700 ],
					],
				],
			]
		);

		$blocks = $spec['sections'][0]['blocks'];
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'heading', $blocks[0]['type'] );
		$this->assertSame( 1, $blocks[0]['level'] );
		$this->assertSame( 'Hello world', $blocks[0]['text'] );
		$this->assertSame( '48px', $blocks[0]['style']['font_size'] );
		$this->assertSame( 'Inter', $blocks[0]['style']['font_family'] );
		$this->assertSame( '700', $blocks[0]['style']['font_weight'] );
		$this->assertNotInstanceOf( \WP_Error::class, Validator::validate( $spec ) );
	}

	public function test_small_text_becomes_paragraph(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'         => '0:2',
						'type'       => 'TEXT',
						'name'       => 'Body',
						'characters' => 'Some body copy.',
						'style'      => [ 'fontSize' => 14 ],
					],
				],
			]
		);

		$block = $spec['sections'][0]['blocks'][0];
		$this->assertSame( 'paragraph', $block['type'] );
		$this->assertSame( 'Some body copy.', $block['text'] );
	}

	public function test_frame_with_children_becomes_section_with_nested_blocks(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Root',
				'children' => [
					[
						'id'         => '1:1',
						'type'       => 'FRAME',
						'name'       => 'Hero',
						'layoutMode' => 'VERTICAL',
						'paddingTop' => 40,
						'paddingBottom' => 40,
						'paddingLeft' => 16,
						'paddingRight' => 16,
						'itemSpacing' => 24,
						'fills'      => [
							[ 'type' => 'SOLID', 'color' => [ 'r' => 0.1, 'g' => 0.2, 'b' => 0.3 ] ],
						],
						'children'   => [
							[
								'id'         => '1:2',
								'type'       => 'TEXT',
								'name'       => 'Title',
								'characters' => 'Welcome',
								'style'      => [ 'fontSize' => 32 ],
							],
							[
								'id'         => '1:3',
								'type'       => 'TEXT',
								'name'       => 'Subtitle',
								'characters' => 'Lorem ipsum.',
								'style'      => [ 'fontSize' => 16 ],
							],
						],
					],
				],
			]
		);

		$this->assertCount( 1, $spec['sections'] );
		$section = $spec['sections'][0];
		$this->assertSame( 'Hero', $section['name'] );
		$this->assertSame( 'stack', $section['layout'] );
		$this->assertSame( '#1a334d', $section['background']['color'] );
		$this->assertSame( 24, $section['gap'] );
		$this->assertSame( 40, $section['padding']['top'] );
		$this->assertCount( 2, $section['blocks'] );
		$this->assertSame( 'heading', $section['blocks'][0]['type'] );
		$this->assertSame( 'paragraph', $section['blocks'][1]['type'] );
		$this->assertNotInstanceOf( \WP_Error::class, Validator::validate( $spec ) );
	}

	public function test_button_shaped_frame_is_detected_as_button(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Root',
				'children' => [
					[
						'id'       => '1:1',
						'type'     => 'FRAME',
						'name'     => 'CTA Wrap',
						'children' => [
							[
								'id'           => '2:1',
								'type'         => 'FRAME',
								'name'         => 'Primary CTA',
								'layoutMode'   => 'HORIZONTAL',
								'cornerRadius' => 8,
								'fills'        => [
									[ 'type' => 'SOLID', 'color' => [ 'r' => 0.0, 'g' => 0.4, 'b' => 1.0 ] ],
								],
								'children'     => [
									[
										'id'         => '2:2',
										'type'       => 'TEXT',
										'name'       => 'Label',
										'characters' => 'Sign up',
										'style'      => [ 'fontSize' => 16 ],
										'fills'      => [
											[ 'type' => 'SOLID', 'color' => [ 'r' => 1.0, 'g' => 1.0, 'b' => 1.0 ] ],
										],
									],
								],
							],
						],
					],
				],
			]
		);

		// The CTA Wrap frame is the section's only container child — its
		// nested Primary CTA becomes a button block inside.
		$blocks = $spec['sections'][0]['blocks'];
		$button = null;
		foreach ( $blocks as $b ) {
			if ( 'button' === ( $b['type'] ?? null ) ) {
				$button = $b;
				break;
			}
			if ( 'column' === ( $b['type'] ?? null ) ) {
				foreach ( $b['blocks'] as $nested ) {
					if ( 'button' === ( $nested['type'] ?? null ) ) {
						$button = $nested;
						break 2;
					}
				}
			}
		}

		$this->assertNotNull( $button, 'Expected a button block somewhere in the section tree.' );
		$this->assertSame( 'Sign up', $button['text'] );
		$this->assertSame( '#0066ff', $button['style']['background_color'] );
		$this->assertSame( '#ffffff', $button['style']['color'] );
		$this->assertSame( '8px', $button['style']['border_radius'] );
	}

	public function test_rectangle_with_image_fill_becomes_image_block(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'    => '1:1',
						'type'  => 'RECTANGLE',
						'name'  => 'Hero photo',
						'fills' => [
							[ 'type' => 'IMAGE', 'imageRef' => 'abc123' ],
						],
						'absoluteBoundingBox' => [ 'width' => 800, 'height' => 400 ],
					],
				],
			]
		);

		$block = $spec['sections'][0]['blocks'][0];
		$this->assertSame( 'image', $block['type'] );
		$this->assertSame( 'figma-image:abc123', $block['url'] );
		$this->assertSame( 'Hero photo', $block['alt'] );
		$this->assertSame( '800px', $block['style']['width'] );
		$this->assertSame( '400px', $block['style']['height'] );
	}

	public function test_vector_node_becomes_icon_block(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'    => '1:1',
						'type'  => 'VECTOR',
						'name'  => 'check-icon',
						'fills' => [
							[ 'type' => 'SOLID', 'color' => [ 'r' => 0.2, 'g' => 0.6, 'b' => 0.2 ] ],
						],
					],
				],
			]
		);

		$block = $spec['sections'][0]['blocks'][0];
		$this->assertSame( 'icon', $block['type'] );
		$this->assertSame( 'check-icon', $block['text'] );
		$this->assertSame( '#339933', $block['style']['color'] );
	}

	public function test_responsive_variant_pairing_emits_per_viewport_style_map(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'         => '1:1',
						'type'       => 'TEXT',
						'name'       => 'Hero Title',
						'characters' => 'Hello',
						'style'      => [ 'fontSize' => 48, 'fontFamily' => 'Inter' ],
					],
					[
						'id'         => '1:2',
						'type'       => 'TEXT',
						'name'       => 'Hero Title / mobile',
						'characters' => 'Hello',
						'style'      => [ 'fontSize' => 28, 'fontFamily' => 'Inter' ],
					],
				],
			]
		);

		$blocks = $spec['sections'][0]['blocks'];
		$this->assertCount( 1, $blocks, 'Mobile sibling must collapse into its desktop pair.' );
		$this->assertSame( 'heading', $blocks[0]['type'] );
		$this->assertIsArray( $blocks[0]['style']['font_size'] );
		$this->assertSame( '48px', $blocks[0]['style']['font_size']['desktop'] );
		$this->assertSame( '28px', $blocks[0]['style']['font_size']['mobile'] );
	}

	public function test_unknown_node_type_falls_back_to_paragraph_with_unsupported_annotation(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'   => '1:1',
						'type' => 'WEIRDO',
						'name' => 'Unknown blob',
					],
				],
			]
		);

		$block = $spec['sections'][0]['blocks'][0];
		$this->assertSame( 'paragraph', $block['type'] );
		$this->assertSame( 'Unknown blob', $block['text'] );
		$this->assertSame( 'WEIRDO', $block['style']['__unsupported'] );
		$this->assertNotInstanceOf( \WP_Error::class, Validator::validate( $spec ) );
	}

	public function test_top_level_text_with_no_container_children_still_produces_section(): void {
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'         => '1:1',
						'type'       => 'TEXT',
						'name'       => 'Headline',
						'characters' => 'Just one heading',
						'style'      => [ 'fontSize' => 36 ],
					],
				],
			]
		);

		$this->assertCount( 1, $spec['sections'] );
		$this->assertCount( 1, $spec['sections'][0]['blocks'] );
		$this->assertSame( 'heading', $spec['sections'][0]['blocks'][0]['type'] );
	}

	public function test_naked_frame_with_one_text_child_is_not_a_button(): void {
		// A frame with a single TEXT child but no fills/strokes is just a
		// text wrapper, not a button. Should become a column with a heading.
		$spec = FigmaToSpec::to_spec(
			[
				'id'       => '0:1',
				'type'     => 'FRAME',
				'name'     => 'Page',
				'children' => [
					[
						'id'       => '1:1',
						'type'     => 'FRAME',
						'name'     => 'Outer Wrap',
						'children' => [
							[
								'id'       => '2:1',
								'type'     => 'FRAME',
								'name'     => 'Text-only Wrapper',
								'children' => [
									[
										'id'         => '3:1',
										'type'       => 'TEXT',
										'name'       => 'Label',
										'characters' => 'Not a button',
										'style'      => [ 'fontSize' => 24 ],
									],
								],
							],
						],
					],
				],
			]
		);

		// Walk into the section's column chain and assert no `button` shows up.
		$serialized = wp_json_encode( $spec );
		$this->assertIsString( $serialized );
		$this->assertStringNotContainsString( '"type":"button"', $serialized );
		$this->assertStringContainsString( '"type":"heading"', $serialized );
	}

	// ─────────────────────────────────────────────────────────────────────
	// Ability-level tests
	// ─────────────────────────────────────────────────────────────────────

	public function test_ability_name_and_category(): void {
		$ability = new FigmaToSpecAbility();
		$this->assertSame( 'stonewright/design-figma-to-spec', $ability->name() );
		$this->assertSame( 'design', $ability->category() );
	}

	public function test_ability_input_schema_requires_figma_node(): void {
		$schema = ( new FigmaToSpecAbility() )->input_schema();
		$this->assertContains( 'figma_node', $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
	}

	public function test_ability_returns_validated_spec(): void {
		$result = ( new FigmaToSpecAbility() )->execute(
			[
				'figma_node' => [
					'id'       => '0:1',
					'type'     => 'FRAME',
					'name'     => 'Ability Page',
					'children' => [
						[
							'id'         => '1:1',
							'type'       => 'TEXT',
							'name'       => 'Heading',
							'characters' => 'Hi',
							'style'      => [ 'fontSize' => 32 ],
						],
					],
				],
				'viewport_label' => 'desktop',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertSame( '1.0.0', $result['version'] );
		$this->assertSame( 'Ability Page', $result['page']['title'] );
		$this->assertCount( 1, $result['sections'] );
	}

	public function test_ability_rejects_missing_figma_node(): void {
		$result = ( new FigmaToSpecAbility() )->execute( [] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_missing_figma_node', $result->get_error_code() );
	}

	public function test_ability_rejects_empty_figma_node(): void {
		$result = ( new FigmaToSpecAbility() )->execute( [ 'figma_node' => [] ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_missing_figma_node', $result->get_error_code() );
	}
}
