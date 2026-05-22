<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Renderer\Responsive;

final class ResponsiveTest extends TestCase {
    public function test_apply_emits_base_and_breakpoint_keys(): void {
        $settings = [];
        $value    = [
            'desktop' => '32px',
            'tablet'  => '24px',
            'mobile'  => '16px',
        ];
        $out = Responsive::apply( $settings, 'typography_font_size', $value );
        $this->assertSame( '32px', $out['typography_font_size'] );
        $this->assertSame( '24px', $out['typography_font_size_tablet'] );
        $this->assertSame( '16px', $out['typography_font_size_mobile'] );
    }

    public function test_apply_passthrough_scalar(): void {
        $out = Responsive::apply( [], 'padding', '12px' );
        $this->assertSame( '12px', $out['padding'] );
    }

    public function test_apply_ignores_unknown_breakpoint(): void {
        $out = Responsive::apply( [], 'gap', [ 'desktop' => '8px', 'foo' => 'x' ] );
        $this->assertArrayHasKey( 'gap', $out );
        $this->assertArrayNotHasKey( 'gap_foo', $out );
    }
}
