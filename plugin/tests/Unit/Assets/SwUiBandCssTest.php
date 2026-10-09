<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The band at the top of every Stonewright page and the tooltip of its EXP marker, as the layer styles them
 * (assets/admin/sw-ui.css). SwUiCssContractTest holds the rules every part of the layer keeps; these checks hold
 * the values of this one component.
 *
 * @coversNothing
 */
final class SwUiBandCssTest extends TestCase {

	private const FILE   = 'admin/sw-ui.css';
	private const MARKER = '/* === components';

	private static function css(): string {
		return CssSource::read( self::FILE );
	}

	/** @return array<string, string> The custom properties the token section declares on .sw-ui. */
	private static function tokens(): array {
		$css = self::css();

		return CssSource::custom_properties( substr( $css, 0, (int) strpos( $css, self::MARKER ) ), static fn ( string $s ): bool => '.sw-ui' === $s );
	}

	/** @return list<array{name: string, value: string, important: bool}> */
	private static function rule( string $selector, string $context = '' ): array {
		return CssSource::rule_declarations( self::css(), $selector, $context );
	}

	private static function value( string $selector, string $property, string $context = '' ): ?string {
		return CssSource::value_of( self::rule( $selector, $context ), $property );
	}

	/** @return array{0: float, 1: float, 2: float} */
	private static function colour( string $token, ?array $over = null ): array {
		$value = self::tokens()[ $token ];
		if ( 1 === preg_match( '/^rgb\((\d+) (\d+) (\d+) \/ (\.?\d+(?:\.\d+)?)\)$/', $value, $m ) ) {
			return CssSource::over( [ (float) $m[1], (float) $m[2], (float) $m[3] ], (float) $m[4], $over ?? [ 0.0, 0.0, 0.0 ] );
		}

		return CssSource::hex_to_rgb( $value );
	}

	// ---------------------------------------------------------------------------------------------
	// Tokens
	// ---------------------------------------------------------------------------------------------

	public function test_the_band_and_its_tooltip_colours_are_tokens_of_the_layer(): void {
		$tokens = self::tokens();

		$expected = [
			'--sw-band-bg'      => '#16181d',
			'--sw-band-fg'      => '#f8f9fa',
			'--sw-band-link'    => 'rgb(248 249 250 / .78)',
			'--sw-band-rule'    => 'rgb(255 255 255 / .18)',
			'--sw-band-hover'   => 'rgb(255 255 255 / .08)',
			'--sw-band-current' => 'rgb(79 70 229 / .35)',
			'--sw-tip-bg'       => '#171921',
			'--sw-tip-fg'       => '#f7f8fb',
		];
		foreach ( $expected as $name => $value ) {
			self::assertSame( $value, $tokens[ $name ] ?? null, $name );
		}
		foreach ( [ '--sw-band-label', '--sw-band-count', '--sw-band-focus', '--sw-band-shadow', '--sw-band-ease', '--sw-band-exp-size' ] as $name ) {
			self::assertArrayHasKey( $name, $tokens, $name );
		}
		self::assertSame( '8px', $tokens['--sw-band-exp-size'], 'The marker is a small superscript.' );
		self::assertSame( 'cubic-bezier(.25, .46, .45, .94)', $tokens['--sw-band-ease'] );
	}

	public function test_every_text_on_the_band_reads_on_it(): void {
		$band = self::colour( '--sw-band-bg' );

		foreach ( [ '--sw-band-fg', '--sw-band-link', '--sw-band-label' ] as $name ) {
			$text = self::colour( $name, $band );
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, $band ), $name . ' on the band' );
		}
		// The hover and the current page keep the white text readable on their tint.
		foreach ( [ '--sw-band-hover', '--sw-band-current' ] as $name ) {
			$tinted = self::colour( $name, $band );
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::colour( '--sw-band-fg' ), $tinted ), $name );
		}
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( self::colour( '--sw-band-focus' ), $band ), 'The keyboard ring on the band (WCAG 1.4.11).' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( self::colour( '--sw-tip-fg' ), self::colour( '--sw-tip-bg' ) ), 'The tooltip text.' );
	}

	// ---------------------------------------------------------------------------------------------
	// The band
	// ---------------------------------------------------------------------------------------------

	public function test_the_band_is_a_dark_flat_square_panel_that_scrolls_with_the_page(): void {
		$band = self::rule( '.sw-ui .sw-ui-band' );

		self::assertSame( 'flex', CssSource::value_of( $band, 'display' ) );
		self::assertSame( 'wrap', CssSource::value_of( $band, 'flex-wrap' ) );
		self::assertSame( 'var(--sw-band-bg)', CssSource::value_of( $band, 'background' ) );
		self::assertSame( 'var(--sw-band-fg)', CssSource::value_of( $band, 'color' ) );
		self::assertSame( 'var(--sw-band-shadow)', CssSource::value_of( $band, 'box-shadow' ) );
		self::assertSame( 'var(--sw-space-3) var(--sw-space-5)', CssSource::value_of( $band, 'padding' ), '12px above and below, 24px at the sides.' );
		self::assertSame( 'var(--sw-space-4)', CssSource::value_of( $band, 'gap' ), '16px between the mark and the links, and between the rows.' );
		self::assertNull( CssSource::value_of( $band, 'border-radius' ), 'Square corners.' );
		self::assertNull( CssSource::value_of( $band, 'position' ), 'Not pinned.' );
		self::assertSame( '0', CssSource::value_of( $band, 'margin' ), 'No margin: the strip above is the WordPress Help row, and the space below comes from the content and the header.' );
	}

	public function test_the_page_title_sits_24px_below_the_band_and_16px_at_782px_and_below(): void {
		$shell = CssSource::read( 'admin/shell.css' );
		self::assertSame( 'var(--sw-space-3) var(--sw-space-5) var(--sw-space-7)', CssSource::value_of( CssSource::rule_declarations( $shell, '.sw-shell__content' ), 'padding' ), '12px of content padding and the 12px the page header pads itself: 24px.' );
		self::assertSame( 'var(--sw-space-1) var(--sw-space-3) var(--sw-space-3)', CssSource::value_of( CssSource::rule_declarations( $shell, '.sw-shell__content', '@media screen and (max-width: 782px)' ), 'padding' ), '4px and the 12px of the header: 16px.' );
		self::assertSame( 'var(--sw-space-3) 0', self::value( '.sw-ui .sw-ui-page-header', 'padding' ) );
	}

	public function test_the_band_keeps_a_keyboard_ring_that_shows_on_dark(): void {
		self::assertSame( 'var(--sw-band-focus)', self::value( '.sw-ui .sw-ui-band', '--sw-focus-ring' ), 'The layer\'s one outline, in a colour that shows on the band.' );
	}

	public function test_the_band_narrows_in_two_steps(): void {
		$tablet = self::rule( '.sw-ui .sw-ui-band', '@media (max-width: 782px)' );
		self::assertSame( 'var(--sw-space-3)', CssSource::value_of( $tablet, 'padding-inline' ) );
		self::assertNull( CssSource::value_of( $tablet, 'margin-bottom' ), 'The space under the band comes from the content and the header.' );

		$phone = self::rule( '.sw-ui .sw-ui-band', '@media (max-width: 400px)' );
		self::assertSame( 'var(--sw-space-2)', CssSource::value_of( $phone, 'padding-inline' ) );
		self::assertNull( CssSource::value_of( $phone, 'margin-inline' ), 'The 8px inset at the sides is the padding of the shell itself.' );
		self::assertSame( '28px', CssSource::value_of( $phone, '--sw-band-link-h' ) );
		self::assertSame( 'var(--sw-fs-xs)', CssSource::value_of( $phone, '--sw-band-link-fs' ) );
		self::assertSame( 'var(--sw-space-2)', CssSource::value_of( $phone, '--sw-band-link-pad' ) );

		self::assertSame( '100%', CssSource::value_of( self::rule( '.sw-ui .sw-ui-band__group', '@media (max-width: 400px)' ), 'flex-basis' ), 'Every group is a row of its own on a phone.' );
		self::assertSame( 'none', CssSource::value_of( self::rule( '.sw-ui .sw-ui-band__label', '@media (max-width: 400px)' ), 'display' ) );
		self::assertSame( '0', CssSource::value_of( self::rule( '.sw-ui .sw-ui-band__group--labelled', '@media (max-width: 400px)' ), 'border-inline-start-width' ), 'No rule on a phone.' );
	}

	public function test_the_name_is_bold_white_and_the_mark_is_28_pixels(): void {
		$name = self::rule( '.sw-ui .sw-ui-band__name' );
		self::assertSame( 'var(--sw-fs-md)', CssSource::value_of( $name, 'font-size' ) );
		self::assertSame( '700', CssSource::value_of( $name, 'font-weight' ) );
		self::assertSame( '.02em', CssSource::value_of( $name, 'letter-spacing' ) );
		self::assertSame( 'var(--sw-band-fg)', CssSource::value_of( $name, 'color' ) );

		$logo = self::rule( '.sw-ui .sw-ui-band__logo' );
		self::assertSame( '28px', CssSource::value_of( $logo, 'width' ) );
		self::assertSame( '28px', CssSource::value_of( $logo, 'height' ) );
	}

	public function test_links_flow_in_wrapping_groups_with_a_label_and_a_thin_rule_for_the_labelled_ones(): void {
		self::assertSame( 'wrap', self::value( '.sw-ui .sw-ui-band__nav', 'flex-wrap' ) );
		self::assertSame( 'var(--sw-space-2)', self::value( '.sw-ui .sw-ui-band__nav', 'gap' ) );
		self::assertSame( '0', self::value( '.sw-ui .sw-ui-band__nav', 'min-width' ) );

		$group = self::rule( '.sw-ui .sw-ui-band__group' );
		self::assertSame( 'var(--sw-space-1)', CssSource::value_of( $group, 'gap' ) );
		self::assertSame( 'wrap', CssSource::value_of( $group, 'flex-wrap' ) );
		self::assertSame( 'var(--sw-band-link-h)', CssSource::value_of( $group, 'min-height' ) );

		$labelled = self::rule( '.sw-ui .sw-ui-band__group--labelled' );
		self::assertSame( 'var(--sw-space-2)', CssSource::value_of( $labelled, 'padding-inline-start' ) );
		self::assertSame( '1px solid var(--sw-band-rule)', CssSource::value_of( $labelled, 'border-inline-start' ) );

		$label = self::rule( '.sw-ui .sw-ui-band__label' );
		self::assertSame( 'uppercase', CssSource::value_of( $label, 'text-transform' ) );
		self::assertSame( 'var(--sw-fs-xs)', CssSource::value_of( $label, 'font-size' ) );
		self::assertSame( '.06em', CssSource::value_of( $label, 'letter-spacing' ) );
		self::assertSame( 'var(--sw-band-label)', CssSource::value_of( $label, 'color' ) );

		self::assertSame( '0', CssSource::value_of( self::rule( ':where(.sw-ui) .sw-ui-band__group:first-child' ), 'border-inline-start-width' ), 'No rule before the first group.' );
	}

	public function test_a_link_is_a_pill_that_changes_colour_with_the_layers_timing(): void {
		$link = self::rule( '.sw-ui .sw-ui-band__link' );

		self::assertSame( 'var(--sw-band-link-h)', CssSource::value_of( $link, 'height' ) );
		self::assertSame( '0 var(--sw-band-link-pad)', CssSource::value_of( $link, 'padding' ) );
		self::assertSame( 'var(--sw-band-link-fs)', CssSource::value_of( $link, 'font-size' ) );
		self::assertSame( 'var(--sw-weight-medium)', CssSource::value_of( $link, 'font-weight' ) );
		self::assertSame( 'var(--sw-band-link)', CssSource::value_of( $link, 'color' ) );
		self::assertSame( 'var(--sw-radius-sm)', CssSource::value_of( $link, 'border-radius' ) );
		self::assertSame( 'none', CssSource::value_of( $link, 'text-decoration' ) );
		self::assertSame( 'background-color var(--sw-dur) var(--sw-band-ease), color var(--sw-dur) var(--sw-band-ease)', CssSource::value_of( $link, 'transition' ), 'A scaled duration, so reduced motion makes it instant.' );
		self::assertSame( 'var(--sw-control-h-sm)', self::value( '.sw-ui .sw-ui-band', '--sw-band-link-h' ), '32px, above the 24px floor; 28px on a phone.' );
		self::assertSame( 'var(--sw-fs-sm)', self::value( '.sw-ui .sw-ui-band', '--sw-band-link-fs' ) );
		self::assertSame( 'var(--sw-band-link-pad-exp)', CssSource::value_of( self::rule( '.sw-ui .sw-ui-band__link--exp' ), 'padding-inline-end' ) );
		self::assertSame( '32px', self::value( '.sw-ui .sw-ui-band', '--sw-band-link-pad-exp' ) );
	}

	public function test_the_states_of_a_link(): void {
		self::assertSame( 'var(--sw-band-hover)', self::value( ':where(.sw-ui) .sw-ui-band__link:hover', 'background-color' ) );
		self::assertSame( 'var(--sw-band-fg)', self::value( ':where(.sw-ui) .sw-ui-band__link:hover', 'color' ) );

		$current = self::rule( ':where(.sw-ui) .sw-ui-band__link[aria-current="page"]' );
		self::assertSame( 'var(--sw-band-current)', CssSource::value_of( $current, 'background-color' ) );
		self::assertSame( 'var(--sw-band-fg)', CssSource::value_of( $current, 'color' ) );

		$focus = self::rule( ':where(.sw-ui) .sw-ui-band__link:focus-visible' );
		self::assertSame( 'var(--sw-band-hover)', CssSource::value_of( $focus, 'background-color' ) );
		self::assertSame( 'var(--sw-radius-control)', CssSource::value_of( $focus, 'border-radius' ) );

		// The current page keeps its tint on hover and on focus: its rule comes last.
		$css = self::css();
		self::assertGreaterThan( (int) strpos( $css, '.sw-ui-band__link:focus-visible' ), (int) strpos( $css, '.sw-ui-band__link[aria-current="page"]' ) );
	}

	public function test_a_link_draws_no_ring_of_its_own_so_the_keyboard_gets_the_layers_one_outline(): void {
		foreach ( CssSource::rules( self::css() ) as $rule ) {
			if ( $rule['at'] || ! str_contains( $rule['selector'], 'sw-ui-band__link' ) ) {
				continue;
			}
			foreach ( CssSource::declarations( $rule['body'] ) as $declaration ) {
				self::assertNotContains( $declaration['name'], [ 'outline', 'outline-color', 'outline-offset', 'box-shadow' ], $rule['selector'] . ' must leave the ring to the layer.' );
			}
		}
	}

	public function test_the_exp_marker_is_a_raised_help_cursor_superscript(): void {
		$exp = self::rule( '.sw-ui .sw-ui-band__exp' );

		self::assertSame( 'absolute', CssSource::value_of( $exp, 'position' ) );
		self::assertSame( 'var(--sw-space-1)', CssSource::value_of( $exp, 'inset-block-start' ), '4px below the top of the link.' );
		self::assertSame( '6px', CssSource::value_of( $exp, 'inset-inline-end' ) );
		self::assertSame( 'var(--sw-band-exp-size)', CssSource::value_of( $exp, 'font-size' ) );
		self::assertSame( 'var(--sw-band-exp-size)', CssSource::value_of( $exp, 'line-height' ) );
		self::assertSame( 'var(--sw-weight-semibold)', CssSource::value_of( $exp, 'font-weight' ) );
		self::assertSame( '.06em', CssSource::value_of( $exp, 'letter-spacing' ) );
		self::assertSame( 'uppercase', CssSource::value_of( $exp, 'text-transform' ) );
		self::assertSame( 'var(--sw-band-fg)', CssSource::value_of( $exp, 'color' ) );
		self::assertSame( 'help', CssSource::value_of( $exp, 'cursor' ) );
	}

	public function test_a_count_on_the_band_is_a_quiet_pill(): void {
		$count = self::rule( '.sw-ui .sw-ui-band__count' );

		self::assertSame( 'var(--sw-band-count)', CssSource::value_of( $count, 'background-color' ) );
		self::assertSame( 'var(--sw-band-fg)', CssSource::value_of( $count, 'color' ) );
		self::assertSame( 'var(--sw-fs-xs)', CssSource::value_of( $count, 'font-size' ) );
		self::assertSame( 'var(--sw-radius-pill)', CssSource::value_of( $count, 'border-radius' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// The tooltip
	// ---------------------------------------------------------------------------------------------

	public function test_the_tooltip_floats_above_the_admin_bar_and_ignores_the_pointer(): void {
		$tip = self::rule( '.sw-ui .sw-ui-band-tip' );

		self::assertSame( 'absolute', CssSource::value_of( $tip, 'position' ) );
		self::assertSame( 'var(--sw-z-popover)', CssSource::value_of( $tip, 'z-index' ) );
		self::assertSame( '100000', self::tokenValue( '--sw-z-popover' ), 'Above the admin bar at 99999.' );
		self::assertSame( 'none', CssSource::value_of( $tip, 'pointer-events' ) );
		self::assertSame( '280px', CssSource::value_of( $tip, 'max-width' ) );
		self::assertSame( 'max-content', CssSource::value_of( $tip, 'width' ) );
		self::assertSame( 'var(--sw-space-2) var(--sw-space-3)', CssSource::value_of( $tip, 'padding' ) );
		self::assertSame( 'var(--sw-radius-sm)', CssSource::value_of( $tip, 'border-radius' ) );
		self::assertSame( 'var(--sw-tip-bg)', CssSource::value_of( $tip, 'background' ) );
		self::assertSame( 'var(--sw-tip-fg)', CssSource::value_of( $tip, 'color' ) );
		self::assertSame( 'var(--sw-fs-xs)', CssSource::value_of( $tip, 'font-size' ) );
		self::assertSame( 'var(--sw-band-shadow)', CssSource::value_of( $tip, 'box-shadow' ) );
		self::assertNull( CssSource::value_of( $tip, 'border' ), 'No border and no arrow.' );
		self::assertStringNotContainsString( 'sw-ui-band-tip::', self::css() );
		self::assertStringNotContainsString( 'sw-ui-band-tip::before', self::css() );
	}

	public function test_the_tooltip_fades_in_and_the_fade_follows_reduced_motion(): void {
		$tip = self::rule( '.sw-ui .sw-ui-band-tip' );

		self::assertSame( '0', CssSource::value_of( $tip, 'opacity' ) );
		self::assertSame( 'opacity var(--sw-dur) var(--sw-band-ease)', CssSource::value_of( $tip, 'transition' ), 'A scaled 150ms: --sw-motion-scale is 0 under reduced motion.' );
		self::assertSame( '1', self::value( ':where(.sw-ui) .sw-ui-band-tip[data-state="shown"]', 'opacity' ) );
		self::assertSame( 'calc(150ms * var(--sw-motion-scale))', self::tokens()['--sw-dur'] );
	}

	public function test_the_current_link_stays_visible_when_forced_colours_drop_backgrounds(): void {
		$rule = self::rule( ':where(.sw-ui) .sw-ui-band__link[aria-current="page"]', '@media (forced-colors: active)' );

		self::assertSame( 'underline', CssSource::value_of( $rule, 'text-decoration' ) );
	}

	private static function tokenValue( string $name ): string {
		return self::tokens()[ $name ];
	}
}
