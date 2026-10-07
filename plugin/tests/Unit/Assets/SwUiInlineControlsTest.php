<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Two small parts of the shared layer the AI Abilities page needs: a disclosure that sits inside a table cell
 * without the look of a card, and touch-sized switches and checkboxes at phone width.
 *
 * @coversNothing
 */
final class SwUiInlineControlsTest extends TestCase {

	private static function css(): string {
		return CssSource::read( 'admin/sw-ui.css' );
	}

	private static function value( string $selector, string $property, string $context = '' ): ?string {
		return CssSource::value_of( CssSource::rule_declarations( self::css(), $selector, $context ), $property );
	}

	public function test_an_inline_disclosure_has_no_border_background_or_padding_of_a_card(): void {
		self::assertSame( '0', self::value( '.sw-ui .sw-ui-disclosure--inline', 'border' ) );
		self::assertSame( 'transparent', self::value( '.sw-ui .sw-ui-disclosure--inline', 'background' ) );
		self::assertSame( '0', self::value( '.sw-ui .sw-ui-disclosure--inline > :where(summary)', 'padding' ) );
		self::assertSame( '0', self::value( '.sw-ui .sw-ui-disclosure--inline > :where(.sw-ui-disclosure__body)', 'border-top' ) );
	}

	public function test_an_inline_disclosure_still_has_a_24px_target_and_reads_as_a_link(): void {
		self::assertSame( 'var(--sw-control-h-xs)', self::value( '.sw-ui .sw-ui-disclosure--inline > :where(summary)', 'min-height' ) );
		self::assertSame( 'var(--sw-accent-text)', self::value( '.sw-ui .sw-ui-disclosure--inline > :where(summary)', 'color' ) );
	}

	public function test_the_inline_variant_comes_after_the_block_it_overrides(): void {
		$css = self::css();

		self::assertGreaterThan(
			(int) strpos( $css, '.sw-ui .sw-ui-disclosure > :where(summary) {' ),
			(int) strpos( $css, '.sw-ui .sw-ui-disclosure--inline > :where(summary) {' ),
			'Equal specificity: the later rule wins, so the variant must follow the base.'
		);
	}

	public function test_switches_and_checkboxes_are_40px_targets_at_phone_width(): void {
		$phone = '@media (max-width: 782px)';

		self::assertSame( '40px', self::value( '.sw-ui .sw-ui-switch', 'min-height', $phone ) );
		self::assertSame( '40px', self::value( '.sw-ui .sw-ui-switch :where(input)', 'height', $phone ) );
		self::assertSame( '40px', self::value( '.sw-ui .sw-ui-checkbox', 'min-height', $phone ) );
	}

	public function test_the_desktop_switch_is_not_smaller_than_the_wcag_floor(): void {
		self::assertSame( 'var(--sw-control-h-xs)', self::value( '.sw-ui .sw-ui-switch', 'min-height' ) );
		self::assertSame( '24px', self::value( '.sw-ui .sw-ui-switch :where(input)', 'height' ) );
	}

	public function test_a_code_textarea_is_monospace_from_the_tokens_and_keeps_its_lines(): void {
		$selector = '.sw-ui .sw-ui-textarea--code';

		self::assertSame( 'var(--sw-font-mono)', self::value( $selector, 'font-family' ) );
		self::assertSame( 'var(--sw-fs-sm)', self::value( $selector, 'font-size' ) );
		self::assertSame( 'pre', self::value( $selector, 'white-space' ), 'Code is not re-wrapped.' );
		self::assertSame( 'auto', self::value( $selector, 'overflow' ), 'A long line scrolls inside the field, not the page.' );
		self::assertSame( 'vertical', self::value( $selector, 'resize' ) );
		self::assertGreaterThanOrEqual( 160, (int) self::value( $selector, 'min-height' ) );
	}
}
