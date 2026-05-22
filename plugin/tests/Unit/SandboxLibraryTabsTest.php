<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage
 *
 * Verifies Gap 3: tab whitelist and correct file isolation per tab.
 * Verifies Gap 4: category and status filter sanitization.
 */
final class SandboxLibraryTabsTest extends TestCase {

	private string $sandbox_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}

		$GLOBALS['stonewright_test_options']     = [];
		$GLOBALS['stonewright_test_user_caps']   = [
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_user_caps'] = [];

		// Clean up test files.
		foreach ( [ 'widget-tab-test.php', 'plugin-tab-test.php', 'snippet-tab-test.php' ] as $f ) {
			$p = $this->sandbox_dir . '/' . $f;
			if ( file_exists( $p ) ) {
				unlink( $p );
			}
		}
	}

	// -------------------------------------------------------------------------
	// resolve_sandbox_basename — path traversal / invalid name rejection
	// -------------------------------------------------------------------------

	public function test_resolve_sandbox_basename_rejects_path_traversal(): void {
		$result = SandboxLibraryPage::resolve_sandbox_basename( '../secret.php' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_path_traversal', $result->get_error_code() );
	}

	public function test_resolve_sandbox_basename_rejects_invalid_name(): void {
		$result = SandboxLibraryPage::resolve_sandbox_basename( 'UPPER.php' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_invalid_name', $result->get_error_code() );
	}

	public function test_resolve_sandbox_basename_accepts_valid_name(): void {
		$result = SandboxLibraryPage::resolve_sandbox_basename( 'my-snippet.php' );
		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// Tab: default to 'snippets' when invalid tab provided
	// -------------------------------------------------------------------------

	public function test_render_uses_snippets_tab_as_default_for_invalid_tab(): void {
		$_GET = [ 'tab' => 'evil<script>' ];

		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();

		$_GET = [];

		// The snippets tab should be selected (nav-tab-active on snippets link).
		$this->assertStringContainsString( 'tab=snippets', $output );
		$this->assertStringNotContainsString( '<script>', $output );
	}

	public function test_render_shows_all_four_tabs(): void {
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'tab=snippets', $output );
		$this->assertStringContainsString( 'tab=widgets', $output );
		$this->assertStringContainsString( 'tab=plugins', $output );
		$this->assertStringContainsString( 'tab=qa-artifacts', $output );
	}

	// -------------------------------------------------------------------------
	// Tab filtering: widget files appear only in widgets tab, not snippets
	// -------------------------------------------------------------------------

	public function test_widget_files_appear_in_widgets_tab(): void {
		file_put_contents( $this->sandbox_dir . '/widget-tab-test.php', "<?php\n// widget\n" );

		$_GET = [ 'tab' => 'widgets' ];
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();
		$_GET   = [];

		$this->assertStringContainsString( 'widget-tab-test.php', $output );
	}

	public function test_widget_files_do_not_appear_in_snippets_tab(): void {
		file_put_contents( $this->sandbox_dir . '/widget-tab-test.php', "<?php\n// widget\n" );

		$_GET = [ 'tab' => 'snippets' ];
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();
		$_GET   = [];

		$this->assertStringNotContainsString( 'widget-tab-test.php', $output );
	}

	public function test_plugin_files_appear_in_plugins_tab(): void {
		file_put_contents( $this->sandbox_dir . '/plugin-tab-test.php', "<?php\n// plugin\n" );

		$_GET = [ 'tab' => 'plugins' ];
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();
		$_GET   = [];

		$this->assertStringContainsString( 'plugin-tab-test.php', $output );
	}

	public function test_snippet_files_appear_in_snippets_tab(): void {
		file_put_contents( $this->sandbox_dir . '/snippet-tab-test.php', "<?php\n// snippet\n" );

		$_GET = [ 'tab' => 'snippets' ];
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();
		$_GET   = [];

		$this->assertStringContainsString( 'snippet-tab-test.php', $output );
	}

	// -------------------------------------------------------------------------
	// Gap 4: Filter sanitization — invalid values silently fall back to ''
	// -------------------------------------------------------------------------

	public function test_invalid_category_filter_is_sanitized(): void {
		$_GET = [ 'category' => '<script>bad</script>' ];
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();
		$_GET   = [];

		// No XSS should appear.
		$this->assertStringNotContainsString( '<script>', $output );
	}

	public function test_invalid_status_filter_is_sanitized(): void {
		$_GET = [ 'status' => 'evil-status' ];
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();
		$_GET   = [];

		// No unrecognized status values in output.
		$this->assertStringNotContainsString( 'evil-status', $output );
	}

	// -------------------------------------------------------------------------
	// QA Artifacts tab: shows "no artifacts" message when dir missing
	// -------------------------------------------------------------------------

	public function test_qa_artifacts_tab_shows_empty_message_when_dir_missing(): void {
		// Ensure the QA dir doesn't exist.
		$qa_dir = WP_CONTENT_DIR . '/stonewright-qa-artifacts';
		if ( is_dir( $qa_dir ) ) {
			$this->markTestSkipped( 'QA artifacts dir exists; skipping empty-dir test.' );
		}

		$_GET = [ 'tab' => 'qa-artifacts' ];
		ob_start();
		SandboxLibraryPage::render();
		$output = ob_get_clean();
		$_GET   = [];

		$this->assertStringContainsString( 'does not exist', $output );
	}
}
