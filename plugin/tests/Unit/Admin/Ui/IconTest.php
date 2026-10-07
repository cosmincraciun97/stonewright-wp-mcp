<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\Icon;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\Icon
 */
final class IconTest extends TestCase {

	protected function setUp(): void {
		Icon::reset_for_tests();
		$GLOBALS['stonewright_test_actions'] = [];
	}

	public function test_an_icon_is_a_hidden_reference_to_the_sprite(): void {
		self::assertSame( '<svg class="sw-ui-icon" aria-hidden="true"><use href="#sw-ui-icon-check"></use></svg>', Icon::render( 'check' ) );
		self::assertSame( '<svg class="sw-ui-icon sw-ui-icon--lg sw-ui-copy__icon-done" aria-hidden="true"><use href="#sw-ui-icon-check"></use></svg>', Icon::render( 'check', [ 'size' => 'lg', 'class' => 'sw-ui-copy__icon-done' ] ) );
	}

	public function test_an_unknown_icon_renders_nothing(): void {
		self::assertSame( '', Icon::render( 'no-such-icon' ) );
		self::assertSame( '', Icon::render( '"><script>' ) );
		self::assertFalse( Icon::exists( 'no-such-icon' ) );
	}

	public function test_the_sprite_holds_one_symbol_per_icon_and_nothing_executable(): void {
		$sprite = Icon::sprite();

		foreach ( Icon::names() as $name ) {
			self::assertStringContainsString( '<symbol id="sw-ui-icon-' . $name . '" viewBox="0 0 24 24">', $sprite, $name );
		}
		self::assertSame( count( Icon::names() ), substr_count( $sprite, '<symbol ' ) );
		self::assertStringStartsWith( '<svg class="sw-ui-sprite" aria-hidden="true"', $sprite );
		self::assertStringNotContainsString( '<script', $sprite );
		self::assertStringNotContainsString( 'style=', $sprite, 'No inline style: the sprite is hidden by a class.' );
		self::assertStringNotContainsString( 'fill=', $sprite, 'Icons take the colour of the text around them.' );
	}

	public function test_the_icons_the_other_helpers_use_all_exist(): void {
		foreach ( [ 'check', 'x', 'alert', 'info', 'copy', 'eye', 'chev-r', 'search', 'bolt' ] as $name ) {
			self::assertTrue( Icon::exists( $name ), $name );
		}
	}

	public function test_using_an_icon_prints_the_sprite_in_the_footer_once(): void {
		Icon::render( 'check' );
		Icon::render( 'x' );

		$hooks = $GLOBALS['stonewright_test_actions']['admin_footer'] ?? [];
		self::assertCount( 1, $hooks );
		self::assertSame( [ Icon::class, 'print_sprite' ], $hooks[0]['callback'] );

		ob_start();
		Icon::print_sprite();
		self::assertSame( Icon::sprite(), ob_get_clean() );
	}

	public function test_an_unknown_icon_does_not_hook_the_footer(): void {
		Icon::render( 'nope' );

		self::assertArrayNotHasKey( 'admin_footer', $GLOBALS['stonewright_test_actions'] );
	}
}
