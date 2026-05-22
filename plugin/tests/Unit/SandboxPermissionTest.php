<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxDeactivate;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxDelete;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxEdit;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxList;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxRead;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxToggle;
use Stonewright\WpMcp\Abilities\Sandbox\SandboxWrite;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Security\Permissions;

/**
 * @covers \Stonewright\WpMcp\Security\Permissions
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxActivate
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxDeactivate
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxDelete
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxEdit
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxList
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxRead
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxToggle
 * @covers \Stonewright\WpMcp\Abilities\Sandbox\SandboxWrite
 */
final class SandboxPermissionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']     = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_current_user_id'] = 42;
		$GLOBALS['stonewright_test_options']       = [];
		$GLOBALS['stonewright_test_transients']    = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']     = [];
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']       = [];
		$GLOBALS['stonewright_test_transients']    = [];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** @param array<int, string> $caps */
	private function loginAs( array $caps ): void {
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']     = array_fill_keys( $caps, true );
	}

	/** @return array<int, \Stonewright\WpMcp\Abilities\AbilityKernel> */
	private function all_abilities(): array {
		return [
			new SandboxList(),
			new SandboxRead(),
			new SandboxActivate(),
			new SandboxDeactivate(),
			new SandboxDelete(),
			new SandboxEdit(),
			new SandboxToggle(),
			new SandboxWrite(),
		];
	}

	// -------------------------------------------------------------------------
	// Permission gate: must require BOTH edit_plugins AND manage_options.
	// -------------------------------------------------------------------------

	/** @dataProvider non_admin_role_provider */
	public function test_non_admin_role_blocked_on_all_sandbox_abilities( string $role, array $caps ): void {
		$this->loginAs( $caps );

		foreach ( $this->all_abilities() as $ability ) {
			$this->assertFalse(
				$ability->permission_callback( [] ),
				sprintf( '%s should have been blocked from %s', $role, $ability->name() )
			);
		}
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public function non_admin_role_provider(): array {
		return [
			'anonymous'   => [ 'anonymous', [] ],
			'subscriber'  => [ 'subscriber', [ 'read' ] ],
			'contributor' => [ 'contributor', [ 'read', 'edit_posts', 'delete_posts' ] ],
			'author'      => [ 'author', [ 'read', 'edit_posts', 'publish_posts', 'upload_files' ] ],
			'editor'      => [
				'editor',
				[ 'read', 'edit_posts', 'edit_others_posts', 'publish_posts', 'manage_categories', 'moderate_comments' ],
			],
			// Even a user with only one of the two required caps is rejected.
			'manage_options without edit_plugins' => [ 'almost-admin', [ 'read', 'manage_options' ] ],
			'edit_plugins without manage_options' => [ 'plugin-installer', [ 'read', 'edit_plugins' ] ],
		];
	}

	public function test_admin_passes_permission_callback(): void {
		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins', 'edit_themes' ] );

		foreach ( $this->all_abilities() as $ability ) {
			$this->assertTrue(
				$ability->permission_callback( [] ),
				sprintf( 'admin should have been allowed for %s', $ability->name() )
			);
		}
	}

	public function test_permissions_can_manage_sandbox_requires_both_caps(): void {
		$this->loginAs( [ 'manage_options' ] );
		$this->assertFalse( Permissions::can_manage_sandbox() );

		$this->loginAs( [ 'edit_plugins' ] );
		$this->assertFalse( Permissions::can_manage_sandbox() );

		$this->loginAs( [ 'edit_plugins', 'manage_options' ] );
		$this->assertTrue( Permissions::can_manage_sandbox() );
	}

	// -------------------------------------------------------------------------
	// DISALLOW_FILE_MODS gate. Constant is process-global, so this is isolated.
	// -------------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_admin_blocked_when_disallow_file_mods_is_true(): void {
		define( 'DISALLOW_FILE_MODS', true );

		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );

		$mutating = [
			new SandboxActivate(),
			new SandboxDeactivate(),
			new SandboxDelete(),
			new SandboxEdit(),
			new SandboxToggle(),
			new SandboxWrite(),
		];

		foreach ( $mutating as $ability ) {
			$args = match ( $ability->name() ) {
				'stonewright/sandbox-edit'   => [ 'name' => 'a.php', 'old_string' => 'x', 'new_string' => 'y' ],
				'stonewright/sandbox-toggle' => [ 'name' => 'a.php', 'action' => 'disable' ],
				'stonewright/sandbox-write'  => [ 'name' => 'a.php', 'contents' => '<?php' ],
				default                      => [ 'name' => 'a.php' ],
			};

			$result = $ability->execute( $args );
			$this->assertInstanceOf( \WP_Error::class, $result, sprintf( '%s should return WP_Error', $ability->name() ) );
			$this->assertSame(
				'stonewright_sandbox_file_mods_disabled',
				$result->get_error_code(),
				sprintf( '%s should return file-mods-disabled error', $ability->name() )
			);
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_read_and_list_still_allowed_when_disallow_file_mods_is_true(): void {
		define( 'DISALLOW_FILE_MODS', true );

		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );

		// Permission still passes — these are not mutating.
		$this->assertTrue( ( new SandboxList() )->permission_callback( [] ) );
		$this->assertTrue( ( new SandboxRead() )->permission_callback( [] ) );
	}

	public function test_file_mods_allowed_returns_true_when_constant_not_set(): void {
		$this->assertTrue( Permissions::file_mods_allowed() );
	}

	// -------------------------------------------------------------------------
	// Production-safe mode: mutations require valid confirmation_token.
	// -------------------------------------------------------------------------

	public function test_production_safe_mutation_without_token_errors(): void {
		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new SandboxActivate();
		$result  = $ability->execute( [ 'name' => 'a.php' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	public function test_production_safe_mutation_with_wrong_token_errors(): void {
		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new SandboxActivate();
		$result  = $ability->execute( [
			'name'               => 'a.php',
			'confirmation_token' => 'swc_invalid_token',
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_production_safe_mutation_with_valid_token_proceeds_past_token_check(): void {
		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new SandboxDeactivate();
		$token   = ConfirmationToken::issue( $ability->name(), [ 'name' => 'never.php' ] );

		$result = $ability->execute( [
			'name'               => 'never.php',
			'confirmation_token' => $token,
		] );

		// We didn't pre-create the file, so SandboxFiles::deactivate() will
		// fail with a different (filesystem) error. The point is that we got
		// PAST the token gate.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
		$this->assertNotSame( 'stonewright_confirmation_invalid', $result->get_error_code() );
	}

	public function test_production_safe_token_mismatch_args_errors(): void {
		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$ability = new SandboxDelete();
		// Token issued for a different filename — verify must reject.
		$token   = ConfirmationToken::issue( $ability->name(), [ 'name' => 'mismatched.php' ] );

		$result = $ability->execute( [
			'name'               => 'real.php',
			'confirmation_token' => $token,
		] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		// New HMAC-based verifier returns a specific code for each failure mode.
		// Args mismatch now returns stonewright_confirmation_args_mismatch.
		$this->assertSame( 'stonewright_confirmation_args_mismatch', $result->get_error_code() );
	}

	public function test_development_mode_skips_token_check(): void {
		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );
		// Default mode is 'development' (not production-safe).
		$ability = new SandboxDelete();

		// No token provided. The ability should proceed past the token gate
		// and fail at the filesystem layer instead.
		$result = $ability->execute( [ 'name' => 'never.php' ] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( 'stonewright_confirmation_required', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// SandboxList output must not leak absolute filesystem paths.
	// -------------------------------------------------------------------------

	public function test_sandbox_list_strips_absolute_paths_from_response(): void {
		$this->loginAs( [ 'read', 'edit_posts', 'manage_options', 'edit_plugins' ] );

		// Plant a draft file via SandboxFiles so the listing has something to
		// return. We use the SandboxFiles facade directly to avoid the
		// production-safe token gate for write.
		$draft_dir = \Stonewright\WpMcp\Sandbox\SandboxFiles::draft_dir();
		$file_path = $draft_dir . '/list-test.php';
		file_put_contents( $file_path, "<?php\n" );

		$ability = new SandboxList();
		$result  = $ability->execute( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'files', $result );
		$this->assertGreaterThan( 0, $result['count'] );

		foreach ( $result['files'] as $row ) {
			$this->assertArrayHasKey( 'path', $row );
			$this->assertIsString( $row['path'] );
			$this->assertStringStartsNotWith( '/', $row['path'], 'list response leaks an absolute path: ' . $row['path'] );
			$this->assertStringContainsString( 'wp-content/', $row['path'] );
		}

		// Cleanup.
		@unlink( $file_path );
	}

	// -------------------------------------------------------------------------
	// Audit-log redaction: contents / old_string / new_string must never
	// appear in the sanitized args passed to AuditLog::record().
	// -------------------------------------------------------------------------

	/**
	 * Helper: invoke the protected sanitize_for_audit() method via reflection.
	 *
	 * @param \Stonewright\WpMcp\Abilities\AbilityKernel $ability
	 * @param array<string, mixed>                        $args
	 * @return array<string, mixed>
	 */
	private function call_sanitize_for_audit( \Stonewright\WpMcp\Abilities\AbilityKernel $ability, array $args ): array {
		$ref = new \ReflectionMethod( $ability, 'sanitize_for_audit' );
		// setAccessible() is a no-op since PHP 8.1 (protected methods are
		// always invokable via reflection). Call it only on older runtimes.
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true ); // @phpstan-ignore-line
		}
		/** @var array<string, mixed> $result */
		$result = $ref->invoke( $ability, $args );
		return $result;
	}

	public function test_sandbox_write_redacts_contents_from_audit(): void {
		$ability  = new SandboxWrite();
		$contents = "<?php\n\$secret_key = 'AKIAIOSFODNN7EXAMPLE';\n";
		$args     = [
			'name'     => 'test.php',
			'contents' => $contents,
		];

		$sanitized = $this->call_sanitize_for_audit( $ability, $args );

		// The raw contents must not be present.
		$this->assertNotSame( $contents, $sanitized['contents'] );
		$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', (string) $sanitized['contents'] );

		// Must contain the structured redaction marker.
		$this->assertStringStartsWith( '[redacted, length=', (string) $sanitized['contents'] );
		$this->assertStringContainsString( 'sha256=', (string) $sanitized['contents'] );

		// Length and hash must match the actual value.
		$expected_len    = mb_strlen( $contents );
		$expected_digest = mb_substr( hash( 'sha256', $contents ), 0, 8 );
		$this->assertSame(
			"[redacted, length={$expected_len}, sha256={$expected_digest}]",
			$sanitized['contents']
		);

		// Non-sensitive keys pass through normally.
		$this->assertSame( 'test.php', $sanitized['name'] );
	}

	public function test_sandbox_edit_redacts_old_string_and_new_string_from_audit(): void {
		$ability    = new SandboxEdit();
		$old_string = "DB_PASSWORD = 'hunter2'";
		$new_string = "DB_PASSWORD = 'correcthorsebatterystaple'";
		$args       = [
			'name'       => 'config.php',
			'old_string' => $old_string,
			'new_string' => $new_string,
		];

		$sanitized = $this->call_sanitize_for_audit( $ability, $args );

		// Raw strings must not be present.
		$this->assertStringNotContainsString( 'hunter2', (string) $sanitized['old_string'] );
		$this->assertStringNotContainsString( 'correcthorsebatterystaple', (string) $sanitized['new_string'] );

		// Both must be structured redaction markers.
		$this->assertStringStartsWith( '[redacted, length=', (string) $sanitized['old_string'] );
		$this->assertStringStartsWith( '[redacted, length=', (string) $sanitized['new_string'] );

		// Length and hash must match.
		$old_len    = mb_strlen( $old_string );
		$old_digest = mb_substr( hash( 'sha256', $old_string ), 0, 8 );
		$this->assertSame( "[redacted, length={$old_len}, sha256={$old_digest}]", $sanitized['old_string'] );

		$new_len    = mb_strlen( $new_string );
		$new_digest = mb_substr( hash( 'sha256', $new_string ), 0, 8 );
		$this->assertSame( "[redacted, length={$new_len}, sha256={$new_digest}]", $sanitized['new_string'] );

		// Non-sensitive key passes through.
		$this->assertSame( 'config.php', $sanitized['name'] );
	}

	public function test_base_kernel_does_not_redact_by_default(): void {
		// A plain kernel subclass with no override should not redact 'contents'.
		$ability = new SandboxList(); // uses default audit_redacted_keys() = []
		$args    = [ 'irrelevant_key' => 'some value that should truncate but not redact' ];

		$sanitized = $this->call_sanitize_for_audit( $ability, $args );

		$this->assertSame( 'some value that should truncate but not redact', $sanitized['irrelevant_key'] );
	}

	public function test_sandbox_write_redacts_non_string_contents_from_audit(): void {
		// If a non-string slips through to the kernel (e.g. caller forgot to
		// coerce, or schema validation is bypassed), the redaction MUST still
		// apply — the raw structure must never reach the audit log.
		$ability = new SandboxWrite();
		$secret  = [ 'key' => 'AKIAIOSFODNN7EXAMPLE', 'nested' => [ 1, 2, 3 ] ];
		$args    = [
			'name'     => 'test.php',
			'contents' => $secret,
		];

		$sanitized = $this->call_sanitize_for_audit( $ability, $args );

		$serialized = json_encode( $sanitized );
		$this->assertIsString( $serialized );
		$this->assertStringNotContainsString( 'AKIAIOSFODNN7EXAMPLE', $serialized );
		$this->assertSame(
			'[redacted, type=array, length=2]',
			$sanitized['contents']
		);
		$this->assertSame( 'test.php', $sanitized['name'] );
	}

	// -------------------------------------------------------------------------
	// Confirmation-token schema is exposed on every mutating ability.
	// -------------------------------------------------------------------------

	/** @dataProvider mutating_ability_provider */
	public function test_mutating_ability_exposes_confirmation_token_in_schema( \Stonewright\WpMcp\Abilities\AbilityKernel $ability ): void {
		$schema = $ability->input_schema();
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey(
			'confirmation_token',
			$schema['properties'],
			sprintf( '%s must expose confirmation_token in input_schema', $ability->name() )
		);
	}

	/** @return array<string, array{0: \Stonewright\WpMcp\Abilities\AbilityKernel}> */
	public function mutating_ability_provider(): array {
		return [
			'activate'   => [ new SandboxActivate() ],
			'deactivate' => [ new SandboxDeactivate() ],
			'delete'     => [ new SandboxDelete() ],
			'edit'       => [ new SandboxEdit() ],
			'toggle'     => [ new SandboxToggle() ],
			'write'      => [ new SandboxWrite() ],
		];
	}
}
