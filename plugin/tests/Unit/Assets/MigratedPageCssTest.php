<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * A page that has moved to the shared UI layer keeps a page file that only places things: no colour, radius,
 * type size or motion of its own, no !important, and under 3 KB. Every component comes from sw-ui.css.
 *
 * @coversNothing
 */
final class MigratedPageCssTest extends TestCase {

	/** Page file => the class prefix its own selectors use. */
	private const PAGES = [
		'admin/abilities.css' => 'sw-abilities',
		'admin/sandbox.css'   => 'sw-code',
		'admin/pages/changes.css' => 'sw-changes',
	];

	private const LIMIT = 3072;

	/** @return array<string, array{0: string, 1: string}> */
	public static function pages(): array {
		$cases = [];
		foreach ( self::PAGES as $file => $prefix ) {
			$cases[ $file ] = [ $file, $prefix ];
		}

		return $cases;
	}

	/** @dataProvider pages */
	public function test_the_file_is_licensed_and_small( string $file ): void {
		$css = CssSource::read( $file );

		self::assertStringStartsWith( '/* SPDX-License-Identifier: GPL-2.0-or-later */', $css );
		self::assertLessThan( self::LIMIT, strlen( $css ), $file . ' places things; components belong in sw-ui.css.' );
		self::assertStringNotContainsString( "\r", $css, 'LF line endings.' );
	}

	/** @dataProvider pages */
	public function test_there_is_no_important_and_no_motion_of_its_own( string $file ): void {
		$css = CssSource::strip_comments( CssSource::read( $file ) );

		self::assertStringNotContainsString( '!important', $css );
		self::assertDoesNotMatchRegularExpression( '/\b(transition|animation|@keyframes)\b/', $css, 'Motion comes from the layer, through its tokens.' );
	}

	/** @dataProvider pages */
	public function test_colours_radii_and_type_sizes_are_tokens( string $file ): void {
		$violations = [];
		foreach ( CssSource::rules( CssSource::read( $file ) ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			foreach ( CssSource::declarations( $rule['body'] ) as $declaration ) {
				$name  = $declaration['name'];
				$value = $declaration['value'];
				if ( 1 === preg_match( '/#[0-9a-f]{3,8}\b|\b(?:rgb|rgba|hsl|hsla|oklch|oklab|color-mix)\(/i', $value ) ) {
					$violations[] = $rule['selector'] . ' { ' . $name . ': ' . $value . ' } is a raw colour';
				}
				if ( 'font-size' === $name && 1 !== preg_match( '/^(?:var\(--sw-fs-[a-z0-9]+\)|inherit)$/', $value ) ) {
					$violations[] = $rule['selector'] . ' { font-size: ' . $value . ' } is not a type token';
				}
				if ( 'border-radius' === $name && 1 !== preg_match( '/^(?:0|var\(--sw-radius[a-z-]*\))$/', $value ) ) {
					$violations[] = $rule['selector'] . ' { border-radius: ' . $value . ' } is not a radius token';
				}
				if ( 1 === preg_match( '/\b\d+(?:\.\d+)?px\b/', $value ) && ! in_array( $name, [ 'content' ], true ) && 1 === preg_match( '/^(?:color|background|background-color|border|border-color|font-family|font-size|font-weight|box-shadow|outline)/', $name ) ) {
					$violations[] = $rule['selector'] . ' { ' . $name . ': ' . $value . ' } sets a look in pixels';
				}
			}
		}

		self::assertSame( [], $violations );
	}

	/** @dataProvider pages */
	public function test_every_custom_property_it_reads_exists_in_the_layer( string $file ): void {
		$layer = CssSource::read( 'admin/sw-ui.css' );
		preg_match_all( '/--[a-z0-9-]+(?=\s*:)/', $layer, $declared );
		$declared = array_flip( $declared[0] );

		preg_match_all( '/var\((--[a-z0-9-]+)/', CssSource::strip_comments( CssSource::read( $file ) ), $used );
		$unknown = array_values( array_filter( array_unique( $used[1] ), static fn ( string $name ): bool => ! isset( $declared[ $name ] ) ) );

		self::assertSame( [], $unknown, 'Tokens the layer does not define.' );
	}

	/** @dataProvider pages */
	public function test_its_own_classes_carry_the_page_prefix_and_no_component_look_alike_is_defined( string $file, string $prefix ): void {
		$offenders = [];
		foreach ( CssSource::rules( CssSource::read( $file ) ) as $rule ) {
			if ( $rule['at'] ) {
				continue;
			}
			preg_match_all( '/\.([A-Za-z_][A-Za-z0-9_-]*)/', $rule['selector'], $classes );
			foreach ( $classes[1] as $class ) {
				if ( ! str_starts_with( $class, $prefix ) && ! str_starts_with( $class, 'sw-ui-' ) ) {
					$offenders[] = '.' . $class . ' in ' . $rule['selector'];
				}
			}
		}

		self::assertSame( [], $offenders, 'A page file names its own classes (' . $prefix . '*) and the layer\'s, nothing else.' );
	}
}
