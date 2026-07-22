# Stonewright Implementation-Success, Token-Efficiency & Figma-Fidelity Plan (2026-07-22)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Raise real-world implementation success rate and cut token burn for both plugin mode and Direct mode by fixing the exact failure chain observed live (generic Elementor errors → php-execute retry storms → broken learning promotion), making `stonewright-task-start` reach every client automatically, and giving agents concrete dual-Figma-MCP extraction guidance.

**Architecture:** Nine independent phases ordered by ROI. Phases 1–3 fix observed live defects (error truthfulness, memory/learning persistence, retry-storm braking). Phase 4 closes task-start delivery gaps across both modes. Phases 5–8 cover token efficiency, Figma guidance, onboarding docs, and Direct-mode parity tests. Phase 9 (Elementor V4 surgical write) is investigation-gated and largest — do it last.

**Tech Stack:** WordPress plugin PHP 8.1+ (PHPUnit, PHPStan, PHPCS), Node companion TypeScript (vitest, eslint), MCP protocol.

---

## Evidence base (verified 2026-07-22 — do not re-derive, but re-verify line numbers before editing)

**Live site audit (transavia.ro, plugin 1.0.0-alpha.77, `wp-admin/admin.php?page=stonewright-audit-log`):**
- One session on 2026-07-21/22 produced ~50 consecutive `stonewright/php-execute` calls (audit IDs 76–125) after typed Elementor abilities failed.
- Recurring errors panel: 8× `stonewright_php_elementor_raw_write_blocked`, 3× + 2× `stonewright_php_execute_failed`, 2× `stonewright/elementor-v3-update-element` → `stonewright_write_failed` ("Could not save Elementor data." — no cause), 2× `stonewright_v3_architecture_mismatch` (V4 Atomic document).
- **Memory page (`?page=stonewright-memory`): 0 entries across all types** despite `ErrorPatterns::ensure_learning_record()` (shipped since alpha.70) being required to auto-write a memory row at count ≥ 2. Auto-promotion is broken on live.
- Skills page: only the seeded `stonewright-how-to-write-skills` builtin is present.

**Code facts:**
- `ElementorData::write()` populates rich `self::$last_write_error` (`plugin/includes/Support/ElementorData.php:17-20,62+`) but returns bool; **zero** callers in `plugin/includes/Abilities/` read `last_write_error()` — 5 call sites emit generic `write_failed`.
- `ContextToken::error()` (`plugin/includes/Context/ContextToken.php:65-71`) tells agents to call `stonewright-context-bootstrap`, contradicting the documented canonical `stonewright-task-start`.
- `Memory::maybe_install_table()` (`plugin/includes/Memory/Memory.php:46-83`) bumps `stonewright_memory_schema_version` **unconditionally** after `dbDelta()`; `Memory::put_typed()` returns `0` on DB failure with no logging; `AuditLog::record()` wraps `ErrorPatterns::observe()` in `catch (\Throwable) {}` with no logging.
- Direct-mode task-start latch (`companion/src/direct/writes.ts`): process-lifetime boolean, never expires; plugin-mode token: 30-min TTL. Companion proxy (`companion/src/wordpress-mcp.ts`) **discards** the plugin's `initialize.instructions`.
- Read-only abilities are exempt from the context-token gate in both modes → a read-only session never gets nudged toward task-start.
- Direct `elementorDataGet()` (`companion/src/direct/tools/elementor-direct.ts:225-321`) always returns the full parsed `_elementor_data` tree — no summary mode, no cap. Plugin `ElementorV4/ReadAtomicTree.php` likewise unbounded. Only 8 of ~325 plugin abilities support `responseMode`.
- No V4 surgical mutation ability exists (only ReadAtomicTree, RenderFromSpec dry-run-only, Migrate, classes/variables). The `v3_architecture_mismatch` repair text says "use the V4 editor pipeline" but names no callable tool → dead end that caused the live php-execute storm.
- Figma: DesignEvidence 1.0 contract (`plugin/includes/Design/Evidence/Validator.php`) is vendor-neutral and complete; `skills/design-to-wordpress/SKILL.md:53` says only "Use the client's Figma MCP" — zero guidance on which external Figma MCP tools to call per extraction step.
- Docs: `docs/onboarding.md` and `docs/getting-started/claude-code.md` are 100% plugin-mode; the documented smoke tests (`stonewright-ping`, `stonewright-context-bootstrap`-as-primary) dead-end in Direct mode. No `docs/getting-started/cursor.md`. `docs/install-prompts.md` is the only dual-mode doc.
- Missing companion tests: `elementor_requires_plugin` blueprint rejection, `acf-fields-get/update` handlers, `seo-head-get`.

**Repo rules that bind every phase (from `CLAUDE.md`/`AGENTS.md`):**
- Every ability change ships a test under `plugin/tests/` (or `companion/tests/`) in the same commit.
- Changelogs (`CHANGELOG.md` + `plugin/CHANGELOG.md`) and affected docs ship in the same PR as behavior.
- PR description lists changed abilities and whether backup/token/permission/validation/audit gates changed.
- Never weaken permission, backup, validation, confirmation-token, or audit gates.
- No competitor product names in public docs/commits. No automated-authorship claims.
- Before closing docs/release work: `node scripts/check-docs-freshness.mjs` and `git diff --check` must pass.
- Elementor integrity hard rules: no double-encoding, no stripping unknown settings, no widgetType conversion without user intent, surgical mutations only.

**Build commands** (run from repo root):

```bash
cd plugin && composer install && composer test && composer phpstan && composer phpcs
cd ../companion && npm install && npm run typecheck && npm test
```

---

## Phase 1 — Truthful Elementor write errors (plugin)

Highest ROI, smallest diff. When `ElementorData::write()` fails, the agent must see the real `WP_Error` (code + fix hints), not "Could not save Elementor data."

### Task 1.1: Surface `last_write_error` in all five call sites

**Files:**
- Modify: `plugin/includes/Abilities/ElementorV3/UpdateElement.php` (~line 110)
- Modify: `plugin/includes/Abilities/ElementorV3/MoveElement.php` (~line 95)
- Modify: `plugin/includes/Abilities/ElementorV3/RemoveElement.php` (~line 90)
- Modify: `plugin/includes/Abilities/ElementorV3/BatchMutate.php` (~line 285)
- Modify: `plugin/includes/Abilities/Design/SpecToElementorV3.php` (~line 132)
- Test: `plugin/tests/Unit/Elementor/WriteErrorSurfacingTest.php` (new)

- [ ] **Step 1: Write the failing test**

Look at existing tests in `plugin/tests/Unit/Elementor/DocumentIntegrityGateTest.php` for fixture/bootstrapping conventions (how `ElementorData` is exercised without a real DB) and mirror them. The test asserts that when `ElementorData::write()` fails with a gate error, the ability response carries the underlying code, not the generic one:

```php
<?php
declare(strict_types=1);

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\ElementorData;

final class WriteErrorSurfacingTest extends TestCase {

	public function test_write_failure_exposes_last_write_error(): void {
		// Force a gate failure: a tree that collapses a previously large document.
		// DocumentIntegrityGate::assert_write_allowed() blocks size-collapse
		// (prev > 2048 bytes, next < 85% of prev).
		$previous = $this->large_tree();
		$next     = []; // empty tree = collapse

		$gate = \Stonewright\WpMcp\Elementor\Integrity\DocumentIntegrityGate::assert_write_allowed( $next, $previous, [] );
		self::assertInstanceOf( \WP_Error::class, $gate );

		// The helper every ability must now use:
		$surfaced = ElementorData::write_error_for_ability( 'stonewright_write_failed' );
		// When no write happened yet in this process, helper returns the generic fallback.
		self::assertInstanceOf( \WP_Error::class, $surfaced );
	}

	public function test_write_error_helper_returns_last_error_when_present(): void {
		$tree_previous = $this->large_tree();
		ElementorData::write( 123456, [], [ 'previous_override_for_tests' => $tree_previous ] );
		$err = ElementorData::write_error_for_ability( 'stonewright_write_failed' );
		self::assertInstanceOf( \WP_Error::class, $err );
		self::assertNotSame( 'stonewright_write_failed', $err->get_error_code(), 'Underlying gate code must surface, not the generic fallback.' );
	}

	private function large_tree(): array {
		$widgets = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$widgets[] = [
				'id'         => 'w' . $i,
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => [ 'title' => str_repeat( 'Stonewright heading text ', 5 ) ],
				'elements'   => [],
			];
		}
		return [ [ 'id' => 'sec1', 'elType' => 'container', 'settings' => [], 'elements' => $widgets ] ];
	}
}
```

> Note for the executor: the second test's `previous_override_for_tests` option does not exist yet — check how `DocumentIntegrityGateTest.php` feeds a "previous" document (it may stub `ElementorData::read()` via post meta in the test bootstrap). Use the same mechanism instead of inventing a new option. The essential assertions are the two `write_error_for_ability()` behaviors; adapt setup to existing test infrastructure, do not add production code paths purely for tests.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd plugin && vendor/bin/phpunit tests/Unit/Elementor/WriteErrorSurfacingTest.php
```

Expected: FAIL with "Call to undefined method ... write_error_for_ability".

- [ ] **Step 3: Add the helper to `ElementorData`**

In `plugin/includes/Support/ElementorData.php`, after `last_write_error()`:

```php
	/**
	 * WP_Error an ability should return after ElementorData::write() failed.
	 * Prefers the specific gate/validator error; falls back to a generic code.
	 */
	public static function write_error_for_ability( string $fallback_code = 'stonewright_write_failed' ): \WP_Error {
		if ( self::$last_write_error instanceof \WP_Error ) {
			return self::$last_write_error;
		}
		return new \WP_Error(
			$fallback_code,
			__( 'Could not save Elementor data.', 'stonewright' ),
			[ 'status' => 500 ]
		);
	}
```

- [ ] **Step 4: Replace all five generic call sites**

In each file, the current pattern is:

```php
if ( ! ElementorData::write( $post_id, $new_tree ) ) {
	return $this->error( 'write_failed', __( 'Could not save Elementor data.', 'stonewright' ) );
}
```

Replace the `return` line with:

```php
	return ElementorData::write_error_for_ability();
```

For `BatchMutate.php` (~line 285), preserve the restore info by merging it into the error data:

```php
	$err = ElementorData::write_error_for_ability();
	$err->add_data( array_merge( (array) $err->get_error_data(), [ 'restored' => $restored ] ), $err->get_error_code() );
	return $err;
```

- [ ] **Step 5: Run test to verify it passes, plus full quality gates**

```bash
cd plugin && vendor/bin/phpunit tests/Unit/Elementor/WriteErrorSurfacingTest.php && composer test && composer phpstan && composer phpcs
```

Expected: PASS, no regressions.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/Support/ElementorData.php plugin/includes/Abilities/ElementorV3/UpdateElement.php plugin/includes/Abilities/ElementorV3/MoveElement.php plugin/includes/Abilities/ElementorV3/RemoveElement.php plugin/includes/Abilities/ElementorV3/BatchMutate.php plugin/includes/Abilities/Design/SpecToElementorV3.php plugin/tests/Unit/Elementor/WriteErrorSurfacingTest.php
git commit -m "fix: surface real Elementor write error instead of generic write_failed"
```

### Task 1.2: Fix `ContextToken::error()` to name `stonewright-task-start`

**Files:**
- Modify: `plugin/includes/Context/ContextToken.php:65-71`
- Test: `plugin/tests/Unit/Context/ContextTokenErrorTest.php` (new; if a ContextToken test already exists, extend it instead)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Stonewright\WpMcp\Tests\Unit\Context;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Context\ContextToken;

final class ContextTokenErrorTest extends TestCase {
	public function test_missing_token_error_names_task_start(): void {
		$result = ContextToken::verify( 'not-a-real-token', 'stonewright/example-write' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringContainsString( 'stonewright-task-start', $result->get_error_message() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd plugin && vendor/bin/phpunit tests/Unit/Context/ContextTokenErrorTest.php
```

Expected: FAIL (message currently names `stonewright-context-bootstrap`).

- [ ] **Step 3: Update the error message**

Replace the message in `ContextToken::error()`:

```php
	private static function error(): \WP_Error {
		return new \WP_Error(
			'stonewright_context_required',
			__( 'Call MCP tool stonewright-task-start (WordPress ability stonewright/task-start) first for this task and pass the returned stonewright_context_token to write or destructive abilities. Compatibility path: stonewright-context-bootstrap.', 'stonewright' ),
			[ 'status' => 403 ]
		);
	}
```

- [ ] **Step 4: Run tests + gates**

```bash
cd plugin && composer test && composer phpcs
```

Grep for tests asserting the old message and update them:

```bash
grep -rn "context-bootstrap" plugin/tests/ | grep -i "error\|message" || true
```

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Context/ContextToken.php plugin/tests/Unit/Context/ContextTokenErrorTest.php
git commit -m "fix: context-token error points agents to canonical task-start"
```

### Task 1.3: Actionable remediation for `v3_architecture_mismatch` and write failures

**Files:**
- Modify: `plugin/includes/Security/RemediationHints.php` (per-code map at ~lines 14-39)
- Modify: `plugin/includes/Abilities/ElementorV3/BatchMutate.php` (~line 190, the mismatch error `repair` text)
- Modify: `plugin/includes/Abilities/ElementorV3/BuildPageFromSpec.php` (~line 135, same)
- Test: extend `plugin/tests/Unit/` — locate the existing test covering `RemediationHints` (`grep -rn "RemediationHints" plugin/tests/`) and add cases; if none exists, create `plugin/tests/Unit/Security/RemediationHintsTest.php`

- [ ] **Step 1: Write failing tests**

```php
	public function test_v3_architecture_mismatch_names_concrete_tools(): void {
		$hint = RemediationHints::for_code( 'stonewright_v3_architecture_mismatch', 'stonewright/elementor-v3-batch-mutate' );
		self::assertStringContainsString( 'elementor-v4-read-atomic-tree', $hint );
		self::assertStringContainsString( 'do not use php-execute', strtolower( $hint ) );
	}

	public function test_raw_write_blocked_hint_names_batch_mutate(): void {
		$hint = RemediationHints::for_code( 'stonewright_php_elementor_raw_write_blocked', 'stonewright/php-execute' );
		self::assertStringContainsString( 'elementor-v3-batch-mutate', $hint );
	}
```

> Verify the exact registered MCP names for the V4 abilities first (`grep -n "elementor-v4" plugin/includes/Core/AbilityRegistry.php` and the abilities' `name()` methods) and use the real names in hints and tests.

- [ ] **Step 2: Run to verify failure, then add the hint entries**

In the `RemediationHints::for_code()` per-code map add (adjust ability names to what Step 1 verification found):

```php
	'stonewright_v3_architecture_mismatch' => 'This document uses Elementor V4 Atomic nodes. Do not retry V3 mutation abilities and do not use php-execute. Read the document with stonewright/elementor-v4-read-atomic-tree, check stonewright/elementor-v4-status, and make V4 changes through the V4 abilities (classes, variables, render-from-spec) or tell the user surgical V4 element editing is not yet supported.',
	'stonewright_php_elementor_raw_write_blocked' => 'Raw Elementor writes via php-execute are permanently blocked — do not retry php-execute for this. Use stonewright/elementor-v3-batch-mutate (surgical operations) or stonewright/elementor-v3-update-element with dry_run:true first.',
```

Update the inline `repair` strings in `BatchMutate.php` (~line 190) and `BuildPageFromSpec.php` (~line 135) to the same V4 text (single source: consider referencing `RemediationHints::for_code('stonewright_v3_architecture_mismatch', ...)` instead of duplicating the literal).

- [ ] **Step 3: Run tests + gates, commit**

```bash
cd plugin && composer test && composer phpstan && composer phpcs
git add plugin/includes/Security/RemediationHints.php plugin/includes/Abilities/ElementorV3/BatchMutate.php plugin/includes/Abilities/ElementorV3/BuildPageFromSpec.php plugin/tests/Unit/Security/RemediationHintsTest.php
git commit -m "fix: concrete V4 and raw-write remediation hints replace dead-end advice"
```

### Task 1.4: Changelog + docs for Phase 1

- [ ] Add entries under Unreleased in `CHANGELOG.md` and `plugin/CHANGELOG.md` (error surfacing, task-start-first error text, remediation hints). Run `node scripts/check-docs-freshness.mjs` and `git diff --check`. Commit as `docs: changelog for Elementor error truthfulness`.

---

## Phase 2 — Memory/learning pipeline: fix silent failure, verify live

The live site has recurring error patterns but zero memory rows. Root-cause candidates (in likelihood order): (a) `put_typed()` insert failing silently against a drifted live table schema, (b) `maybe_install_table()` marked schema v3 as done even though `dbDelta` failed, (c) something throwing inside `ensure_learning_record()` swallowed upstream. Fix all three failure modes in core, then diagnose live.

### Task 2.1: Make `maybe_install_table()` verify before bumping the version

**Files:**
- Modify: `plugin/includes/Memory/Memory.php:46-83`
- Test: `plugin/tests/Unit/Memory/` — locate the existing Memory test file (`grep -rln "class .*MemoryTest" plugin/tests/`) and extend; create `plugin/tests/Unit/Memory/MemorySchemaTest.php` if none

- [ ] **Step 1: Write the failing test**

```php
	public function test_schema_version_not_bumped_when_columns_missing(): void {
		// Simulate dbDelta failure by pointing table_name at a table that was
		// never created (use a filter/option the test bootstrap provides, or
		// drop the table first — mirror how existing Memory tests reset state).
		delete_option( 'stonewright_memory_schema_version' );
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . \Stonewright\WpMcp\Memory\Memory::table_name() );

		// Sabotage: make dbDelta a no-op is not possible; instead assert on the
		// new verification helper directly.
		self::assertFalse( \Stonewright\WpMcp\Memory\Memory::table_schema_ok() );
	}

	public function test_schema_ok_after_install(): void {
		\Stonewright\WpMcp\Memory\Memory::maybe_install_table();
		self::assertTrue( \Stonewright\WpMcp\Memory\Memory::table_schema_ok() );
		self::assertSame( 3, (int) get_option( 'stonewright_memory_schema_version' ) );
	}
```

> The plugin test bootstrap (`plugin/tests/bootstrap.php`) defines how DB-backed tests run (wp-env, SQLite shim, or `$wpdb` stub). Check it first; if unit tests run without a real DB, put these in the integration suite the repo already uses for `dbDelta`-dependent tests (`grep -rn "dbDelta\|maybe_install_table" plugin/tests/` to find the pattern). Do not invent a new harness.

- [ ] **Step 2: Implement `table_schema_ok()` and the conditional bump**

```php
	/**
	 * True when the memory table exists with all v3 columns.
	 */
	public static function table_schema_ok(): bool {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $columns ) || [] === $columns ) {
			return false;
		}
		$required = [ 'id', 'scope', 'type', 'name', 'memory_key', 'value_json', 'confidence', 'topic', 'version_fingerprint', 'expires_at', 'status', 'precedence', 'created_by', 'created_at', 'updated_at' ];
		return [] === array_diff( $required, $columns );
	}
```

In `maybe_install_table()`, replace the unconditional bump:

```php
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		if ( self::table_schema_ok() ) {
			update_option( 'stonewright_memory_schema_version', self::SCHEMA_VERSION );
		} else {
			Logger::error(
				'memory_schema_install_failed',
				[ 'table' => self::table_name(), 'target_version' => self::SCHEMA_VERSION ]
			);
		}
```

Add `use Stonewright\WpMcp\Support\Logger;` at the top of the file if absent. Because the version option now stays below `SCHEMA_VERSION` on failure, the `init`-hooked `maybe_install_table()` (`plugin/includes/Core/PluginRegistration.php:100`) retries every request until the schema is healthy — this is the self-heal path for live.

- [ ] **Step 3: Run tests + gates, commit**

```bash
cd plugin && composer test && composer phpstan && composer phpcs
git add plugin/includes/Memory/Memory.php plugin/tests/Unit/Memory/MemorySchemaTest.php
git commit -m "fix: memory schema version bumps only after verified install"
```

### Task 2.2: Log and propagate `put_typed()` / learning-promotion failures

**Files:**
- Modify: `plugin/includes/Memory/Memory.php` (`put_typed()`, ~lines 208-247)
- Modify: `plugin/includes/Security/ErrorPatterns.php` (`ensure_learning_record()`, ~lines 156-192)
- Modify: `plugin/includes/Security/AuditLog.php` (~lines 66-70, the silent catch)
- Modify: `plugin/includes/Abilities/Memory/LearningRecord.php` (`execute()`, return an error when the store write fails)
- Test: extend the Memory/ErrorPatterns unit tests (`grep -rln "ErrorPatterns" plugin/tests/`)

- [ ] **Step 1: Write failing tests**

```php
	public function test_put_typed_failure_is_logged_and_returns_zero(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Memory::table_name() );
		delete_option( 'stonewright_memory_schema_version' );
		$id = Memory::put_typed( 'feedback', 'audit', 'learning-test', 'Test', [ 'x' => 1 ] );
		self::assertSame( 0, $id );
		// Assert Logger captured it — check how existing tests assert Logger output
		// (Logger may write to error_log or an in-memory sink in tests).
	}

	public function test_learning_record_returns_error_when_store_fails(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Memory::table_name() );
		delete_option( 'stonewright_memory_schema_version' );
		$ability = new \Stonewright\WpMcp\Abilities\Memory\LearningRecord();
		$result  = $ability->execute( [ 'topic' => 'X', 'correction' => 'Y' ] );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_memory_write_failed', $result->get_error_code() );
	}
```

> `LearningRecord::execute()` may be permission-gated; check how existing `plugin/tests/Unit/Memory/` tests invoke abilities (user setup, kernel wrapper) and mirror it.

- [ ] **Step 2: Implement**

In `put_typed()`, on both failure branches (`false !== $result` checks), before returning 0:

```php
		Logger::error(
			'memory_put_failed',
			[
				'scope'      => $scope,
				'memory_key' => $key,
				'type'       => $type,
				'wpdb_error' => (string) $wpdb->last_error,
				'schema_ok'  => self::table_schema_ok(),
			]
		);
```

In `ErrorPatterns::ensure_learning_record()`, capture the return and log when 0:

```php
		$row_id = Memory::put_typed( /* ...existing args unchanged... */ );
		if ( 0 === $row_id ) {
			Logger::error( 'error_pattern_learning_write_failed', [ 'key' => $key, 'ability' => $ability ] );
		}
		return $key;
```

In `AuditLog::record()`, log instead of swallowing:

```php
		try {
			ErrorPatterns::observe( $ability, $status, $sanitized_args );
		} catch ( \Throwable $t ) {
			Logger::error( 'error_patterns_observe_threw', [ 'ability' => $ability, 'error' => $t->getMessage() ] );
		}
```

In `LearningRecord::execute()`, where `Memory::put_typed()` is called (~lines 154-173), capture the id and return a structured error on 0:

```php
		if ( 0 === $row_id ) {
			return $this->error(
				'memory_write_failed',
				__( 'Learning could not be stored — the memory table is unavailable. Report this to the site owner; the Memory admin page shows schema health.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}
```

- [ ] **Step 3: Run tests + gates, commit**

```bash
cd plugin && composer test && composer phpstan && composer phpcs
git add plugin/includes/Memory/Memory.php plugin/includes/Security/ErrorPatterns.php plugin/includes/Security/AuditLog.php plugin/includes/Abilities/Memory/LearningRecord.php plugin/tests/
git commit -m "fix: memory writes fail loudly — log, propagate, and self-heal schema"
```

### Task 2.3: Memory admin page schema-health notice

**Files:**
- Modify: `plugin/includes/Admin/MemoryInstructionsPage.php` (render method, near the top of the page output, ~line 91 where `$mem_enabled` is read)
- Test: if admin pages have render tests (`grep -rln "MemoryInstructionsPage" plugin/tests/`), extend; otherwise cover `table_schema_ok()` display logic via the existing page test pattern or skip UI test with a note in the PR

- [ ] **Step 1: Add a visible error notice when the schema is broken**

```php
		if ( ! Memory::table_schema_ok() ) {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Stonewright memory table is missing or outdated. Learning promotion and memory abilities cannot store entries. Deactivate and reactivate the plugin, or check database ALTER/CREATE permissions, then reload this page.', 'stonewright' );
			echo '</p></div>';
		}
```

- [ ] **Step 2: Gates + commit**

```bash
cd plugin && composer test && composer phpcs
git add plugin/includes/Admin/MemoryInstructionsPage.php plugin/tests/
git commit -m "feat: memory admin page surfaces schema health"
```

### Task 2.4: End-to-end unit test — two errors auto-create a learning

**Files:**
- Test: `plugin/tests/Unit/Security/ErrorPatternsPromotionTest.php` (new, or extend existing ErrorPatterns test)

- [ ] **Step 1: Write the test (fails only if promotion is broken — should pass after 2.1/2.2)**

```php
	public function test_two_identical_errors_create_active_learning_row(): void {
		Memory::maybe_install_table();
		delete_option( 'stonewright_error_patterns' );

		$args = [ 'error_code' => 'stonewright_demo_failure', 'message' => 'Demo failed' ];
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );
		ErrorPatterns::observe( 'stonewright/demo-ability', 'error', $args );

		$rows = Memory::list_by_type( 'feedback', 50, 0 );
		$keys = array_column( $rows, 'memory_key' );
		$hit  = array_filter( $keys, static fn( $k ) => str_starts_with( (string) $k, 'learning-audit-error-' ) );
		self::assertNotEmpty( $hit, 'count>=2 must auto-create a learning-audit-error-* memory row' );
	}
```

> Check `Memory::list_by_type()` exact signature/return shape before asserting (`plugin/includes/Memory/Memory.php:257-277`).

- [ ] **Step 2: Run, fix anything it exposes, commit**

```bash
cd plugin && vendor/bin/phpunit tests/Unit/Security/ErrorPatternsPromotionTest.php && composer test
git add plugin/tests/Unit/Security/ErrorPatternsPromotionTest.php
git commit -m "test: recurring errors auto-promote to memory learnings"
```

### Task 2.5: Live diagnosis + verification on transavia.ro (after Phases 1–2 are deployed)

This task runs against the live site through the Stonewright MCP (plugin mode). Follow repo MCP rules: call `stonewright-task-start` first; use `stonewright-php-execute` for runtime inspection (first-class, read-only here); no REST/shell workarounds.

- [ ] **Step 1: Diagnose current live state (before deploying the fix, to confirm root cause)**

Via `stonewright-php-execute` (read-only):

```php
global $wpdb;
$table = $wpdb->prefix . 'stonewright_memory';
return [
  'schema_version_option' => get_option( 'stonewright_memory_schema_version' ),
  'table_exists' => (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
  'columns' => $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ),
  'row_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
  'last_db_error' => $wpdb->last_error,
  'memory_enabled' => get_option( 'stonewright_memory_enabled', true ),
  'error_patterns_count' => count( (array) get_option( 'stonewright_error_patterns', [] ) ),
];
```

Record the findings in the PR description. Expected confirmation: patterns > 0 while row_count = 0, plus either missing table/columns or a schema-version/columns mismatch.

- [ ] **Step 2: After deploying the fixed build, verify self-heal**

Reload any wp-admin page twice (triggers `init` → `maybe_install_table()`), then re-run the snippet: `table_exists=true`, all 15 columns present, `schema_version_option=3`. Then trigger promotion: call `stonewright-learning-record` with `{ "topic": "post-deploy smoke", "correction": "Memory pipeline verified after schema fix", "severity": "low" }` and confirm the row appears on `wp-admin/admin.php?page=stonewright-memory` (Feedback tab) and in `stonewright-memory-list`.

- [ ] **Step 3: Confirm recurring-error learnings backfill**

The existing 8×/3×/2× patterns will re-promote on their next occurrence only. To backfill immediately, run via `stonewright-php-execute` (write, needs context token from task-start):

```php
$patterns = (array) get_option( 'stonewright_error_patterns', [] );
$made = [];
foreach ( $patterns as $sig => $row ) {
	if ( (int) ( $row['count'] ?? 0 ) >= 2 && empty( $row['dismissed'] ) ) {
		\Stonewright\WpMcp\Security\ErrorPatterns::observe( (string) $row['ability'], 'error', [ 'error_code' => (string) $row['error_code'], 'message' => (string) $row['message'] ] );
		$made[] = $sig;
	}
}
return $made;
```

> Note: this increments each pattern's count by one — acceptable one-time cost; mention it in the PR. Verify rows exist afterward via `stonewright-memory-list`.

### Task 2.6: Changelog + docs for Phase 2

- [ ] Update both changelogs; update `docs/knowledge-learning-lifecycle.md` if its retrieval/promotion description changed (it should not — behavior now matches the doc). Run `node scripts/check-docs-freshness.mjs` and `git diff --check`. Commit.

---

## Phase 3 — Retry-storm brake (plugin)

Observed live: the same blocked php-execute error 8×. The block worked; the *pacing* didn't. Escalate the error message when the same signature repeats within a session, at one central point.

### Task 3.1: Escalating error envelope in the ability execution wrapper

**Files:**
- Modify: `plugin/includes/Core/AbilityRegistry.php` (`execute_with_context_guard()`, ~lines 702-720)
- Modify: `plugin/includes/Security/ErrorPatterns.php` (add a public lookup)
- Test: `plugin/tests/Unit/Security/RetryEscalationTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
	public function test_repeated_error_gets_escalation_data(): void {
		delete_option( 'stonewright_error_patterns' );
		$args = [ 'error_code' => 'stonewright_php_elementor_raw_write_blocked', 'message' => 'Raw Elementor document writes are blocked in php-execute.' ];
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );
		ErrorPatterns::observe( 'stonewright/php-execute', 'error', $args );

		$count = ErrorPatterns::occurrence_count( 'stonewright/php-execute', $args );
		self::assertGreaterThanOrEqual( 2, $count );

		$err = new \WP_Error( 'stonewright_php_elementor_raw_write_blocked', 'Raw Elementor document writes are blocked in php-execute.' );
		$escalated = ErrorPatterns::escalate_error( 'stonewright/php-execute', $err, $args );
		self::assertStringContainsString( 'STOP', $escalated->get_error_message() );
		$data = (array) $escalated->get_error_data();
		self::assertArrayHasKey( 'occurrences', $data );
		self::assertArrayHasKey( 'repair', $data );
	}
```

- [ ] **Step 2: Implement in `ErrorPatterns`**

```php
	/**
	 * Occurrences recorded for this ability+args signature.
	 */
	public static function occurrence_count( string $ability, array $sanitized_args ): int {
		$store = self::load();
		$sig   = self::signature( $ability, $sanitized_args );
		return (int) ( $store[ $sig ]['count'] ?? 0 );
	}

	/**
	 * When the same error signature repeats, prepend a hard-stop instruction and
	 * attach the remediation hint so the agent changes strategy instead of retrying.
	 */
	public static function escalate_error( string $ability, \WP_Error $error, array $sanitized_args ): \WP_Error {
		$count = self::occurrence_count( $ability, $sanitized_args );
		if ( $count < 2 ) {
			return $error;
		}
		$code   = $error->get_error_code();
		$repair = RemediationHints::for_code( (string) $code, $ability );
		$message = sprintf(
			/* translators: 1: occurrence count, 2: original message, 3: repair hint */
			__( 'STOP: this exact error occurred %1$d times — do not retry the same call. %2$s Next step: %3$s', 'stonewright' ),
			$count,
			$error->get_error_message(),
			$repair
		);
		$data = array_merge( (array) $error->get_error_data(), [ 'occurrences' => $count, 'repair' => $repair ] );
		return new \WP_Error( $code, $message, $data );
	}
```

- [ ] **Step 3: Wire it into the single execution path**

In `AbilityRegistry::execute_with_context_guard( Ability $ability, array $input )` (`plugin/includes/Core/AbilityRegistry.php:702`) — the wrapper already sees every ability result. After the wrapped `execute` returns, before handing the result back:

```php
		if ( $result instanceof \WP_Error ) {
			$result = ErrorPatterns::escalate_error( $ability->name(), $result, $input );
		}
		return $result;
```

> Verify the wrapper's actual variable names and where the audit record happens relative to this return (audit runs via `AbilityKernel`; escalation must not change what gets audited as the signature — pass the same `$sanitized_args` shape `signature()` uses. Read `ErrorPatterns::signature()` (~line 110) first: it derives from ability + error_code + message; ensure `escalate_error`'s lookup matches how `observe()` stored it — derive the signature from the WP_Error itself if needed: `[ 'error_code' => $error->get_error_code(), 'message' => $error->get_error_message() ]`.)

- [ ] **Step 4: Run tests + gates, commit**

```bash
cd plugin && composer test && composer phpstan && composer phpcs
git add plugin/includes/Core/AbilityRegistry.php plugin/includes/Security/ErrorPatterns.php plugin/tests/Unit/Security/RetryEscalationTest.php
git commit -m "feat: repeated identical ability errors escalate with hard-stop guidance"
```

### Task 3.2: Direct-mode equivalent (companion)

**Files:**
- Modify: `companion/src/direct/audit.ts` (`recentRecurringErrors()` already groups by tool; add per-signature lookup)
- Modify: the shared Direct error-return path — find it via `grep -n "appendDirectAudit" companion/src/direct/*.ts companion/src/direct/tools/*.ts` and identify the single choke point where tool errors are returned (if none exists, wrap in the registry dispatch in `companion/src/direct/registry.ts`)
- Test: `companion/tests/direct-error-escalation.test.ts` (new)

- [ ] **Step 1: Write the failing test** (mirror `companion/tests/direct-error-audit.test.ts` setup for the JSONL audit file):

```typescript
import { describe, expect, it } from 'vitest';
import { escalateDirectError } from '../src/direct/audit.js';

describe('direct error escalation', () => {
  it('prepends STOP guidance after two identical failures', () => {
    const first = escalateDirectError('stonewright-elementor-data-update', { ok: false, error: 'x_failed', message: 'Update failed.' }, 1);
    expect(first.message).not.toContain('STOP');
    const third = escalateDirectError('stonewright-elementor-data-update', { ok: false, error: 'x_failed', message: 'Update failed.' }, 3);
    expect(third.message).toContain('STOP');
    expect(third.occurrences).toBe(3);
  });
});
```

- [ ] **Step 2: Implement `escalateDirectError(tool, errorResult, occurrences)`** in `audit.ts` (pure function taking the count so it is trivially testable), plus a counting helper reading the existing JSONL grouping. Wire it where Direct tool errors are returned. Keep the wording aligned with the plugin: `STOP: this exact error occurred N times — do not retry the same call. <original>. Next step: <hint from the static hint table>`.

- [ ] **Step 3: Run + commit**

```bash
cd companion && npm run typecheck && npm test
git add companion/src/direct/audit.ts companion/src/direct/registry.ts companion/tests/direct-error-escalation.test.ts
git commit -m "feat: Direct mode escalates repeated identical tool errors"
```

### Task 3.3: Changelog for Phase 3

- [ ] Both changelogs, PR gate statement ("no gates weakened; error envelopes enriched"). Commit.

---

## Phase 4 — task-start reaches every client, in both modes

The user's explicit ask: when Stonewright MCP is used, task-start context should arrive automatically instead of depending on the client's memory.

### Task 4.1: Companion proxy forwards the plugin's `initialize.instructions`

**Files:**
- Modify: `companion/src/wordpress-mcp.ts` (`WordPressMcpClient.ensureInitialized()`, ~lines 1092-1106, currently discards the initialize result)
- Modify: `companion/src/mcp-server.ts` (~line 121, `companionInstructions(profile)`)
- Test: `companion/tests/mcp-server.test.ts` (extend) or new `companion/tests/instructions-forwarding.test.ts`

- [ ] **Step 1: Write the failing test** — assert that when the proxied WP initialize result contains `instructions`, the companion's exposed instructions include both the companion's own text and the plugin's bootstrap summary (which contains the literal line about calling `stonewright-task-start` first). Mirror the existing mock-transport pattern in `companion/tests/mcp-server.test.ts`.

- [ ] **Step 2: Implement** — capture `result.instructions` in `ensureInitialized()`, store on the client instance (`this.remoteInstructions`), and expose a getter. In `mcp-server.ts`, when the WordPress proxy is active, build the server `instructions` as:

```typescript
const remote = wordpressClient?.remoteInstructions?.trim();
const instructions = remote
  ? `${companionInstructions(profile)}\n\n--- WordPress plugin instructions ---\n${remote}`
  : companionInstructions(profile);
```

> Timing caveat: the `McpServer` is constructed before the remote initialize may have completed. Check whether the SDK allows setting instructions after construction; if not, perform the remote initialize eagerly during companion startup before constructing the server (the connection is needed anyway), and fall back to static text when the site is unreachable. Whichever mechanism, cover it with the test.

- [ ] **Step 3: Run + gates + commit**

```bash
cd companion && npm run typecheck && npm test && npm run tokens:measure
git add companion/src/wordpress-mcp.ts companion/src/mcp-server.ts companion/tests/
git commit -m "feat: companion forwards plugin MCP instructions to clients"
```

### Task 4.2: Direct-mode task-start latch gets a TTL and site-scope

**Files:**
- Modify: `companion/src/direct/writes.ts` (process-lifetime boolean → per-site timestamped latch, 30-min TTL to match plugin token)
- Modify: `companion/src/direct/tools/self-improve.ts` (`markTaskStartSeen()` call site passes the site alias)
- Test: extend `companion/tests/direct-taskstart-gate.test.ts`

- [ ] **Step 1: Write failing tests** — (a) write blocked again after TTL expiry (inject a clock: give `assertWriteAllowed`/`markTaskStartSeen` an optional `now` parameter defaulting to `Date.now()`, matching how existing companion tests stub time — check first with `grep -rn "vi.useFakeTimers\|now()" companion/tests/ | head`); (b) task-start for site A does not unlock writes for site B.

```typescript
it('re-requires task-start after 30 minutes', () => {
  resetTaskStartSeenForTests();
  markTaskStartSeen('site-a', 0);
  expect(() => assertWriteAllowed({ site: 'site-a', now: 29 * 60_000 })).not.toThrow();
  expect(() => assertWriteAllowed({ site: 'site-a', now: 31 * 60_000 })).toThrow(/task-start/);
});

it('task-start is per site', () => {
  resetTaskStartSeenForTests();
  markTaskStartSeen('site-a', 0);
  expect(() => assertWriteAllowed({ site: 'site-b', now: 1000 })).toThrow(/task-start/);
});
```

> `assertWriteAllowed`'s current args shape is in `companion/src/direct/writes.ts` (74 lines — read it fully). Extend the existing args object; do not break the dozens of call sites — give `site` and `now` safe defaults (`site` falls back to the current single-site alias the tools already know; find how tools identify the site via `grep -n "siteAlias\|site:" companion/src/direct/tools/content.ts | head`).

- [ ] **Step 2: Implement** — `let taskStartSeenAt: Record<string, number> = {}`; `markTaskStartSeen(site, now = Date.now())` stamps; `assertWriteAllowed` checks `now - seenAt <= 30 * 60_000` for the target site; error message unchanged plus `It also re-arms 30 minutes after the last task-start.` Keep `STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off` escape hatch as-is.

- [ ] **Step 3: Run + commit**

```bash
cd companion && npm run typecheck && npm test
git add companion/src/direct/writes.ts companion/src/direct/tools/self-improve.ts companion/tests/direct-taskstart-gate.test.ts
git commit -m "feat: Direct task-start latch is per-site with 30-minute TTL"
```

### Task 4.3: First-read nudge — reads hint at task-start without blocking

Read-only sessions currently get zero task-start signal in both modes. Add a **non-blocking** one-line hint to read-ability responses until task-start has run.

**Files (plugin):**
- Modify: `plugin/includes/Core/AbilityRegistry.php` (`execute_with_context_guard()` — same wrapper as Task 3.1)
- Test: `plugin/tests/Unit/Core/TaskStartNudgeTest.php` (new)

- [ ] **Step 1: Write the failing test**

```php
	public function test_read_result_carries_task_start_hint_before_session_start(): void {
		// Simulate: no session tool profile set (no task-start yet this session).
		// Execute a read-only ability through the registry wrapper and assert the
		// array result contains the hint key.
		$result = /* invoke wrapper around a trivial read ability — mirror how
		            AbilityRegistryBootstrapModeTest.php executes abilities */;
		self::assertIsArray( $result );
		self::assertArrayHasKey( 'task_start_hint', $result );
		self::assertStringContainsString( 'stonewright-task-start', (string) $result['task_start_hint'] );
	}

	public function test_hint_absent_after_task_start(): void {
		\Stonewright\WpMcp\Core\AbilityRegistry::set_session_tool_profile( 'essential', [] );
		$result = /* same invocation */;
		self::assertArrayNotHasKey( 'task_start_hint', $result );
	}
```

> Session detection: `AbilityRegistry::session_tool_profile()` (~lines 961-1016) already tracks whether task-start applied a session profile. Read it and `plugin/tests/Unit/Core/AbilityRegistryBootstrapModeTest.php` first; use the same session-id mechanics. If session state is only set when the configured surface is `bootstrap`, key the nudge instead on a cheap session transient that `TaskStart`/`WorkflowPreflight`/`ContextBootstrap` set (`stonewright_task_started_<session>` — same HMAC session-id helper) so it works on all surfaces; set it inside `WorkflowPreflight::execute()` where the context token is issued.

- [ ] **Step 2: Implement in the wrapper** (single point, applies to every ability):

```php
		$name = $ability->name();
		if (
			is_array( $result )
			&& ! self::session_task_started() // new private helper: reads the session transient set by WorkflowPreflight (see note above)
			&& in_array( $name, self::context_exempt_abilities(), true ) // read-only set, private method at AbilityRegistry.php:820 — same class, callable directly
			&& ! in_array( $name, [ 'stonewright/task-start', 'stonewright/workflow-preflight', 'stonewright/context-bootstrap' ], true )
		) {
			$result['task_start_hint'] = __( 'Session not initialized: call stonewright-task-start with your task description to load site skills, memory, recurring errors, and the write token.', 'stonewright' );
		}
```

Token-budget check: the hint is one short key on read responses only, and disappears after task-start. Run `cd plugin && composer tokens:measure` — task-start compact budgets (800/1200) are unaffected because task-start responses never carry the hint.

**Files (companion Direct):** same concept — in the Direct registry dispatch, when `taskStartSeen` is false/expired for the site and the tool is read-only (not in the write-gated set), append `task_start_hint` to the JSON result. Test alongside Task 4.2's file.

- [ ] **Step 3: Run all gates + commit**

```bash
cd plugin && composer test && composer phpstan && composer phpcs && composer tokens:measure
cd ../companion && npm run typecheck && npm test && npm run tokens:measure
git add plugin/includes/Core/AbilityRegistry.php plugin/includes/Abilities/System/WorkflowPreflight.php plugin/tests/Unit/Core/TaskStartNudgeTest.php companion/src/direct/ companion/tests/
git commit -m "feat: non-blocking task-start nudge on pre-session read calls"
```

### Task 4.4: Changelog + docs for Phase 4

- [ ] Update both changelogs; update `docs/architecture.md` (instructions forwarding), `docs/companion.md` (latch TTL). Freshness script + `git diff --check`. Commit.

---

## Phase 5 — Token efficiency: bounded Elementor reads everywhere

### Task 5.1: Direct `elementor-data-get` gains `responseMode` summary (default) + cap

**Files:**
- Modify: `companion/src/direct/tools/elementor-direct.ts` (`elementorDataGet()`, ~lines 225-321; registry schema for the tool in `companion/src/direct/registry.ts`)
- Modify: `companion/src/direct/tools/elementor-direct.ts` (`elementorDataUpdate()` internal backup read must keep using the FULL tree — factor the raw read into a private `readFullTree()` used by both)
- Test: extend `companion/tests/direct-elementor.test.ts`

- [ ] **Step 1: Write failing tests**

```typescript
it('elementor-data-get defaults to summary outline with cap', async () => {
  // reuse the existing WP-CLI/REST mocks in this file that return a parsed tree
  const result = await elementorDataGet({ postId: 1, /* existing mock args */ });
  expect(result.response_mode).toBe('summary');
  expect(result.outline).toBeDefined();
  expect(result.data).toBeUndefined();
  expect(result.element_count).toBeGreaterThan(0);
  expect(result.full_mode_hint).toContain('responseMode');
});

it('elementor-data-get responseMode=full returns the tree', async () => {
  const result = await elementorDataGet({ postId: 1, responseMode: 'full' });
  expect(result.data).toBeDefined();
});

it('summary outline truncates at maxElements', async () => {
  const result = await elementorDataGet({ postId: 1, maxElements: 2 });
  expect(result.truncated).toBe(true);
});
```

- [ ] **Step 2: Implement** — mirror the plugin's `GetPageStructure` summary shape exactly (`plugin/includes/Abilities/ElementorV3/GetPageStructure.php`): flatten to `outline` rows `{ id, elType, widgetType, label, depth, settings_keys }`, `maxElements` default 200 max 500, `label` ≤ 80 chars, `settings_keys` ≤ 30, `truncated` flag, `tree_omitted: true`, `full_mode_hint`. Update the tool's inputSchema in `registry.ts` with `responseMode` (enum `summary|full`, default `summary`) and `maxElements`. **Backup path in `elementorDataUpdate()` keeps reading the full tree internally — never summarize what gets written to `~/.stonewright/backups/`.**

- [ ] **Step 3: Contract check** — `docs/contracts/direct-tools-v1.json` freezes the Direct surface. Adding input properties is additive; confirm the contract test tolerance (`companion/tests/direct-tools-contract.test.ts`) and regenerate the contract snapshot per its documented procedure (see the header of the contract file / the test) rather than hand-editing.

- [ ] **Step 4: Run + commit**

```bash
cd companion && npm run typecheck && npm test && npm run tokens:measure
git add companion/src/direct/tools/elementor-direct.ts companion/src/direct/registry.ts companion/tests/direct-elementor.test.ts docs/contracts/direct-tools-v1.json
git commit -m "feat: Direct elementor-data-get defaults to capped summary outline"
```

### Task 5.2: Cap `elementor-v4-read-atomic-tree` (plugin)

**Files:**
- Modify: `plugin/includes/Abilities/ElementorV4/ReadAtomicTree.php` (90 lines — read fully first)
- Test: `plugin/tests/Unit/` — extend the existing V4 test file (`grep -rln "ReadAtomicTree" plugin/tests/`)

- [ ] **Step 1: Failing test** — summary default returns `outline` + `truncated` + no `atomic_tree`; `responseMode: full` returns `atomic_tree`; `max_nodes` default 200 caps output.

- [ ] **Step 2: Implement** — same summary shape as Task 5.1 (id/type/label/depth rows from `AtomicTreeInspector::inspect()` output — read that inspector's return shape first). Schema additions: `responseMode` enum summary|full default summary, `max_nodes` integer default 200 max 500.

- [ ] **Step 3: Gates + commit**

```bash
cd plugin && composer test && composer phpstan && composer phpcs && composer tokens:measure
git add plugin/includes/Abilities/ElementorV4/ReadAtomicTree.php plugin/tests/
git commit -m "feat: V4 atomic tree read gains summary mode and node cap"
```

### Task 5.3: Shared tree-summary utility (deduplicate compaction logic)

**Files:**
- Create: `plugin/includes/Support/TreeSummary.php`
- Modify: `plugin/includes/Abilities/ElementorV3/GetPageStructure.php`, `plugin/includes/Abilities/ElementorV4/ReadAtomicTree.php` (from 5.2) to delegate
- Test: `plugin/tests/Unit/Support/TreeSummaryTest.php` (new)

- [ ] **Step 1: Failing test** — outline rows, cap behavior, label truncation at 80, settings_keys cap at 30, `estimated_tokens` = `ceil(strlen(json)/4)` on its own output (adopt PageDigest's self-reporting pattern).

- [ ] **Step 2: Implement** `TreeSummary::outline( array $tree, int $max_elements, callable $row_mapper ): array` returning `{ outline, count, returned_count, truncated, estimated_tokens }`. Move the walk logic out of `GetPageStructure` (behavior-identical — its existing tests must keep passing untouched; if any output key would change, stop and keep the old shape).

- [ ] **Step 3: Gates + commit**

```bash
cd plugin && composer test && composer phpstan && composer phpcs
git add plugin/includes/Support/TreeSummary.php plugin/includes/Abilities/ plugin/tests/Unit/Support/TreeSummaryTest.php
git commit -m "refactor: shared TreeSummary utility backs Elementor tree reads"
```

### Task 5.4: Changelog + benchmark doc note

- [ ] Both changelogs. Add one line to `docs/benchmarks/README.md` documenting the new summary-mode defaults. Freshness script. Commit.

---

## Phase 6 — Dual-Figma-MCP extraction guidance (skills + prompts)

Pure documentation/skill work — the DesignEvidence contract already accepts everything; agents just lack instructions on *which* external Figma tools populate *which* evidence fields. Repo rule reminder: Figma is an integration (nameable); do not name competing WordPress AI products anywhere.

### Task 6.1: Write the extraction reference

**Files:**
- Create: `skills/design-to-wordpress/references/figma-mcp-extraction.md`

- [ ] **Step 1: Author the file** with this structure and content (verify current tool names against the live MCP surfaces at execution time; the figma-console names below were verified 2026-07-22, official Figma MCP names must be re-checked against its current release):

```markdown
# Figma MCP extraction map for DesignEvidence 1.0

Stonewright never calls Figma. The AI client extracts design facts with
whatever Figma MCP servers it has, normalizes them into DesignEvidence 1.0,
and hands that to `stonewright-design-native-plan`. This reference maps
evidence fields to the best extraction tool when one or both common Figma
MCP servers are connected.

Detect what you have: list your MCP tools. Official Figma MCP tools are
prefixed by the Figma server name (commonly `get_code`, `get_image`,
`get_variable_defs`, `get_metadata`); a console-bridge Figma MCP exposes a
wider `figma_*` surface (e.g. `figma_export_tokens`,
`figma_get_design_system_summary`, `figma_capture_screenshot`).

| DesignEvidence field | Console-bridge MCP (preferred when present) | Official Figma MCP | Neither |
| --- | --- | --- | --- |
| `global.color_tokens`, `figma_token_table` | `figma_export_tokens` / `figma_get_token_values` (full variable collections incl. modes) | `get_variable_defs` on the selected frame | Sample screenshot pixels; mark provenance `inference`, `requires_confirmation: true` |
| `global.typography_ramp` | `figma_get_design_system_summary` + `figma_get_text_styles` | `get_variable_defs` + `get_code` on text nodes | Measure from screenshot; `inference` |
| `nodes[].bounds`, `layout` | `figma_get_component_for_development_deep` / `figma_get_file_data` (absolute bounds, auto-layout props) | `get_metadata` (node positions/sizes) | Screenshot measurement; `inference` |
| `measured_targets[]` | Same as bounds source — record px values + `tolerance_px` | Same | Screenshot measurement |
| `sources[].ref` + screenshot hash | `figma_capture_screenshot` / `figma_take_screenshot` per viewport | `get_image` per frame | User-provided screenshot |
| `nodes[].content` (copy) | `figma_get_file_data` text chars | `get_code` output text | User brief |
| Post-write verification | `figma_check_design_parity` against the live URL, plus your browser MCP screenshots | `get_image` + browser MCP screenshot diff | Browser MCP screenshots vs. reference |

Rules that always apply (from the parent skill):
- Screenshots stay the layout source of truth. Figma layer structure,
  group names, and auto-layout nesting are hints, never authoritative
  (`source_authority` in the Stonewright context).
- Never pass a raw Figma document/node tree into any Stonewright ability —
  normalize into DesignEvidence first. Raw trees are rejected.
- Two viewports minimum (desktop + mobile) whenever a visual source exists.
- Every non-neutral style needs provenance; token values extracted from
  variables get provenance `design`, screenshot-measured values get
  `inference` + `requires_confirmation`.

Efficiency rules (token budget):
- One `figma_export_tokens`-style call beats dozens of per-node color reads.
  Prefer collection-level extraction, then per-node reads only for gaps.
- Extract per top-level section/frame, not per leaf node. Leaf detail comes
  from the deep component call for that section only.
- Do not re-fetch the Figma file after normalization; DesignEvidence is the
  working artifact from then on.
```

- [ ] **Step 2: Verify tool-name accuracy** — at execution time, list the connected Figma MCP servers' tools (the executor's client may have both) and correct any renamed tools in the table before committing. If the official Figma MCP is not connectable for verification, keep names but add "(verify against your server's tool list)" once at the top of the table.

### Task 6.2: Wire the reference into the skill + prompt catalog

**Files:**
- Modify: `skills/design-to-wordpress/SKILL.md` (step 1 "Extract", ~line 53)
- Modify: `plugin/data/prompts/catalog.json` (~lines 287-303, `figma-to-native-pixel` prerequisites)
- Test: `plugin/tests/Unit/BuiltInSkillFilesTest.php` (extend if it validates skill structure), plus `Skills::lint()` must pass on the updated skill (it validates `stonewright/xxx` ability references)

- [ ] **Step 1: SKILL.md edit** — replace the single sentence at ~line 53 with:

```markdown
1. **Extract.** Use the client's Figma MCP to read frames, tokens, and
   bounds. Do not pass the raw document into Stonewright. When more than
   one Figma MCP is connected, follow
   `references/figma-mcp-extraction.md`
   to pick the right tool per evidence field and to keep extraction to a
   handful of collection-level calls instead of per-node reads.
```

- [ ] **Step 2: catalog.json prerequisites** — extend the `figma-to-native-pixel` entry's prerequisites string to mention the extraction reference by skill path.

- [ ] **Step 3: Seeder check** — `SkillsSeeder::seed()` upserts builtin skills from `skills/` on activation/upgrade. Verify the reference file ships: check how `SkillsSeeder` handles `references/` subdirectories (`plugin/includes/Skills/SkillsSeeder.php:96-130`) — if only `SKILL.md` content is seeded into the DB, ensure SKILL.md inlines the decision table's essence or the seeder includes reference files; add a seeder test if behavior changes. Also confirm the release packaging includes `skills/design-to-wordpress/references/` (check `wp-plugin-packaging` config / release workflow file list).

- [ ] **Step 4: Gates + commit**

```bash
cd plugin && composer test && composer phpcs
git add skills/design-to-wordpress/ plugin/data/prompts/catalog.json plugin/tests/
git commit -m "docs: dual Figma MCP extraction map for design-to-wordpress"
```

---

## Phase 7 — Onboarding docs: Direct mode first-class, correct smoke tests

### Task 7.1: Fix `docs/onboarding.md` and `docs/getting-started/claude-code.md`

**Files:**
- Modify: `docs/onboarding.md` (169 lines)
- Modify: `docs/getting-started/claude-code.md` (121 lines)

- [ ] **Step 1: onboarding.md** — add a mode-selection section at the top:

```markdown
## Choose your mode

- **Plugin mode** (full capability): install the Stonewright WordPress
  plugin. Elementor engines, DesignSpec rendering, php-execute, shared
  site memory/skills, audit UI.
- **Direct mode** (no plugin): the companion serves 99 typed tools over
  core REST/WP-CLI with an Application Password. Content, media, menus,
  templates, taxonomy, users, raw Elementor document edits with integrity
  gates. Not available without the plugin: Elementor batch engines,
  DesignSpec rendering, php-execute, confirmation tokens, content-model
  registration, shared site memory.
- The companion auto-detects the mode (`STONEWRIGHT_MODE=direct|plugin`
  overrides; otherwise it probes the plugin MCP endpoint).
```

Fix the smoke-test steps: canonical first call is `stonewright-task-start` (works in both modes); `stonewright-context-bootstrap` mentioned only as compatibility; drop `stonewright-ping` as the primary verification in favor of `stonewright-task-start` returning `ok: true` (keep ping as plugin-mode extra).

- [ ] **Step 2: claude-code.md** — add a "Fastest start (Direct mode)" section before the plugin install section, using the config snippet style already present in `docs/install-prompts.md` Option B (copy the working snippet from there — do not invent flags). Update the verification section to `stonewright-task-start`.

- [ ] **Step 3: Cross-check counts** — any tool/ability counts cited must come from `docs/ability-truth-matrix.md` (regenerate only via `composer docs:matrix` if the matrix is stale — do not hand-edit).

### Task 7.2: Create `docs/getting-started/cursor.md`

- [ ] Mirror the structure of `docs/getting-started/claude-code.md` (post-7.1 version). Source the Cursor config snippet from `docs/admin/connect-clients.md:171` (verified working snippet — copy, do not invent) and the verified client versions from `docs/verified-client-versions.md:11`. Cover both modes, task-start-first verification, and the low-tools profile note for strict-cap clients.

### Task 7.3: Direct-mode expectation note in expertise docs

- [ ] In `docs/expertise-parity.md`, add one paragraph: expertise packs and evidence-graded guidance are plugin-mode; Direct mode's local skills/memory are functional but unscored — link to `docs/direct-mode-e2e.md`'s capability matrix.

### Task 7.4: Docs gates + commit

```bash
node scripts/check-docs-freshness.mjs && git diff --check
git add docs/
git commit -m "docs: dual-mode onboarding, cursor guide, task-start-first smoke tests"
```

---

## Phase 8 — Direct parity regression tests (companion)

### Task 8.1: Blueprint Elementor-engine rejection test

**Files:**
- Test: extend the blueprints test file (`grep -rln "applyBlueprint\|blueprint" companion/tests/ | head`; create `companion/tests/direct-blueprints-engine.test.ts` if none)

- [ ] Write and commit:

```typescript
import { describe, expect, it } from 'vitest';
import { applyBlueprint } from '../src/direct/tools/blueprints.js';

describe('direct blueprint engine gate', () => {
  it('rejects engine: elementor with elementor_requires_plugin', async () => {
    const result = await applyBlueprint({ /* minimal valid args — mirror existing blueprint test fixtures */ engine: 'elementor' });
    expect(result.ok).toBe(false);
    expect(result.error).toBe('elementor_requires_plugin');
  });

  it('tags successful applies as gutenberg engine', async () => {
    const result = await applyBlueprint({ /* fixture args */ engine: 'auto' });
    if (result.ok) {
      expect(result.engine).toBe('gutenberg');
    }
  });
});
```

> `applyBlueprint`'s signature and required context (site config, fetch mocks) — read `companion/src/direct/tools/blueprints.ts:66-136` and copy the mock scaffolding from whichever existing test exercises Direct tools with fetch mocks (`companion/tests/direct-tools-content.test.ts` has the established pattern).

### Task 8.2: `acf-fields-get` / `acf-fields-update` and `seo-head-get` handler tests

- [ ] Locate handlers (`companion/src/direct/tools/acf.ts`, `companion/src/direct/tools/` for seo-head — confirm with `grep -rn "acf-fields-get\|seo-head-get" companion/src/direct/`). Write happy-path + error-path tests using the wave2/content test mock pattern: get returns fields for a post, update requires the write gate (`assertWriteAllowed` — compose with Task 4.2's helper), seo-head-get returns head payload for a URL. Commit as `test: cover Direct ACF and seo-head tools`.

### Task 8.3: Run the full companion suite

```bash
cd companion && npm run typecheck && npm test && npm run tokens:measure
git add companion/tests/
git commit -m "test: Direct parity gates for blueprints, ACF, seo-head"
```

---

## Phase 9 — Elementor V4 surgical editing (investigation-gated)

The live failure's deepest root: V4 documents have no surgical write path. This is the largest work item; do it after everything above ships. **Gate: Task 9.1's findings decide 9.2's scope. Do not skip 9.1.**

### Task 9.1: Investigation (no production code)

- [ ] Answer, with file:line evidence written into `docs/plans/evidence/v4-surgical-write-findings.md` (new file):
  1. What does `AtomicTreeInspector::inspect()` return for a real V4 document (node shape, id scheme, settings model)? Use fixtures under `plugin/tests/fixtures/` if present; otherwise pull a sanitized sample from a staging site via `stonewright-elementor-v4-read-atomic-tree`.
  2. Does `SettingsValidator::validate_tree()` accept a V4 atomic tree, and does `DocumentIntegrityGate::assert_write_allowed()` pass V4→V4 writes? (Both run inside `ElementorData::write()` — a V4 write path must survive them or get an explicit, gated bypass that preserves double-encode/size-collapse checks.)
  3. What does `plugin/includes/Elementor/V4/AtomicSchemaRepository.php` expose for per-widget setting validation?
  4. How does Elementor v4 itself persist atomic settings (styles as classes/variables vs. inline settings) — from `docs/elementor-v4-engine.md` and the schema repository, not from guesses.
- [ ] Commit the findings doc: `docs: V4 surgical write investigation findings`.

### Task 9.2: Implement `stonewright/elementor-v4-update-node` (scope per 9.1)

Minimal viable scope — update settings/content of ONE atomic node by id, with snapshot, integrity gate, dry-run, and readback:

**Files:**
- Create: `plugin/includes/Abilities/ElementorV4/UpdateNode.php`
- Modify: `plugin/includes/Core/AbilityRegistry.php` (register; add to `elementor-design` profile band in `ToolProfile::profile_tools()`)
- Modify: `plugin/includes/Security/RemediationHints.php` + `BatchMutate.php`/`BuildPageFromSpec.php` mismatch text (from Task 1.3) to point at the new ability
- Test: `plugin/tests/Unit/Elementor/V4UpdateNodeTest.php`

- [ ] **Step 1: Failing tests first** — (a) dry-run returns planned diff without writing; (b) write path: snapshot via `Backup::snapshot_post()` before mutation, readback verified, `snapshot_id` in response; (c) unknown node id → structured error with live-id hint; (d) V3 document → `v4_architecture_mismatch` error mirroring the V3 gate; (e) settings validated against `AtomicSchemaRepository` (exact call per 9.1 findings); (f) permission callback uses `Permissions` write helper — never `__return_true`; (g) production-safe mode requires confirmation token (destructive=false for single-node update is a judgment call — match how `elementor-v3-update-element` classifies itself: `grep -n "ConfirmationToken" plugin/includes/Abilities/ElementorV3/UpdateElement.php`).
- [ ] **Step 2: Implement** following `ElementorV3/UpdateElement.php`'s structure (resolve → validate → snapshot → `ElementorData::write()` with the V4-aware options 9.1 determined → readback), reusing `write_error_for_ability()` from Task 1.1.
- [ ] **Step 3: Gates** — full plugin suite + `composer tokens:measure` (new tool must fit profile budgets; if `elementor-design` exceeds 20 tools, decide the drop/swap explicitly and document it in the PR).
- [ ] **Step 4: Update `docs/ability-truth-matrix.md` via `composer docs:matrix`**, changelogs, and the V4 docs (`docs/elementor-v4-engine.md`). Commit: `feat: surgical Elementor V4 node update with snapshot and readback`.

---

## PR packaging (applies to every phase)

Each phase = one PR (Phases 1–3 may combine into one "live-failure fixes" PR if the executor prefers — they touch adjacent files). Every PR description must include:
1. Changed abilities list (e.g., Phase 1: `stonewright/elementor-v3-update-element`, `-move-element`, `-remove-element`, `-batch-mutate`, `stonewright/design-spec-to-elementor-v3` — error envelope only).
2. Gate statement: which backup/token/permission/validation/audit gates changed (Phases 1–8: none weakened; Phase 3 enriches error envelopes; Phase 4 adds a *non-blocking* hint and a TTL that *strengthens* the Direct write gate; Phase 9 adds new gated ability).
3. Public docs changed, or why none needed changing.
4. `node scripts/check-docs-freshness.mjs` + `git diff --check` output confirmation.
5. No competitor product names, no automated-authorship claims, in any public text.

## Execution order and independence

| Order | Phase | Depends on | Size |
| --- | --- | --- | --- |
| 1 | Phase 1 — truthful errors | — | S |
| 2 | Phase 2 — memory/learning fix + live verify | — (2.5 needs deploy) | M |
| 3 | Phase 3 — retry brake | 1.3 (hints) | S–M |
| 4 | Phase 4 — task-start reach | — | M |
| 5 | Phase 5 — token caps | — | M |
| 6 | Phase 6 — Figma map | — | S (docs) |
| 7 | Phase 7 — onboarding docs | — | S (docs) |
| 8 | Phase 8 — parity tests | — | S |
| 9 | Phase 9 — V4 surgical write | 1.1, 1.3; gated by 9.1 | L |

Success criteria (re-check on the live audit page after deployment):
- A failed typed Elementor write shows a specific error code + repair, never bare "Could not save Elementor data."
- The same error signature never appears 3+ times in one session without an escalated STOP message in between.
- Recurring errors (count ≥ 2) always have a matching `learning-audit-error-*` row on the Memory page.
- A fresh client session (either mode) that skips task-start gets nudged on its first read and blocked with task-start-naming text on its first write.
- `stonewright-elementor-data-get` (Direct) and V4 tree reads return capped summaries by default.
