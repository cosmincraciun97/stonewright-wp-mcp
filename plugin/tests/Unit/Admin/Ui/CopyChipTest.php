<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\CopyChip;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\CopyChip
 */
final class CopyChipTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_chip_is_the_value_a_named_copy_button_and_a_hidden_live_status(): void {
		self::assertSame(
			'<span class="sw-ui-copy-chip"><code class="sw-ui-copy-chip__value" id="sw-ui-chip-1">https://example.test/wp-json/mcp/stonewright</code>'
			. '<button type="button" class="sw-ui-btn sw-ui-btn--tertiary sw-ui-btn--xs sw-ui-btn--icon" aria-label="Copy server URL" data-sw-ui-copy="#sw-ui-chip-1" data-sw-ui-copy-status="#sw-ui-chip-1-status" data-sw-ui-copied-label="Copied" data-sw-ui-copy-failed-label="Press Ctrl+C">'
			. '<svg class="sw-ui-icon sw-ui-copy__icon-copy" aria-hidden="true"><use href="#sw-ui-icon-copy"></use></svg>'
			. '<svg class="sw-ui-icon sw-ui-copy__icon-done" aria-hidden="true"><use href="#sw-ui-icon-check"></use></svg></button>'
			. '<span class="sw-ui-visually-hidden" role="status" id="sw-ui-chip-1-status"></span></span>',
			CopyChip::render( 'https://example.test/wp-json/mcp/stonewright', [ 'label' => 'server URL' ] )
		);
	}

	public function test_the_button_is_named_after_what_it_copies(): void {
		self::assertStringContainsString( 'aria-label="Copy"', CopyChip::render( 'x' ) );
		self::assertStringContainsString( 'aria-label="Copy server name"', CopyChip::render( 'x', [ 'label' => 'server name' ] ) );
		self::assertStringContainsString( 'aria-label="Copy it"', CopyChip::render( 'x', [ 'copy_label' => 'Copy it' ] ) );
	}

	public function test_two_chips_on_one_page_do_not_share_an_id(): void {
		self::assertStringContainsString( 'id="sw-ui-chip-1"', CopyChip::render( 'a' ) );
		self::assertStringContainsString( 'id="sw-ui-chip-2"', CopyChip::render( 'b' ) );
		$mine = CopyChip::render( 'c', [ 'id' => 'mine', 'class' => 'extra' ] );
		self::assertStringContainsString( 'id="mine"', $mine );
		self::assertStringContainsString( 'id="mine-status"', $mine );
		self::assertStringContainsString( 'class="sw-ui-copy-chip extra"', $mine );
	}

	public function test_the_value_is_escaped(): void {
		$html = CopyChip::render( '"><script>alert(1)</script>', [ 'label' => '"><img src=x>' ] );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<img', $html );
		self::assertStringContainsString( '&quot;&gt;&lt;script&gt;', $html );
	}
}
