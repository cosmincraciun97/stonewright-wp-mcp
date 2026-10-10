<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\HelpTabs;
use Stonewright\WpMcp\Admin\MenuRegistry;

/**
 * @covers \Stonewright\WpMcp\Admin\HelpTabs
 */
final class HelpTabsTest extends TestCase {

	protected function setUp(): void {
		MenuRegistry::reset_for_tests();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$_GET                                  = [];
	}

	protected function tearDown(): void {
		MenuRegistry::reset_for_tests();
		$GLOBALS['stonewright_test_user_caps'] = [];
		$_GET                                  = [];
	}

	private function screen(): object {
		return new class() {
			/** @var list<array<string, string>> */
			public array $tabs = [];
			public string $sidebar = '';

			/** @param array<string, string> $tab */
			public function add_help_tab( array $tab ): void {
				$this->tabs[] = $tab;
			}

			public function set_help_sidebar( string $content ): void {
				$this->sidebar = $content;
			}
		};
	}

	public function test_a_stonewright_page_gets_a_page_tab_and_a_glossary(): void {
		$_GET['page'] = 'stonewright-memory';
		$screen       = $this->screen();

		HelpTabs::add( $screen );

		self::assertSame( [ 'stonewright-help-page', 'stonewright-help-glossary' ], array_column( $screen->tabs, 'id' ) );
		self::assertSame( [ 'What is this page?', 'Glossary' ], array_column( $screen->tabs, 'title' ) );
	}

	public function test_the_page_tab_says_what_the_page_is_and_where_it_sits(): void {
		$_GET['page'] = 'stonewright-memory';
		$screen       = $this->screen();

		HelpTabs::add( $screen );
		$content = $screen->tabs[0]['content'];

		self::assertStringContainsString( 'Memory &amp; instructions', $content );
		self::assertStringContainsString( 'Durable site knowledge', $content, 'The same sentence the page header shows.' );
		self::assertStringContainsString( 'Knowledge', $content );
		foreach ( [ 'stonewright-skills', 'stonewright-context', 'stonewright-design', 'stonewright-prompts' ] as $slug ) {
			self::assertStringContainsString( 'page=' . $slug, $content, 'Related pages are linked: ' . $slug );
		}
		self::assertStringNotContainsString( 'page=stonewright-memory', $content, 'A page does not link to itself.' );
	}

	public function test_the_glossary_defines_the_words_the_product_uses(): void {
		$_GET['page'] = 'stonewright-status';
		$screen       = $this->screen();

		HelpTabs::add( $screen );
		$content = $screen->tabs[1]['content'];

		foreach ( [ 'Abilities', 'Skills', 'Memory', 'Context', 'Design direction', 'Sandbox' ] as $term ) {
			self::assertStringContainsString( '<dt>' . $term . '</dt>', $content, $term );
		}
		self::assertStringContainsString( '<dd>', $content );
	}

	public function test_the_sidebar_points_to_the_overview_and_setup(): void {
		$_GET['page'] = 'stonewright-abilities';
		$screen       = $this->screen();

		HelpTabs::add( $screen );

		self::assertStringContainsString( 'page=stonewright-status', $screen->sidebar );
		self::assertStringContainsString( 'page=stonewright"', $screen->sidebar );
	}

	public function test_pages_that_are_not_stonewrights_get_nothing(): void {
		foreach ( [ '', 'some-other-plugin', 'stonewright-not-registered' ] as $page ) {
			$_GET['page'] = $page;
			$screen       = $this->screen();

			HelpTabs::add( $screen );

			self::assertSame( [], $screen->tabs, $page );
			self::assertSame( '', $screen->sidebar, $page );
		}
	}

	public function test_someone_without_access_gets_no_help_for_a_page_they_cannot_open(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];
		$_GET['page']                          = 'stonewright-memory';
		$screen                                = $this->screen();

		HelpTabs::add( $screen );

		self::assertSame( [], $screen->tabs );
	}

	public function test_it_attaches_to_the_current_screen(): void {
		$GLOBALS['stonewright_test_actions'] = [];

		HelpTabs::register();

		self::assertArrayHasKey( 'current_screen', $GLOBALS['stonewright_test_actions'] );
	}
}
