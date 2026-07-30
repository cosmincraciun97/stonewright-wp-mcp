<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetBuilder\Compiler;

final class WidgetCompilerResponsiveTest extends TestCase {
    public function test_responsive_flag_emits_add_responsive_control(): void {
        $spec = [
            'slug'     => 'my-card',
            'title'    => 'My Card',
            'category' => 'general',
            'controls' => [
                [
                    'name'       => 'padding',
                    'type'       => 'slider',
                    'label'      => 'Padding',
                    'responsive' => true,
                ],
                [
                    'name'  => 'title',
                    'type'  => 'text',
                    'label' => 'Title',
                ],
            ],
            'template' => '<div>{{ title }}</div>',
        ];
        $src = ( new Compiler() )->compile( $spec );
        $this->assertStringContainsString( '$this->add_responsive_control(', $src );
        $this->assertStringContainsString( '$this->add_control(', $src );
    }
}
