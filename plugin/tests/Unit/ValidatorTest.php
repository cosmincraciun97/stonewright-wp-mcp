<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignSpec\Validator;

/**
 * @covers \Stonewright\WpMcp\DesignSpec\Validator
 */
final class ValidatorTest extends TestCase {

	private static function minimal_valid_spec(): array {
		return [
			'page'     => [ 'title' => 'Test Page' ],
			'sections' => [
				[
					'id'     => 'sec1',
					'blocks' => [
						[ 'type' => 'paragraph', 'text' => 'Hello world' ],
					],
				],
			],
		];
	}

	public function test_validate_returns_normalized_spec_when_valid(): void {
		$result = Validator::validate( self::minimal_valid_spec() );

		$this->assertIsArray( $result, 'Expected array, got WP_Error: ' . ( $result instanceof \WP_Error ? $result->get_error_message() : '' ) );
		$this->assertArrayHasKey( 'sections', $result );
		$this->assertNotEmpty( $result['sections'][0]['blocks'] );
	}

	public function test_validate_returns_wp_error_when_page_title_missing(): void {
		$result = Validator::validate( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	public function test_validate_returns_wp_error_when_no_sections(): void {
		$result = Validator::validate( [
			'page'     => [ 'title' => 'Page with no sections' ],
			'sections' => [],
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	public function test_validate_wp_error_does_not_contain_spec_in_data(): void {
		// Regression: Validator must never embed the raw spec in error data (information disclosure).
		$result = Validator::validate( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data, 'Error data should be an array.' );
		$this->assertArrayNotHasKey( 'spec', $data, 'Error data must not contain spec (information disclosure).' );
		$this->assertArrayHasKey( 'errors', $data, 'Error data must still contain validation errors.' );
	}

	public function test_normalize_assigns_default_version_and_section_ids(): void {
		$normalized = Validator::normalize( [
			'page'     => [ 'title' => 'Bare' ],
			'sections' => [
				[ 'blocks' => [ [ 'type' => 'paragraph', 'text' => 'Hi' ] ] ],
			],
		] );

		$this->assertSame( '1.0.0', $normalized['version'] );
		$this->assertSame( 'section_0', $normalized['sections'][0]['id'] );
	}

	public function test_validate_succeeds_with_form_block(): void {
		$spec = [
			'version' => '1.0.0',
			'page'     => [ 'title' => 'Form Page' ],
			'sections' => [
				[
					'id'     => 'sec_form',
					'blocks' => [
						[
							'type'           => 'form',
							'id'             => 'form_block_1',
							'form_name'      => 'Newsletter',
							'button_text'    => 'Subscribe',
							'fields'         => [
								[ 'type' => 'text', 'label' => 'Name', 'placeholder' => 'Enter name', 'required' => true ],
							],
							'submit_actions' => [ 'email' ],
						],
					],
				],
			],
		];

		$result = Validator::validate( $spec );
		$this->assertIsArray( $result, 'Expected form block spec to validate successfully' );
	}

	public function test_validate_rejects_html_widget_block(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'HTML Page' ],
			'sections' => [
				[
					'id'     => 'sec_html',
					'blocks' => [
						[
							'type' => 'html',
							'html' => '<style>.sw-html{display:block}</style><div class="sw-html">HTML</div>',
						],
					],
				],
			],
		];

		$result = Validator::validate( $spec );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_spec_invalid', $result->get_error_code() );
	}

	public function test_validate_succeeds_with_additional_block_properties(): void {
		$spec = [
			'version' => '1.0.0',
			'page'     => [ 'title' => 'Additional Properties Page' ],
			'sections' => [
				[
					'id'     => 'sec_add',
					'blocks' => [
						[
							'type'       => 'image',
							'id'         => 'img_1',
							'styles'     => [ 'backgroundColor' => '#000' ],
							'typography' => [ 'fontFamily' => 'Montserrat' ],
							'src'        => 'http://example.com/img.png',
							'assetRef'   => 'ref_1',
							'width'      => 72.428,
							'height'     => 72.428,
						],
					],
				],
			],
		];

		$result = Validator::validate( $spec );
		$this->assertIsArray( $result, 'Expected block with additional properties and float width/height to validate successfully' );
	}

	public function test_validate_accepts_external_design_payload_shape(): void {
		$spec = [
			'version' => '1.0.0',
			'page'    => [ 'title' => 'Companion Design reference Page' ],
			'assets' => [
				[
					'id'      => 'asset_1',
					'url'     => 'https://assets.example.com/hero.png',
					'altText' => 'Hero image',
					'width'   => 631,
					'height'  => 441,
					'mimeType' => 'image/png',
				],
			],
			'breakpoints' => [
				[ 'id' => 'desktop', 'label' => 'Desktop' ],
			],
			'meta' => [
				'source'        => 'image',
				'source_node_id' => '97:8306',
			],
			'sections' => [
				[
					'id'        => '97:8306',
					'name'      => 'Hero Section',
					'fullWidth' => true,
					'blocks'    => [
						[
							'type'      => 'container',
							'id'        => '97:8307',
							'layout'    => 'flex',
							'direction' => 'row',
							'styles'    => [
								'backgroundColor' => '#030712',
								'padding'         => '80px 40px',
							],
							'blocks'    => [
								[
									'type'       => 'heading',
									'id'         => '97:8308',
									'text'       => 'nZEB Expo Bucuresti 2025',
									'level'      => 1,
									'typography' => [
										'fontFamily' => 'Montserrat',
										'fontSize'   => 72,
										'fontWeight' => 700,
									],
									'styles'     => [
										'color' => '#ffffff',
									],
								],
								[
									'type'     => 'image',
									'id'       => '97:8309',
									'src'      => 'https://assets.example.com/hero.png',
									'assetRef' => 'asset_1',
									'alt'      => 'Hero',
									'width'    => 631,
									'height'   => 441,
								],
							],
						],
					],
				],
			],
		];

		$result = Validator::validate( $spec );

		$this->assertIsArray( $result, 'Expected external design payload shape to validate successfully' );
	}

	public function test_validate_accepts_external_background_image_ref(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Design reference Background Page' ],
			'assets'   => [
				[
					'id'       => 'asset_glow_bg',
					'url'      => 'https://assets.example.com/hero-glow.png',
					'mimeType' => 'image/png',
				],
			],
			'sections' => [
				[
					'id'         => 'hero',
					'background' => [
						'color'    => '#030712',
						'imageRef' => 'asset_glow_bg',
						'position' => 'center center',
						'size'     => 'cover',
					],
					'blocks'     => [
						[ 'type' => 'heading', 'text' => 'Hero', 'level' => 1 ],
					],
				],
			],
		];

		$result = Validator::validate( $spec );

		$this->assertIsArray( $result, 'Expected Design reference background imageRef payload to validate successfully' );
	}

	public function test_validate_accepts_native_elementor_quality_controls(): void {
		$spec = [
			'version'  => '1.0.0',
			'page'     => [ 'title' => 'Native Elementor Page' ],
			'sections' => [
				[
					'id'              => 'header',
					'layout'          => 'row',
					'fullWidth'       => true,
					'background'      => [ 'color' => '#0a0526' ],
					'padding'         => [ 'top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0 ],
					'sticky'          => 'top',
					'sticky_on'       => [ 'desktop', 'tablet', 'mobile' ],
					'sticky_offset'   => 0,
					'z_index'         => 100,
					'css_classes'     => 'sw-header',
					'justify_content' => 'center',
					'align_items'     => 'center',
					'blocks'          => [
						[
							'type'            => 'container',
							'id'              => 'inner',
							'layout'          => 'flex',
							'direction'       => 'row',
							'gap'             => 32,
							'fullWidth'       => true,
							'background'      => [ 'color' => '#0a0526' ],
							'padding'         => [ 'top' => 0, 'right' => 32, 'bottom' => 0, 'left' => 32 ],
							'sticky'          => 'top',
							'sticky_on'       => [ 'desktop', 'mobile' ],
							'sticky_offset'   => 0,
							'z_index'         => 100,
							'hide_on'         => [ 'tablet' ],
							'css_classes'     => 'sw-header-inner',
							'justify_content' => 'space-between',
							'align_items'     => 'center',
							'wrap'            => 'nowrap',
							'blocks'          => [
								[
									'type'         => 'nav-menu',
									'menu'         => 'primary',
									'layout'       => 'horizontal',
									'dropdown'     => 'mobile',
									'toggle'       => 'hamburger',
									'toggle_align' => 'end',
									'toggle_color' => '#ffffff',
									'style'        => [
										'color'       => '#ffffff',
										'font_family' => 'Montserrat',
										'font_size'   => 16,
										'font_weight' => 600,
									],
								],
								[
									'type'       => 'image-gallery',
									'images'     => [
										[ 'id' => 10, 'url' => 'https://example.com/a.jpg' ],
										[ 'id' => 11, 'url' => 'https://example.com/b.jpg' ],
									],
									'columns'    => 4,
									'image_size' => 'full',
									'spacing'    => 16,
									'link_to'    => 'file',
									'orderby'    => 'default',
								],
								[
									'type' => 'image',
									'id'   => 12,
									'url'  => 'https://example.com/icon.svg',
									'alt'  => 'Social icon',
									'link' => [ 'url' => 'https://example.com/social' ],
								],
								[
									'type'   => 'divider',
									'weight' => 1,
									'width'  => 100,
									'color'  => '#1e2939',
								],
								[
									'type'         => 'form',
									'form_name'    => 'Newsletter',
									'button_text'  => 'Aboneaza-te',
									'field_style'  => [
										'background'    => '#ffffff',
										'text_color'    => '#030712',
										'border_radius' => 8,
									],
									'button_style' => [
										'background'    => '#fdee17',
										'color'         => '#000000',
										'font_weight'   => 700,
										'border_radius' => 0,
									],
									'fields'       => [
										[ 'type' => 'email', 'label' => 'Email', 'required' => true ],
									],
								],
							],
						],
					],
				],
			],
		];

		$result = Validator::validate( $spec );

		$this->assertIsArray( $result, 'Expected native Elementor quality controls to validate successfully' );
	}
}

