<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\IncidentStore;

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
		IncidentStore::reset_for_tests();

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
		IncidentStore::reset_for_tests();
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
		$this->assertSame( 'sw_test_boom', $decoded['_meta']['remediation_code'] ?? $inserts[0]['data']['remediation_code'] ?? null );
	}

	public function test_audit_stamps_nested_target_and_truncated_error_without_payload(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/design-validate-spec';
			}
			public function label(): string {
				return 'Test';
			}
			public function description(): string {
				return 'Nested target audit test';
			}
			public function category(): string {
				return 'test';
			}
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn () => new \WP_Error(
						'stonewright_spec_invalid',
						str_repeat( 'm', 600 ),
						[ 'status' => 400, 'verification_status' => 'failed' ]
					)
				);
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'staging';
		$result = $kernel->execute(
			[
				'args'     => [ 'post_id' => 88, 'title' => 'must-not-persist' ],
				'password' => 'sentinel-private-example-secret',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );

		$row     = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		$decoded = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		$details = json_decode( (string) ( $row['redacted_details'] ?? '' ), true );
		$this->assertIsArray( $decoded );
		$this->assertIsArray( $details );
		$this->assertSame( '[array:2]', $decoded['args'] ?? null );
		$this->assertSame( '88', (string) ( $decoded['_meta']['target_id'] ?? $details['target_id'] ?? '' ) );
		$this->assertSame( 500, mb_strlen( (string) ( $decoded['_meta']['error_message'] ?? $details['error_message'] ?? '' ) ) );
		$this->assertSame( 'stonewright_spec_invalid', $details['error_code'] ?? $decoded['_meta']['error_code'] ?? null );
		$this->assertSame( 'stonewright_spec_invalid', $row['remediation_code'] ?? $details['remediation_code'] ?? null );
		$this->assertSame( 'staging', $row['mode'] ?? null );
		$this->assertStringNotContainsString( 'must-not-persist', (string) wp_json_encode( $decoded ) );
		$this->assertStringNotContainsString( 'sentinel-private-example-secret', (string) wp_json_encode( [ $decoded, $details ] ) );
		$this->assertNotEquals( [ 'verification_status' ], array_keys( $details ) );
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

	public function test_audit_converts_a_thrown_callback_into_one_failed_audit_and_incident(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string { return 'stonewright/test-throwable'; }
			public function label(): string { return 'Throwable'; }
			public function description(): string { return 'Synthetic throwable boundary.'; }
			public function category(): string { return 'test'; }
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static function (): never {
						throw new \RuntimeException( 'Synthetic callback failure.' );
					}
				);
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = $kernel->execute( [ 'post_id' => 41 ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_ability_throwable', $result->get_error_code() );
		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'error', $row['result_status'] ?? null );
		self::assertSame( 'FAILED', $row['outcome'] ?? null );
		self::assertNotSame( 'SUCCESS', $row['outcome'] ?? null );
		$incidents = IncidentStore::recent();
		self::assertCount( 1, $incidents );
		self::assertSame( 'stonewright_ability_throwable', $incidents[0]['root_error_code'] ?? null );
		self::assertSame( 1, $incidents[0]['occurrence_count'] ?? null );
	}

	public function test_audit_classifies_structured_ok_false_as_one_failed_audit_and_incident(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string { return 'stonewright/test-structured-failure'; }
			public function label(): string { return 'Structured failure'; }
			public function description(): string { return 'Synthetic structured failure.'; }
			public function category(): string { return 'test'; }
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn (): array => [
						'ok'         => false,
						'error_code' => 'stonewright_structured_failure',
						'error'      => 'Synthetic structured failure.',
					]
				);
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = $kernel->execute( [ 'post_id' => 42 ] );

		self::assertIsArray( $result );
		self::assertFalse( $result['ok'] );
		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'error', $row['result_status'] ?? null );
		self::assertSame( 'stonewright_structured_failure', $row['error_code'] ?? null );
		self::assertSame( 'FAILED', $row['outcome'] ?? null );
		self::assertNotSame( 'SUCCESS', $row['outcome'] ?? null );
		$incidents = IncidentStore::recent();
		self::assertCount( 1, $incidents );
		self::assertSame( 'stonewright_structured_failure', $incidents[0]['root_error_code'] ?? null );
		self::assertSame( 1, $incidents[0]['occurrence_count'] ?? null );
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

	public function test_structured_failure_keeps_wrapper_and_cause_codes_distinct(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/test-wrapper-cause';
			}
			public function label(): string {
				return 'Wrapper cause';
			}
			public function description(): string {
				return 'Keep wrapper and cause distinct.';
			}
			public function category(): string {
				return 'test';
			}
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn (): array => [
						'ok'              => false,
						'error_code'      => 'stonewright_structured_failure',
						'root_error_code' => 'stonewright_elementor_settings_invalid',
						'error'           => 'Setting rejected.',
					]
				);
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$result = $kernel->execute( [ 'post_id' => 12 ] );

		self::assertIsArray( $result );
		$row     = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		$decoded = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		self::assertSame( 'error', $row['result_status'] ?? null );
		self::assertSame( 'stonewright_structured_failure', $row['error_code'] ?? $decoded['_meta']['error_code'] ?? null );
		self::assertSame( 'stonewright_elementor_settings_invalid', $row['root_error_code'] ?? $decoded['_meta']['root_error_code'] ?? null );
		self::assertNotSame( $row['error_code'] ?? null, $row['root_error_code'] ?? null );
	}

	public function test_structured_failure_is_last_resort_when_no_cause_code_exists(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/test-last-resort';
			}
			public function label(): string {
				return 'Last resort';
			}
			public function description(): string {
				return 'Last-resort structured failure.';
			}
			public function category(): string {
				return 'test';
			}
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn (): array => [
						'ok'    => false,
						'error' => 'Synthetic unstructured failure.',
					]
				);
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [ 'post_id' => 13 ] );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'stonewright_structured_failure', $row['error_code'] ?? null );
		self::assertSame( 'stonewright_structured_failure', $row['root_error_code'] ?? null );
	}

	public function test_dry_run_success_is_validation_planned_not_write(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/example-update';
			}
			public function label(): string {
				return 'Example';
			}
			public function description(): string {
				return 'Dry-run fixture.';
			}
			public function category(): string {
				return 'test';
			}
			public function execute( array $args ): array|\WP_Error {
				return $this->audit( $args, static fn (): array => [ 'ok' => true ] );
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [ 'post_id' => 14, 'dry_run' => true ] );
		$row     = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		$decoded = json_decode( (string) ( $row['sanitized_args'] ?? '' ), true );
		self::assertSame( 'VALIDATION', $row['category'] ?? null );
		self::assertSame( 'SUCCESS', $row['outcome'] ?? null );
		self::assertSame( 'planned', $row['execution_status'] ?? $decoded['_meta']['execution_status'] ?? null );
	}

	public function test_valid_noop_does_not_open_incident(): void {
		$kernel = new class() extends AbilityKernel {
			public function name(): string {
				return 'stonewright/acf-value-update';
			}
			public function label(): string {
				return 'ACF';
			}
			public function description(): string {
				return 'No-op fixture.';
			}
			public function category(): string {
				return 'test';
			}
			public function execute( array $args ): array|\WP_Error {
				return $this->audit(
					$args,
					static fn (): array => [
						'ok'               => true,
						'changed'          => false,
						'execution_status' => 'unchanged',
					]
				);
			}
		};

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [ 'post_id' => 15 ] );
		$row = $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'];
		self::assertSame( 'ok', $row['result_status'] ?? null );
		self::assertSame( 'unchanged', $row['execution_status'] ?? null );
		self::assertSame( [], IncidentStore::recent() );
	}

	// -------------------------------------------------------------------------
	// add_audit_details(): code an ability delegates to adds details to the call's one row.
	// -------------------------------------------------------------------------

	public function test_details_added_inside_a_call_land_on_that_calls_one_row(): void {
		$accepted = null;
		$kernel   = $this->detail_kernel(
			'stonewright/test-detailed',
			static function () use ( &$accepted ): array {
				$accepted = AbilityKernel::add_audit_details(
					'stonewright/test-detailed',
					[
						'detail_text'  => 'kept',
						'detail_count' => 3,
						'detail_flag'  => true,
						'detail_none'  => null,
					]
				);
				return [ 'ok' => true ];
			}
		);

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [ 'post_id' => 5 ] );

		self::assertTrue( $accepted );
		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		$meta = $this->recorded_meta( 0 );
		self::assertSame( 'kept', $meta['detail_text'] ?? null );
		self::assertSame( 3, $meta['detail_count'] ?? null );
		self::assertTrue( $meta['detail_flag'] ?? null );
		self::assertArrayHasKey( 'detail_none', $meta );
		self::assertNull( $meta['detail_none'] );
	}

	public function test_details_are_refused_outside_a_call_and_for_another_ability_name(): void {
		self::assertFalse( AbilityKernel::add_audit_details( 'stonewright/test-detailed', [ 'detail_text' => 'x' ] ), 'No call is in progress.' );

		$accepted = null;
		$kernel   = $this->detail_kernel(
			'stonewright/test-detailed',
			static function () use ( &$accepted ): array {
				$accepted = AbilityKernel::add_audit_details( 'stonewright/test-someone-else', [ 'detail_text' => 'x' ] );
				return [ 'ok' => true ];
			}
		);

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [] );

		self::assertFalse( $accepted, 'A call only takes details meant for its own name.' );
		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		self::assertArrayNotHasKey( 'detail_text', $this->recorded_meta( 0 ) );
	}

	public function test_added_details_keep_only_bounded_scalars(): void {
		$kernel = $this->detail_kernel(
			'stonewright/test-detailed',
			static function (): array {
				AbilityKernel::add_audit_details(
					'stonewright/test-detailed',
					[
						'detail_long'   => str_repeat( 'l', 400 ),
						'detail_array'  => [ 'secret body' ],
						'detail_object' => new \stdClass(),
						7               => 'numeric key',
						'detail_kept'   => 1.5,
					]
				);
				return [ 'ok' => true ];
			}
		);

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [] );

		$meta = $this->recorded_meta( 0 );
		self::assertSame( 255, mb_strlen( (string) ( $meta['detail_long'] ?? '' ) ) );
		self::assertSame( 1.5, $meta['detail_kept'] ?? null );
		self::assertArrayNotHasKey( 'detail_array', $meta );
		self::assertArrayNotHasKey( 'detail_object', $meta );
		self::assertArrayNotHasKey( '7', $meta );
		self::assertStringNotContainsString( 'secret body', (string) $GLOBALS['stonewright_test_wpdb_inserts'][0]['data']['sanitized_args'] );
	}

	public function test_the_abilitys_own_audit_metadata_wins_over_added_details(): void {
		$kernel = $this->detail_kernel(
			'stonewright/test-detailed',
			static function (): array {
				AbilityKernel::add_audit_details( 'stonewright/test-detailed', [ 'detail_text' => 'from the delegate', 'detail_extra' => 'kept' ] );
				return [ 'ok' => true ];
			},
			[ 'detail_text' => 'from the ability' ]
		);

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [] );

		$meta = $this->recorded_meta( 0 );
		self::assertSame( 'from the ability', $meta['detail_text'] ?? null );
		self::assertSame( 'kept', $meta['detail_extra'] ?? null );
	}

	public function test_the_kernel_keeps_its_own_failure_fields_over_added_details(): void {
		$kernel = $this->detail_kernel(
			'stonewright/test-detailed',
			static function (): \WP_Error {
				AbilityKernel::add_audit_details( 'stonewright/test-detailed', [ 'error_code' => 'forged', 'operation_kind' => 'read' ] );
				return new \WP_Error( 'sw_real_failure', 'The call really failed.' );
			}
		);

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$kernel->execute( [] );

		$meta = $this->recorded_meta( 0 );
		self::assertSame( 'sw_real_failure', $meta['error_code'] ?? null );
		self::assertSame( 'write', $meta['operation_kind'] ?? null );
	}

	public function test_a_finished_call_leaves_no_call_open_for_later_details(): void {
		$returning = $this->detail_kernel( 'stonewright/test-detailed', static fn(): array => [ 'ok' => true ] );
		$throwing  = $this->detail_kernel(
			'stonewright/test-throwing',
			static function (): never {
				throw new \RuntimeException( 'Synthetic callback failure.' );
			}
		);

		$returning->execute( [] );
		self::assertFalse( AbilityKernel::add_audit_details( 'stonewright/test-detailed', [ 'detail_text' => 'late' ] ) );

		$throwing->execute( [] );
		self::assertFalse( AbilityKernel::add_audit_details( 'stonewright/test-throwing', [ 'detail_text' => 'late' ] ) );
	}

	public function test_nested_calls_each_take_the_details_meant_for_their_own_name(): void {
		$inner = $this->detail_kernel(
			'stonewright/test-inner',
			static function (): array {
				AbilityKernel::add_audit_details( 'stonewright/test-outer', [ 'detail_text' => 'for the outer call' ] );
				AbilityKernel::add_audit_details( 'stonewright/test-inner', [ 'detail_text' => 'for the inner call' ] );
				return [ 'ok' => true ];
			}
		);
		$outer = $this->detail_kernel(
			'stonewright/test-outer',
			static function () use ( $inner ): array {
				$inner->execute( [] );
				return [ 'ok' => true ];
			}
		);

		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		$outer->execute( [] );

		self::assertSame(
			[ 'stonewright/test-inner', 'stonewright/test-outer' ],
			array_column( array_column( $GLOBALS['stonewright_test_wpdb_inserts'], 'data' ), 'ability_name' )
		);
		self::assertSame( 'for the inner call', $this->recorded_meta( 0 )['detail_text'] ?? null );
		self::assertSame( 'for the outer call', $this->recorded_meta( 1 )['detail_text'] ?? null );
	}

	/**
	 * An ability whose audited callback the test supplies.
	 *
	 * @param array<string, scalar|null> $metadata What the ability's own audit_metadata() returns.
	 */
	private function detail_kernel( string $name, \Closure $work, array $metadata = [] ): AbilityKernel {
		return new class( $name, $work, $metadata ) extends AbilityKernel {
			/** @param array<string, scalar|null> $metadata */
			public function __construct( private string $ability, private \Closure $work, private array $metadata ) {}
			public function name(): string { return $this->ability; }
			public function label(): string { return 'Detail fixture'; }
			public function description(): string { return 'Adds audit details from inside its call.'; }
			public function category(): string { return 'test'; }
			public function execute( array $args ): array|\WP_Error {
				return $this->audit_write( $args, $this->work );
			}
			protected function audit_metadata( array $args, array|\WP_Error $result, int $elapsed_ms ): array {
				return $this->metadata;
			}
		};
	}

	/**
	 * The `_meta` block of one captured audit row.
	 *
	 * @return array<string, mixed>
	 */
	private function recorded_meta( int $index ): array {
		$decoded = json_decode( (string) ( $GLOBALS['stonewright_test_wpdb_inserts'][ $index ]['data']['sanitized_args'] ?? '' ), true );
		return is_array( $decoded ) && is_array( $decoded['_meta'] ?? null ) ? $decoded['_meta'] : [];
	}
}
