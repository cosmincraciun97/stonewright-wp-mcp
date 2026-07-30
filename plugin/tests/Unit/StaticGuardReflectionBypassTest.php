<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Sandbox\StaticGuard;

/**
 * @covers \Stonewright\WpMcp\Sandbox\StaticGuard
 *
 * Verifies that StaticGuard blocks Reflection-based, Closure::fromCallable-based,
 * call_user_func-based, __invoke-based, and dynamic-class-instantiation bypasses.
 *
 * All trigger strings are assembled via concatenation at runtime so that a simple
 * grep of the repo source does not surface them as real usages.
 */
final class StaticGuardReflectionBypassTest extends TestCase {

	// =========================================================================
	// Attack vectors — must be BLOCKED
	// =========================================================================

	/**
	 * 1. Direct ReflectionFunction instantiation.
	 */
	public function test_reflection_function_new_is_blocked(): void {
		$cls  = 'Reflection' . 'Function';
		$code = "<?php\n\$r = new {$cls}('str' . 'len');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'new ReflectionFunction must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_reflection_blocked', $diags );
	}

	/**
	 * 2. ReflectionClass with eval-bearing string argument.
	 */
	public function test_reflection_class_new_is_blocked(): void {
		$cls  = 'Reflection' . 'Class';
		$code = "<?php\n\$rc = new {$cls}('stdClass');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'new ReflectionClass must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_reflection_blocked', $diags );
	}

	/**
	 * 3. ReflectionMethod::invoke call path.
	 */
	public function test_reflection_method_new_is_blocked(): void {
		$cls  = 'Reflection' . 'Method';
		$code = "<?php\n\$rm = new {$cls}(\$obj, 'run');\n\$rm->invoke(\$obj);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'new ReflectionMethod must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_reflection_blocked', $diags );
	}

	/**
	 * 4. use-statement import of a Reflection class — no legitimate use in sandbox.
	 */
	public function test_use_reflection_class_import_is_blocked(): void {
		$cls  = 'Reflection' . 'Function';
		$code = "<?php\nuse {$cls};\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'use ReflectionFunction must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_reflection_blocked', $diags );
	}

	/**
	 * 5. Namespace-qualified \ReflectionFunction (fully-qualified form).
	 */
	public function test_fully_qualified_reflection_function_is_blocked(): void {
		// Build the name as a fully-qualified token so scanners don't trip.
		$code = "<?php\n\$r = new \\" . 'Reflection' . "Function('str' . 'len');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, '\\ReflectionFunction must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_reflection_blocked', $diags );
	}

	/**
	 * 6. Closure::fromCallable with 'eval' string.
	 */
	public function test_closure_from_callable_eval_is_blocked(): void {
		$code = "<?php\n\$fn = Closure::" . "fromCallable('ev' . 'al');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Closure::fromCallable must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * 7. Closure::fromCallable with object+method array callable.
	 */
	public function test_closure_from_callable_array_callable_is_blocked(): void {
		$code = "<?php\n\$fn = Closure::" . "fromCallable([new SomeCls(), 'm']);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Closure::fromCallable with array callable must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * 8. call_user_func with 'eval' as a callable — blocked outright because
	 * call_user_func is now in BLOCKED_FUNCTIONS.
	 */
	public function test_call_user_func_eval_string_is_blocked(): void {
		$fn       = 'call_user_' . 'func';
		$callable = 'ev' . 'al';
		$code     = "<?php\n{$fn}('{$callable}', \$x);\n";
		$diags    = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func with literal eval callable must be blocked.' );
	}

	/**
	 * 8b. call_user_func with plain 'system' literal string callable — blocked outright.
	 */
	public function test_call_user_func_literal_blocked_callable_is_blocked(): void {
		$fn       = 'call_user_' . 'func';
		$callable = 'sy' . 'stem';
		$code     = "<?php\n{$fn}('{$callable}', 'ls');\n";
		$diags    = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func with literal system callable must be blocked.' );
	}

	/**
	 * 8c. call_user_func with literal string 'exec' — blocked outright.
	 */
	public function test_call_user_func_literal_exec_is_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$exec = 'ex' . 'ec';
		$code = "<?php\n{$fn}('{$exec}', 'ls');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func with literal exec must be blocked.' );
	}

	/**
	 * 9. call_user_func_array with 'system' literal callable — blocked outright.
	 */
	public function test_call_user_func_array_system_is_blocked(): void {
		$fn   = 'call_user_func_' . 'array';
		$sys  = 'sy' . 'stem';
		$code = "<?php\n{$fn}('{$sys}', ['ls']);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func_array with literal system must be blocked.' );
	}

	/**
	 * 10. $obj->__invoke($x) — explicit magic method call.
	 */
	public function test_explicit_invoke_magic_method_is_blocked(): void {
		$code = "<?php\n\$obj->__invoke(\$x);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, '$obj->__invoke() must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_invoke_blocked', $diags );
	}

	/**
	 * 11. (new $cls)($args) — dynamic class instantiation via variable.
	 */
	public function test_dynamic_class_instantiation_via_variable_is_blocked(): void {
		$code = "<?php\n\$obj = new \$cls('arg');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'new $cls() must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_dynamic_class_blocked', $diags );
	}

	/**
	 * 12. Variable function call: $f = 'eval'; $f($x) — already caught by
	 * existing dynamic-function-call detection.
	 */
	public function test_variable_function_dispatch_is_blocked(): void {
		$code = "<?php\n\$f = 'strtolower';\n\$f(\$x);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Variable function call $f() must be blocked.' );
		// Reason is the existing "dynamic function call" diagnostic.
		$reasons = implode( ' ', array_column( $diags, 'reason' ) );
		$this->assertStringContainsString( 'dynamic function call', strtolower( $reasons ) );
	}

	/**
	 * 13. ReflectionObject instantiation.
	 */
	public function test_reflection_object_is_blocked(): void {
		$cls  = 'Reflection' . 'Object';
		$code = "<?php\n\$ro = new {$cls}(\$obj);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'new ReflectionObject must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_reflection_blocked', $diags );
	}

	/**
	 * 14. ReflectionProperty usage.
	 */
	public function test_reflection_property_is_blocked(): void {
		$cls  = 'Reflection' . 'Property';
		$code = "<?php\n\$rp = new {$cls}('SomeClass', 'field');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'new ReflectionProperty must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_reflection_blocked', $diags );
	}

	/**
	 * 15. call_user_func with a variable callable — blocked outright because
	 * call_user_func itself is now in BLOCKED_FUNCTIONS.
	 */
	public function test_call_user_func_variable_callable_is_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n\$cb = 'strtoupper';\n{$fn}(\$cb, 'val');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func with variable callable must be blocked.' );
	}

	// =========================================================================
	// BLOCKER 1 — \Closure::fromCallable (fully-qualified form)
	// =========================================================================

	/**
	 * B1-a. \Closure::fromCallable('eval') — fully-qualified form must be blocked.
	 */
	public function test_fully_qualified_closure_from_callable_eval_is_blocked(): void {
		// Build the source so that the scanner sees \Closure::fromCallable with a
		// literal 'eval' string — the real RCE bypass this test guards against.
		$callable = 'ev' . 'al';
		$code     = "<?php\n\$fn = \\" . "Closure::fromCallable('{$callable}');\n";
		$diags    = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, '\\Closure::fromCallable must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * B1-b. \Closure::fromCallable('system') — fully-qualified form must be blocked.
	 */
	public function test_fully_qualified_closure_from_callable_system_is_blocked(): void {
		$callable = 'sy' . 'stem';
		$code     = "<?php\n\$fn = \\" . "Closure::fromCallable('{$callable}');\n";
		$diags    = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, '\\Closure::fromCallable with system must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * B1-c. add_action('init', \Closure::fromCallable('system')) — the live RCE vector.
	 */
	public function test_fully_qualified_closure_from_callable_in_add_action_is_blocked(): void {
		$callable = 'sy' . 'stem';
		$code     = "<?php\nadd_action('init', \\" . "Closure::fromCallable('{$callable}'));\n";
		$diags    = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'add_action with \\Closure::fromCallable(system) must be blocked.' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	// =========================================================================
	// BLOCKER 2 — call_user_func concatenation bypass
	// =========================================================================

	/**
	 * B2-a. call_user_func('ev'.'al', $x) — blocked outright (call_user_func is
	 * in BLOCKED_FUNCTIONS) and additionally as a concatenated callable.
	 */
	public function test_call_user_func_concatenated_eval_is_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n{$fn}('ev'.'al', \$x);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, "call_user_func('ev'.'al') must be blocked." );
	}

	/**
	 * B2-b. call_user_func('sys'.'tem', ...) — blocked outright.
	 */
	public function test_call_user_func_concatenated_system_is_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n{$fn}('sys'.'tem', 'ls');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, "call_user_func('sys'.'tem') must be blocked." );
	}

	/**
	 * B2-c. call_user_func($x, ...) — blocked outright.
	 */
	public function test_call_user_func_with_variable_arg_is_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n{$fn}(\$x, 'arg');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func($x) must be blocked.' );
	}

	// =========================================================================
	// False-positive cases — must NOT be blocked
	// =========================================================================

	/**
	 * FP1. String containing 'Reflection' in an echo statement must pass.
	 */
	public function test_string_containing_reflection_passes(): void {
		$code = "<?php\necho 'My reflection on this topic';\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertSame( [], $diags, 'String literal containing "reflection" must not be flagged.' );
	}

	/**
	 * FP2. Comment containing 'ReflectionClass' must pass.
	 */
	public function test_comment_containing_reflection_class_passes(): void {
		$code = "<?php\n// ReflectionClass is useful for meta-programming.\necho 'hello';\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertSame( [], $diags, 'Comment containing ReflectionClass must not be flagged.' );
	}

	/**
	 * FP3. Closure::bind() is already blocked by the existing rule;
	 * confirm Closure::fromCallable is blocked separately so neither
	 * regresses on the other.
	 */
	public function test_closure_bind_still_blocked_independently(): void {
		$code = "<?php\n\$bound = Closure::bind(\$fn, \$scope, 'SomeClass');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Closure::bind must still be blocked.' );
		// Must be the existing bind-specific reason, not the fromCallable one.
		$reasons = implode( ' ', array_column( $diags, 'reason' ) );
		$this->assertStringContainsString( 'closure::bind', strtolower( $reasons ) );
		$this->assertStringNotContainsString( 'fromCallable', $reasons );
	}

	/**
	 * FP4. call_user_func is now blocked outright (added to BLOCKED_FUNCTIONS)
	 * because there is no safe use of dynamic callable dispatch in sandboxed code.
	 * Even a benign callable like 'strtoupper' is rejected — the function itself
	 * is the risk vector, not only the callable string.
	 */
	public function test_call_user_func_safe_literal_callable_is_now_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n{$fn}('strtoupper', 'value');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func is blocked outright regardless of callable.' );
	}

	/**
	 * FP5. call_user_func_array is now blocked outright for the same reason.
	 */
	public function test_call_user_func_array_safe_literal_callable_is_now_blocked(): void {
		$fn   = 'call_user_func_' . 'array';
		$code = "<?php\n{$fn}('array_map', ['strtolower', ['A', 'B']]);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func_array is blocked outright regardless of callable.' );
	}

	/**
	 * FP6. call_user_func is now blocked outright — even with a closure literal.
	 */
	public function test_call_user_func_closure_literal_is_now_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n{$fn}(function (\$x) { return \$x; }, 42);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func is blocked outright regardless of callable form.' );
	}

	/**
	 * FP7. call_user_func is now blocked outright — even with a class-method array callable.
	 */
	public function test_call_user_func_class_method_array_callable_is_now_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n{$fn}(['Foo', 'bar'], 'arg');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func is blocked outright regardless of callable form.' );
	}

	/**
	 * FP8. Plain new with a literal class name is not flagged.
	 */
	public function test_new_with_literal_class_name_passes(): void {
		$code = "<?php\n\$obj = new stdClass();\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertSame( [], $diags, 'new with literal class name must pass.' );
	}

	// =========================================================================
	// Important 1 — Case-insensitive class/method names bypass
	// =========================================================================

	/**
	 * I1-a. \closure::fromCallable('exec') — lowercase class name must be blocked.
	 */
	public function test_lowercase_fq_closure_from_callable_is_blocked(): void {
		$exec = 'ex' . 'ec';
		$code = "<?php\n\$fn = \\" . "closure::fromCallable('{$exec}');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, '\\closure::fromCallable must be blocked (case-insensitive class).' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * I1-b. Closure::fromcallable('exec') — lowercase method name must be blocked.
	 */
	public function test_lowercase_method_closure_from_callable_is_blocked(): void {
		$exec = 'ex' . 'ec';
		$code = "<?php\n\$fn = Closure::fromcallable('{$exec}');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Closure::fromcallable must be blocked (case-insensitive method).' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * I1-c. CLOSURE::fromCallable('exec') — uppercase class name must be blocked.
	 */
	public function test_uppercase_class_closure_from_callable_is_blocked(): void {
		$exec = 'ex' . 'ec';
		$code = "<?php\n\$fn = CLOSURE::fromCallable('{$exec}');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'CLOSURE::fromCallable must be blocked (case-insensitive class).' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * I1-d. closure::bind($fn, $scope, 'X') — lowercase class name must be blocked.
	 */
	public function test_lowercase_unqualified_closure_bind_is_blocked(): void {
		$code = "<?php\n\$b = closure::bind(\$fn, \$scope, 'X');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'closure::bind must be blocked (case-insensitive class).' );
		$reasons = implode( ' ', array_column( $diags, 'reason' ) );
		$this->assertStringContainsString( 'closure::bind', strtolower( $reasons ) );
	}

	// =========================================================================
	// Important 2 — Whitespace around `::` bypass
	// =========================================================================

	/**
	 * I2-a. Closure :: fromCallable('eval') — whitespace around :: must be blocked.
	 */
	public function test_whitespace_around_double_colon_closure_from_callable_is_blocked(): void {
		$callable = 'ev' . 'al';
		$code     = "<?php\n\$fn = Closure :: fromCallable('{$callable}');\n";
		$diags    = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Closure :: fromCallable must be blocked (whitespace around ::).' );
		$this->assertReasonContains( 'stonewright_guard_closure_callable_blocked', $diags );
	}

	/**
	 * I2-b. Closure :: bind($fn, $scope, 'X') — whitespace around :: must be blocked.
	 */
	public function test_whitespace_around_double_colon_closure_bind_is_blocked(): void {
		$code = "<?php\n\$b = Closure :: bind(\$fn, \$scope, 'X');\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Closure :: bind must be blocked (whitespace around ::).' );
		$reasons = implode( ' ', array_column( $diags, 'reason' ) );
		$this->assertStringContainsString( 'closure::bind', strtolower( $reasons ) );
	}

	// =========================================================================
	// Important 3 — Nested call_user_func bypass
	// =========================================================================

	/**
	 * I3-a. call_user_func('call_user_func', 'eval', $x) — nested self-reference
	 * must be blocked because call_user_func is now in BLOCKED_FUNCTIONS.
	 */
	public function test_nested_call_user_func_self_reference_is_blocked(): void {
		$outer = 'call_user_' . 'func';
		$inner = 'call_user_' . 'func';
		$ev    = 'ev' . 'al';
		$code  = "<?php\n{$outer}('{$inner}', '{$ev}', \$x);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'Nested call_user_func self-reference must be blocked.' );
	}

	/**
	 * I3-b. call_user_func('strlen', $x) — call_user_func itself is blocked
	 * outright regardless of the callable it wraps.
	 */
	public function test_call_user_func_direct_call_is_blocked(): void {
		$fn   = 'call_user_' . 'func';
		$code = "<?php\n{$fn}('strlen', \$x);\n";
		$diags = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'call_user_func is blocked outright per policy.' );
	}

	// =========================================================================
	// Bonus S2 — fopen mode arg truncation
	// =========================================================================

	/**
	 * S2. fopen reason string stays bounded even with an absurdly long mode argument.
	 */
	public function test_fopen_write_mode_reason_length_is_bounded(): void {
		$long_mode = str_repeat( 'w', 200 );
		$code      = "<?php\nfopen('/tmp/x', '{$long_mode}');\n";
		$diags     = StaticGuard::scan_with_diagnostics( $code );
		$this->assertNotEmpty( $diags, 'fopen with write mode must still be blocked.' );
		foreach ( $diags as $d ) {
			if ( false !== strpos( $d['reason'], 'fopen' ) ) {
				$this->assertLessThanOrEqual(
					100,
					strlen( $d['reason'] ),
					'fopen diagnostic reason must not echo attacker-controlled data verbatim: ' . $d['reason']
				);
			}
		}
	}

	// =========================================================================
	// Helper
	// =========================================================================

	/**
	 * Assert that at least one diagnostic reason contains the given substring.
	 *
	 * @param array<int, array{line: int, offending_token: string, reason: string}> $diags
	 */
	private function assertReasonContains( string $needle, array $diags ): void {
		$reasons = implode( ' | ', array_column( $diags, 'reason' ) );
		$this->assertStringContainsString(
			$needle,
			$reasons,
			"Expected a diagnostic reason containing '{$needle}'. Got: {$reasons}"
		);
	}
}
