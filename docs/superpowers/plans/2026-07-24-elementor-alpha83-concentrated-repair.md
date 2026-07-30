# Elementor alpha.83 Concentrated Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship Stonewright `1.0.0-alpha.83` with post-scoped Elementor cache invalidation, bounded document health, accurate mixed-document guidance, precise schema errors, and verified V4 toggle persistence.

**Architecture:** Centralize Elementor cache invalidation in one post-scoped service and route every write path through it. Add one read-only health ability that composes existing tree inspection and schema validation, while workflow preflight distinguishes surgical V3 writes from blocked high-level mixed-tree writes.

**Tech Stack:** PHP 8.1, WordPress Settings/Abilities APIs, Elementor runtime APIs, PHPUnit 9, Composer, TypeScript, Node.js 20+, Vitest, npm packaging.

## Global Constraints

- Namespace remains `Stonewright\WpMcp`; ability prefix remains `stonewright/`.
- Every Elementor write retains backup, permission, validation, confirmation, audit, and readback gates.
- No raw `_elementor_data` writes, implicit V3/V4 conversion, or unknown-setting stripping.
- Public docs and generated contracts change in the same release.
- Live verification starts with `stonewright-task-start`; missing startup tools stop live WordPress work.
- Existing untracked `companion/bin/`, `companion/lib/`, and `docs/plans.bak-local/` remain untouched.

---

### Task 1: Post-scoped Elementor cache invalidation

**Files:**
- Create: `plugin/includes/Elementor/PostCacheInvalidator.php`
- Create: `plugin/tests/Unit/Elementor/PostCacheInvalidatorTest.php`
- Modify: `plugin/includes/Support/ElementorData.php`
- Modify: `plugin/includes/Elementor/ElementorTransactionRunner.php`
- Modify: `plugin/includes/Abilities/ElementorV3/UpdatePageSettings.php`
- Modify: `plugin/tests/Unit/Support/ElementorDataWriteTest.php`

**Interfaces:**
- Produces: `PostCacheInvalidator::invalidate(int $post_id): array{ok:bool,post_id:int,method:string}`
- Consumes: Elementor `posts_css_manager->clear_cache_post(int)` and WordPress `clean_post_cache()`.

- [ ] **Step 1: Write failing tests**

Add spies to the Elementor test instance and assert:

```php
$result = PostCacheInvalidator::invalidate( 8800 );
self::assertSame( 1, $posts_css->calls );
self::assertSame( 0, $files_manager->calls );
self::assertSame( 'posts_css_manager', $result['method'] );
```

Add a full-tree transaction assertion proving one write triggers exactly one
post-scoped invalidation and zero global invalidations.

- [ ] **Step 2: Verify RED**

Run:

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Elementor/PostCacheInvalidatorTest.php tests/Unit/Support/ElementorDataWriteTest.php
```

Expected: failure because `PostCacheInvalidator` does not exist and current
writes call `files_manager->clear_cache()`.

- [ ] **Step 3: Implement minimal service**

Implement:

```php
final class PostCacheInvalidator {
	public static function invalidate( int $post_id ): array {
		clean_post_cache( $post_id );
		if ( did_action( 'elementor/loaded' ) && class_exists( '\\Elementor\\Plugin' ) ) {
			$manager = \Elementor\Plugin::$instance->posts_css_manager ?? null;
			if ( is_object( $manager ) && method_exists( $manager, 'clear_cache_post' ) ) {
				$manager->clear_cache_post( $post_id );
				return [ 'ok' => true, 'post_id' => $post_id, 'method' => 'posts_css_manager' ];
			}
		}
		delete_post_meta( $post_id, '_elementor_css' );
		return [ 'ok' => true, 'post_id' => $post_id, 'method' => 'meta_delete' ];
	}
}
```

Replace three global clear sites with this service. Remove the second clear
from `ElementorTransactionRunner::run_full_tree()`.

- [ ] **Step 4: Verify GREEN**

Run the Task 1 PHPUnit command. Expected: all selected tests pass and global
clear spy remains zero.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/PostCacheInvalidator.php plugin/includes/Support/ElementorData.php plugin/includes/Elementor/ElementorTransactionRunner.php plugin/includes/Abilities/ElementorV3/UpdatePageSettings.php plugin/tests/Unit/Elementor/PostCacheInvalidatorTest.php plugin/tests/Unit/Support/ElementorDataWriteTest.php
git commit -m "fix: scope Elementor cache invalidation"
```

### Task 2: V4 dashboard toggle persistence

**Files:**
- Modify: `plugin/includes/Admin/ConfigurationPage.php`
- Modify: `plugin/tests/Unit/Admin/ConfigurationPageTest.php`
- Modify: `plugin/tests/Unit/Admin/SettingsSanitizerTest.php`
- Create: `plugin/tests/Unit/Elementor/V4FeatureGateTest.php`

**Interfaces:**
- Produces: explicit `stonewright_elementor_v4_atomic=0|1` settings form input.
- Consumes: `V4FeatureGate::check(bool $write = false)`.

- [ ] **Step 1: Write failing tests**

Assert the form emits a hidden false value immediately before the checkbox:

```php
self::assertMatchesRegularExpression(
	'/name="stonewright_elementor_v4_atomic" value="0".*name="stonewright_elementor_v4_atomic".*value="1"/s',
	$html
);
```

Use the registered sanitizer for `null`, `'0'`, and `'1'`, then assert
`V4FeatureGate::check()` returns `feature_disabled` for false and `true` for
true.

- [ ] **Step 2: Verify RED**

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Admin/ConfigurationPageTest.php tests/Unit/Admin/SettingsSanitizerTest.php tests/Unit/Elementor/V4FeatureGateTest.php
```

Expected: hidden false-value assertion fails.

- [ ] **Step 3: Implement explicit toggle value**

Insert before the V4 checkbox:

```php
<input type="hidden" name="stonewright_elementor_v4_atomic" value="0" />
```

Keep the checkbox after it with `value="1"` so checked form submission wins.

- [ ] **Step 4: Verify GREEN**

Run the Task 2 command. Expected: all selected tests pass.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Admin/ConfigurationPage.php plugin/tests/Unit/Admin/ConfigurationPageTest.php plugin/tests/Unit/Admin/SettingsSanitizerTest.php plugin/tests/Unit/Elementor/V4FeatureGateTest.php
git commit -m "fix: verify Elementor V4 toggle persistence"
```

### Task 3: Precise schema error messages

**Files:**
- Modify: `plugin/includes/Elementor/Schema/SettingsValidator.php`
- Modify: `plugin/tests/Unit/Elementor/Schema/WidgetSchemaRepositoryTest.php`

**Interfaces:**
- Produces: first-violation message without including the rejected value.
- Preserves: structured `violations`, `schema_request`, and `repair` data.

- [ ] **Step 1: Write failing test**

For malformed `_background_color_stop_laptop`, assert:

```php
self::assertStringContainsString( 'settings._background_color_stop_laptop', $error->get_error_message() );
self::assertStringContainsString( 'expected', $error->get_error_message() );
self::assertStringContainsString( 'received array', $error->get_error_message() );
self::assertStringNotContainsString( '"unit":"%"', $error->get_error_message() );
```

- [ ] **Step 2: Verify RED**

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Elementor/Schema/WidgetSchemaRepositoryTest.php
```

Expected: current generic message lacks path/type detail.

- [ ] **Step 3: Implement safe first-violation formatter**

Build the message only from `path`, `expected`, and `got_type`:

```php
$message = sprintf(
	__( 'Elementor setting %1$s rejected: expected %2$s; received %3$s.', 'stonewright' ),
	(string) $first['path'],
	(string) $first['expected'],
	(string) $first['got_type']
);
```

- [ ] **Step 4: Verify GREEN**

Run Task 3 test. Expected: pass with unchanged structured error data.

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/Elementor/Schema/SettingsValidator.php plugin/tests/Unit/Elementor/Schema/WidgetSchemaRepositoryTest.php
git commit -m "fix: identify rejected Elementor settings"
```

### Task 4: Bounded Elementor document health

**Files:**
- Create: `plugin/includes/Abilities/ElementorV3/DocumentHealth.php`
- Create: `plugin/tests/Unit/ElementorV3/DocumentHealthTest.php`
- Modify: `plugin/includes/Core/AbilityRegistry.php`
- Modify: `plugin/includes/Abilities/System/ToolProfile.php`

**Interfaces:**
- Produces: ability `stonewright/elementor-document-health`.
- Input: `{post_id:int,max_issues?:int}` with `max_issues` clamped to `1..100`.
- Output: byte/KiB size, architecture/counts, widget counts, paragraph IDs,
  bounded issues/warnings, and truncation.

- [ ] **Step 1: Write failing fixtures/tests**

Seed V3, V4, mixed, 48-paragraph, and malformed-setting trees. Assert:

```php
self::assertSame( 'mixed', $result['architecture'] );
self::assertSame( 48, $result['e_paragraph_count'] );
self::assertCount( 5, $result['issues'] );
self::assertTrue( $result['issues_truncated'] );
self::assertArrayNotHasKey( 'tree', $result );
```

Assert ability registration and `Permissions::edit_post()` callback.

- [ ] **Step 2: Verify RED**

```bash
cd plugin
vendor/bin/phpunit tests/Unit/ElementorV3/DocumentHealthTest.php
```

Expected: class/ability missing.

- [ ] **Step 3: Implement health scanner**

Read raw meta only to compute bytes. Parse via `ElementorData::read()`. Use
`AtomicTreeInspector::inspect()` for architecture. Walk nodes once, count
types, collect `e-paragraph` IDs, and call `SettingsValidator` only until
`max_issues` is reached. Never return full settings or text.

- [ ] **Step 4: Register compact tool**

Add `DocumentHealth::class` beside `PageDigest::class` and expose it in
Elementor essential/task-aware tool lists.

- [ ] **Step 5: Verify GREEN**

Run Task 4 test. Expected: all fixtures pass with bounded output.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/Abilities/ElementorV3/DocumentHealth.php plugin/tests/Unit/ElementorV3/DocumentHealthTest.php plugin/includes/Core/AbilityRegistry.php plugin/includes/Abilities/System/ToolProfile.php
git commit -m "feat: add Elementor document health"
```

### Task 5: Mixed-document surgical routing

**Files:**
- Modify: `plugin/includes/Elementor/ArchitectureRouter.php`
- Modify: `plugin/includes/Abilities/System/WorkflowPreflight.php`
- Modify: `plugin/includes/Abilities/ElementorV3/BatchMutate.php`
- Modify: `plugin/tests/Unit/Elementor/ArchitectureRouterTest.php`
- Modify: `plugin/tests/Unit/ElementorV3/BatchMutateTest.php`
- Modify: `plugin/tests/Unit/WorkflowEfficiencyAbilitiesTest.php`

**Interfaces:**
- Produces routing fields `surgical_v3_allowed`, `high_level_write_blocked`,
  and target `v3-surgical`.
- Preserves high-level mixed-tree block.
- Rejects root additions to mixed trees without an explicit V3 parent.

- [ ] **Step 1: Write failing routing tests**

Assert mixed architecture reports:

```php
self::assertSame( 'v3-surgical', $out['write_target'] );
self::assertFalse( $out['write_blocked'] );
self::assertTrue( $out['surgical_v3_allowed'] );
self::assertTrue( $out['high_level_write_blocked'] );
```

Assert task-start recommends only health/schema/batch mutation for mixed
writes, not `build-page-from-spec`.

- [ ] **Step 2: Write failing batch safety test**

On a mixed tree:

```php
$result = $ability->execute( [
	'post_id' => 9049,
	'dry_run' => true,
	'operations' => [ [ 'action' => 'add_widget', 'widget_type' => 'heading', 'settings' => [ 'title' => 'Safe' ] ] ],
] );
self::assertSame( 'stonewright_mixed_root_add_blocked', $result->get_error_code() );
```

Add a sibling test where `parent_id` is a V3-only container and the operation
passes.

- [ ] **Step 3: Verify RED**

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Elementor/ArchitectureRouterTest.php tests/Unit/ElementorV3/BatchMutateTest.php tests/Unit/WorkflowEfficiencyAbilitiesTest.php
```

Expected: mixed routing remains blanket-blocked and root addition is accepted.

- [ ] **Step 4: Implement routing and enforcement**

Return surgical fields from `ArchitectureRouter`. Propagate them into the task
profile. Add `DocumentHealth` and `BatchMutate` to the mixed call sequence.
Before batch operations, reject `add_container`/`add_widget` without parent
when overall architecture is mixed.

- [ ] **Step 5: Verify GREEN**

Run Task 5 command. Expected: surgical parent operation passes; root add and
V4/mixed subtree targets fail.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/Elementor/ArchitectureRouter.php plugin/includes/Abilities/System/WorkflowPreflight.php plugin/includes/Abilities/ElementorV3/BatchMutate.php plugin/tests/Unit/Elementor/ArchitectureRouterTest.php plugin/tests/Unit/ElementorV3/BatchMutateTest.php plugin/tests/Unit/WorkflowEfficiencyAbilitiesTest.php
git commit -m "fix: route mixed Elementor writes surgically"
```

### Task 6: Release metadata, contracts, and documentation

**Files:**
- Modify: `plugin/stonewright.php`
- Modify: `plugin/README.md`
- Modify: `plugin/CHANGELOG.md`
- Modify: `companion/package.json`
- Modify: `companion/package-lock.json`
- Modify: `companion/src/version.ts`
- Modify: `companion/CHANGELOG.md`
- Modify: `CHANGELOG.md`
- Create: `docs/releases/1.0.0-alpha.83.md`
- Regenerate: `docs/ability-truth-matrix.md`
- Regenerate: `docs/contracts/public-api-v1.json`
- Regenerate: `docs/contracts/direct-tools-v1.json` when companion generator changes it.

**Interfaces:**
- Produces synchronized version `1.0.0-alpha.83`.
- Documents one new read ability and changed Elementor write/cache behavior.

- [ ] **Step 1: Update user-facing changelogs/docs**

Record cache performance fix, V4 toggle fix, precise schema errors, document
health, and mixed surgical routing. State Direct mode remains limited and
unchanged.

- [ ] **Step 2: Bump versions**

Change all alpha.82 runtime metadata listed above to `1.0.0-alpha.83`.

- [ ] **Step 3: Regenerate artifacts**

```bash
cd plugin
composer docs:matrix
composer contracts:generate
cd ../companion
npm run contracts:generate
```

Expected: generated files include `stonewright/elementor-document-health` and
version metadata remains synchronized.

- [ ] **Step 4: Run freshness checks**

```bash
node scripts/check-docs-freshness.mjs
git diff --check
```

Expected: both exit 0.

- [ ] **Step 5: Commit**

Stage only release/doc/generated files and commit:

```bash
git commit -m "release: prepare 1.0.0-alpha.83"
```

### Task 7: Full verification, packaging, and local installation

**Files:**
- Build: `dist/stonewright-1.0.0-alpha.83.zip`
- Build: `dist/stonewright-companion-1.0.0-alpha.83.tgz`
- Build: `dist/SHA256SUMS.txt`

**Interfaces:**
- Produces clean plugin/companion artifacts and a locally installed companion.

- [ ] **Step 1: Run plugin gates**

```bash
cd plugin
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit
composer provenance:lint
composer tokens:measure
```

Expected: every command exits 0. If PHPStan exhausts memory, rerun with
`vendor/bin/phpstan analyse --memory-limit=512M`.

- [ ] **Step 2: Run companion gates**

```bash
cd companion
npm run typecheck
npm test
npm run tokens:measure
npm run build
```

Expected: every command exits 0.

- [ ] **Step 3: Package production artifacts**

Follow `.github/workflows/release.yml`: install no-dev plugin dependencies,
copy runtime files into `dist/stonewright`, exclude tests/build metadata, zip
the plugin, and run `npm pack --pack-destination ../dist` for the companion.

- [ ] **Step 4: Inspect artifacts**

```bash
unzip -Z1 dist/stonewright-1.0.0-alpha.83.zip
tar -tzf dist/stonewright-companion-1.0.0-alpha.83.tgz
node scripts/package-verify.mjs dist/stonewright-1.0.0-alpha.83.zip
shasum -a 256 dist/stonewright-1.0.0-alpha.83.zip dist/stonewright-companion-1.0.0-alpha.83.tgz > dist/SHA256SUMS.txt
```

Expected: plugin root is `stonewright/`; no tests, bin, lockfiles, node_modules,
or private docs exist in artifacts.

- [ ] **Step 5: Install local plugin and companion**

Install the ZIP only on the configured local WordPress target through the
typed Stonewright/WordPress path. Replace the local Stonewright MCP command
through `codex mcp remove/add` using the absolute alpha.83 TGZ path and existing
environment values without printing them.

- [ ] **Step 6: Restart and verify live**

After MCP restart, call `stonewright-task-start` first. Verify plugin and
companion alpha.83, V4 toggle false/true persistence, document health, mixed
surgical guidance, and one controlled write/readback. If task-start remains
missing, report the restart requirement and stop live WordPress mutations.

- [ ] **Step 7: Final repository check**

```bash
git status --short
git diff --check
```

Expected: only pre-existing user untracked paths remain; implementation commits
are clean and artifacts are reported with checksums.
