<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\WidgetRegistry;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetImplementationGuide;

/**
 * @covers \Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetImplementationGuide
 */
final class WidgetImplementationGuideTest extends TestCase {

	public function test_guide_recommends_native_widget_controls_and_research_signal(): void {
		$result = WidgetImplementationGuide::build(
			'Build a sticky header with menu and hamburger behavior',
			[ 'nav-menu', 'html' ],
			'Desktop row, mobile hamburger, sticky on scroll.'
		);

		self::assertTrue( $result['ok'] );
		self::assertNotEmpty( $result['recommendations'] );

		$first = $result['recommendations'][0];
		self::assertSame( 'nav-menu', $first['widget'] );
		self::assertSame( 'stonewright/elementor-add-nav-menu', $first['ability'] );
		self::assertArrayHasKey( 'Content', $first['required_controls'] );
		self::assertArrayHasKey( 'Style', $first['required_controls'] );
		self::assertArrayHasKey( 'Advanced', $first['required_controls'] );
		self::assertContains( 'responsive visibility', $first['required_controls']['Advanced'] );
		self::assertContains( 'When any recommendation has needs_online_research=true, research official Elementor documentation before writing.', $result['global_required_steps'] );
		self::assertContains( 'Before using background assets, write an asset selection plan: target section, source layer/node, crop bounds, WordPress media URL, and why it is not a full-page screenshot.', $result['global_required_steps'] );
		self::assertContains( 'Do not use a full-page screenshot as a section background; export the exact layer/section asset or recreate simple colors/gradients with Elementor controls.', $result['global_required_steps'] );

		$widgets = array_column( $result['recommendations'], 'widget' );
		self::assertNotContains( 'html', $widgets, 'HTML must not be recommended unless explicitly approved.' );
	}
}
