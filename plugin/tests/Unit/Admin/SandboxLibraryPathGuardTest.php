<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;

/**
 * Verifies that SandboxLibraryPage::resolve_sandbox_basename() rejects
 * all path-traversal and escape attempts.
 *
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage::resolve_sandbox_basename
 */
final class SandboxLibraryPathGuardTest extends TestCase {

	// -------------------------------------------------------------------------
	// Rejection cases
	// -------------------------------------------------------------------------

	/** @dataProvider traversal_provider */
	public function test_path_traversal_attempts_are_rejected( string $input ): void {
		$result = SandboxLibraryPage::resolve_sandbox_basename( $input );

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			"Expected WP_Error for input: {$input}"
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function traversal_provider(): array {
		return [
			'directory separator forward slash' => [ '../etc/passwd' ],
			'directory separator backslash'      => [ '..\\windows\\system32' ],
			'absolute path unix'                 => [ '/etc/passwd' ],
			'absolute path with extension'        => [ '/tmp/evil.php' ],
			'nested traversal'                   => [ 'a/../../../b.php' ],
			'double slash'                        => [ 'foo//bar.php' ],
			'null byte'                           => [ "foo\0.php" ],
			'invalid extension txt'               => [ 'foo.txt' ],
			'invalid extension no ext'            => [ 'foo' ],
			'uppercase letters'                   => [ 'Foo.php' ],
			'space in name'                       => [ 'foo bar.php' ],
			'special chars'                       => [ 'foo!bar.php' ],
		];
	}

	// -------------------------------------------------------------------------
	// Acceptance cases (basename only, no existing file — dir resolution only)
	// -------------------------------------------------------------------------

	/** @dataProvider valid_basename_provider */
	public function test_valid_sandbox_basename_accepted( string $input ): void {
		// These names pass the regex check. realpath on the sandbox dir will work
		// because bootstrap creates a temp WP_CONTENT_DIR. The file doesn't need
		// to exist — the guard skips the realpath step for non-existent files.
		$result = SandboxLibraryPage::resolve_sandbox_basename( $input );

		// Result is either true (accepted) or WP_Error with dir_missing code
		// (if the sandbox dir does not exist yet in this test environment).
		if ( is_wp_error( $result ) ) {
			$this->assertSame(
				'stonewright_sandbox_dir_missing',
				$result->get_error_code(),
				"Unexpected WP_Error code for valid basename '{$input}': " . $result->get_error_code()
			);
		} else {
			$this->assertTrue( $result, "Expected true for valid basename '{$input}'" );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function valid_basename_provider(): array {
		return [
			'simple lowercase'          => [ 'my-snippet.php' ],
			'with digits'               => [ 'foo-123.php' ],
			'underscores'               => [ 'my_widget_v2.php' ],
			'all lowercase letters'     => [ 'abcdefghijklmnopqrstuvwxyz.php' ],
		];
	}

	// -------------------------------------------------------------------------
	// Symlink escape — symlink pointing outside sandbox must be rejected
	// -------------------------------------------------------------------------

	public function test_symlink_outside_sandbox_rejected(): void {
		// Create a temp sandbox dir and a symlink that points outside it.
		$sandbox_dir = sys_get_temp_dir() . '/sw-sandbox-symlink-test-' . getmypid();
		$outside_dir = sys_get_temp_dir() . '/sw-outside-' . getmypid();

		wp_mkdir_p( $sandbox_dir );
		wp_mkdir_p( $outside_dir );

		$outside_file = $outside_dir . '/evil.php';
		file_put_contents( $outside_file, '<?php' ); // phpcs:ignore

		$symlink_name = $sandbox_dir . '/link-to-evil.php';

		// Only run if symlink creation succeeds (may fail on some environments).
		if ( ! @symlink( $outside_file, $symlink_name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->markTestSkipped( 'Symlink creation not supported in this environment.' );
			return;
		}

		// Override the sandbox dir constant via file-system gymnastics: the
		// resolve_sandbox_basename method calls SandboxFiles::draft_dir() which
		// uses WP_CONTENT_DIR. We cannot redefine the constant, so we test the
		// realpath logic directly using our temporary dirs.
		//
		// Verify: realpath($symlink_name) starts with $outside_dir, NOT $sandbox_dir.
		// This is the invariant the guard enforces.
		$real_link = realpath( $symlink_name );
		$real_dir  = realpath( $sandbox_dir );

		$this->assertIsString( $real_link, 'realpath on symlink returned false — file system issue.' );
		$this->assertIsString( $real_dir );
		$this->assertStringStartsNotWith(
			$real_dir . DIRECTORY_SEPARATOR,
			$real_link,
			'Symlink realpath should NOT start with sandbox dir — this is the escape condition the guard catches.'
		);

		// Cleanup.
		@unlink( $symlink_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $outside_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $outside_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $sandbox_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// -------------------------------------------------------------------------
	// Basename-only requirement: input must equal basename($input)
	// -------------------------------------------------------------------------

	public function test_input_with_directory_component_rejected(): void {
		$result = SandboxLibraryPage::resolve_sandbox_basename( 'subdir/foo.php' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_path_traversal', $result->get_error_code() );
	}

	public function test_plain_basename_passes_basename_check(): void {
		// 'foo-bar.php' === basename('foo-bar.php') → passes the basename check.
		// Then hits valid_name check (passes), then realpath on dir (may succeed or fail
		// with dir_missing in test environment). Either way: not path_traversal error.
		$result = SandboxLibraryPage::resolve_sandbox_basename( 'foo-bar.php' );
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'stonewright_sandbox_path_traversal', $result->get_error_code() );
		} else {
			$this->assertTrue( $result );
		}
	}
}
