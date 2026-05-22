<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\SandboxFiles;

/**
 * @covers \Stonewright\WpMcp\Sandbox\SandboxFiles
 *
 * Verifies Important 1 + Suggested 3:
 * - index.php (the silence-is-golden stub) is excluded from list_files() output.
 * - All write/delete/activate operations on index.php return WP_Error stonewright_sandbox_reserved_name.
 * - Backup .bak.php files do not appear in list_files() output.
 */
final class SandboxReservedNamesTest extends TestCase {

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

		// Clean up any test files created (but not index.php — it is part of the sandbox stub).
		$patterns = [
			$this->sandbox_dir . '/reserved-test-*.php',
			$this->sandbox_dir . '/reserved-test-*.php.*.bak.php',
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
	// RESERVED_NAMES constant
	// -------------------------------------------------------------------------

	public function test_reserved_names_constant_contains_index_php(): void {
		$this->assertContains( 'index.php', SandboxFiles::RESERVED_NAMES );
	}

	// -------------------------------------------------------------------------
	// list_files() exclusions
	// -------------------------------------------------------------------------

	public function test_index_php_not_in_list_files(): void {
		// Ensure index.php actually exists (draft_dir() creates it).
		$index = $this->sandbox_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$files = SandboxFiles::list_files();
		$names = array_column( $files, 'name' );

		$this->assertNotContains( 'index.php', $names, 'index.php must never appear in list_files() output' );
	}

	public function test_bak_files_not_in_list_files(): void {
		// Create a real snippet + trigger a backup by writing twice.
		$name = 'reserved-test-bak.php';
		$path = $this->sandbox_dir . '/' . $name;

		// Write initial content so a backup can be made.
		file_put_contents( $path, "<?php\n// v1\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Write via SandboxFiles to trigger backup_before_write.
		SandboxFiles::write( $name, "<?php\n// v2\n" );

		// Verify at least one .bak.php file exists.
		$bak_files = glob( $this->sandbox_dir . '/*.bak.php' );
		$this->assertNotEmpty( $bak_files, 'At least one .bak.php backup should exist after write' );

		// list_files() must not include any .bak.php.
		$listed_names = array_column( SandboxFiles::list_files(), 'name' );
		foreach ( $listed_names as $listed_name ) {
			$this->assertStringNotContainsString( '.bak.php', $listed_name, "Backup file '{$listed_name}' must not appear in list_files()" );
		}
	}

	// -------------------------------------------------------------------------
	// Reserved name rejection on all write/delete/activate paths
	// -------------------------------------------------------------------------

	public function test_delete_index_php_returns_reserved_name_error(): void {
		$result = SandboxFiles::delete( 'index.php' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_reserved_name', $result->get_error_code() );
	}

	public function test_write_index_php_returns_reserved_name_error(): void {
		$result = SandboxFiles::write( 'index.php', "<?php\n// attacker content\n" );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_reserved_name', $result->get_error_code() );
	}

	public function test_activate_index_php_returns_reserved_name_error(): void {
		$result = SandboxFiles::activate( 'index.php' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_reserved_name', $result->get_error_code() );
	}

	public function test_edit_index_php_returns_reserved_name_error(): void {
		$result = SandboxFiles::edit( 'index.php', '// Silence is golden.', '// pwned' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_reserved_name', $result->get_error_code() );
	}
}
