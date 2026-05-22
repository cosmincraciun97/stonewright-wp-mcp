<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Sandbox\StaticGuard;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\SandboxLibraryPage
 *
 * Verifies Gap 1: production-safe confirmation token required for
 * activate/delete in production-safe mode; not required in development mode.
 *
 * Verifies Gap 6: editor save rejects unsafe content, accepts safe content,
 * audits on success.
 *
 * Tests call the private do_* methods via reflection to avoid the `exit` calls
 * in handle_action().
 */
final class SandboxLibraryProductionSafeTest extends TestCase {

	private string $sandbox_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}

		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_user_caps']       = [
			'manage_options' => true,
			'edit_plugins'   => true,
		];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];

		// Remove test sandbox files.
		$patterns = [
			$this->sandbox_dir . '/test-pst-*.php',
			$this->sandbox_dir . '/test-pst-*.php.*.bak.php',
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

	// -------------------------------------------------------------------------
	// Reflection helpers
	// -------------------------------------------------------------------------

	/**
	 * Calls a private static method on SandboxLibraryPage and populates
	 * $_POST['stonewright_content'] and $_POST['stonewright_rollback_ts'] before
	 * the call (for do_edit and do_rollback that read from $_POST).
	 *
	 * @param string               $method      Private static method name.
	 * @param string               $name        Sandbox basename.
	 * @param bool                 $is_prod     True when production-safe.
	 * @param string               $raw_token   Confirmation token string.
	 * @param array<string, mixed> $extra_post  Extra $_POST entries.
	 * @return true|\WP_Error
	 */
	private function call_do_method(
		string $method,
		string $name,
		bool $is_prod,
		string $raw_token,
		array $extra_post = []
	): true|\WP_Error {
		foreach ( $extra_post as $k => $v ) {
			$_POST[ $k ] = $v;
		}

		try {
			$ref = new \ReflectionMethod( SandboxLibraryPage::class, $method );
			/** @var true|\WP_Error $result */
			$result = $ref->invoke( null, $name, $is_prod, $raw_token );
		} finally {
			$_POST = [];
		}

		return $result;
	}

	private function create_test_file( string $name ): void {
		file_put_contents( $this->sandbox_dir . '/' . $name, "<?php\n// test\n" );
	}

	// -------------------------------------------------------------------------
	// Gap 1: Confirmation token — activate
	// -------------------------------------------------------------------------

	public function test_activate_in_development_mode_requires_no_token(): void {
		$file = 'test-pst-activate-dev.php';
		$this->create_test_file( $file );

		$result = $this->call_do_method( 'do_activate', $file, false, '' );

		// In dev mode with no token, should NOT fail with a token error.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'stonewright_confirmation_invalid',
				$result->get_error_code(),
				'Dev mode should not enforce tokens'
			);
			$this->assertNotSame(
				'stonewright_confirmation_replayed',
				$result->get_error_code()
			);
		} else {
			$this->assertTrue( $result );
		}
	}

	public function test_activate_in_production_safe_mode_without_token_is_rejected(): void {
		$file = 'test-pst-activate-noprod.php';
		$this->create_test_file( $file );

		$result = $this->call_do_method( 'do_activate', $file, true, '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString(
			'token',
			strtolower( $result->get_error_message() ),
			'Empty token in production-safe should produce a token error'
		);
	}

	public function test_activate_in_production_safe_mode_with_valid_token_proceeds(): void {
		$file  = 'test-pst-activate-validprod.php';
		$this->create_test_file( $file );

		$token = ConfirmationToken::issue( 'stonewright/sandbox-activate', [ 'name' => $file ] );
		$result = $this->call_do_method( 'do_activate', $file, true, $token );

		// Token should pass; any subsequent error is filesystem-level (not token).
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'stonewright_confirmation_invalid',
				$result->get_error_code(),
				'With valid token, no token error should appear'
			);
		} else {
			$this->assertTrue( $result );
		}
	}

	// -------------------------------------------------------------------------
	// Gap 1: Confirmation token — delete
	// -------------------------------------------------------------------------

	public function test_delete_in_development_mode_requires_no_token(): void {
		$file = 'test-pst-delete-dev.php';
		$this->create_test_file( $file );

		$result = $this->call_do_method( 'do_delete', $file, false, '' );

		if ( is_wp_error( $result ) ) {
			$this->assertNotSame(
				'stonewright_confirmation_invalid',
				$result->get_error_code(),
				'Dev mode should not enforce tokens for delete'
			);
		} else {
			$this->assertTrue( $result );
		}
	}

	public function test_delete_in_production_safe_mode_without_token_is_rejected(): void {
		$file = 'test-pst-delete-noprod.php';
		$this->create_test_file( $file );

		$result = $this->call_do_method( 'do_delete', $file, true, '' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString(
			'token',
			strtolower( $result->get_error_message() )
		);
	}

	// -------------------------------------------------------------------------
	// Gap 6: Editor — StaticGuard, audit, production-safe token
	// -------------------------------------------------------------------------

	public function test_edit_rejects_unsafe_content(): void {
		$file         = 'test-pst-edit-unsafe.php';
		$disk_content = "<?php\n// test\n";
		file_put_contents( $this->sandbox_dir . '/' . $file, $disk_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$correct_hash = substr( hash( 'sha256', $disk_content ), 0, 16 );

		$result = $this->call_do_method(
			'do_edit',
			$file,
			false,
			'',
			[
				'stonewright_content'    => "<?php\nev" . "al(\$x);\n",
				'content_hash_at_render' => $correct_hash,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_static_guard', $result->get_error_code() );
	}

	public function test_edit_accepts_safe_content_and_audits(): void {
		$file         = 'test-pst-edit-safe.php';
		$disk_content = "<?php\n// test\n";
		file_put_contents( $this->sandbox_dir . '/' . $file, $disk_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$correct_hash = substr( hash( 'sha256', $disk_content ), 0, 16 );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		$new_content = "<?php\nadd_action('init', function() {});\n";
		$result      = $this->call_do_method(
			'do_edit',
			$file,
			false,
			'',
			[
				'stonewright_content'    => $new_content,
				'content_hash_at_render' => $correct_hash,
			]
		);

		$this->assertTrue( $result );

		// Verify audit log — SandboxFiles::write emits 'sandbox.write' (not 'sandbox.edit').
		$audit_inserts = array_filter(
			$GLOBALS['stonewright_test_wpdb_inserts'],
			static fn( array $i ): bool =>
				isset( $i['data']['ability_name'] ) && 'sandbox.write' === $i['data']['ability_name']
		);
		$this->assertNotEmpty( $audit_inserts, 'Audit record for sandbox.write should exist' );

		// Ensure no duplicate 'sandbox.edit' entries are emitted.
		$edit_inserts = array_filter(
			$GLOBALS['stonewright_test_wpdb_inserts'],
			static fn( array $i ): bool =>
				isset( $i['data']['ability_name'] ) && 'sandbox.edit' === $i['data']['ability_name']
		);
		$this->assertEmpty( $edit_inserts, 'No duplicate sandbox.edit audit entry should be emitted' );

		// Verify content_sha8 is present in the audit payload.
		$audit = reset( $audit_inserts );
		$args  = json_decode( (string) ( $audit['data']['sanitized_args'] ?? '{}' ), true );
		$this->assertIsArray( $args );
		$this->assertArrayHasKey( 'content_sha8', $args, 'sandbox.write audit must include content_sha8' );
	}

	public function test_edit_audit_records_content_sha8(): void {
		$file         = 'test-pst-edit-sha8.php';
		$disk_content = "<?php\n// test\n";
		file_put_contents( $this->sandbox_dir . '/' . $file, $disk_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$correct_hash = substr( hash( 'sha256', $disk_content ), 0, 16 );
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];

		$content = "<?php\nadd_filter('the_content', 'wp_strip_all_tags');\n";

		$this->call_do_method(
			'do_edit',
			$file,
			false,
			'',
			[
				'stonewright_content'    => $content,
				'content_hash_at_render' => $correct_hash,
			]
		);

		$expected_sha8 = substr( hash( 'sha256', $content ), 0, 8 );

		// SandboxFiles::write now emits 'sandbox.write' with content_sha8.
		$audit_inserts = array_filter(
			$GLOBALS['stonewright_test_wpdb_inserts'],
			static fn( array $i ): bool =>
				isset( $i['data']['ability_name'] ) && 'sandbox.write' === $i['data']['ability_name']
		);
		$this->assertNotEmpty( $audit_inserts );

		$audit = reset( $audit_inserts );
		$args  = json_decode( (string) ( $audit['data']['sanitized_args'] ?? '{}' ), true );
		$this->assertIsArray( $args );
		$this->assertSame( $expected_sha8, $args['content_sha8'] );
	}

	public function test_edit_in_production_safe_without_token_is_rejected(): void {
		$file         = 'test-pst-edit-prodnotoken.php';
		$disk_content = "<?php\n// test\n";
		file_put_contents( $this->sandbox_dir . '/' . $file, $disk_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$correct_hash = substr( hash( 'sha256', $disk_content ), 0, 16 );

		$result = $this->call_do_method(
			'do_edit',
			$file,
			true,
			'',
			[
				'stonewright_content'    => "<?php\n// safe\n",
				'content_hash_at_render' => $correct_hash,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString(
			'token',
			strtolower( $result->get_error_message() )
		);
	}

	public function test_edit_in_production_safe_with_valid_token_accepts_safe_content(): void {
		$file         = 'test-pst-edit-prodtoken.php';
		$disk_content = "<?php\n// test\n";
		file_put_contents( $this->sandbox_dir . '/' . $file, $disk_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Token must be bound to the content hash as render_editor now issues it.
		$content_hash = substr( hash( 'sha256', $disk_content ), 0, 16 );
		$token        = ConfirmationToken::issue(
			'stonewright/sandbox-edit',
			[ 'name' => $file, 'content_hash' => $content_hash ]
		);

		$result = $this->call_do_method(
			'do_edit',
			$file,
			true,
			$token,
			[
				'stonewright_content'    => "<?php\n// safe content\n",
				'content_hash_at_render' => $content_hash,
			]
		);

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// Suggested 6: content_hash optimistic locking
	// -------------------------------------------------------------------------

	public function test_edit_with_stale_content_hash_returns_conflict_error(): void {
		$file         = 'test-pst-conflict.php';
		$disk_content = "<?php\n// disk version\n";
		file_put_contents( $this->sandbox_dir . '/' . $file, $disk_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// Simulate a stale hash — whatever was rendered is now outdated.
		$stale_hash = 'aabbccddeeff0011'; // does not match disk content.

		$result = $this->call_do_method(
			'do_edit',
			$file,
			false, // dev mode — no token required, pure conflict check.
			'',
			[
				'stonewright_content'    => "<?php\n// my edits\n",
				'content_hash_at_render' => $stale_hash,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_conflict', $result->get_error_code() );
	}

	public function test_edit_with_matching_content_hash_succeeds(): void {
		$file         = 'test-pst-match-hash.php';
		$disk_content = "<?php\n// original content\n";
		file_put_contents( $this->sandbox_dir . '/' . $file, $disk_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$correct_hash = substr( hash( 'sha256', $disk_content ), 0, 16 );

		$result = $this->call_do_method(
			'do_edit',
			$file,
			false,
			'',
			[
				'stonewright_content'    => "<?php\n// new content\n",
				'content_hash_at_render' => $correct_hash,
			]
		);

		$this->assertTrue( $result );
	}

	/**
	 * Security: sending an empty content_hash_at_render for an EXISTING file must
	 * be treated as a conflict (not a silent bypass). An attacker who omits the
	 * hash field must not be able to overwrite arbitrary files.
	 */
	public function test_edit_empty_hash_on_existing_file_returns_conflict(): void {
		$file = 'test-pst-empty-hash-existing.php';
		file_put_contents( $this->sandbox_dir . '/' . $file, "<?php\n// content\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $this->call_do_method(
			'do_edit',
			$file,
			false,
			'',
			[
				'stonewright_content'    => "<?php\n// new content\n",
				'content_hash_at_render' => '', // empty → must be rejected for existing file.
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_sandbox_conflict', $result->get_error_code() );
	}

	/**
	 * A NEW file (not yet on disk) with an empty hash is a genuine create —
	 * no conflict check applies and the save should succeed.
	 */
	public function test_edit_empty_hash_on_new_file_succeeds(): void {
		$file = 'test-pst-empty-hash-new.php';
		// Explicitly do NOT create the file on disk.
		$this->assertFileDoesNotExist( $this->sandbox_dir . '/' . $file );

		$result = $this->call_do_method(
			'do_edit',
			$file,
			false,
			'',
			[
				'stonewright_content'    => "<?php\n// brand new file\n",
				'content_hash_at_render' => '', // empty is valid for a new (non-existent) file.
			]
		);

		$this->assertTrue( $result );
	}
}
