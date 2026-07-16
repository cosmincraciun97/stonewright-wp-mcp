<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\PromptLibraryPage;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\PromptLibraryPage
 */
final class PromptLibraryPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
	}

	public function test_render_lists_prompt_cards_with_copy_buttons(): void {
		ob_start();
		PromptLibraryPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Prompt Library', $html );
		self::assertStringContainsString( 'sw-copy-prompt', $html );
		self::assertStringContainsString( 'data-prompt=', $html );
		self::assertStringContainsString( 'data-sw-prompt-card', $html );
		self::assertStringContainsString( 'Copy prompt', $html );
	}

	public function test_slug_constant(): void {
		self::assertSame( 'stonewright-prompts', PromptLibraryPage::SLUG );
	}
}
