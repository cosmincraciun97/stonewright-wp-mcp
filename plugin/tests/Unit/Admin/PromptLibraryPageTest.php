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

	private function render(): string {
		ob_start();
		PromptLibraryPage::render();

		return (string) ob_get_clean();
	}

	public function test_render_lists_prompt_cards_with_copy_buttons(): void {
		$html = $this->render();

		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Prompt library</h1>', $html );
		self::assertStringContainsString( 'data-sw-prompt-card', $html );
		self::assertStringContainsString( 'Copy prompt', $html );
		self::assertStringContainsString( 'Every prompt starts with stonewright-task-start', $html );
		self::assertStringContainsString( 'Prompts contain no site URL, username, Application Password, token', $html );
		self::assertStringContainsString( 'Available modes', $html );
		self::assertStringContainsString( 'Direct', $html );
		self::assertStringContainsString( 'Requirements and verification', $html );
		self::assertStringContainsString( 'Update and verify Stonewright', $html );
	}

	public function test_the_page_is_built_from_the_layer(): void {
		$html = $this->render();

		self::assertStringContainsString( 'sw-ui sw-ui-page sw-prompts', $html );
		foreach ( [ 'sw-blueprint', 'sw-copy-prompt', 'sw-prompt-safety', 'sw-prompt-search', 'data-stonewright-prompt', 'class="sw-btn', 'sw-empty-state', ' style=', 'onclick=' ] as $legacy ) {
			self::assertStringNotContainsString( $legacy, $html, $legacy );
		}
		self::assertSame( 1, substr_count( $html, '<h1' ) );
	}

	public function test_each_prompt_is_an_article_card_named_by_its_heading_and_found_by_the_filter(): void {
		$html = $this->render();

		self::assertSame( 1, preg_match( '/<article class="sw-ui-card"[^>]*data-sw-prompt-card[^>]*data-sw-ui-filter-item[^>]*data-sw-ui-filter-text="([^"]+)"/', $html, $card ) );
		self::assertNotSame( '', $card[1] );
		self::assertSame( strtolower( $card[1] ), $card[1], 'The text to match is lower case.' );
		self::assertMatchesRegularExpression( '/<article[^>]*aria-labelledby="([^"]+)"[^>]*>.*?<h3 class="sw-ui-card__title" id="\1">/s', $html );
	}

	public function test_every_card_has_the_same_three_parts_and_the_modes_sit_in_the_footer(): void {
		$html = $this->render();

		preg_match_all( '/<article class="sw-ui-card".*?<\/article>/s', $html, $cards );
		self::assertGreaterThan( 20, count( $cards[0] ) );
		foreach ( $cards[0] as $card ) {
			self::assertSame( 1, preg_match( '/^<article[^>]*><div class="sw-ui-card__header">(.*?)<\/div><div class="sw-ui-card__body">.*<div class="sw-ui-card__footer">(.*)<\/div><\/article>$/s', $card, $parts ), 'header, body, footer in that order' );
			self::assertStringNotContainsString( 'Available modes', $parts[1], 'The header holds the title and the description only.' );
			self::assertSame( 1, substr_count( $parts[2], 'aria-label="Available modes"' ), 'The footer holds the copy action and the modes.' );
			self::assertLessThan( strpos( $parts[2], 'aria-label="Available modes"' ), strpos( $parts[2], 'Copy prompt' ), 'The copy action comes first.' );
		}
	}

	public function test_outcome_groups_are_sections_named_by_a_heading_with_their_count(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<section[^>]*data-sw-ui-filter-group[^>]*aria-labelledby="([^"]+)"[^>]*>\s*<div class="sw-prompts__head"><h2 id="\1">[^<]+<\/h2>/', $html );
		self::assertMatchesRegularExpression( '/Showing \d+ of \d+ prompts/', $html );
	}

	public function test_the_search_field_is_labelled_filters_the_list_and_announces_the_count(): void {
		$html = $this->render();

		self::assertMatchesRegularExpression( '/<label class="sw-ui-field__label" for="sw-prompts-search">Search prompts<\/label>/', $html );
		self::assertMatchesRegularExpression( '/<input[^>]*type="search"[^>]*id="sw-prompts-search"[^>]*data-sw-ui-filter="#sw-prompts-list"[^>]*data-sw-ui-search/', $html );
		self::assertStringContainsString( 'id="sw-prompts-list"', $html );
		self::assertMatchesRegularExpression( '/<span[^>]*role="status"[^>]*data-sw-ui-filter-count[^>]*data-sw-ui-filter-label="Showing %1\$s of %2\$s prompts"/', $html );
		self::assertMatchesRegularExpression( '/<div[^>]*data-sw-ui-filter-empty[^>]*hidden[^>]*>.*No prompt matches/s', $html );
	}

	public function test_a_copy_button_copies_its_own_prompt_names_it_and_reports_next_to_itself(): void {
		$html = $this->render();

		self::assertSame( 1, preg_match( '/<button[^>]*data-sw-ui-copy-text="[^"]+"[^>]*data-sw-ui-copy-status="#([^"]+)"[^>]*>(?:<svg.*?<\/svg>)?Copy prompt<span class="sw-ui-visually-hidden"> [^<]+<\/span><\/button><span class="sw-ui-copy__status" id="\1" role="status"><\/span>/', $html, $found ) );
		self::assertSame( 1, substr_count( $html, 'id="' . $found[1] . '"' ) );
		self::assertStringContainsString( 'data-sw-ui-copied-label="Copied"', $html );
		self::assertStringContainsString( 'data-sw-ui-copy-failed-label="Press Ctrl+C"', $html );
	}

	public function test_copy_is_a_secondary_button_so_the_page_has_no_wall_of_primaries(): void {
		self::assertStringNotContainsString( 'sw-ui-btn--primary', $this->render() );
	}

	public function test_no_id_is_used_twice(): void {
		preg_match_all( '/\bid="([^"]+)"/', $this->render(), $found );

		self::assertSame( [], array_keys( array_filter( array_count_values( $found[1] ), static fn ( int $count ): bool => $count > 1 ) ) );
	}

	public function test_admin_bootstrap_maps_prompts_to_its_own_page_stylesheet(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php'
		);
		self::assertMatchesRegularExpression(
			"/'stonewright-prompts'\s*=>\s*'pages\\/prompts\\.css'/",
			$source
		);
	}

	public function test_slug_constant(): void {
		self::assertSame( 'stonewright-prompts', PromptLibraryPage::SLUG );
	}
}
