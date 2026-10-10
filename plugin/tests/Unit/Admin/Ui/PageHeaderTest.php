<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Badge;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\PageHeader;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\PageHeader
 */
final class PageHeaderTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_header_has_a_title_a_lede_and_a_status_area(): void {
		self::assertSame(
			'<header class="sw-ui-page-header"><div class="sw-ui-page-header__main">'
			. '<h1 class="sw-ui-page-title">Overview</h1><p class="sw-ui-page-lede">Your AI connection at a glance.</p></div>'
			. '<div class="sw-ui-page-header__aside"><span class="sw-ui-badge sw-ui-badge--ok sw-ui-badge--dot">AI abilities on</span></div></header>',
			PageHeader::render(
				'Overview',
				[
					'lede'       => 'Your AI connection at a glance.',
					'aside_html' => Badge::render( 'AI abilities on', [ 'variant' => 'ok', 'dot' => true ] ),
				]
			)
		);
	}

	public function test_the_title_alone_is_a_valid_header(): void {
		self::assertSame(
			'<header class="sw-ui-page-header"><div class="sw-ui-page-header__main"><h1 class="sw-ui-page-title">Setup</h1></div></header>',
			PageHeader::render( 'Setup' )
		);
	}

	public function test_the_heading_level_can_be_lowered_but_stays_a_heading(): void {
		self::assertStringContainsString( '<h2 class="sw-ui-page-title">', PageHeader::render( 'T', [ 'heading' => 2 ] ) );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">', PageHeader::render( 'T', [ 'heading' => 0 ] ) );
		self::assertStringContainsString( '<h6 class="sw-ui-page-title">', PageHeader::render( 'T', [ 'heading' => 10 ] ) );
	}

	public function test_text_is_escaped(): void {
		$html = PageHeader::render( '<script>x</script>', [ 'lede' => '<b>l</b>' ] );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<b>', $html );
	}

	public function test_the_header_prints_no_line_above_the_title(): void {
		// The title is the first thing in the header: there is no eyebrow option and no logo square.
		$html = PageHeader::render( 'Overview', [ 'eyebrow' => 'Stonewright', 'lede' => 'Lede.' ] );

		self::assertStringStartsWith( '<header class="sw-ui-page-header"><div class="sw-ui-page-header__main"><h1 class="sw-ui-page-title">Overview</h1>', $html );
		self::assertStringNotContainsString( 'eyebrow', $html );
		self::assertStringNotContainsString( 'page-header__logo', $html );
		self::assertStringNotContainsString( 'Stonewright', $html );

		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/includes/Admin/Ui/PageHeader.php' );
		self::assertStringNotContainsString( 'eyebrow', $source, 'Nothing reads the option, so it is not documented or kept.' );
	}

	public function test_the_header_does_not_claim_the_banner_landmark(): void {
		self::assertStringNotContainsString( 'role=', PageHeader::render( 'T' ), 'A header inside the main region is not the page banner.' );
	}
}
