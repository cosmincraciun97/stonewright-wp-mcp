<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Sandbox;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Common\CodePayloadCanonicalizer;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxEdit;
use Stonewright\WpMcp\Sandbox\SandboxFiles;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * sandbox-edit keeps its confirmation token out of the audit log and reads its code payload through the
 * same canonicalizer as sandbox-write.
 *
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxEdit
 * @covers \Stonewright\WpMcp\Abilities\Common\CodePayloadCanonicalizer
 */
final class SandboxEditAuditTest extends TestCase {

	private const NAME = 'edit-audit-test.php';

	private string $sandbox_dir;

	protected function setUp(): void {
		$this->sandbox_dir = WP_CONTENT_DIR . '/stonewright-sandbox';
		if ( ! is_dir( $this->sandbox_dir ) ) {
			mkdir( $this->sandbox_dir, 0755, true );
		}
		$GLOBALS['stonewright_test_options']         = [ 'stonewright_mode' => 'production-safe' ];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_logged_in']  = true;
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'manage_options' => true, 'edit_plugins' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 1;
		$GLOBALS['stonewright_test_transients']      = [];
		file_put_contents( SandboxFiles::stored_path( self::NAME ), "<?php\n// BEFORE_MARKER\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->sandbox_dir . '/edit-audit-test*' ) as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_user_logged_in']  = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_transients']      = [];
	}

	public function test_redacted_keys_include_the_parent_list_and_the_code_fields(): void {
		$method = new \ReflectionMethod( SandboxEdit::class, 'audit_redacted_keys' );
		$method->setAccessible( true );
		$keys = $method->invoke( new SandboxEdit() );

		foreach ( [ 'confirmation_token', 'old_string', 'new_string' ] as $key ) {
			self::assertContains( $key, $keys );
		}
	}

	public function test_the_confirmation_token_is_never_stored_in_the_audit_log(): void {
		$args  = [ 'name' => self::NAME, 'old_string' => '// BEFORE_MARKER', 'new_string' => '// AFTER_MARKER' ];
		$token = ConfirmationToken::issue( 'stonewright/sandbox-edit', $args );
		self::assertIsString( $token );

		$result = ( new SandboxEdit() )->execute( $args + [ 'confirmation_token' => $token ] );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertNotSame( [], $GLOBALS['stonewright_test_wpdb_inserts'], 'the call must be audited' );
		$stored = wp_json_encode( $GLOBALS['stonewright_test_wpdb_inserts'] );
		self::assertIsString( $stored );
		self::assertStringNotContainsString( $token, $stored );
		self::assertStringNotContainsString( 'BEFORE_MARKER', $stored );
		self::assertStringNotContainsString( 'AFTER_MARKER', $stored );
	}

	public function test_the_canonicalizer_covers_sandbox_edit_and_keeps_fragments_exact(): void {
		$args = [
			'name'       => self::NAME,
			'old_string' => "line one  \r\nline two",
			'new_string' => "added\r\n",
		];

		$canonical = CodePayloadCanonicalizer::canonicalize( 'stonewright/sandbox-edit', $args );

		self::assertIsArray( $canonical );
		self::assertSame( "line one  \nline two", $canonical['old_string'] );
		self::assertSame( "added\n", $canonical['new_string'] );
	}

	public function test_the_canonicalizer_refuses_a_non_string_fragment(): void {
		$canonical = CodePayloadCanonicalizer::canonicalize( 'stonewright/sandbox-edit', [ 'name' => self::NAME, 'old_string' => [ 'x' ], 'new_string' => 'y' ] );

		self::assertInstanceOf( \WP_Error::class, $canonical );
		self::assertSame( 'stonewright_code_payload_invalid', $canonical->get_error_code() );
	}

	public function test_the_token_binds_to_the_canonical_fragments(): void {
		// A caller that sends CRLF gets the same token binding as one that sends LF.
		$canonical_args = [ 'name' => self::NAME, 'old_string' => '// BEFORE_MARKER', 'new_string' => "// AFTER_MARKER\n// SECOND" ];
		$token          = ConfirmationToken::issue( 'stonewright/sandbox-edit', $canonical_args );
		self::assertIsString( $token );

		$result = ( new SandboxEdit() )->execute(
			[
				'name'               => self::NAME,
				'old_string'         => '// BEFORE_MARKER',
				'new_string'         => "// AFTER_MARKER\r\n// SECOND",
				'confirmation_token' => $token,
			]
		);

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertStringNotContainsString( "\r", (string) file_get_contents( SandboxFiles::stored_path( self::NAME ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
