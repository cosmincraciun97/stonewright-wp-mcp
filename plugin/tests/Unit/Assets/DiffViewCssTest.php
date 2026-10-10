<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The CSS contract of the DiffView component (Ui\DiffView): it is a component of the shared layer, drawn from the
 * layer's tokens, readable on its own dark surface whatever colour scheme WordPress is in, scrolls inside its own
 * block and never moves the page sideways. The layer-wide rules (no !important, specificity, raw colours only in
 * the token sections) are checked for every rule by SwUiCssContractTest; this file checks what is specific to it.
 *
 * @coversNothing
 */
final class DiffViewCssTest extends TestCase {

	private const FILE = 'admin/sw-ui.css';

	private static function css(): string {
		return CssSource::read( self::FILE );
	}

	/** @return array<string, string> The custom properties of the first token rule. */
	private static function tokens(): array {
		$css = self::css();
		$cut = (int) strpos( $css, '/* === components' );

		return CssSource::custom_properties( substr( $css, 0, $cut ), static fn ( string $s ): bool => '.sw-ui' === $s );
	}

	/** A colour token as RGB, following a var() to the token it names. @return array{0: float, 1: float, 2: float} */
	private static function colour( string $name ): array {
		$tokens = self::tokens();
		self::assertArrayHasKey( $name, $tokens, $name . ' must be a token of the layer.' );
		$value = $tokens[ $name ];
		if ( 1 === preg_match( '/^var\((--[\w-]+)\)$/', $value, $m ) ) {
			return self::colour( $m[1] );
		}
		self::assertMatchesRegularExpression( '/^#[0-9a-f]{3,6}$/i', $value, $name . ' is a plain colour.' );

		return CssSource::hex_to_rgb( $value );
	}

	/** @return array<string, string> The declarations of the rule with this exact selector, in the components. */
	private static function declared( string $selector, string $context = '' ): array {
		$css  = self::css();
		$rest = substr( $css, (int) strpos( $css, '/* === components' ) );
		$out  = [];
		foreach ( CssSource::rules( $rest ) as $rule ) {
			if ( $rule['at'] || $rule['selector'] !== $selector || $rule['context'] !== $context ) {
				continue;
			}
			foreach ( CssSource::declarations( $rule['body'] ) as $declaration ) {
				$out[ $declaration['name'] ] = $declaration['value'];
			}
		}

		return $out;
	}

	public function test_the_diff_view_is_a_component_of_the_layer_with_its_own_named_block(): void {
		$css = self::css();

		self::assertStringContainsString( '/* 12a. Diff view (Ui\DiffView)', $css );
		foreach ( [ '.sw-ui .sw-ui-diff', '.sw-ui .sw-ui-diff__body', '.sw-ui .sw-ui-diff__table', '.sw-ui .sw-ui-diff__line--add', '.sw-ui .sw-ui-diff__line--del', '.sw-ui .sw-ui-diff__num' ] as $selector ) {
			self::assertNotSame( [], self::declared( $selector ), $selector . ' belongs to the layer.' );
		}
	}

	public function test_it_defines_no_page_file_and_no_colour_of_its_own_outside_the_tokens(): void {
		$css  = self::css();
		$cut  = (int) strpos( $css, '/* 12a. Diff view' );
		$from = (int) strpos( $css, '/* === components' );
		self::assertGreaterThan( $from, $cut, 'The diff view sits below the marker: raw colours stop above it.' );
		$block = CssSource::strip_comments( substr( $css, $cut, (int) strpos( $css, '/* 13.', $cut ) - $cut ) );

		self::assertNotSame( '', trim( $block ) );
		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\(/', $block );
		self::assertStringNotContainsString( '!important', $block );
		self::assertStringNotContainsString( 'prefers-color-scheme', $block, 'The admin is light only; the diff keeps its own dark surface.' );
	}

	public function test_every_kind_of_line_reads_on_its_own_tint(): void {
		$surfaces = [
			'context' => '--sw-diff-bg',
			'added'   => '--sw-diff-add-bg',
			'removed' => '--sw-diff-del-bg',
		];
		$text     = self::colour( '--sw-diff-fg' );
		$muted    = self::colour( '--sw-diff-muted' );
		foreach ( $surfaces as $kind => $surface ) {
			$background = self::colour( $surface );
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, $background ), 'Line text on a ' . $kind . ' line.' );
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $muted, $background ), 'Line numbers on a ' . $kind . ' line.' );
		}
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::colour( '--sw-diff-add-mark' ), self::colour( '--sw-diff-add-bg' ) ), 'The + marker.' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::colour( '--sw-diff-del-mark' ), self::colour( '--sw-diff-del-bg' ) ), 'The - marker.' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::colour( '--sw-inverse-muted' ), self::colour( '--sw-diff-hunk-bg' ) ), 'The hunk header.' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::colour( '--sw-diff-add-mark' ), self::colour( '--sw-diff-bg' ) ), 'The added count in the head.' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::colour( '--sw-diff-del-mark' ), self::colour( '--sw-diff-bg' ) ), 'The removed count in the head.' );
	}

	public function test_added_and_removed_lines_differ_by_more_than_hue(): void {
		$add = self::colour( '--sw-diff-add-bg' );
		$del = self::colour( '--sw-diff-del-bg' );
		$bg  = self::colour( '--sw-diff-bg' );

		// Each tint is distinguishable from the plain surface, so a line that changed never looks unchanged.
		self::assertGreaterThanOrEqual( 1.15, CssSource::contrast( $add, $bg ) );
		self::assertGreaterThanOrEqual( 1.15, CssSource::contrast( $del, $bg ) );
		// And the marker column is drawn from the line's own mark colour, so + and - carry a glyph as well.
		self::assertSame( 'var(--sw-diff-mark, var(--sw-diff-muted))', self::declared( '.sw-ui .sw-ui-diff__mark' )['color'] ?? '' );
	}

	public function test_the_lines_scroll_inside_the_block_and_never_widen_the_page(): void {
		$body  = self::declared( '.sw-ui .sw-ui-diff__body' );
		$table = self::declared( '.sw-ui .sw-ui-diff__table' );
		$code  = self::declared( '.sw-ui .sw-ui-diff__code' );
		$block = self::declared( '.sw-ui .sw-ui-diff' );

		self::assertSame( 'auto', $body['overflow'] ?? $body['overflow-x'] ?? '', 'The region scrolls.' );
		self::assertArrayHasKey( 'max-height', $body, 'A long diff scrolls inside the drawer instead of making it endless.' );
		self::assertSame( 'max-content', $table['width'] ?? '', 'Lines keep their length; the region scrolls.' );
		self::assertSame( '100%', $table['min-width'] ?? '', 'A short diff still fills the block.' );
		self::assertSame( 'pre', $code['white-space'] ?? '', 'Indentation survives.' );
		self::assertSame( '0', $block['min-width'] ?? '', 'The block may shrink inside a grid or flex parent.' );
		self::assertSame( 'hidden', $block['overflow'] ?? '', 'Nothing of the block pokes out of its rounded corners.' );
	}

	public function test_the_structured_kinds_wrap_instead_of_scrolling(): void {
		self::assertSame( 'anywhere', self::declared( '.sw-ui .sw-ui-diff__value' )['overflow-wrap'] ?? '' );
		self::assertSame( 'pre-wrap', self::declared( '.sw-ui .sw-ui-diff__value' )['white-space'] ?? '' );
		self::assertSame( 'anywhere', self::declared( '.sw-ui .sw-ui-diff__summary' )['overflow-wrap'] ?? '' );
	}

	public function test_it_narrows_its_gutters_on_a_phone_and_keeps_scrolling_inside(): void {
		$narrow = self::declared( '.sw-ui .sw-ui-diff__num', '@media (max-width: 782px)' );
		$body   = self::declared( '.sw-ui .sw-ui-diff__body', '@media (max-width: 782px)' );

		self::assertNotSame( [], $narrow, 'Line numbers take less room at 782px and below.' );
		self::assertNotSame( [], $body );
		self::assertStringContainsString( 'min(', $body['max-height'] ?? '' );
	}

	public function test_a_keyboard_user_gets_the_inverse_focus_ring_on_the_dark_block(): void {
		self::assertSame( 'var(--sw-diff-fg)', self::declared( '.sw-ui .sw-ui-diff--text' )['--sw-focus-ring'] ?? '', 'The ring must show on the dark surface.' );
	}

	public function test_forced_colours_keep_added_and_removed_apart_without_a_tint(): void {
		$add = self::declared( ':where(.sw-ui) .sw-ui-diff__line--add', '@media (forced-colors: active)' );
		$del = self::declared( ':where(.sw-ui) .sw-ui-diff__line--del', '@media (forced-colors: active)' );

		self::assertStringContainsString( 'solid', $add['border-inline-start'] ?? '' );
		self::assertStringContainsString( 'dashed', $del['border-inline-start'] ?? '' );
	}

	public function test_the_code_text_resets_what_core_puts_on_a_code_element(): void {
		$code = self::declared( '.sw-ui .sw-ui-diff__code' );

		self::assertSame( 'transparent', $code['background'] ?? '' );
		self::assertSame( '0', $code['padding'] ?? '' );
		self::assertSame( '0', $code['margin'] ?? '' );
		self::assertSame( 'inherit', $code['font'] ?? '' );
	}
}
