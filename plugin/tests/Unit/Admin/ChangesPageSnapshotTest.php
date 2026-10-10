<?php
/**
 * The Changes page printed once, with its drawer open, as a static page for the browser spec.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ChangesPage;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Admin\Ui\Html;
use Stonewright\WpMcp\Admin\Ui\Icon;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilies;
use Stonewright\WpMcp\Security\Rollback\RollbackFamilyHandler;
use Stonewright\WpMcp\Tests\Unit\Admin\Fixtures\LedgerFixture;
use Stonewright\WpMcp\Tests\Unit\Assets\CssSource;

/**
 * plugin/tests/fixtures/admin-ui/changes-page.html is the Changes page, rendered by the page class from ledger
 * rows with synthetic content, next to the stylesheets and scripts of the shell and the page. e2e/tests/
 * changes-page.spec.ts serves it from the repository and measures the drawer in a real browser at every viewport
 * (the real site's ledger can be empty). Regenerate it with STONEWRIGHT_UPDATE_FIXTURES=1 after changing the page.
 *
 * @covers \Stonewright\WpMcp\Admin\ChangesPage
 */
final class ChangesPageSnapshotTest extends TestCase {

	use LedgerFixture;

	private const FIXTURE = '/fixtures/admin-ui/changes-page.html';

	private const OPEN = 'cs-aaaaaaaaaaaaaaaaaaaaaaaa';

	protected function setUp(): void {
		$this->ledger_up();
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_users']     = [ (object) [ 'ID' => 7, 'user_login' => 'editor-one', 'display_name' => 'Editor One' ] ];
		$GLOBALS['stonewright_test_posts']     = [ 42 => (object) [ 'ID' => 42, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home page' ] ];
		$_GET = [];
		MenuRegistry::reset_for_tests();
		Html::reset_ids();
		Icon::reset_for_tests();
		// The fixture's theme files are not on this machine: this handler reads the stored after image as the live state.
		RollbackFamilies::register(
			new class() implements RollbackFamilyHandler {
				public function families(): array {
					return [ 'theme_file' ];
				}

				public function live_image( array $row ): string|array|\WP_Error|null {
					return ChangeLedger::read_image( (string) $row['change_id'], 'after' );
				}

				public function restore( array $row, string|array|null $image, array $options ): array {
					return [ 'status' => 'failed', 'detail' => 'fixture' ];
				}

				public function describe( array $row, string|array|null $image ): string {
					return 'Writes the file back as it was.';
				}

				public function records_own_row( array $row ): bool {
					return true;
				}

				public function requires_human( array $row ): bool {
					return true;
				}
			}
		);
	}

	protected function tearDown(): void {
		RollbackFamilies::reset_for_tests();
		$this->ledger_down();
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_users']     = [];
		$GLOBALS['stonewright_test_posts']     = [];
		$_GET = [];
		MenuRegistry::reset_for_tests();
	}

	private static function path(): string {
		return dirname( __DIR__, 2 ) . self::FIXTURE;
	}

	private function document(): string {
		$long_old = "\$wide = 'old-value-" . str_repeat( 'abcdefghij', 14 ) . "';";
		$long_new = "\$wide = 'new-value-" . str_repeat( 'abcdefghij', 14 ) . "';";
		$old      = "<?php\n// Example theme functions\nadd_action( 'init', 'example_old' );\n" . $long_old . "\n\$count = 1;\n";
		$new      = "<?php\n// Example theme functions\nadd_action( 'init', 'example_new' );\n" . $long_new . "\n\$count = 1;\n// <script>alert('shown as text')</script>\n";

		$this->now = gmmktime( 9, 0, 0, 9, 12, 2026 );
		$this->seed_change( [ 'change_id' => self::OPEN, 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/functions.php', 'ability' => 'stonewright/theme-file-write', 'summary' => 'Renamed the setup hook.' ], $old, $new, 'rolled_back_by' );
		$this->seed_change( [ 'change_id' => 'cs-bbbbbbbbbbbbbbbbbbbbbbbb', 'family' => 'option', 'resource_type' => 'option', 'resource_id' => 'blogname', 'ability' => 'stonewright/settings-update', 'summary' => 'Renamed the site.' ], [ 'value' => 'Example site' ], [ 'value' => 'Example site, renamed' ] );
		$this->seed_change( [ 'change_id' => 'cs-cccccccccccccccccccccccc', 'kind' => 'rollback', 'parent_id' => self::OPEN, 'family' => 'theme_file', 'resource_type' => 'theme_file', 'resource_id' => 'example-theme/functions.php', 'ability' => 'stonewright/change-rollback', 'summary' => 'Rolled back the setup hook change.' ], $new, $old );
		$this->seed_change( [ 'change_id' => 'cs-dddddddddddddddddddddddd', 'family' => 'post', 'resource_id' => '42', 'summary' => 'Changed the page title.' ], [ 'post_title' => 'Home' ], [ 'post_title' => 'Home page' ] );

		$_GET = [ 'change' => self::OPEN ];
		ob_start();
		try {
			ChangesPage::render();
			// The band's mark is printed only when the plugin address is defined, which depends on the test that ran before.
			$page = (string) preg_replace( '#<img class="sw-ui-band__logo"[^>]*>#', '', (string) ob_get_contents() );
		} finally {
			ob_end_clean();
		}

		return "<!doctype html>\n"
			. '<html lang="en">' . "\n"
			. '<head>' . "\n"
			. '<meta charset="utf-8">' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<title>Changes</title>' . "\n"
			. '<link rel="stylesheet" href="sw-ui.css">' . "\n"
			. '<link rel="stylesheet" href="shell.css">' . "\n"
			. '<link rel="stylesheet" href="admin.css">' . "\n"
			. '<link rel="stylesheet" href="stonewright-admin.css">' . "\n"
			. '<link rel="stylesheet" href="changes.css">' . "\n"
			. '<style>body{margin:0;background:#f0f0f1;color:#1d2327;font:13px/1.4 sans-serif}#wpcontent{padding:0 20px}.wrap h1{font-size:23px;font-weight:400;margin:0;padding:9px 0 4px;line-height:1.3}code{background:rgba(0,0,0,.07);padding:3px 5px 2px 1px;margin:0 1px;font-size:13px}</style>' . "\n"
			. '</head>' . "\n"
			. '<body class="wp-admin wp-core-ui admin-color-modern">' . "\n"
			. Icon::sprite() . "\n"
			. '<div id="wpcontent"><main id="wpbody-content">' . "\n"
			. $page . "\n"
			. '</main></div>' . "\n"
			. '<script src="sw-ui.js"></script>' . "\n"
			. '<script src="changes.js"></script>' . "\n"
			. '</body>' . "\n"
			. '</html>' . "\n";
	}

	public function test_the_checked_in_page_is_what_the_page_class_renders(): void {
		$html = $this->document();
		if ( '1' === getenv( 'STONEWRIGHT_UPDATE_FIXTURES' ) ) {
			$dir = dirname( self::path() );
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( self::path(), $html );
		}

		self::assertFileExists( self::path() );
		self::assertSame( file_get_contents( self::path() ), $html, 'Regenerate with STONEWRIGHT_UPDATE_FIXTURES=1 after changing the page.' );
		self::assertStringNotContainsString( "\r", $html );
	}

	public function test_the_fixture_carries_what_the_browser_spec_measures(): void {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $this->document() );
		libxml_clear_errors();
		$xpath = new DOMXPath( $dom );

		self::assertSame( 1, $xpath->query( '//dialog[@data-sw-changes-drawer and @data-sw-changes-id="' . self::OPEN . '" and @open]' )->length );
		self::assertSame( 1, $xpath->query( '//a[@data-sw-changes-open="' . self::OPEN . '"]' )->length, 'The row that opened the drawer is there to get focus back.' );
		self::assertSame( 4, $xpath->query( '//tr[starts-with(@id, "sw-change-")]' )->length );
		self::assertGreaterThan( 0, $xpath->query( '//*[contains(@class, "sw-ui-diff__line--add")]' )->length );
		self::assertGreaterThan( 0, $xpath->query( '//*[contains(@class, "sw-ui-diff__line--del")]' )->length );
		self::assertSame( 0, $xpath->query( '//script[not(@src)]' )->length, 'No inline script.' );

		$ids = [];
		foreach ( $xpath->query( '//*[@id]' ) ?: [] as $node ) {
			$ids[] = $node->getAttribute( 'id' );
		}
		self::assertSame( array_values( array_unique( $ids ) ), $ids, 'Ids are unique.' );
	}

	public function test_the_stylesheets_the_fixture_links_exist(): void {
		$document = $this->document();
		foreach ( [ 'admin/sw-ui.css', 'admin/shell.css', 'admin/admin.css', 'css/stonewright-admin.css', 'admin/pages/changes.css' ] as $asset ) {
			self::assertNotSame( '', CssSource::read( $asset ) );
		}
		self::assertStringContainsString( '<link rel="stylesheet" href="changes.css">', $document );
	}
}
