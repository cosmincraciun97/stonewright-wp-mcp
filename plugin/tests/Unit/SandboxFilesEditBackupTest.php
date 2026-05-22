<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * @covers \Stonewright\WpMcp\Sandbox\SandboxFiles
 *
 * Verifies Suggested 4:
 * - SandboxFiles::edit() creates a backup before applying the string replacement.
 * - backup_versions() returns the pre-edit content after edit completes.
 */
final class SandboxFilesEditBackupTest extends TestCase {

	private string $sandbox_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}

		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']        = [
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']        = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;

		$patterns = [
			$this->sandbox_dir . '/edit-backup-test-*.php',
			$this->sandbox_dir . '/edit-backup-test-*.php.*.bak.php',
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

	public function test_edit_creates_backup_before_modifying(): void {
		$name     = 'edit-backup-test-create.php';
		$original = "<?php\n// ORIGINAL_MARKER\nadd_action('init', '__return_true');\n";

		// Seed the file.
		file_put_contents( $this->sandbox_dir . '/' . $name, $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Sanity: no backups yet.
		$before = SandboxFiles::backup_versions( $name );
		$this->assertCount( 0, $before, 'No backups should exist before edit()' );

		// Perform an edit.
		$result = SandboxFiles::edit( $name, '// ORIGINAL_MARKER', '// MODIFIED_MARKER' );
		$this->assertTrue( $result, 'edit() should return true on success' );

		// A backup of the original must now exist.
		$after = SandboxFiles::backup_versions( $name );
		$this->assertNotEmpty( $after, 'edit() must create a backup of the pre-edit content' );

		// The backup must contain the original content.
		$backup_content = (string) file_get_contents( $after[0]['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'ORIGINAL_MARKER', $backup_content, 'Backup must preserve the pre-edit content' );

		// The current file must have the modified content.
		$current = (string) file_get_contents( $this->sandbox_dir . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'MODIFIED_MARKER', $current, 'Current file must reflect the edit' );
		$this->assertStringNotContainsString( 'ORIGINAL_MARKER', $current, 'Original marker must be replaced' );
	}

	public function test_backup_versions_returns_pre_edit_content_after_edit(): void {
		$name     = 'edit-backup-test-versions.php';
		$original = "<?php\n// VERSION_ONE\n";

		file_put_contents( $this->sandbox_dir . '/' . $name, $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		SandboxFiles::edit( $name, '// VERSION_ONE', '// VERSION_TWO' );

		$versions = SandboxFiles::backup_versions( $name );
		$this->assertNotEmpty( $versions );

		// Newest-first — first entry is the backup of VERSION_ONE.
		$backup_content = (string) file_get_contents( $versions[0]['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( 'VERSION_ONE', $backup_content );
	}

	public function test_edit_audit_event_is_sandbox_write(): void {
		$name = 'edit-backup-test-audit.php';
		file_put_contents( $this->sandbox_dir . '/' . $name, "<?php\n// OLD\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		SandboxFiles::edit( $name, '// OLD', '// NEW' );

		$writes = array_filter(
			$GLOBALS['stonewright_test_wpdb_inserts'],
			static fn( array $i ): bool =>
				isset( $i['data']['ability_name'] ) && 'sandbox.write' === $i['data']['ability_name']
		);

		$this->assertNotEmpty( $writes, 'edit() must emit a sandbox.write audit event' );
	}
}
