<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Guardrail: page/component CSS uses semantic tokens only.
 * Token definitions (raw hex) live exclusively in shell.css.
 */
final class AdminCssNoRawHexTest extends TestCase {

	public function test_page_css_uses_only_semantic_tokens(): void {
		$dir   = dirname( __DIR__, 3 ) . '/assets';
		$files = array_merge(
			glob( $dir . '/admin/*.css' ) ?: [],
			glob( $dir . '/css/*.css' ) ?: []
		);
		$this->assertNotEmpty( $files, 'expected admin CSS files' );

		foreach ( $files as $file ) {
			if ( str_ends_with( $file, 'shell.css' ) ) {
				continue; // token definitions live here.
			}
			$css = (string) file_get_contents( $file );
			// Strip comments before scanning.
			$css = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
			$this->assertDoesNotMatchRegularExpression(
				'/#[0-9a-fA-F]{3,8}\b/',
				$css,
				basename( $file ) . ' contains raw hex colors; use var(--sw-*) tokens'
			);
		}
	}
}
