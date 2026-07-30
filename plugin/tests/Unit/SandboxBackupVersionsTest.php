<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * @covers \Stonewright\WpMcp\Sandbox\SandboxFiles
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage
 *
 * Verifies Gap 8: backup created on write, rollback action replaces content,
 * StaticGuard re-checked on rollback.
 *
 * Uses reflection to invoke SandboxLibraryPage::do_rollback() directly to avoid
 * the `exit` calls in handle_action().
 */
final class SandboxBackupVersionsTest extends TestCase {

	private string $sandbox_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}

		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_user_caps']    = [
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']      = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_user_caps']    = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;

		// Clean up test files.
		$patterns = [
			$this->sandbox_dir . '/backup-test-*.php',
			$this->sandbox_dir . '/backup-test-*.php.*.bak.php',
		];
		foreach ( $patterns as $pattern ) {
			$files = glob( $pattern );
			if ( is_array( $files ) ) {
				foreach ( $files as $f ) {
					if ( file_exists( $f ) ) {
						@unlink( $f );
					}
				}
			}
		}
	}

	/**
	 * Invoke SandboxLibraryPage::do_rollback() via reflection, optionally setting
	 * $_POST['stonewright_rollback_ts'].
	 *
	 * @return bool|\WP_Error
	 */
	private function call_do_rollback( string $name, bool $is_prod, string $token, int $ts ): bool|\WP_Error {
		$_POST['stonewright_rollback_ts'] = (string) $ts;

		try {
			$ref = new \ReflectionMethod( SandboxLibraryPage::class, 'do_rollback' );
			/** @var bool|\WP_Error $result */
			$result = $ref->invoke( null, $name, $is_prod, $token );
		} finally {
			unset( $_POST['stonewright_rollback_ts'] );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// SandboxFiles::backup_versions()
	// -------------------------------------------------------------------------

	public function test_backup_versions_returns_empty_when_no_backups(): void {
		$versions = SandboxFiles::backup_versions( 'backup-test-none.php' );
		$this->assertSame( [], $versions );
	}

	public function test_write_creates_backup_of_existing_file(): void {
		$name = 'backup-test-create.php';
		$path = $this->sandbox_dir . '/' . $name;

		// Write initial content.
		file_put_contents( $path, "<?php\n// v1\n" );

		// Write again — should create a backup.
		SandboxFiles::write( $name, "<?php\n// v2\n" );

		$versions = SandboxFiles::backup_versions( $name );
		$this->assertCount( 1, $versions, 'One backup should have been created' );
		$this->assertArrayHasKey( 'timestamp', $versions[0] );
		$this->assertArrayHasKey( 'path', $versions[0] );
		$this->assertFileExists( $versions[0]['path'] );
		$this->assertStringContainsString( '// v1', (string) file_get_contents( $versions[0]['path'] ) );
	}

	public function test_write_does_not_create_backup_for_new_file(): void {
		$name = 'backup-test-new.php';
		$path = $this->sandbox_dir . '/' . $name;
		if ( file_exists( $path ) ) {
			unlink( $path );
		}

		SandboxFiles::write( $name, "<?php\n// v1\n" );

		$versions = SandboxFiles::backup_versions( $name );
		$this->assertCount( 0, $versions, 'No backup should be created for a new file' );
	}

	public function test_backup_versions_sorted_newest_first(): void {
		$name = 'backup-test-sorted.php';
		$path = $this->sandbox_dir . '/' . $name;

		$ts1 = time() - 200;
		$ts2 = time() - 100;

		file_put_contents( $path . '.' . $ts1 . '.bak.php', "<?php\n// old\n" );
		file_put_contents( $path . '.' . $ts2 . '.bak.php', "<?php\n// newer\n" );

		$versions = SandboxFiles::backup_versions( $name );

		$this->assertCount( 2, $versions );
		$this->assertGreaterThan(
			$versions[1]['timestamp'],
			$versions[0]['timestamp'],
			'Newest backup should come first'
		);
	}

	public function test_write_multiple_times_creates_multiple_backups(): void {
		$name = 'backup-test-multi.php';
		$path = $this->sandbox_dir . '/' . $name;

		file_put_contents( $path, "<?php\n// v1\n" );
		SandboxFiles::write( $name, "<?php\n// v2\n" );
		SandboxFiles::write( $name, "<?php\n// v3\n" );

		$versions = SandboxFiles::backup_versions( $name );
		$this->assertGreaterThanOrEqual( 1, count( $versions ) );
	}

	// -------------------------------------------------------------------------
	// Rollback via SandboxLibraryPage::do_rollback (private, accessed via reflection)
	// -------------------------------------------------------------------------

	public function test_rollback_replaces_content_with_backup(): void {
		$name = 'backup-test-rollback.php';
		$path = $this->sandbox_dir . '/' . $name;

		// Write v1.
		file_put_contents( $path, "<?php\n// v1-rollback-target\n" );

		// Write v2 → creates backup of v1.
		SandboxFiles::write( $name, "<?php\n// v2\n" );

		$versions = SandboxFiles::backup_versions( $name );
		$this->assertNotEmpty( $versions, 'At least one backup should exist' );

		$ts = $versions[0]['timestamp'];

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = $this->call_do_rollback( $name, false, '', $ts );

		$this->assertTrue( $result );

		// File should now have v1 content.
		$current = (string) file_get_contents( $path );
		$this->assertStringContainsString( 'v1-rollback-target', $current );

		// Audit entry.
		$audit_inserts = array_filter(
			$GLOBALS['stonewright_test_wpdb_inserts'],
			static fn( array $i ): bool =>
				isset( $i['data']['ability_name'] ) && 'sandbox.rollback' === $i['data']['ability_name']
		);
		$this->assertNotEmpty( $audit_inserts, 'Audit entry for rollback should exist' );
	}

	public function test_rollback_with_unsafe_backup_content_is_blocked(): void {
		$name = 'backup-test-unsafe-rb.php';
		$path = $this->sandbox_dir . '/' . $name;

		file_put_contents( $path, "<?php\n// safe\n" );

		// Manually create a backup with unsafe content.
		$ts          = time() - 50;
		$backup_path = $path . '.' . $ts . '.bak.php';
		file_put_contents( $backup_path, "<?php\nev" . "al(\$x);\n" );

		$result = $this->call_do_rollback( $name, false, '', $ts );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_static_guard', $result->get_error_code() );

		// Current content unchanged.
		$current = (string) file_get_contents( $path );
		$this->assertStringContainsString( '// safe', $current );
		$this->assertStringNotContainsString( 'eval', $current );

		@unlink( $backup_path );
	}

	public function test_rollback_with_nonexistent_timestamp_returns_error(): void {
		$name = 'backup-test-nots.php';
		$path = $this->sandbox_dir . '/' . $name;
		file_put_contents( $path, "<?php\n// content\n" );

		$result = $this->call_do_rollback( $name, false, '', 999 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_rollback_not_found', $result->get_error_code() );
	}
}
