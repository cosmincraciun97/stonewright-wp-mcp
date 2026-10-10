<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/**
 * Guardrail: page/component CSS uses semantic tokens only.
 * Token definitions (raw hex) live in shell.css (legacy tokens) and in the token sections of sw-ui.css,
 * which end at its components marker.
 */
final class AdminCssNoRawHexTest extends TestCase {

	private const SHARED_LAYER = 'sw-ui.css';
	private const MARKER       = '/* === components';

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
			if ( str_ends_with( $file, self::SHARED_LAYER ) ) {
				// Token sections may define colours; the components below the marker may not.
				$marker = strpos( $css, self::MARKER );
				$this->assertNotFalse( $marker, self::SHARED_LAYER . ' needs the components marker' );
				$css = substr( $css, (int) $marker );
			}
			// Strip comments before scanning.
			$css = (string) preg_replace( '~/\*.*?\*/~s', '', $css );
			$this->assertDoesNotMatchRegularExpression(
				'/#[0-9a-fA-F]{3,8}\b/',
				$css,
				basename( $file ) . ' contains raw hex colors; use var(--sw-*) tokens'
			);
		}
	}

	public function test_the_shared_layer_is_one_of_the_files_the_guard_reads(): void {
		$names = array_map( 'basename', glob( dirname( __DIR__, 3 ) . '/assets/admin/*.css' ) ?: [] );

		$this->assertContains( self::SHARED_LAYER, $names, 'The guard globs assets/admin/*.css, so it reads the shared layer.' );
	}
}
