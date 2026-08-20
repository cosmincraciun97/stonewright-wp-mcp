<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\ConfirmationToken;

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
			 * @param array<string, mixed> $args
			 * @param array<string, mixed>|null $verify_args
			 */
			public function expose_require_production_safe_token( array $args, ?array $verify_args = null ): ?\WP_Error {
				return $this->require_production_safe_token( $args, $verify_args );
			}

			/**
			 * @param array<string, mixed> $args
			 * @param callable             $callback
			 * @return array<string, mixed>|\WP_Error
			 */
			public function expose_audit_write( array $args, callable $callback ) {
				return $this->audit_write( $args, $callback );
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
			'token'     => 'test-plain-token',
			'password'  => 'test-password-value',
			'user_pass' => 'test-user-password',
			'api_key'   => 'test-api-key',
			'secret'    => 'test-secret-value',
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

	/** @dataProvider safety_block_code_provider */
	public function test_safety_and_architecture_stops_are_audited_as_blocked( string $code ): void {
		$kernel = new class( $code ) extends AbilityKernel {
			public function __construct( private string $code ) {}
			public function name(): string { return 'stonewright/test-policy-stop'; }
			public function label(): string { return 'Policy stop'; }
			public function description(): string { return 'Synthetic policy stop.'; }
			public function category(): string { return 'test'; }
			public function execute( array $args ): array|\WP_Error {
				return $this->audit( $args, fn (): \WP_Error => new \WP_Error( $this->code, 'Stopped by contract.' ) );
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		self::assertInstanceOf( \WP_Error::class, $kernel->execute( [] ) );
		self::assertSame( 'blocked', $GLOBALS['stonewright_test_wpdb_inserts'][0]['data']['result_status'] ?? null );
	}

	/** @return array<string,array{string}> */
	public static function safety_block_code_provider(): array {
		return [
			'architecture mismatch' => [ 'stonewright_v3_architecture_mismatch' ],
			'approval required'     => [ 'stonewright_custom_code_approval_required' ],
			'php read only'         => [ 'stonewright_php_read_only_violation' ],
			'raw Elementor'         => [ 'stonewright_raw_elementor_mutation' ],
			'migration loss'        => [ 'stonewright_v4_migration_has_loss' ],
		];
	}

	public function test_require_production_safe_token_is_null_in_development(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'development';
		self::assertNull( $this->kernel->expose_require_production_safe_token( [ 'title' => 'x' ] ) );
	}

	public function test_require_production_safe_token_errors_without_token_in_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$error = $this->kernel->expose_require_production_safe_token( [ 'title' => 'x' ] );
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'stonewright_confirmation_required', $error->get_error_code() );
	}

	public function test_audit_write_accepts_a_matching_token_in_production_safe(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';
		$GLOBALS['stonewright_test_current_user_id']            = 1;
		$GLOBALS['stonewright_test_transients']                 = [];
		$args = [ 'title' => 'x' ];
		$args['confirmation_token'] = ConfirmationToken::issue( 'stonewright/test-audit', $args );

		$result = $this->kernel->expose_audit_write( $args, static fn( array $a ): array => [ 'ok' => true, 'title' => $a['title'] ] );
		self::assertIsArray( $result );
		self::assertSame( 'x', $result['title'] );
	}
}
