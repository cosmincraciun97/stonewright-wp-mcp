<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * WCAG contrast guardrail for semantic theme tokens (both themes).
 */
final class AdminThemeContrastTest extends TestCase {

	/**
	 * @return array{light: array<string, string>, dark: array<string, string>}
	 */
	private function token_maps(): array {
		$css  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/shell.css' );
		$root = $this->extract_block( $css, ':root' );
		$dark = $this->extract_block( $css, '.sw-theme-dark' );

		return [
			'light' => $this->parse_tokens( $root ),
			'dark'  => $this->parse_tokens( $dark ),
		];
	}

	public function test_critical_pairs_meet_wcag_aa(): void {
		$maps = $this->token_maps();

		foreach ( [ 'light', 'dark' ] as $theme ) {
			$t = $maps[ $theme ];
			$pairs = [
				[ '--sw-text', '--sw-surface', 4.5 ],
				[ '--sw-text-secondary', '--sw-surface', 4.5 ],
				[ '--sw-text-muted', '--sw-surface', 4.5 ],
				[ '--sw-brand', '--sw-surface', 4.5 ],
				[ '--sw-on-brand', '--sw-brand-fill', 4.5 ],
			];

			foreach ( $pairs as [ $fg, $bg, $min ] ) {
				$fg_hex = $this->resolve( $t, $fg );
				$bg_hex = $this->resolve( $t, $bg );
				$ratio  = $this->contrast_ratio( $fg_hex, $bg_hex );
				$this->assertGreaterThanOrEqual(
					$min,
					$ratio,
					sprintf( '%s %s on %s = %.2f (need %.1f)', $theme, $fg, $bg, $ratio, $min )
				);
			}

			// Soft backgrounds: compose rgba over surface when needed.
			$soft_pairs = [
				[ '--sw-ok-text', '--sw-ok-soft', 4.5 ],
				[ '--sw-danger-text', '--sw-danger-soft', 4.5 ],
			];
			foreach ( $soft_pairs as [ $fg, $bg, $min ] ) {
				$fg_hex = $this->resolve( $t, $fg );
				$bg_raw = $t[ $bg ] ?? '';
				$bg_hex = $this->compose_over_surface( $bg_raw, $this->resolve( $t, '--sw-surface' ) );
				$ratio  = $this->contrast_ratio( $fg_hex, $bg_hex );
				$this->assertGreaterThanOrEqual(
					$min,
					$ratio,
					sprintf( '%s %s on composed %s = %.2f (need %.1f)', $theme, $fg, $bg, $ratio, $min )
				);
			}
		}
	}

	/**
	 * @param array<string, string> $tokens
	 */
	private function resolve( array $tokens, string $name ): string {
		$value = $tokens[ $name ] ?? '';
		$this->assertNotSame( '', $value, "missing token {$name}" );
		if ( str_starts_with( $value, 'var(' ) ) {
			if ( preg_match( '/var\((--sw-[a-z0-9-]+)\)/', $value, $m ) ) {
				return $this->resolve( $tokens, $m[1] );
			}
		}
		$this->assertMatchesRegularExpression( '/^#[0-9a-fA-F]{3,8}$/', $value, "token {$name} is not a resolvable hex" );
		return $value;
	}

	private function compose_over_surface( string $fg, string $surface_hex ): string {
		if ( str_starts_with( $fg, '#' ) ) {
			return $fg;
		}
		if ( preg_match( '/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*([0-9.]+)\s*\)/', $fg, $m ) ) {
			$sr = $this->hex_to_rgb( $surface_hex );
			$a  = (float) $m[4];
			$r  = (int) round( ( (int) $m[1] * $a ) + ( $sr[0] * ( 1 - $a ) ) );
			$g  = (int) round( ( (int) $m[2] * $a ) + ( $sr[1] * ( 1 - $a ) ) );
			$b  = (int) round( ( (int) $m[3] * $a ) + ( $sr[2] * ( 1 - $a ) ) );
			return sprintf( '#%02x%02x%02x', $r, $g, $b );
		}
		$this->fail( "cannot compose soft color: {$fg}" );
	}

	/**
	 * @return array{0:int,1:int,2:int}
	 */
	private function hex_to_rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return [
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		];
	}

	private function relative_luminance( string $hex ): float {
		[ $r, $g, $b ] = $this->hex_to_rgb( $hex );
		$channels      = array_map(
			static function ( int $c ): float {
				$s = $c / 255;
				return $s <= 0.03928 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
			},
			[ $r, $g, $b ]
		);
		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	private function contrast_ratio( string $fg, string $bg ): float {
		$l1 = $this->relative_luminance( $fg );
		$l2 = $this->relative_luminance( $bg );
		$hi = max( $l1, $l2 );
		$lo = min( $l1, $l2 );
		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	/**
	 * @return array<string, string>
	 */
	private function parse_tokens( string $block ): array {
		// Strip comments so token names inside docs never poison the map.
		$block = (string) preg_replace( '~/\*.*?\*/~s', '', $block );
		$out   = [];
		if ( preg_match_all( '/(--sw-[a-z0-9-]+)\s*:\s*([^;]+);/', $block, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$out[ $m[1] ] = trim( $m[2] );
			}
		}
		return $out;
	}

	private function extract_block( string $css, string $selector ): string {
		$pos = strpos( $css, $selector );
		$this->assertNotFalse( $pos, "selector {$selector} not found" );
		$open  = strpos( $css, '{', $pos );
		$this->assertNotFalse( $open );
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = (int) $open; $i < $len; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $css, (int) $open, $i - (int) $open + 1 );
				}
			}
		}
		$this->fail( "unclosed {$selector}" );
	}
}
