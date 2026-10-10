<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Ui\CodeBlock;
use Stonewright\WpMcp\Admin\Ui\Html;

/**
 * @covers \Stonewright\WpMcp\Admin\Ui\CodeBlock
 */
final class CodeBlockTest extends TestCase {

	protected function setUp(): void {
		Html::reset_ids();
	}

	public function test_a_block_is_a_titled_head_a_named_copy_button_and_a_focusable_body(): void {
		self::assertSame(
			'<div class="sw-ui-code"><div class="sw-ui-code__head"><span>Terminal</span>'
			. '<span class="sw-ui-copy__status" role="status" id="add-cmd-status"></span>'
			. '<button type="button" class="sw-ui-btn sw-ui-btn--xs" data-sw-ui-copy="#add-cmd" data-sw-ui-copy-status="#add-cmd-status" data-sw-ui-copied-label="Copied" data-sw-ui-copy-failed-label="Press Ctrl+C">Copy<span class="sw-ui-visually-hidden"> add command</span></button></div>'
			. '<pre class="sw-ui-code__body" id="add-cmd" tabindex="0" aria-label="Terminal: add command"><code>example mcp add --transport http stonewright-example https://example.test/mcp</code></pre></div>',
			CodeBlock::render( 'example mcp add --transport http stonewright-example https://example.test/mcp', [ 'id' => 'add-cmd', 'title' => 'Terminal', 'copy_label' => 'add command' ] )
		);
	}

	public function test_where_the_code_goes_is_shown_in_the_head(): void {
		$html = CodeBlock::render( '{}', [ 'id' => 'cfg', 'title' => 'Config', 'where' => '.mcp.json', 'copy_label' => 'config' ] );

		self::assertStringContainsString( '<span>Config <span class="sw-ui-code__where">.mcp.json</span></span>', $html );
	}

	public function test_a_page_can_add_a_class_to_the_body_to_find_it(): void {
		$html = CodeBlock::render( 'x', [ 'id' => 'c', 'title' => 'T', 'copy_label' => 'x', 'body_class' => 'page-hook' ] );

		self::assertStringContainsString( '<pre class="sw-ui-code__body page-hook" id="c"', $html );
	}

	public function test_a_page_can_hang_data_hooks_on_the_body_but_not_unmake_it(): void {
		$html = CodeBlock::render( 'x', [ 'id' => 'c', 'title' => 'T', 'copy_label' => 'x', 'body_attrs' => [ 'data-hook' => 'a', 'tabindex' => '-1', 'onclick' => 'x()' ] ] );

		self::assertStringContainsString( ' data-hook="a"', $html );
		self::assertStringContainsString( 'tabindex="0"', $html );
		self::assertStringNotContainsString( 'onclick', $html );
	}

	public function test_code_is_text_and_never_markup(): void {
		$html = CodeBlock::render( '<script>alert(1)</script>', [ 'id' => 'x', 'title' => '<b>t</b>', 'copy_label' => 'x' ] );

		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( '<b>', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_two_blocks_without_an_id_do_not_share_one(): void {
		$first  = CodeBlock::render( 'a', [ 'title' => 'A', 'copy_label' => 'a' ] );
		$second = CodeBlock::render( 'b', [ 'title' => 'B', 'copy_label' => 'b' ] );

		self::assertStringContainsString( 'id="sw-ui-code-1"', $first );
		self::assertStringContainsString( 'id="sw-ui-code-2"', $second );
	}
}
