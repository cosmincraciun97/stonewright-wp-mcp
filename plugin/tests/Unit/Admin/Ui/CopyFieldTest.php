<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\CopyField;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\CopyField
 */
final class CopyFieldTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_copy_field_is_the_value_a_named_copy_button_and_a_live_status(): void {
		self::assertSame(
			'<div class="sw-ui-copy"><code class="sw-ui-copy__value" id="sw-ui-copy-1">https://example.test/wp-json/mcp/stonewright</code>'
			. '<button type="button" class="sw-ui-btn sw-ui-btn--icon" aria-label="Copy MCP server URL" data-sw-ui-copy="#sw-ui-copy-1" data-sw-ui-copied-label="Copied" data-sw-ui-copy-failed-label="Press Ctrl+C">'
			. '<svg class="sw-ui-icon sw-ui-copy__icon-copy" aria-hidden="true"><use href="#sw-ui-icon-copy"></use></svg>'
			. '<svg class="sw-ui-icon sw-ui-copy__icon-done" aria-hidden="true"><use href="#sw-ui-icon-check"></use></svg></button>'
			. '<span class="sw-ui-copy__status" role="status"></span></div>',
			CopyField::render( 'https://example.test/wp-json/mcp/stonewright', [ 'label' => 'MCP server URL' ] )
		);
	}

	public function test_the_button_is_named_after_what_it_copies(): void {
		self::assertStringContainsString( 'aria-label="Copy"', CopyField::render( 'x' ) );
		self::assertStringContainsString( 'aria-label="Copy install command"', CopyField::render( 'x', [ 'label' => 'install command' ] ) );
		self::assertStringContainsString( 'aria-label="Copy it"', CopyField::render( 'x', [ 'copy_label' => 'Copy it' ] ) );
	}

	public function test_two_fields_on_one_page_do_not_share_an_id(): void {
		$first  = CopyField::render( 'a' );
		$second = CopyField::render( 'b' );

		self::assertStringContainsString( 'id="sw-ui-copy-1"', $first );
		self::assertStringContainsString( 'id="sw-ui-copy-2"', $second );
		self::assertStringContainsString( 'data-sw-ui-copy="#sw-ui-copy-2"', $second );
		self::assertStringContainsString( 'id="mine"', CopyField::render( 'c', [ 'id' => 'mine' ] ) );
	}

	public function test_a_secret_is_masked_read_only_and_can_be_revealed(): void {
		$html = CopyField::render( 'tok-123', [ 'secret' => true, 'label' => 'Bridge token' ] );

		self::assertStringContainsString( '<input type="password" class="sw-ui-input sw-ui-copy__value" id="sw-ui-copy-1" value="tok-123" readonly autocomplete="off" aria-label="Bridge token">', $html );
		self::assertStringContainsString( 'aria-pressed="false" data-sw-ui-reveal="#sw-ui-copy-1" data-sw-ui-show-label="Show value" data-sw-ui-hide-label="Hide value"', $html );
		self::assertStringStartsWith( '<div class="sw-ui-copy sw-ui-copy--secret">', $html );
		self::assertStringNotContainsString( '<code', $html, 'A secret is never printed as readable text.' );
	}

	public function test_the_value_is_escaped(): void {
		$html = CopyField::render( '"><script>alert(1)</script>' );
		$secret = CopyField::render( '"><script>alert(1)</script>', [ 'secret' => true ] );

		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( '<script', $secret );
		self::assertStringContainsString( '&quot;&gt;&lt;script&gt;', $html );
	}
}
