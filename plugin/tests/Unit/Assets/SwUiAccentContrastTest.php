<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The accent follows the user's WordPress admin colour scheme, so its contrast is not ours to choose.
 * This test evaluates the formulas in sw-ui.css for every core scheme and checks, on white, on the page and on
 * the accent tint, that
 *   - accent text (--sw-accent-text, which links, current tabs and the focus ring use) is 4.5:1 or better, and
 *   - white button labels (--sw-on-accent) on the solid accent fill (--sw-accent-fill and its hover) are 4.5:1 or better.
 * Where a scheme's own accent is too light, sw-ui.css carries a fallback for that scheme only.
 *
 * @coversNothing
 */
final class SwUiAccentContrastTest extends TestCase {

	private const SUPPORTS = '@supports (color: color-mix(in srgb, red 50%, blue))';

	/** @var array<string, string> */
	private static array $defaults = [];

	/** @var array<string, array<string, string>> Per scheme name: the tokens its override rules set. */
	private static array $overrides = [];

	public static function setUpBeforeClass(): void {
		$css = CssSource::read( 'admin/sw-ui.css' );

		self::$defaults = CssSource::custom_properties( $css, static fn ( string $s ): bool => '.sw-ui' === $s );
		foreach ( CoreAdminSchemes::NAMES as $name ) {
			self::$overrides[ $name ] = [];
		}
		foreach ( CssSource::rules( $css ) as $rule ) {
			if ( $rule['at'] || self::SUPPORTS !== $rule['context'] ) {
				continue;
			}
			foreach ( CssSource::selectors( $rule['selector'] ) as $selector ) {
				foreach ( CoreAdminSchemes::NAMES as $name ) {
					$direct = '.admin-color-' . $name . ' .sw-ui' === $selector;
					$listed = str_starts_with( $selector, ':is(' ) && str_contains( $selector, '.admin-color-' . $name ) && str_ends_with( $selector, ') .sw-ui' );
					if ( $direct || $listed ) {
						foreach ( CssSource::declarations( $rule['body'] ) as $declaration ) {
							self::$overrides[ $name ][ $declaration['name'] ] = $declaration['value'];
						}
					}
				}
			}
		}
	}

	/**
	 * Evaluate a token expression the way the browser does for the given scheme.
	 *
	 * @param array{0: array{0: float, 1: float, 2: float}, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}} $steps accent, darker-10, darker-20
	 * @return array{0: float, 1: float, 2: float}
	 */
	private static function evaluate( string $expression, string $scheme, array $steps ): array {
		$expression = trim( $expression );
		$tokens     = array_merge( self::$defaults, self::$overrides[ $scheme ] );

		if ( 1 === preg_match( '/^#[0-9a-fA-F]{3,6}$/', $expression ) ) {
			return CssSource::hex_to_rgb( $expression );
		}
		if ( 1 === preg_match( '/^var\(\s*(--[\w-]+)\s*(?:,\s*(.*))?\)$/s', $expression, $m ) ) {
			$external = [
				'--wp-admin-theme-color'           => $steps[0],
				'--wp-admin-theme-color-darker-10' => $steps[1],
				'--wp-admin-theme-color-darker-20' => $steps[2],
			];
			if ( isset( $external[ $m[1] ] ) ) {
				return $external[ $m[1] ];
			}
			if ( isset( $tokens[ $m[1] ] ) ) {
				return self::evaluate( $tokens[ $m[1] ], $scheme, $steps );
			}
			if ( isset( $m[2] ) ) {
				return self::evaluate( $m[2], $scheme, $steps );
			}
			self::fail( 'Unresolved token ' . $m[1] );
		}
		if ( 1 === preg_match( '/^color-mix\(\s*in srgb,\s*(.+?)\s+([\d.]+)%,\s*(.+)\)$/s', $expression, $m ) ) {
			return CssSource::mix( self::evaluate( $m[1], $scheme, $steps ), (float) $m[2], self::evaluate( $m[3], $scheme, $steps ) );
		}

		self::fail( 'Cannot evaluate ' . $expression );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function palettes(): array {
		$cases = [];
		foreach ( CoreAdminSchemes::NAMES as $name ) {
			$cases[ 'WordPress 7.1 ' . $name ] = [ 'current', $name ];
			$cases[ 'earlier accent ' . $name ] = [ 'earlier', $name ];
		}

		return $cases;
	}

	/** @dataProvider palettes */
	public function test_accent_text_and_button_labels_reach_4_5_to_1_in_every_scheme( string $palette, string $scheme ): void {
		$steps = 'current' === $palette ? CoreAdminSchemes::current()[ $scheme ] : CoreAdminSchemes::earlier()[ $scheme ];
		$white = CssSource::hex_to_rgb( '#fff' );
		$page  = self::evaluate( 'var(--sw-bg)', $scheme, $steps );
		$soft  = CssSource::mix( $steps[0], 9, $white );

		$text = self::evaluate( 'var(--sw-accent-text)', $scheme, $steps );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, $white ), $scheme . ': accent text on white' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, $page ), $scheme . ': accent text on the page' );
		self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, $soft ), $scheme . ': accent text on the accent tint' );

		$label = self::evaluate( 'var(--sw-on-accent)', $scheme, $steps );
		foreach ( [ '--sw-accent-fill', '--sw-accent-fill-hover' ] as $fill ) {
			self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $label, self::evaluate( 'var(' . $fill . ')', $scheme, $steps ) ), $scheme . ': button label on ' . $fill );
		}

		// The focus ring is the accent text colour, so it clears the 3:1 non-text bar everywhere it is drawn.
		$ring = self::evaluate( 'var(--sw-focus-ring)', $scheme, $steps );
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( $ring, $white ), $scheme . ': focus ring on white' );
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( $ring, $page ), $scheme . ': focus ring on the page' );

		// Underlines, outlines and borders that show a state are drawn with the fill, which is dark enough to carry
		// a white label, so they clear the same 3:1 bar against the surface and the page.
		$fill = self::evaluate( 'var(--sw-accent-fill)', $scheme, $steps );
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( $fill, $white ), $scheme . ': state indicator on white' );
		self::assertGreaterThanOrEqual( 3.0, CssSource::contrast( $fill, $page ), $scheme . ': state indicator on the page' );
	}

	public function test_no_component_draws_a_state_indicator_with_the_raw_scheme_accent(): void {
		$css    = CssSource::read( 'admin/sw-ui.css' );
		$marker = strpos( $css, '/* === components' );
		self::assertNotFalse( $marker, 'The components marker separates the token sections from the components.' );
		$components = CssSource::strip_comments( substr( $css, (int) $marker ) );

		self::assertSame( 0, preg_match_all( '/var\(--sw-accent\)/', $components ), 'Use var(--sw-accent-fill) for borders, underlines and outlines.' );
	}

	public function test_a_scheme_gets_a_fallback_only_when_one_of_the_known_accents_needs_it(): void {
		$needing = [];
		foreach ( CoreAdminSchemes::NAMES as $scheme ) {
			foreach ( [ CoreAdminSchemes::current()[ $scheme ], CoreAdminSchemes::earlier()[ $scheme ] ] as $steps ) {
				$white = CssSource::hex_to_rgb( '#fff' );
				$page  = CssSource::hex_to_rgb( '#f0f0f1' );
				$plain = $steps[1]; // The default accent text is the darker-10 step.
				$soft  = CssSource::mix( $steps[0], 9, $white );
				if ( min( CssSource::contrast( $plain, $white ), CssSource::contrast( $plain, $page ), CssSource::contrast( $plain, $soft ) ) < 4.5 ) {
					$needing['text'][ $scheme ] = true;
				}
				if ( CssSource::contrast( $white, $steps[0] ) < 4.5 ) {
					$needing['fill'][ $scheme ] = true;
				}
			}
		}

		foreach ( CoreAdminSchemes::NAMES as $scheme ) {
			$text_override = array_key_exists( '--sw-accent-text', self::$overrides[ $scheme ] );
			$fill_override = array_key_exists( '--sw-accent-fill', self::$overrides[ $scheme ] );
			self::assertSame( isset( $needing['text'][ $scheme ] ), $text_override, $scheme . ': accent text fallback present exactly where some accent needs it' );
			self::assertSame( isset( $needing['fill'][ $scheme ] ), $fill_override, $scheme . ': fill fallback present exactly where some accent needs it' );
		}
		self::assertNotEmpty( $needing['text'] ?? [], 'The earlier palette must exercise the text fallback.' );
		self::assertNotEmpty( $needing['fill'] ?? [], 'The earlier palette must exercise the fill fallback.' );
	}

	/** @return array<string, array{0: string}> */
	public static function schemes_with_a_fallback(): array {
		return [ 'light' => [ 'light' ], 'midnight' => [ 'midnight' ], 'ocean' => [ 'ocean' ], 'sunrise' => [ 'sunrise' ] ];
	}

	/**
	 * A fallback must not depend on the exact accent. Sweep every accent between the two palettes of a scheme, in
	 * steps of two lightness points, with the hue and saturation of each palette: the band an accent of this
	 * scheme has actually lived in. (A formula tuned for the lightest accent only gets safer for darker ones.)
	 *
	 * @dataProvider schemes_with_a_fallback
	 */
	public function test_each_fallback_holds_across_the_lightness_band_of_its_scheme( string $scheme ): void {
		$white = CssSource::hex_to_rgb( '#fff' );
		$page  = CssSource::hex_to_rgb( '#f0f0f1' );
		$known = [ CssSource::hex_to_rgb( CoreAdminSchemes::EARLIER_ACCENTS[ $scheme ] ), CoreAdminSchemes::current()[ $scheme ][0] ];
		$bands = array_map( static fn ( array $rgb ): float => CoreAdminSchemes::to_hsl( $rgb )[2] * 100, $known );
		$low   = floor( min( $bands ) ) - 4;
		$high  = floor( max( $bands ) );

		$swept = 0;
		foreach ( $known as $source ) {
			[ $h, $s ] = CoreAdminSchemes::to_hsl( $source );
			for ( $lightness = $low; $lightness <= $high; $lightness += 2 ) {
				$accent = CoreAdminSchemes::from_hsl( $h, $s, $lightness / 100 );
				$steps  = [ $accent, CoreAdminSchemes::lighten( $accent, -5 ), CoreAdminSchemes::lighten( $accent, -10 ) ];
				$soft   = CssSource::mix( $accent, 9, $white );
				$label  = 'L=' . $lightness;
				++$swept;

				$text = self::evaluate( 'var(--sw-accent-text)', $scheme, $steps );
				foreach ( [ $white, $page, $soft ] as $background ) {
					self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $text, $background ), $scheme . ' ' . $label . ': text' );
				}
				foreach ( [ '--sw-accent-fill', '--sw-accent-fill-hover' ] as $fill ) {
					self::assertGreaterThanOrEqual( 4.5, CssSource::contrast( $white, self::evaluate( 'var(' . $fill . ')', $scheme, $steps ) ), $scheme . ' ' . $label . ': ' . $fill );
				}
			}
		}
		self::assertGreaterThan( 4, $swept );
	}

	public function test_the_fallbacks_are_guarded_where_colour_mixing_is_missing(): void {
		// Older browsers drop the whole @supports block and keep the plain tokens, so the formulas never run unguarded.
		$css = CssSource::strip_comments( CssSource::read( 'admin/sw-ui.css' ) );

		self::assertSame( 2, preg_match_all( '/@supports \(color: color-mix\(in srgb, red 50%, blue\)\)/', $css ) );
		foreach ( CssSource::rules( $css ) as $rule ) {
			if ( ! $rule['at'] && str_contains( $rule['body'], 'color-mix(' ) ) {
				self::assertSame( self::SUPPORTS, $rule['context'], $rule['selector'] . ' mixes colours outside the supports guard.' );
			}
		}
	}
}
