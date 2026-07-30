<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Sandbox;

/**
 * Static analysis guard for sandbox files.
 *
 * Uses token_get_all() for primary detection and regex for constructs that
 * don't tokenize cleanly (short-echo tag, backtick operator).
 *
 * The guard returns either flat human-readable strings (back-compat
 * `::scan()`) or structured diagnostics carrying line numbers and reasons
 * (`::scan_with_diagnostics()`). Diagnostics are designed to be precise:
 * harmless usages (method calls named like a blocked builtin, string
 * literals passed to functions, namespaced function references) are not
 * flagged.
 *
 * @phpstan-type Diagnostic array{line: int, offending_token: string, reason: string}
 */
final class StaticGuard {

	/**
	 * Function names that are unconditionally blocked when called directly.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKED_FUNCTIONS = [
		// Direct execution.
		'create_function',
		'exec',
		'shell_exec',
		'system',
		'passthru',
		'proc_open',
		'popen',
		'pcntl_exec',

		// Dynamic callable dispatch — no legitimate use in sandboxed widget code;
		// blocks nested call_user_func('call_user_func', 'eval', ...) patterns.
		'call_user_func',
		'call_user_func_array',

		// File mutation.
		'file_put_contents',
		'unlink',
		'rename',
		'copy',
		'mkdir',
		'rmdir',
		'chmod',
		'chown',
		'symlink',
		'link',
		'tempnam',
		'move_uploaded_file',

		// Network / process / sockets / mail.
		'curl_exec',
		'curl_init',
		'curl_multi_exec',
		'curl_multi_init',
		'fsockopen',
		'pfsockopen',
		'stream_socket_client',
		'stream_socket_server',
		'socket_create',
		'socket_connect',
		'mail',
		'imap_open',
	];

	/**
	 * Function-name prefixes that are entirely blocked when called directly.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKED_PREFIXES = [
		'pcntl_',
		'posix_',
	];

	/**
	 * Callables routed through `call_user_func`/`call_user_func_array`. A
	 * literal string is acceptable (we cannot verify what the host function
	 * resolves it to, but the caller has named it). A variable is not.
	 *
	 * These names are intentionally left here for documentation purposes only;
	 * both are now also present in BLOCKED_FUNCTIONS so they are blocked
	 * outright — there is no legitimate need for dynamic callable dispatch in
	 * sandboxed widget code.
	 *
	 * @var array<int, string>
	 */
	private const VARIABLE_DISPATCH_FUNCTIONS = [
		'call_user_func',
		'call_user_func_array',
	];

	/**
	 * Scan PHP source code for disallowed constructs.
	 *
	 * Returns an array of human-readable error strings, one per offending
	 * site. Empty array means the file is clean.
	 *
	 * @param string $code Raw PHP source (with or without opening tag).
	 * @return array<int, string>
	 */
	public static function scan( string $code ): array {
		$diagnostics = self::scan_with_diagnostics( $code );
		$out         = [];

		foreach ( $diagnostics as $d ) {
			$out[] = sprintf( '%s (line %d)', $d['reason'], $d['line'] );
		}

		return $out;
	}

	/**
	 * Scan PHP source code for disallowed constructs and return structured
	 * diagnostics. Each diagnostic carries the line, the offending token text
	 * (e.g. function name), and a short reason.
	 *
	 * @param string $code Raw PHP source (with or without opening tag).
	 * @return array<int, array{line: int, offending_token: string, reason: string}>
	 */
	public static function scan_with_diagnostics( string $code ): array {
		$diagnostics = [];

		// --- Regex-based checks (these don't tokenize cleanly) ----------------

		// Short-echo tag: <?=
		if ( preg_match( '/\<\?=/', $code, $m, PREG_OFFSET_CAPTURE ) ) {
			$diagnostics[] = [
				'line'            => self::line_of_offset( $code, (int) $m[0][1] ),
				'offending_token' => '<?=',
				'reason'          => 'Disallowed token: short-echo opening tag (<?=)',
			];
		}

		// Backtick execution operator: `command` — match a backtick that follows
		// whitespace, an operator, open-paren, equals, semicolon, or start of line.
		if ( preg_match( '/(?:^|[\s=\(;,\[\{&|!])\s*`/m', $code, $m, PREG_OFFSET_CAPTURE ) ) {
			$diagnostics[] = [
				'line'            => self::line_of_offset( $code, (int) $m[0][1] ),
				'offending_token' => '`',
				'reason'          => 'Disallowed token: backtick execution operator',
			];
		}

		// assert() with string literal argument.
		if ( preg_match( '/\bassert\s*\(\s*[\'"]/', $code, $m, PREG_OFFSET_CAPTURE ) ) {
			$diagnostics[] = [
				'line'            => self::line_of_offset( $code, (int) $m[0][1] ),
				'offending_token' => 'assert',
				'reason'          => 'Disallowed function: assert with string argument',
			];
		}

		// --- Token-based checks -----------------------------------------------

		// Ensure code starts with a PHP opening tag for token_get_all.
		$source = $code;
		if ( ! preg_match( '/^\s*<\?(?:php\b|=)/', $source ) ) {
			$source = '<?php ' . $source;
		}

		$tokens = @token_get_all( $source );
		$count  = count( $tokens );

		// First pass: collect dynamic-function-call positions (where the call
		// operator '(' follows a variable, ${...} expression, or curly object
		// access). These are not addressable by single-token IDs alone.
		$dynamic_call_lines = self::collect_dynamic_call_lines( $tokens );

		foreach ( $dynamic_call_lines as $line ) {
			$diagnostics[] = [
				'line'            => $line,
				'offending_token' => '$fn(',
				'reason'          => 'Disallowed pattern: dynamic function call (variable or expression in call position)',
			];
		}

		// Closure::bind heuristic — flag the static-method call form.
		foreach ( self::collect_closure_bind_lines( $tokens ) as $line ) {
			$diagnostics[] = [
				'line'            => $line,
				'offending_token' => 'Closure::bind',
				'reason'          => 'Disallowed pattern: Closure::bind with arbitrary scope',
			];
		}

		// Closure::bindTo — instance-method equivalent of Closure::bind.
		foreach ( self::collect_closure_bindto_lines( $tokens ) as $line ) {
			$diagnostics[] = [
				'line'            => $line,
				'offending_token' => 'Closure::bindTo',
				'reason'          => 'Disallowed pattern: Closure::bindTo with arbitrary scope',
			];
		}

		// Closure::fromCallable — dynamic callable dispatch bypass.
		foreach ( self::collect_closure_from_callable_lines( $tokens ) as $line ) {
			$diagnostics[] = [
				'line'            => $line,
				'offending_token' => 'Closure::fromCallable',
				'reason'          => 'stonewright_guard_closure_callable_blocked: Closure::fromCallable is disallowed in sandbox code',
			];
		}

		// Reflection* class usage — any class starting with "Reflection" or fully-
		// qualified \Reflection* used in new-expression or use-import context.
		foreach ( self::collect_reflection_lines( $tokens ) as $line ) {
			$diagnostics[] = [
				'line'            => $line,
				'offending_token' => 'Reflection*',
				'reason'          => 'stonewright_guard_reflection_blocked: Reflection API is disallowed in sandbox code',
			];
		}

		// __invoke magic method call on any object.
		foreach ( self::collect_invoke_magic_lines( $tokens ) as $line ) {
			$diagnostics[] = [
				'line'            => $line,
				'offending_token' => '__invoke',
				'reason'          => 'stonewright_guard_invoke_blocked: explicit __invoke() call is disallowed in sandbox code',
			];
		}

		// (new $class)(...) — dynamic class instantiation via variable.
		foreach ( self::collect_dynamic_new_lines( $tokens ) as $line ) {
			$diagnostics[] = [
				'line'            => $line,
				'offending_token' => 'new $var',
				'reason'          => 'stonewright_guard_dynamic_class_blocked: dynamic class instantiation via variable is disallowed',
			];
		}

		// Second pass: token-by-token.
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_string( $token ) ) {
				continue;
			}

			[ $id, $text, $line ] = $token;

			// T_EVAL — PHP eval keyword.
			if ( T_EVAL === $id ) {
				$diagnostics[] = [
					'line'            => $line,
					'offending_token' => 'eval',
					'reason'          => 'Disallowed token: eval',
				];
				continue;
			}

			// T_INCLUDE / T_REQUIRE / T_INCLUDE_ONCE / T_REQUIRE_ONCE.
			if ( in_array( $id, [ T_INCLUDE, T_REQUIRE, T_INCLUDE_ONCE, T_REQUIRE_ONCE ], true ) ) {
				$kind         = strtolower( token_name( $id ) );
				$result       = self::classify_include_argument( $tokens, $i );

				if ( 'remote' === $result ) {
					$diagnostics[] = [
						'line'            => $line,
						'offending_token' => $kind,
						'reason'          => 'Remote include detected: ' . $kind . ' with http(s) URL',
					];
				} elseif ( 'non_literal' === $result ) {
					$diagnostics[] = [
						'line'            => $line,
						'offending_token' => $kind,
						'reason'          => 'Disallowed pattern: ' . $kind . ' with non-literal path (variable, concatenation, or function call)',
					];
				}
				continue;
			}

			// Variable-variables: T_DOLLAR_OPEN_CURLY_BRACES or T_STRING_VARNAME-like
			// patterns. Token id may differ; detect via $$ in source as well.
			// T_VARIABLE followed immediately by '(' is the canonical $fn() call
			// — handled above by collect_dynamic_call_lines. Here we catch
			// the variable-variable construct '$$name'.
			if ( T_VARIABLE === $id ) {
				$prev = self::prev_non_whitespace( $tokens, $i );
				if ( null !== $prev && is_string( $prev ) && '$' === $prev ) {
					$diagnostics[] = [
						'line'            => $line,
						'offending_token' => '$$',
						'reason'          => 'Disallowed pattern: variable variable ($$name)',
					];
				}
				continue;
			}

			// T_NAME_FULLY_QUALIFIED / T_NAME_RELATIVE — PHP 8 emits these as a
			// single token for names like "\exec" or "namespace\exec". The
			// T_STRING-only blocked-function scan above never sees them, so we
			// catch them here. We strip any leading "\" and the optional
			// "namespace\" prefix, then normalize the trailing simple name.
			if ( self::is_qualified_name_token( $id ) ) {
				$bare = self::bare_name_from_qualified( $text );
				if ( '' === $bare ) {
					continue;
				}
				$name_lower = strtolower( $bare );

				// Fully-qualified eval is "\eval" → T_NAME_FULLY_QUALIFIED, not
				// T_EVAL. Treat it the same way for diagnostic clarity.
				if ( 'eval' === $name_lower ) {
					if ( self::is_call_target( $tokens, $i, $count ) ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => 'eval',
							'reason'          => 'Disallowed token: eval',
						];
					}
					continue;
				}

				if ( self::is_blocked_function_name( $name_lower ) && self::is_call_target( $tokens, $i, $count ) ) {
					$diagnostics[] = [
						'line'            => $line,
						'offending_token' => $name_lower,
						'reason'          => 'Disallowed function: ' . $name_lower,
					];
					continue;
				}

				// fopen with a write-mode literal argument (fully-qualified form).
				if ( 'fopen' === $name_lower && self::is_call_target( $tokens, $i, $count ) ) {
					$mode = self::fopen_mode_arg( $tokens, $i, $count );
					if ( null !== $mode && self::is_write_mode( $mode ) ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => 'fopen',
							'reason'          => 'Disallowed function: fopen with write mode (' . substr( $mode, 0, 32 ) . ')',
						];
					}
					continue;
				}

				// call_user_func{,_array} (fully-qualified form) — callable must
				// be a literal string that does not name a blocked function.
				if ( in_array( $name_lower, self::VARIABLE_DISPATCH_FUNCTIONS, true ) && self::is_call_target( $tokens, $i, $count ) ) {
					$kind = self::call_user_func_first_arg_kind( $tokens, $i, $count );
					if ( 'literal_string' !== $kind ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => $name_lower,
							'reason'          => 'stonewright_guard_call_user_func_blocked: ' . $name_lower . ' with non-literal-string callable',
						];
					} elseif ( self::call_user_func_callable_is_blocked( $tokens, $i, $count ) ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => $name_lower,
							'reason'          => 'stonewright_guard_call_user_func_blocked: ' . $name_lower . ' with blocked callable name',
						];
					}
					continue;
				}

				continue;
			}

			// T_STRING — check for blocked function names followed by '('.
			if ( T_STRING === $id ) {
				$name_lower = strtolower( $text );

				if ( self::is_blocked_function_name( $name_lower ) ) {
					if ( ! self::is_in_safe_context( $tokens, $i ) && self::is_call_target( $tokens, $i, $count ) ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => $name_lower,
							'reason'          => 'Disallowed function: ' . $name_lower,
						];
					}
					continue;
				}

				// fopen with a write-mode literal argument.
				if ( 'fopen' === $name_lower && ! self::is_in_safe_context( $tokens, $i ) && self::is_call_target( $tokens, $i, $count ) ) {
					$mode = self::fopen_mode_arg( $tokens, $i, $count );
					if ( null !== $mode && self::is_write_mode( $mode ) ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => 'fopen',
							'reason'          => 'Disallowed function: fopen with write mode (' . substr( $mode, 0, 32 ) . ')',
						];
						continue;
					}
				}

				// call_user_func / call_user_func_array — callable must be a literal string
				// that does not name a blocked function or dangerous builtin.
				if ( in_array( $name_lower, self::VARIABLE_DISPATCH_FUNCTIONS, true ) && ! self::is_in_safe_context( $tokens, $i ) && self::is_call_target( $tokens, $i, $count ) ) {
					$kind = self::call_user_func_first_arg_kind( $tokens, $i, $count );
					if ( 'literal_string' !== $kind ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => $name_lower,
							'reason'          => 'stonewright_guard_call_user_func_blocked: ' . $name_lower . ' with non-literal-string callable',
						];
					} elseif ( self::call_user_func_callable_is_blocked( $tokens, $i, $count ) ) {
						$diagnostics[] = [
							'line'            => $line,
							'offending_token' => $name_lower,
							'reason'          => 'stonewright_guard_call_user_func_blocked: ' . $name_lower . ' with blocked callable name',
						];
					}
					continue;
				}

				// base64_decode followed by execution-related call — heuristic.
				if ( 'base64_decode' === $name_lower && ! self::is_in_safe_context( $tokens, $i ) && self::is_call_target( $tokens, $i, $count ) ) {
					for ( $j = $i + 1; $j < $count && $j < $i + 50; $j++ ) {
						$next = $tokens[ $j ];
						if ( ! is_array( $next ) ) {
							continue;
						}
						if ( T_STRING === $next[0] && self::is_blocked_function_name( strtolower( $next[1] ) ) ) {
							$diagnostics[] = [
								'line'            => $line,
								'offending_token' => 'base64_decode',
								'reason'          => 'Disallowed pattern: base64_decode combined with execution function',
							];
							break;
						}
					}
					continue;
				}

				continue;
			}
		}

		return $diagnostics;
	}

	// -------------------------------------------------------------------------
	// Token helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array<int, int> Line numbers where dynamic calls were found.
	 */
	private static function collect_dynamic_call_lines( array $tokens ): array {
		$lines = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			// ${expr}(...) — raw '$' followed by '{ ... }' followed by '('.
			// In older PHP versions T_DOLLAR_OPEN_CURLY_BRACES is emitted; in
			// modern versions the lexer emits a bare '$' and '{' as separate
			// string tokens at expression position.
			if ( is_string( $token ) && '$' === $token ) {
				$next = self::next_non_whitespace( $tokens, $i, $next_idx );
				if ( is_string( $next ) && '{' === $next && null !== $next_idx ) {
					$close = self::find_matching_close( $tokens, $next_idx, '{', '}' );
					if ( null !== $close ) {
						$after = self::next_non_whitespace( $tokens, $close );
						if ( is_string( $after ) && '(' === $after ) {
							$lines[] = self::token_line( $tokens, $i );
						}
					}
				}
				continue;
			}

			if ( ! is_array( $token ) ) {
				continue;
			}

			[ $id, , $line ] = $token;

			// $fn(...) — variable directly followed by '(', OR variable followed
			// by one-or-more subscript expressions ($arr[...]) and then '('.
			// The subscript form covers $GLOBALS["exec"](), $arr["fn"](),
			// $_REQUEST["fn"]() and similar dynamic-dispatch patterns.
			if ( T_VARIABLE === $id ) {
				$next = self::next_non_whitespace( $tokens, $i, $next_idx );
				if ( is_string( $next ) && '(' === $next ) {
					$lines[] = $line;
					continue;
				}

				// Walk through any number of '[...]' subscripts. If we land on
				// '(' (a call), flag it; otherwise it is a plain array access.
				$cursor = $next;
				$c_idx  = $next_idx;
				while ( is_string( $cursor ) && '[' === $cursor && null !== $c_idx ) {
					$close = self::find_matching_close( $tokens, $c_idx, '[', ']' );
					if ( null === $close ) {
						break;
					}
					$cursor = self::next_non_whitespace( $tokens, $close, $c_idx );
					if ( is_string( $cursor ) && '(' === $cursor ) {
						$lines[] = $line;
						break;
					}
				}
				continue;
			}

			// ${expr}(...) — T_DOLLAR_OPEN_CURLY_BRACES introduces an expression
			// (older PHP behavior, retained for forward-compat).
			if ( defined( 'T_DOLLAR_OPEN_CURLY_BRACES' ) && T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
				$lines[] = $line;
				continue;
			}

			// $obj->{$dyn}() — T_OBJECT_OPERATOR followed by '{' indicates
			// dynamic property access; if a '(' follows the matching '}' we
			// treat it as a dynamic method call.
			if ( T_OBJECT_OPERATOR === $id ) {
				$next     = self::next_non_whitespace( $tokens, $i, $next_idx );
				if ( is_string( $next ) && '{' === $next && null !== $next_idx ) {
					$close = self::find_matching_close( $tokens, $next_idx, '{', '}' );
					if ( null !== $close ) {
						$after = self::next_non_whitespace( $tokens, $close );
						if ( is_string( $after ) && '(' === $after ) {
							$lines[] = $line;
						}
					}
				}
				continue;
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Flag `Closure::bind(` token sequences.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array<int, int>
	 */
	private static function collect_closure_bind_lines( array $tokens ): array {
		$lines = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count - 4; $i++ ) {
			$a = $tokens[ $i ];
			// PHP class names are case-insensitive; match "Closure" in any case.
			if ( ! is_array( $a ) || T_STRING !== $a[0] || 0 !== strcasecmp( 'Closure', $a[1] ) ) {
				continue;
			}
			// Use next_non_whitespace so `Closure :: bind(` (whitespace around ::) is
			// not missed.
			$b = self::next_non_whitespace( $tokens, $i, $b_idx );
			if ( ! is_array( $b ) || T_DOUBLE_COLON !== $b[0] || null === $b_idx ) {
				continue;
			}
			$c = self::next_non_whitespace( $tokens, $b_idx, $c_idx );
			if ( ! is_array( $c ) || T_STRING !== $c[0] || 'bind' !== strtolower( $c[1] ) ) {
				continue;
			}
			if ( null === $c_idx ) {
				continue;
			}
			$d = self::next_non_whitespace( $tokens, $c_idx );
			if ( is_string( $d ) && '(' === $d ) {
				$lines[] = $a[2];
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Flag `$closure->bindTo(` token sequences. Equivalent in effect to
	 * `Closure::bind` but a method call, so the `is_in_safe_context` check
	 * would otherwise skip it as a "safe" method invocation.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array<int, int>
	 */
	private static function collect_closure_bindto_lines( array $tokens ): array {
		$lines = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count - 3; $i++ ) {
			$a = $tokens[ $i ];
			if ( ! is_array( $a ) || T_VARIABLE !== $a[0] ) {
				continue;
			}
			$b = self::next_non_whitespace( $tokens, $i, $b_idx );
			if ( ! is_array( $b ) || T_OBJECT_OPERATOR !== $b[0] ) {
				if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && is_array( $b ) && T_NULLSAFE_OBJECT_OPERATOR === $b[0] ) {
					// fall through — null-safe bindTo is just as dangerous.
				} else {
					continue;
				}
			}
			if ( null === $b_idx ) {
				continue;
			}
			$c = self::next_non_whitespace( $tokens, $b_idx, $c_idx );
			if ( ! is_array( $c ) || T_STRING !== $c[0] || 'bindto' !== strtolower( $c[1] ) ) {
				continue;
			}
			if ( null === $c_idx ) {
				continue;
			}
			$d = self::next_non_whitespace( $tokens, $c_idx );
			if ( is_string( $d ) && '(' === $d ) {
				$lines[] = $a[2];
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Flag `Closure::fromCallable(` token sequences.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array<int, int>
	 */
	private static function collect_closure_from_callable_lines( array $tokens ): array {
		$lines = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count - 4; $i++ ) {
			$a = $tokens[ $i ];
			if ( ! is_array( $a ) ) {
				continue;
			}

			// Match "Closure" (any case) as T_STRING or the fully-qualified "\Closure"
			// as T_NAME_FULLY_QUALIFIED. PHP class names are case-insensitive.
			$is_closure_name = false;
			if ( T_STRING === $a[0] && 0 === strcasecmp( 'Closure', $a[1] ) ) {
				$is_closure_name = true;
			} elseif ( self::is_qualified_name_token( $a[0] ) && 0 === strcasecmp( 'Closure', ltrim( $a[1], '\\' ) ) ) {
				$is_closure_name = true;
			}

			if ( ! $is_closure_name ) {
				continue;
			}

			// Use next_non_whitespace so `Closure :: fromCallable(` (whitespace around
			// ::) is not missed.
			$b = self::next_non_whitespace( $tokens, $i, $b_idx );
			if ( ! is_array( $b ) || T_DOUBLE_COLON !== $b[0] || null === $b_idx ) {
				continue;
			}
			$c = self::next_non_whitespace( $tokens, $b_idx, $c_idx );
			// Method name is case-insensitive at runtime.
			if ( ! is_array( $c ) || T_STRING !== $c[0] || 0 !== strcasecmp( 'fromCallable', $c[1] ) ) {
				continue;
			}
			if ( null === $c_idx ) {
				continue;
			}
			$d = self::next_non_whitespace( $tokens, $c_idx );
			if ( is_string( $d ) && '(' === $d ) {
				$lines[] = $a[2];
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Flag any usage of a class whose name starts with "Reflection" —
	 * either via `new ReflectionXxx(`, `use ReflectionXxx`, or a
	 * fully-qualified `\ReflectionXxx` form followed by `::` or `new`.
	 *
	 * Comments and string literals are not tokenized as T_STRING so they
	 * won't trigger this.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array<int, int>
	 */
	private static function collect_reflection_lines( array $tokens ): array {
		$lines = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) ) {
				continue;
			}

			[ $id, $text, $line ] = $t;

			// T_STRING "ReflectionXxx" — catch new/use/static-call forms.
			if ( T_STRING === $id && 0 === stripos( $text, 'Reflection' ) ) {
				$lines[] = $line;
				continue;
			}

			// T_NAME_FULLY_QUALIFIED "\ReflectionXxx" (PHP 8 lexer).
			if ( self::is_qualified_name_token( $id ) ) {
				$bare = ltrim( $text, '\\' );
				// Only the single-segment form "\ReflectionXxx" (no further backslash).
				if ( false === strpos( $bare, '\\' ) && 0 === stripos( $bare, 'Reflection' ) ) {
					$lines[] = $line;
					continue;
				}
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Flag explicit `$obj->__invoke(` calls — a direct bypass of name-based
	 * blocklists. Note: `$fn()` (call via variable) is already caught by
	 * `collect_dynamic_call_lines`; this targets the method-call form.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array<int, int>
	 */
	private static function collect_invoke_magic_lines( array $tokens ): array {
		$lines = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count - 2; $i++ ) {
			$a = $tokens[ $i ];
			if ( ! is_array( $a ) ) {
				continue;
			}

			// Object operator -> or ?->
			$is_obj_op = T_OBJECT_OPERATOR === $a[0];
			if ( ! $is_obj_op && defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
				$is_obj_op = T_NULLSAFE_OBJECT_OPERATOR === $a[0];
			}
			if ( ! $is_obj_op ) {
				continue;
			}

			$b = self::next_non_whitespace( $tokens, $i, $b_idx );
			if ( ! is_array( $b ) || T_STRING !== $b[0] || '__invoke' !== $b[1] ) {
				continue;
			}
			if ( null === $b_idx ) {
				continue;
			}
			$c = self::next_non_whitespace( $tokens, $b_idx );
			if ( is_string( $c ) && '(' === $c ) {
				// Report the line of the T_VARIABLE before the operator.
				$prev = self::prev_non_whitespace( $tokens, $i );
				$line = is_array( $prev ) ? $prev[2] : $a[2];
				$lines[] = $line;
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Flag `new $var(` patterns — dynamic class instantiation via a variable
	 * in the class-name position. `new ClassName(` (literal) is safe.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array<int, int>
	 */
	private static function collect_dynamic_new_lines( array $tokens ): array {
		$lines = [];
		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_NEW !== $t[0] ) {
				continue;
			}
			$next = self::next_non_whitespace( $tokens, $i );
			if ( is_array( $next ) && T_VARIABLE === $next[0] ) {
				$lines[] = $t[2];
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Returns true when the first argument of a call_user_func / call_user_func_array
	 * call is a literal string that names a blocked function (e.g. 'eval', 'exec').
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function call_user_func_callable_is_blocked( array $tokens, int $i, int $count ): bool {
		// Navigate to the '(' after the name.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( ! ( is_string( $tokens[ $j ] ?? null ) && '(' === $tokens[ $j ] ) ) {
			return false;
		}
		++$j;
		// Skip whitespace.
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}

		$arg = $tokens[ $j ] ?? null;
		if ( ! is_array( $arg ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0] ) {
			return false;
		}

		// Unquote the string literal.
		$callable = trim( $arg[1], "'\"" );
		$callable_lower = strtolower( $callable );

		// Block eval, assert, and all BLOCKED_FUNCTIONS / BLOCKED_PREFIXES.
		if ( 'eval' === $callable_lower || 'assert' === $callable_lower ) {
			return true;
		}

		return self::is_blocked_function_name( $callable_lower );
	}

	/**
	 * True when the token id represents a (possibly) namespaced name token
	 * that PHP 8 lexers emit as a single unit — i.e. `\foo\bar` or
	 * `namespace\foo`. These never appear as separate T_STRING tokens, so
	 * the T_STRING-driven scanner cannot see them.
	 */
	private static function is_qualified_name_token( int $id ): bool {
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $id ) {
			return true;
		}
		if ( defined( 'T_NAME_RELATIVE' ) && T_NAME_RELATIVE === $id ) {
			return true;
		}
		// Note: T_NAME_QUALIFIED ("Foo\bar") deliberately not included here —
		// users already namespacing through a real path are out of scope for
		// the blocked-builtins heuristic, matching the existing
		// `test_namespaced_blocked_call_passes` expectation.
		return false;
	}

	/**
	 * Extract the bare simple-identifier from a fully-qualified or
	 * relative-namespace name token IFF the call is logically into the
	 * global / current namespace — i.e. "\exec", "\eval",
	 * "namespace\exec". Otherwise return '' so the caller skips it.
	 *
	 * Returning '' for "\Foo\exec" preserves the existing test contract
	 * (`test_namespaced_blocked_call_passes`): a true namespaced call is
	 * out of scope for the blocked-builtins heuristic.
	 */
	private static function bare_name_from_qualified( string $text ): string {
		$trimmed = $text;

		// Strip optional "namespace\" prefix (T_NAME_RELATIVE form).
		if ( 0 === stripos( $trimmed, 'namespace\\' ) ) {
			$trimmed = substr( $trimmed, strlen( 'namespace\\' ) );
		} elseif ( 0 === strpos( $trimmed, '\\' ) ) {
			// Strip a single leading "\" (T_NAME_FULLY_QUALIFIED form).
			$trimmed = substr( $trimmed, 1 );
		} else {
			// Plain "Foo\bar" — out of scope.
			return '';
		}

		// If what remains still contains a "\" it is a real namespaced
		// reference (e.g. "Foo\exec"), not a global-scope call. Leave alone.
		if ( false !== strpos( $trimmed, '\\' ) ) {
			return '';
		}

		return $trimmed;
	}

	/**
	 * Returns 'literal', 'remote', 'non_literal', or 'unknown' for the
	 * argument of an include/require token at $i.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function classify_include_argument( array $tokens, int $i ): string {
		$count            = count( $tokens );
		$has_constant_str = false;
		$has_dynamic      = false;
		$collected        = '';

		for ( $j = $i + 1; $j < $count && $j < $i + 60; $j++ ) {
			$t = $tokens[ $j ];

			if ( is_string( $t ) ) {
				if ( ';' === $t || ',' === $t ) {
					break;
				}
				if ( '.' === $t ) {
					$has_dynamic = true; // concatenation can be variable-influenced
				}
				$collected .= $t;
				continue;
			}

			[ $id, $text ] = $t;

			if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
				$has_constant_str = true;
				$collected       .= $text;
				continue;
			}

			if ( T_VARIABLE === $id || T_STRING_VARNAME === $id ) {
				$has_dynamic = true;
				$collected  .= $text;
				continue;
			}

			if ( defined( 'T_CURLY_OPEN' ) && T_CURLY_OPEN === $id ) {
				$has_dynamic = true;
				continue;
			}

			// String interpolation contents and function calls inside the include arg.
			if ( T_STRING === $id ) {
				$has_dynamic = true;
				$collected  .= $text;
				continue;
			}

			$collected .= $text;
		}

		if ( preg_match( '/https?:\/\//i', $collected ) ) {
			return 'remote';
		}

		if ( $has_dynamic ) {
			return 'non_literal';
		}

		if ( $has_constant_str ) {
			return 'literal';
		}

		return 'unknown';
	}

	/**
	 * Returns the literal-string mode argument of fopen() if it is a constant
	 * string, else null. (Variable mode is left to runtime caller policy.)
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function fopen_mode_arg( array $tokens, int $i, int $count ): ?string {
		// Locate '(' after fopen.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( ! is_string( $tokens[ $j ] ?? null ) || '(' !== $tokens[ $j ] ) {
			return null;
		}

		// Walk until first comma at depth 0, then read the next constant string.
		$depth = 1;
		++$j;
		// Skip first argument (path).
		while ( $j < $count && $depth > 0 ) {
			$t = $tokens[ $j ];
			if ( is_string( $t ) ) {
				if ( '(' === $t ) {
					++$depth;
				} elseif ( ')' === $t ) {
					--$depth;
				} elseif ( ',' === $t && 1 === $depth ) {
					++$j;
					break;
				}
			}
			++$j;
		}

		// Skip whitespace.
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}

		$t = $tokens[ $j ] ?? null;
		if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
			return trim( $t[1], "'\"" );
		}
		return null;
	}

	private static function is_write_mode( string $mode ): bool {
		// Write modes: r+, w, w+, a, a+, x, x+, c, c+ — anything that creates
		// or modifies the file. Read-only 'r' is exempt.
		return (bool) preg_match( '/[wax+]|c[bt+]?$/', $mode );
	}

	/**
	 * Returns 'literal_string' if the first argument of a call is a string
	 * literal, else 'variable'.
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function call_user_func_first_arg_kind( array $tokens, int $i, int $count ): string {
		// Find the '(' after the name.
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( ! ( is_string( $tokens[ $j ] ?? null ) && '(' === $tokens[ $j ] ) ) {
			return 'variable';
		}
		++$j;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}

		$t = $tokens[ $j ] ?? null;
		if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
			// Check whether the next non-whitespace token is a concatenation operator.
			// `call_user_func('ev' . 'al', ...)` is a dynamic expression — reject it.
			$after = self::next_non_whitespace( $tokens, $j );
			if ( is_string( $after ) && '.' === $after ) {
				return 'variable';
			}
			return 'literal_string';
		}

		// Arrays like ['ClassName', 'method'] are callable too. Accept if every
		// element of the array literal is a constant string.
		if ( is_string( $t ) && '[' === $t ) {
			$end = self::find_matching_close( $tokens, $j, '[', ']' );
			if ( null !== $end ) {
				if ( self::array_is_all_literal_strings( $tokens, $j, $end ) ) {
					return 'literal_string';
				}
			}
		}

		// Closure literal: T_FUNCTION (anonymous function) or T_FN — accept.
		if ( is_array( $t ) && ( T_FUNCTION === $t[0] || ( defined( 'T_FN' ) && T_FN === $t[0] ) ) ) {
			return 'literal_string';
		}

		return 'variable';
	}

	/**
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function array_is_all_literal_strings( array $tokens, int $open, int $close ): bool {
		for ( $k = $open + 1; $k < $close; $k++ ) {
			$t = $tokens[ $k ];
			if ( is_string( $t ) ) {
				if ( ',' === $t ) {
					continue;
				}
				return false;
			}
			[ $id ] = $t;
			if ( T_WHITESPACE === $id || T_CONSTANT_ENCAPSED_STRING === $id ) {
				continue;
			}
			return false;
		}
		return true;
	}

	private static function is_blocked_function_name( string $name_lower ): bool {
		if ( in_array( $name_lower, self::BLOCKED_FUNCTIONS, true ) ) {
			return true;
		}
		foreach ( self::BLOCKED_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name_lower, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True if the T_STRING at $i should be ignored (function declaration,
	 * method/static call, namespace reference, etc.).
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function is_in_safe_context( array $tokens, int $i ): bool {
		$prev_id = null;
		for ( $k = $i - 1; $k >= 0; $k-- ) {
			$prev = $tokens[ $k ];
			if ( is_array( $prev ) && T_WHITESPACE === $prev[0] ) {
				continue;
			}
			$prev_id = is_array( $prev ) ? $prev[0] : $prev;
			break;
		}

		$context_safe_ids = [
			T_FUNCTION,
			T_OBJECT_OPERATOR,
			T_DOUBLE_COLON,
			T_NS_SEPARATOR,
		];
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$context_safe_ids[] = T_NULLSAFE_OBJECT_OPERATOR;
		}

		return null !== $prev_id && in_array( $prev_id, $context_safe_ids, true );
	}

	/**
	 * True if the T_STRING at $i is followed by '(' (i.e. it is being called).
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function is_call_target( array $tokens, int $i, int $count ): bool {
		for ( $j = $i + 1; $j < $count && $j < $i + 5; $j++ ) {
			$next = $tokens[ $j ];
			if ( is_array( $next ) && T_WHITESPACE === $next[0] ) {
				continue;
			}
			return is_string( $next ) && '(' === $next;
		}
		return false;
	}

	/**
	 * Find the matching close character (skipping nesting).
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function find_matching_close( array $tokens, int $open_idx, string $open, string $close ): ?int {
		$depth = 0;
		$count = count( $tokens );
		for ( $j = $open_idx; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( is_string( $t ) ) {
				if ( $t === $open ) {
					++$depth;
				} elseif ( $t === $close ) {
					--$depth;
					if ( 0 === $depth ) {
						return $j;
					}
				}
			}
		}
		return null;
	}

	/**
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @param int|null $found_idx Outputs the index of the returned token.
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private static function next_non_whitespace( array $tokens, int $i, ?int &$found_idx = null ) {
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
				continue;
			}
			$found_idx = $j;
			return $t;
		}
		$found_idx = null;
		return null;
	}

	/**
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 * @return array{0:int,1:string,2:int}|string|null
	 */
	private static function prev_non_whitespace( array $tokens, int $i ) {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
				continue;
			}
			return $t;
		}
		return null;
	}

	private static function line_of_offset( string $code, int $offset ): int {
		if ( $offset <= 0 ) {
			return 1;
		}
		return substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
	}

	/**
	 * Returns the line of the closest token at-or-before $i that carries
	 * line information. Used when the token at $i is a bare string token (e.g.
	 * the single-character '$' that begins ${...}).
	 *
	 * @param array<int, array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function token_line( array $tokens, int $i ): int {
		for ( $j = $i; $j >= 0; $j-- ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) ) {
				return $t[2];
			}
		}
		return 1;
	}
}
