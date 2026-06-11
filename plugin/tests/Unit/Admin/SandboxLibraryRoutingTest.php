<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage
 * @covers \Stonewright\WpMcp\Admin\SandboxPage
 */
final class SandboxLibraryRoutingTest extends TestCase {

	private string $sandbox_dir;
	private string $mu_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		$this->mu_dir      = WP_CONTENT_DIR . '/mu-plugins';

		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}
		if ( ! is_dir( $this->mu_dir ) ) {
			mkdir( $this->mu_dir, 0755, true );
		}

		$GLOBALS['stonewright_test_user_caps'] = [
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$_GET = [];
		$_POST = [];

		$this->empty_test_dirs();
	}

	protected function tearDown(): void {
		$this->empty_test_dirs();
		$GLOBALS['stonewright_test_user_caps']        = [];
		$GLOBALS['stonewright_test_current_user_id']  = 0;
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_transients']       = [];
		$GLOBALS['stonewright_test_last_redirect']    = null;
		$GLOBALS['stonewright_test_submenu_pages']    = [];
		$_GET = [];
		$_POST = [];
	}

	public function test_register_adds_hidden_admin_page_for_legacy_direct_urls(): void {
		SandboxLibraryPage::register();

		foreach ( $GLOBALS['stonewright_test_actions']['admin_menu'] ?? [] as $action ) {
			$callback = $action['callback'];
			if ( is_callable( $callback ) ) {
				$callback();
			}
		}

		self::assertArrayHasKey( SandboxLibraryPage::SLUG, $GLOBALS['stonewright_test_submenu_pages'] );
		self::assertSame( '', $GLOBALS['stonewright_test_submenu_pages'][ SandboxLibraryPage::SLUG ]['parent'] );
		self::assertSame( 'manage_options', $GLOBALS['stonewright_test_submenu_pages'][ SandboxLibraryPage::SLUG ]['capability'] );
	}

	public function test_embedded_library_uses_library_tab_links_without_page_slug_collision(): void {
		SandboxFiles::write( 'route-test.php', "<?php\n// route test\n" );
		$_GET = [
			'page' => 'stonewright-sandbox',
			'tab'  => 'library',
		];

		ob_start();
		SandboxPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'page=stonewright-sandbox&tab=library&library_tab=widgets', $html );
		self::assertStringContainsString( 'page=stonewright-sandbox&tab=library&library_tab=plugins', $html );
		self::assertStringContainsString( 'page=stonewright-sandbox&tab=library&library_tab=snippets&action=edit&file=route-test.php', $html );
		self::assertStringContainsString( 'name="stonewright_return_tab" value="library"', $html );
		self::assertStringNotContainsString( 'page=stonewright-sandbox-library&tab=widgets', $html );
	}

	public function test_library_url_helper_keeps_embedded_tab_context(): void {
		self::assertSame(
			'https://example.test/wp-admin/admin.php?page=stonewright-sandbox&tab=library&library_tab=widgets',
			SandboxLibraryPage::library_url( [ 'library_tab' => 'widgets' ] )
		);
	}

	public function test_active_mu_plugins_tab_uses_sandbox_active_prefix(): void {
		SandboxFiles::write( 'active-visible.php', "<?php\n// active visible\n" );
		SandboxFiles::activate( 'active-visible.php' );

		$_GET = [
			'page' => 'stonewright-sandbox',
			'tab'  => 'mu-plugins',
		];

		ob_start();
		SandboxPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'active-visible.php', $html );
		self::assertStringNotContainsString( 'No sandbox files are currently active as MU plugins.', $html );
	}

	private function empty_test_dirs(): void {
		foreach ( [ $this->sandbox_dir, $this->mu_dir ] as $dir ) {
			foreach ( glob( $dir . '/*' ) ?: [] as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		}
	}
}
