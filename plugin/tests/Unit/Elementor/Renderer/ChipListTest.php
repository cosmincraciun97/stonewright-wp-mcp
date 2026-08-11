<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\ChipList;

/**
 * @covers \Stonewright\WpMcp\Elementor\Renderer\ChipList
 */
final class ChipListTest extends TestCase {

	public function test_renders_flex_wrap_container_with_button_and_text_chips(): void {
		$diagnostics = [];
		$result      = ChipList::render(
			[
				'type'  => 'chip-list',
				'items' => [
					[ 'text' => 'Audit', 'url' => 'https://example.test/audit' ],
					[ 'text' => 'Native', 'style' => 'text' ],
				],
				'gap'   => 10,
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertSame( 'container', $result['elType'] );
		self::assertSame( 'row', $result['settings']['flex_direction'] );
		self::assertSame( 'wrap', $result['settings']['flex_wrap'] );
		self::assertSame( 10, $result['settings']['flex_gap']['size'] );
		self::assertCount( 2, $result['elements'] );
		self::assertSame( 'button', $result['elements'][0]['widgetType'] );
		self::assertSame( '#ffffff', $result['elements'][0]['settings']['background_color'] );
		self::assertSame( 'container', $result['elements'][1]['elType'] );
		self::assertSame( 'heading', $result['elements'][1]['elements'][0]['widgetType'] );
	}

	public function test_contrast_safe_text_on_white_fill(): void {
		$diagnostics = [];
		$result      = ChipList::render(
			[
				'type'       => 'pills',
				'items'      => [ [ 'text' => 'Safe', 'style' => 'button' ] ],
				'background_color' => '#ffffff',
				'color'            => '#fafafa',
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertSame( '#111111', $result['elements'][0]['settings']['button_text_color'] );
		self::assertSame( 'chip_contrast_adjusted', $diagnostics[0]['code'] );
	}

	public function test_no_list_fallback_unless_explicit(): void {
		$result = ChipList::render(
			[
				'type'  => 'pill-grid',
				'items' => [ 'One', 'Two' ],
			],
			new Resolver( [] ),
			's0.b0'
		);
		self::assertSame( 'container', $result['elType'] );
		self::assertNotSame( 'text-editor', $result['widgetType'] ?? '' );

		$fallback = ChipList::render(
			[
				'type'     => 'chip-list',
				'fallback' => 'list',
				'items'    => [ 'One' ],
			],
			new Resolver( [] ),
			's0.b1'
		);
		self::assertSame( 'text-editor', $fallback['widgetType'] );
		self::assertStringContainsString( '<ul', $fallback['settings']['editor'] );
	}

	public function test_narrow_stack_uses_column_breakpoints(): void {
		$result = ChipList::render(
			[
				'type'   => 'chips',
				'narrow' => 'stack',
				'items'  => [ [ 'text' => 'A', 'style' => 'text' ] ],
			],
			new Resolver( [] ),
			's0.b0'
		);

		self::assertSame( 'row', $result['settings']['flex_direction'] );
		self::assertSame( 'column', $result['settings']['flex_direction_tablet'] );
		self::assertSame( 'column', $result['settings']['flex_direction_mobile'] );
	}
}
