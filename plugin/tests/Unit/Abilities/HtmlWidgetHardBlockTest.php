<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\HtmlWidgetPolicy;

/**
 * @covers \Stonewright\WpMcp\Elementor\HtmlWidgetPolicy
 */
final class HtmlWidgetHardBlockTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_allow_html_widgets' => false,
		];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_site_off_rejects_even_when_per_call_true(): void {
		$result = HtmlWidgetPolicy::allowed( [ 'allow_html_widget' => true ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_html_widget_disabled', $result->get_error_code() );
	}

	public function test_site_on_still_requires_flag_when_provided_false(): void {
		$GLOBALS['stonewright_test_options']['stonewright_allow_html_widgets'] = true;
		$result = HtmlWidgetPolicy::allowed( [ 'allow_html_widget' => false ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'html_widget_requires_explicit_approval', $result->get_error_code() );
	}

	public function test_site_on_allows_with_flag_true(): void {
		$GLOBALS['stonewright_test_options']['stonewright_allow_html_widgets'] = true;
		$result = HtmlWidgetPolicy::allowed( [ 'allow_html_widget' => true ] );
		self::assertTrue( $result );
	}
}
