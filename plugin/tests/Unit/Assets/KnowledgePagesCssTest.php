<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The page files of the Knowledge hub only place things: tokens for every colour, radius and type size, no
 * `!important`, no look-alike of a component, and each under 3 KB.
 *
 * @coversNothing
 */
final class KnowledgePagesCssTest extends TestCase {

	private const PAGES = [
		'stonewright-skills'  => 'skills',
		'stonewright-memory'  => 'memory',
		'stonewright-context' => 'context',
		'stonewright-design'  => 'design',
		'stonewright-prompts' => 'prompts',
	];

	/** @return array<string, array{0: string}> */
	public static function files(): array {
		$cases = [];
		foreach ( self::PAGES as $name ) {
			$cases[ $name ] = [ 'admin/pages/' . $name . '.css' ];
		}

		return $cases;
	}

	/** @dataProvider files */
	public function test_a_page_file_is_small_and_has_the_licence_header(string $file): void {
		$css = CssSource::read( $file );

		self::assertLessThan( 3 * 1024, strlen( $css ), $file . ' stays under 3 KB.' );
		self::assertStringStartsWith( '/* SPDX-License-Identifier: GPL-2.0-or-later */', $css );
		self::assertStringNotContainsString( "\r", $css );
	}

	/** @dataProvider files */
	public function test_a_page_file_uses_tokens_only(string $file): void {
		$css = CssSource::read( $file );

		self::assertStringNotContainsString( '!important', $css );
		self::assertDoesNotMatchRegularExpression( '/#[0-9a-fA-F]{3,8}\b/', $css, 'No raw colour.' );
		self::assertDoesNotMatchRegularExpression( '/\b(?:rgb|rgba|hsl|hsla)\(/', $css, 'No raw colour function.' );
		self::assertDoesNotMatchRegularExpression( '/font-size:\s*[\d.]+(?:px|rem|em)/', $css, 'Type sizes are --sw-fs-* tokens.' );
		self::assertDoesNotMatchRegularExpression( '/border-radius:\s*[\d.]+(?:px|rem|em)/', $css, 'Radii are --sw-radius-* tokens.' );
		self::assertDoesNotMatchRegularExpression( '/(?<![-\w])(?:color|background(?:-color)?):\s*+(?!var\(|inherit|transparent|currentcolor)/', $css, 'Colours are tokens.' );
		self::assertStringNotContainsString( 'transition', $css, 'Motion belongs to the layer.' );
		self::assertStringNotContainsString( 'animation', $css, 'Motion belongs to the layer.' );
	}

	/** @dataProvider files */
	public function test_a_page_file_scopes_every_rule_to_its_page(string $file): void {
		$name = basename( $file, '.css' );
		foreach ( CssSource::rules( CssSource::read( $file ) ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			foreach ( CssSource::selectors( $rule['selector'] ) as $selector ) {
				self::assertStringStartsWith( '.sw-' . $name . ' ', $selector, 'A page rule never reaches outside its page: ' . $selector );
			}
		}
	}

	public function test_each_page_is_mapped_to_its_own_file_and_the_shared_older_file_is_gone(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php' );

		foreach ( self::PAGES as $slug => $name ) {
			self::assertMatchesRegularExpression( "/'" . $slug . "'\s*=>\s*'pages\\/" . $name . "\\.css'/", $bootstrap, $slug );
		}
		self::assertFileDoesNotExist( dirname( __DIR__, 3 ) . '/assets/admin/skills-memory.css' );
		self::assertStringNotContainsString( 'skills-memory', $bootstrap );
	}
}
