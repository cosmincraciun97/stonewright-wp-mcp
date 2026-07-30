<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage
 *
 * Verifies Important 2:
 * - render_editor() emits an error notice and does NOT output file contents when
 *   the file exceeds MAX_EDIT_BYTES (262 144 bytes).
 * - do_edit() (save handler) returns WP_Error stonewright_sandbox_too_large when
 *   the submitted content exceeds MAX_EDIT_BYTES.
 */
final class SandboxLibraryEditorSizeTest extends TestCase {

	private string $sandbox_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}

		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_user_caps']        = [
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']        = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];

		$patterns = [
			$this->sandbox_dir . '/size-test-*.php',
			$this->sandbox_dir . '/size-test-*.php.*.bak.php',
		];
		foreach ( $patterns as $pattern ) {
			$files = glob( $pattern );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( file_exists( $f ) ) {
						@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Helper: invoke private static method via reflection
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $extra_post
	 * @return bool|\WP_Error
	 */
	private function call_do_edit( string $name, bool $is_prod, string $raw_token, array $extra_post = [] ): bool|\WP_Error {
		foreach ( $extra_post as $k => $v ) {
			$_POST[ $k ] = $v;
		}

		try {
			$ref = new \ReflectionMethod( SandboxLibraryPage::class, 'do_edit' );
			/** @var bool|\WP_Error $result */
			$result = $ref->invoke( null, $name, $is_prod, $raw_token );
		} finally {
			$_POST = [];
		}

		return $result;
	}

	private function call_render_editor( string $file ): string {
		ob_start();
		$ref = new \ReflectionMethod( SandboxLibraryPage::class, 'render_editor' );
		$ref->invoke( null, $file );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// do_edit() — POST content size enforcement
	// -------------------------------------------------------------------------

	public function test_do_edit_rejects_oversize_post_content(): void {
		$file = 'size-test-edit.php';
		file_put_contents( $this->sandbox_dir . '/' . $file, "<?php\n// original\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Build content just above the 262 144 byte limit.
		$oversized_content = str_repeat( 'A', 262145 );

		$result = $this->call_do_edit(
			$file,
			false,
			'',
			[
				'stonewright_content'  => $oversized_content,
				'content_hash_at_render' => '',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_too_large', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// render_editor() — oversize file on disk
	// -------------------------------------------------------------------------

	public function test_render_editor_with_oversize_file_shows_error_without_file_contents(): void {
		$file      = 'size-test-render.php';
		$path      = $this->sandbox_dir . '/' . $file;
		$sentinel  = '// SENTINEL_CONTENT_SHOULD_NOT_APPEAR';

		// Write a file exceeding 262 144 bytes. The sentinel string is near the start
		// so if file_get_contents runs we would see it in the output.
		$large_content = $sentinel . str_repeat( 'X', 262200 );
		file_put_contents( $path, $large_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$output = $this->call_render_editor( $file );

		// Must show an error about the file being too large.
		$this->assertStringContainsString( 'too large', strtolower( $output ), 'render_editor must emit a "too large" notice for oversize files' );

		// Must NOT contain the file contents.
		$this->assertStringNotContainsString( $sentinel, $output, 'render_editor must not output oversize file contents' );

		// Must NOT contain the textarea (editor form).
		$this->assertStringNotContainsString( '<textarea', $output, 'render_editor must not render the editor textarea for oversize files' );
	}

	/**
	 * Smoke test that the render_editor size gate is triggered specifically by the
	 * filesize, not by some other code path. The oversize test is sufficient; this
	 * stub confirms the helper works end-to-end for the error branch.
	 */
	public function test_render_editor_oversize_output_contains_back_link(): void {
		$file     = 'size-test-back.php';
		$path     = $this->sandbox_dir . '/' . $file;
		file_put_contents( $path, str_repeat( 'X', 262200 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$output = $this->call_render_editor( $file );

		// The error branch should include a Back link.
		$this->assertStringContainsString( 'Back', $output, 'render_editor must render a Back link for oversize files' );
	}
}
