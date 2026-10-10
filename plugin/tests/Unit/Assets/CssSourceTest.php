<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The contract tests trust CssSource, so it is checked against known answers first.
 *
 * @covers \Stonewright\WpMcp\Tests\Unit\Assets\CssSource
 */
final class CssSourceTest extends TestCase {

	/** @dataProvider specificities */
	public function test_specificity_follows_the_selectors_spec( string $selector, array $expected ): void {
		self::assertSame( $expected, CssSource::specificity( $selector ), $selector );
	}

	/** @return array<string, array{0: string, 1: array{0:int,1:int,2:int}}> */
	public static function specificities(): array {
		return [
			'class'                         => [ '.a', [ 0, 1, 0 ] ],
			'two classes'                   => [ '.a .b', [ 0, 2, 0 ] ],
			'type and class'                => [ 'input.b', [ 0, 1, 1 ] ],
			'id'                            => [ '#a .b', [ 1, 1, 0 ] ],
			'attribute'                     => [ 'input[type="submit"]', [ 0, 1, 1 ] ],
			'attribute with a dot value'    => [ 'a[href$=".css"]', [ 0, 1, 1 ] ],
			'WordPress button trap'         => [ '.sw-shell input[type="submit"].button', [ 0, 3, 1 ] ],
			'pseudo-class'                  => [ '.a:hover', [ 0, 2, 0 ] ],
			'pseudo-element'                => [ '.a::before', [ 0, 1, 1 ] ],
			'legacy pseudo-element'         => [ '.a:before', [ 0, 1, 1 ] ],
			'where counts for nothing'      => [ ':where(.sw-ui) .a', [ 0, 1, 0 ] ],
			'where with a state'            => [ ':where(.sw-ui) .a:hover', [ 0, 2, 0 ] ],
			'is takes the strongest'        => [ ':is(.a, #b) .c', [ 1, 1, 0 ] ],
			'not takes its argument'        => [ '.a:not(.b)', [ 0, 2, 0 ] ],
			'where inside not stays free'   => [ '.a code:where(:not(pre code))', [ 0, 1, 1 ] ],
			'universal'                     => [ '.a *', [ 0, 1, 0 ] ],
			'combinators'                   => [ '.a > .b + .c ~ d', [ 0, 3, 1 ] ],
			'nth-child'                     => [ 'li:nth-child(2n+1)', [ 0, 1, 1 ] ],
			'not with a type'               => [ '.sw-shell :not(pre) > code', [ 0, 1, 2 ] ],
		];
	}

	public function test_rules_flatten_media_blocks_and_keep_keyframes_whole(): void {
		$css = <<<'CSS'
/* comment { with braces } */
.a, .b > .c { color: red; background: url("x;y.png") }
@media (max-width: 782px) {
	.a { color: blue !important; }
	@supports (color: color-mix(in srgb, red, blue)) { .d { color: green } }
}
@keyframes spin { from { opacity: 0 } to { opacity: 1 } }
.e { margin: 0 }
CSS;

		$rules = CssSource::rules( $css );

		self::assertSame( [ '.a, .b > .c', '.a', '.d', '@keyframes spin', '.e' ], array_column( $rules, 'selector' ) );
		self::assertSame( '', $rules[0]['context'] );
		self::assertSame( '@media (max-width: 782px)', $rules[1]['context'] );
		self::assertSame( '@media (max-width: 782px) @supports (color: color-mix(in srgb, red, blue))', $rules[2]['context'] );
		self::assertTrue( $rules[3]['at'] );
		self::assertFalse( $rules[4]['at'] );
	}

	public function test_declarations_split_outside_parentheses_and_strings(): void {
		$declarations = CssSource::declarations( 'color: red; background: url("x;y.png") no-repeat; margin: 0 !important; --t: calc(1px + 2px)' );

		self::assertSame( 'color', $declarations[0]['name'] );
		self::assertSame( 'url("x;y.png") no-repeat', $declarations[1]['value'] );
		self::assertTrue( $declarations[2]['important'] );
		self::assertSame( '0', $declarations[2]['value'] );
		self::assertSame( '--t', $declarations[3]['name'] );
		self::assertSame( 'calc(1px + 2px)', $declarations[3]['value'] );
	}

	public function test_selector_lists_split_only_at_top_level_commas(): void {
		self::assertSame( [ '.a:is(.b, .c)', '.d' ], CssSource::selectors( '.a:is(.b, .c), .d' ) );
	}

	public function test_contrast_matches_known_pairs(): void {
		$white = CssSource::hex_to_rgb( '#fff' );
		$black = CssSource::hex_to_rgb( '#000' );

		self::assertEqualsWithDelta( 21.0, CssSource::contrast( $white, $black ), 0.001 );
		self::assertEqualsWithDelta( 5.61, CssSource::contrast( $white, CssSource::hex_to_rgb( '#3858e9' ) ), 0.01 );
		self::assertEqualsWithDelta( 1.0, CssSource::contrast( $white, $white ), 0.001 );
	}

	public function test_mix_matches_color_mix_in_srgb(): void {
		// color-mix(in srgb, #000 25%, #fff) is #bfbfbf.
		self::assertSame( [ 191.25, 191.25, 191.25 ], CssSource::mix( [ 0.0, 0.0, 0.0 ], 25.0, [ 255.0, 255.0, 255.0 ] ) );
	}
}
