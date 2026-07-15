<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\ElementorWidgets;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\AddWidget;
use Stonewright\WpMcp\Abilities\ElementorWidgets\AddHtml;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorWidgets\WidgetAbilityBase
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\AddWidget
 */
final class HtmlWidgetGateTest extends TestCase {

	public function test_dedicated_html_widget_requires_explicit_allow_flag(): void {
		$result = ( new AddHtml() )->execute(
			[
				'post_id'   => 123,
				'parent_id' => 'root',
				'settings'  => [ 'html' => '<div>unsafe fallback</div>' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
	}

	public function test_raw_add_widget_rejects_html_widget_without_explicit_allow_flag(): void {
		$result = ( new AddWidget() )->execute(
			[
				'post_id'     => 123,
				'parent_id'   => 'root',
				'widget_type' => 'html',
				'settings'    => [ 'html' => '<div>unsafe fallback</div>' ],
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
	}

	public function test_html_widget_schema_exposes_explicit_allow_flag(): void {
		$schema = ( new AddHtml() )->input_schema();

		self::assertArrayHasKey( 'allow_html_widget', $schema['properties'] );
		self::assertStringContainsString( 'explicitly asked', $schema['properties']['allow_html_widget']['description'] );
	}
}
