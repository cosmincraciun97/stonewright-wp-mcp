<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\Button;
use Stonewright\WpMcp\Elementor\Renderer\Divider;
use Stonewright\WpMcp\Elementor\Renderer\Heading;
use Stonewright\WpMcp\Elementor\Renderer\Icon;
use Stonewright\WpMcp\Elementor\Renderer\Image;
use Stonewright\WpMcp\Elementor\Renderer\Spacer;
use Stonewright\WpMcp\Elementor\Renderer\TextEditor;
use Stonewright\WpMcp\Elementor\Renderer\Video;

final class ResponsiveIntegrationTest extends TestCase {

    private Resolver $resolver;

    protected function setUp(): void {
        $this->resolver = new Resolver( [] );
    }

    // -------------------------------------------------------------------------
    // Heading
    // -------------------------------------------------------------------------

    public function test_heading_emits_responsive_font_size(): void {
        $node = [
            'type'      => 'heading',
            'text'      => 'Hi',
            'font_size' => [ 'desktop' => '48px', 'mobile' => '24px' ],
        ];
        $element = Heading::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '48px', $element['settings']['typography_font_size'] );
        $this->assertSame( '24px', $element['settings']['typography_font_size_mobile'] );
        $this->assertArrayNotHasKey( 'typography_font_size_tablet', $element['settings'] );
    }

    public function test_heading_scalar_font_size_passthrough(): void {
        $node = [
            'type'      => 'heading',
            'text'      => 'Hi',
            'font_size' => '32px',
        ];
        $element = Heading::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '32px', $element['settings']['typography_font_size'] );
        $this->assertArrayNotHasKey( 'typography_font_size_tablet', $element['settings'] );
        $this->assertArrayNotHasKey( 'typography_font_size_mobile', $element['settings'] );
    }

    public function test_heading_emits_responsive_align(): void {
        $node = [
            'type'  => 'heading',
            'text'  => 'Hi',
            'align' => [ 'desktop' => 'center', 'tablet' => 'left' ],
        ];
        $element = Heading::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( 'center', $element['settings']['align'] );
        $this->assertSame( 'left', $element['settings']['align_tablet'] );
    }

    // -------------------------------------------------------------------------
    // TextEditor
    // -------------------------------------------------------------------------

    public function test_text_editor_emits_responsive_font_size(): void {
        $node = [
            'type'      => 'text-editor',
            'text'      => 'Body copy',
            'font_size' => [ 'desktop' => '18px', 'mobile' => '14px' ],
        ];
        $element = TextEditor::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '18px', $element['settings']['typography_font_size'] );
        $this->assertSame( '14px', $element['settings']['typography_font_size_mobile'] );
    }

    public function test_text_editor_emits_responsive_align(): void {
        $node = [
            'type'  => 'text-editor',
            'text'  => 'Body copy',
            'align' => [ 'desktop' => 'right', 'mobile' => 'center' ],
        ];
        $element = TextEditor::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( 'right', $element['settings']['align'] );
        $this->assertSame( 'center', $element['settings']['align_mobile'] );
    }

    // -------------------------------------------------------------------------
    // Image
    // -------------------------------------------------------------------------

    public function test_image_emits_responsive_width(): void {
        $node = [
            'type'  => 'image',
            'url'   => 'https://example.com/img.jpg',
            'width' => [ 'desktop' => '100%', 'tablet' => '80%', 'mobile' => '100%' ],
        ];
        $element = Image::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '100%', $element['settings']['width'] );
        $this->assertSame( '80%', $element['settings']['width_tablet'] );
        $this->assertSame( '100%', $element['settings']['width_mobile'] );
    }

    public function test_image_emits_responsive_align(): void {
        $node = [
            'type'  => 'image',
            'url'   => 'https://example.com/img.jpg',
            'align' => [ 'desktop' => 'center', 'mobile' => 'left' ],
        ];
        $element = Image::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( 'center', $element['settings']['align'] );
        $this->assertSame( 'left', $element['settings']['align_mobile'] );
    }

    // -------------------------------------------------------------------------
    // Button
    // -------------------------------------------------------------------------

    public function test_button_emits_responsive_font_size(): void {
        $node = [
            'type'      => 'button',
            'text'      => 'Click',
            'url'       => 'https://example.com',
            'font_size' => [ 'desktop' => '16px', 'mobile' => '14px' ],
        ];
        $element = Button::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '16px', $element['settings']['typography_font_size'] );
        $this->assertSame( '14px', $element['settings']['typography_font_size_mobile'] );
    }

    public function test_button_emits_responsive_align(): void {
        $node = [
            'type'  => 'button',
            'text'  => 'Click',
            'url'   => 'https://example.com',
            'align' => [ 'desktop' => 'center', 'tablet' => 'left' ],
        ];
        $element = Button::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( 'center', $element['settings']['align'] );
        $this->assertSame( 'left', $element['settings']['align_tablet'] );
    }

    public function test_button_emits_responsive_padding(): void {
        $node = [
            'type'    => 'button',
            'text'    => 'Click',
            'url'     => 'https://example.com',
            'padding' => [ 'desktop' => '12px 24px', 'mobile' => '8px 16px' ],
        ];
        $element = Button::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '12px 24px', $element['settings']['padding'] );
        $this->assertSame( '8px 16px', $element['settings']['padding_mobile'] );
    }

    // -------------------------------------------------------------------------
    // Spacer
    // -------------------------------------------------------------------------

    public function test_spacer_emits_responsive_space(): void {
        $node = [
            'type'  => 'spacer',
            'space' => [ 'desktop' => [ 'unit' => 'px', 'size' => 80 ], 'mobile' => [ 'unit' => 'px', 'size' => 40 ] ],
        ];
        $element = Spacer::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( [ 'unit' => 'px', 'size' => 80 ], $element['settings']['space'] );
        $this->assertSame( [ 'unit' => 'px', 'size' => 40 ], $element['settings']['space_mobile'] );
    }

    // -------------------------------------------------------------------------
    // Divider
    // -------------------------------------------------------------------------

    public function test_divider_emits_responsive_gap(): void {
        $node = [
            'type' => 'divider',
            'gap'  => [ 'desktop' => '20px', 'mobile' => '10px' ],
        ];
        $element = Divider::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '20px', $element['settings']['gap'] );
        $this->assertSame( '10px', $element['settings']['gap_mobile'] );
    }

    public function test_divider_emits_responsive_weight(): void {
        $node = [
            'type'   => 'divider',
            'weight' => [ 'desktop' => [ 'unit' => 'px', 'size' => 3 ], 'tablet' => [ 'unit' => 'px', 'size' => 2 ] ],
        ];
        $element = Divider::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( [ 'unit' => 'px', 'size' => 3 ], $element['settings']['weight'] );
        $this->assertSame( [ 'unit' => 'px', 'size' => 2 ], $element['settings']['weight_tablet'] );
    }

    public function test_divider_emits_responsive_align(): void {
        $node = [
            'type'  => 'divider',
            'align' => [ 'desktop' => 'center', 'mobile' => 'left' ],
        ];
        $element = Divider::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( 'center', $element['settings']['align'] );
        $this->assertSame( 'left', $element['settings']['align_mobile'] );
    }

    // -------------------------------------------------------------------------
    // Icon
    // -------------------------------------------------------------------------

    public function test_icon_emits_responsive_size(): void {
        $node = [
            'type' => 'icon',
            'icon' => 'fas fa-star',
            'size' => [ 'desktop' => [ 'unit' => 'px', 'size' => 64 ], 'mobile' => [ 'unit' => 'px', 'size' => 32 ] ],
        ];
        $element = Icon::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( [ 'unit' => 'px', 'size' => 64 ], $element['settings']['size'] );
        $this->assertSame( [ 'unit' => 'px', 'size' => 32 ], $element['settings']['size_mobile'] );
    }

    public function test_icon_emits_responsive_align(): void {
        $node = [
            'type'  => 'icon',
            'icon'  => 'fas fa-star',
            'align' => [ 'desktop' => 'center', 'tablet' => 'left', 'mobile' => 'left' ],
        ];
        $element = Icon::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( 'center', $element['settings']['align'] );
        $this->assertSame( 'left', $element['settings']['align_tablet'] );
        $this->assertSame( 'left', $element['settings']['align_mobile'] );
    }

    // -------------------------------------------------------------------------
    // Video
    // -------------------------------------------------------------------------

    public function test_video_emits_responsive_aspect_ratio(): void {
        $node = [
            'type'         => 'video',
            'url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'aspect_ratio' => [ 'desktop' => '169', 'mobile' => '43' ],
        ];
        $element = Video::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '169', $element['settings']['aspect_ratio'] );
        $this->assertSame( '43', $element['settings']['aspect_ratio_mobile'] );
    }

    public function test_video_scalar_aspect_ratio_passthrough(): void {
        $node = [
            'type'         => 'video',
            'url'          => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'aspect_ratio' => '169',
        ];
        $element = Video::render( $node, $this->resolver, 'p0.s0.b0' );
        $this->assertSame( '169', $element['settings']['aspect_ratio'] );
        $this->assertArrayNotHasKey( 'aspect_ratio_tablet', $element['settings'] );
        $this->assertArrayNotHasKey( 'aspect_ratio_mobile', $element['settings'] );
    }
}
