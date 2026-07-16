<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;

/**
 * Verifies that AbilityKernel redacts confirmation_token (and other sensitive
 * keys) before passing args to the audit log.
 *
 * @covers \Stonewright\WpMcp\Abilities\AbilityKernel
 */
final class AbilityKernelAuditTest extends TestCase {

	private AbilityKernel $kernel;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']     = [];
		$GLOBALS['stonewright_test_options']          = [];
		$GLOBALS['stonewright_test_current_user_id']  = 1;

		// Concrete anonymous subclass — only implements the abstract surface.
		$this->kernel = new class() extends AbilityKernel {
			public function name(): string        { return 'stonewright/test-audit'; }
			public function label(): string       { return 'Test'; }
			public function description(): string { return 'Test kernel for audit redaction.'; }
			public function category(): string    { return 'test'; }

			/**
			 * Execute and return the sanitized args as they would appear in the log.
			 *
			 * @param array<string, mixed> $args
			 * @return array<string, mixed>|\WP_Error
			 */
			public function execute( array $args ): array|\WP_Error {
				// Delegate to $this->audit() so we exercise the full redaction pipeline.
				return $this->audit( $args, fn( array $a ) => [ 'ok' => true ] );
			}

			/**
			 * Expose sanitize_for_audit() for direct testing.
			 *
			 * @param array<string, mixed> $args
			 * @return array<string, mixed>
			 */
			public function expose_sanitize( array $args ): array {
				return $this->sanitize_for_audit( $args );
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_wpdb_inserts']    = [];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
	}

	// -------------------------------------------------------------------------
	// audit_redacted_keys: confirmation_token must be in the default list.
	// -------------------------------------------------------------------------

	public function test_confirmation_token_is_in_default_redacted_keys(): void {
		// Verify via sanitize_for_audit — the token value must not appear verbatim.
		$token  = 'swc_abc123.def456';
		$result = $this->kernel->expose_sanitize( [ 'confirmation_token' => $token, 'name' => 'a.php' ] );

		$this->assertArrayHasKey( 'confirmation_token', $result );
		$this->assertStringNotContainsString( $token, (string) $result['confirmation_token'] );
		$this->assertStringStartsWith( '[redacted,', (string) $result['confirmation_token'] );
	}

	public function test_confirmation_token_redacted_form_contains_sha256_digest(): void {
		$token     = 'swc_some-real-looking-token.sig';
		$result    = $this->kernel->expose_sanitize( [ 'confirmation_token' => $token ] );
		$redacted  = (string) $result['confirmation_token'];
		$expected_digest = substr( hash( 'sha256', $token ), 0, 8 );

		$this->assertStringContainsString( $expected_digest, $redacted );
	}

	public function test_other_sensitive_keys_also_redacted(): void {
		$result = $this->kernel->expose_sanitize( [
			'token'     => 'plain-token',
			'password'  => 's3cr3t',
			'user_pass' => 'wp-user-password-plain',
			'api_key'   => 'ak_live_abc',
			'secret'    => 'topsecret',
		] );

		foreach ( [ 'token', 'password', 'user_pass', 'api_key', 'secret' ] as $key ) {
			$this->assertStringStartsWith(
				'[redacted,',
				(string) $result[ $key ],
				"Key '$key' must be redacted."
			);
		}
	}

	public function test_non_sensitive_args_are_not_redacted(): void {
		$result = $this->kernel->expose_sanitize( [ 'name' => 'hello.php', 'post_id' => 42 ] );
		$this->assertSame( 'hello.php', $result['name'] );
		$this->assertSame( 42, $result['post_id'] );
	}

	// -------------------------------------------------------------------------
	// End-to-end: audit() path writes redacted args to the wpdb stub.
	// -------------------------------------------------------------------------

	public function test_audit_log_record_contains_redacted_confirmation_token(): void {
		$token = 'swc_real-token-value.signature';
		$this->kernel->execute( [ 'confirmation_token' => $token, 'name' => 'a.php' ] );

		$inserts = $GLOBALS['stonewright_test_wpdb_inserts'];
		$this->assertNotEmpty( $inserts, 'Expected at least one wpdb insert from AuditLog::record().' );

		$row           = $inserts[0]['data'];
		$sanitized_raw = $row['sanitized_args'] ?? '';
		$this->assertIsString( $sanitized_raw );
		$this->assertStringNotContainsString( $token, $sanitized_raw );
		$this->assertStringContainsString( '[redacted,', $sanitized_raw );
	}

	public function test_audit_stamps_wp_error_code_and_message_into_meta(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/test-error-audit';
			}
			public function label(): string {
				return 'Test';
			}
			public function description(): string {
				return 'Error audit test';
			}
			public function category(): string {
				return 'test';
			}
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn () => new \WP_Error( 'sw_test_boom', 'Widget type "fake" is not registered on this site' )
				);
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = $kernel->execute( [ 'post_id' => 1 ] );
		$this->assertInstanceOf( \WP_Error::class, $result );

		$inserts = $GLOBALS['stonewright_test_wpdb_inserts'];
		$this->assertNotEmpty( $inserts );
		$decoded = json_decode( (string) ( $inserts[0]['data']['sanitized_args'] ?? '' ), true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'sw_test_boom', $decoded['_meta']['error_code'] ?? null );
		$this->assertStringStartsWith( 'Widget type "fake"', (string) ( $decoded['_meta']['error_message'] ?? '' ) );
		$this->assertSame( 'error', $inserts[0]['data']['result_status'] ?? null );
	}

	public function test_audit_success_omits_error_meta_keys(): void {
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$this->kernel->execute( [ 'name' => 'ok.php' ] );
		$decoded = json_decode( (string) ( $GLOBALS['stonewright_test_wpdb_inserts'][0]['data']['sanitized_args'] ?? '' ), true );
		$this->assertIsArray( $decoded );
		$meta = is_array( $decoded['_meta'] ?? null ) ? $decoded['_meta'] : [];
		$this->assertArrayNotHasKey( 'error_code', $meta );
		$this->assertArrayNotHasKey( 'error_message', $meta );
	}
}
