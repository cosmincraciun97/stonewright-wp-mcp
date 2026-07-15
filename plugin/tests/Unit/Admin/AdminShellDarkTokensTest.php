<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Guardrail: semantic theme tokens must exist in both light and dark maps.
 */
final class AdminShellDarkTokensTest extends TestCase {

	private const TOKENS = [
		'--sw-bg',
		'--sw-surface',
		'--sw-surface-raised',
		'--sw-border',
		'--sw-border-strong',
		'--sw-text',
		'--sw-text-secondary',
		'--sw-text-muted',
		'--sw-text-invert',
		'--sw-brand',
		'--sw-brand-strong',
		'--sw-brand-fill',
		'--sw-brand-fill-hover',
		'--sw-brand-soft',
		'--sw-on-brand',
		'--sw-ok-text',
		'--sw-ok-soft',
		'--sw-warn-text',
		'--sw-warn-soft',
		'--sw-danger-text',
		'--sw-danger-soft',
		'--sw-focus-ring',
	];

	private function css(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/shell.css' );
	}

	public function test_every_semantic_token_is_mapped_in_both_themes(): void {
		$css  = $this->css();
		$root = $this->extract_block( $css, ':root' );
		$dark = $this->extract_block( $css, '.sw-theme-dark' );
		foreach ( self::TOKENS as $token ) {
			$this->assertStringContainsString( $token . ':', $root, "missing {$token} in :root" );
			$this->assertStringContainsString( $token . ':', $dark, "missing {$token} in .sw-theme-dark" );
		}
	}

	public function test_hated_blue_gone(): void {
		$this->assertStringNotContainsStringIgnoringCase( '#5546e8', $this->css() );
	}

	public function test_prefers_color_scheme_fallback_exists(): void {
		$this->assertStringContainsString( '@media (prefers-color-scheme: dark)', $this->css() );
	}

	private function extract_block( string $css, string $selector ): string {
		$pos = strpos( $css, $selector );
		$this->assertNotFalse( $pos, "selector {$selector} not found" );
		$open  = strpos( $css, '{', $pos );
		$this->assertNotFalse( $open, "opening brace for {$selector} not found" );
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = (int) $open; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( '{' === $ch ) {
				++$depth;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $css, (int) $open, $i - (int) $open + 1 );
				}
			}
		}
		$this->fail( "unclosed block for {$selector}" );
	}
}
