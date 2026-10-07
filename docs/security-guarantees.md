# Security Guarantees

This document states the hard rules from `AGENTS.md`, where they are enforced,
and how to verify them.

## Rule 1 - Dedicated PHP Runtime Execution

Stonewright exposes full WordPress runtime snippets only through the dedicated
`stonewright/php-execute` ability. Do not add generic PHP adapters, REST runner
workarounds, shell scripts, `create_function()`, `assert()` with string
arguments, or dynamic include/require paths.

Enforced by:

- `plugin/includes/Abilities/Runtime/PhpExecute.php`
- `plugin/includes/Security/StaticAnalysis.php`
- `plugin/includes/Security/ProtectedWpdbWriteGuard.php` (core-table and
  protected-meta writes, including concatenated keys; `read_only:true`)
- `plugin/includes/Security/ProtectedFilesystemWriteGuard.php`
- `plugin/includes/Security/ProtectedElementorWriteGuard.php`
- `plugin/bin/security-audit.php`

`php-execute` is registered on the **full** MCP profile only. Bootstrap and
essential do not expose it. The `discover-execute` and read-only `inspect`
profiles also omit it.

A successful `php-execute` response may carry a `routing_hint` that names the
typed tool for a common pattern (post meta, options, Elementor data, menus). The
hint is advice built from fixed text. It never blocks or changes the call, and
it never repeats the snippet.

Verify:

```bash
cd plugin
composer test -- --filter PhpExecuteTest
composer security:audit
```

## Rule 2 - No `__return_true` For Writes

Every write/update/delete ability must use a real permission callback that calls
`Stonewright\WpMcp\Security\Permissions`.

Enforced by:

- `plugin/includes/Security/Permissions.php`
- `plugin/tests/Unit/AbilityKernelAuditTest.php`

Verify:

```bash
cd plugin
composer test -- --filter AbilityKernelAudit
```

## Rule 3 - Backup Before Write

Before mutating Elementor data, global styles, templates, or theme.json-backed
content, the ability must call `Backup::snapshot_post( $post_id )`.

Enforced by:

- `plugin/includes/Security/Backup.php`
- Write abilities listed in `docs/ability-truth-matrix.md`

Verify:

```bash
cd plugin
composer test -- --filter FseWriteSafety
composer test -- --filter ElementorWriter
```

## Rule 4 - Validator Before Render

Design specs must be validated through
`Stonewright\WpMcp\DesignSpec\Validator::validate( $spec )` before reaching any
renderer. Invalid specs return `WP_Error( 'stonewright_spec_invalid', ... )`.

Enforced by:

- `plugin/includes/DesignSpec/Validator.php`
- `plugin/includes/ThemeJson/Validator.php`

Verify:

```bash
cd plugin
composer test -- --filter ValidatorTest
composer test -- --filter RendererValidation
```

## Rule 5 - Confirmation Tokens For Destructive Operations

In `production-safe` mode, destructive abilities must verify a token from
`stonewright/security-issue-confirmation-token`.

Enforced by:

- `plugin/includes/Security/ConfirmationToken.php`
- `plugin/includes/Abilities/Common/ConfirmationGuard.php`

Verify:

```bash
cd plugin
composer test -- --filter ConfirmationToken
composer test -- --filter AbilityConfirmation
```

## Rule 6 - Mode Support

Stonewright must honor `development`, `staging`, and `production-safe`. The admin
UI exposes the toggle and permission gates read the option.

Enforced by:

- `plugin/includes/Security/Permissions.php`
- `plugin/includes/Admin/SettingsSanitizer.php`

Verify:

```bash
cd plugin
composer test -- --filter PermissionsTest
composer test -- --filter SettingsSanitizer
```

## Rule 7 - Companion WP-CLI Stays Tokenized

The companion may execute WP-CLI commands for WordPress operations, including
write commands, but only through the tokenized runner. It must not call
WordPress REST write endpoints. PHP snippets go through
`stonewright/php-execute`; WP-CLI PHP and shell entry points stay blocked.

Enforced by:

- `companion/src/wp-cli.ts`
- `plugin/includes/Abilities/WpCli/Run.php`
- `plugin/tests/Unit/WpCli/WpCliAbilitiesTest.php`
- `companion/tests/wp-cli.test.ts`

Verify:

```bash
cd companion
npm test -- tests/wp-cli.test.ts
cd ../plugin
vendor/bin/phpunit tests/Unit/WpCli/WpCliAbilitiesTest.php
```

## Rule 8 - Context Before Task Work

Agents must call MCP tool `stonewright-task-start` at the start of every task.
Write abilities require the returned `stonewright_context_token`.

Enforced by:

- `plugin/includes/Abilities/System/ContextBootstrap.php`
- `plugin/includes/Context/ContextBuilder.php`
- `plugin/includes/Context/ContextToken.php`
- `plugin/includes/Core/AbilityRegistry.php`

Verify:

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Context
```

## Rule 9 - Persistent Learning

Manual instructions, skills, and learned corrections must persist in WordPress
options and be returned by future `context-bootstrap` calls.

Enforced by:

- `plugin/includes/Abilities/System/InstructionsSet.php`
- `plugin/includes/Abilities/Skills/SkillsSave.php`
- `plugin/includes/Abilities/Memory/MemorySave.php`
- `plugin/includes/Abilities/Memory/LearningRecord.php`

Verify:

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Memory
vendor/bin/phpunit tests/Unit/Context
```

## Rule 10 - Permanent audit and incident taxonomy

Audit outcomes are normalized to a versioned category/outcome contract. Recurring
incidents use category-specific thresholds and exact verified correlation for
resolution; permission and safety blocks are not promoted into repair debt. A
verified write that names the failed change in `repair_of`, on the same
resource, is such a correlation: it resolves the incident that change opened,
and the row that resolves it carries no `incident_id`.

Enforced by:

- `plugin/includes/Security/AuditEvent.php`
- `plugin/includes/Security/IncidentStore.php`
- `plugin/includes/Security/AuditReconciler.php`

Verify:

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Security/AuditEventIncidentTest.php
vendor/bin/phpunit tests/Unit/Security/ChangeSetRepairTest.php
```

## Rule 11 - OAuth terminal failure and bounded retry

OAuth refresh rotation is single-flight. Terminal grant/client failures clear
local token state and stop retrying; transient failures honor bounded retry and
`Retry-After` behavior. Server throttles never read forwarded headers: a
request counts under the connection address the web server reports, and an
IPv6 address counts as its /64 prefix.

Enforced by:

- `companion/src/oauth-token-manager.ts`
- `plugin/includes/Authorization/WordPress/RequestLimiter.php`

Verify:

```bash
cd companion
npx vitest run tests/oauth-token-manager.test.ts
cd ../plugin
vendor/bin/phpunit tests/Unit/Authorization/WordPress/RequestLimiterTest.php
```

## Rule 12 - Transaction receipts and evidence-preserving patches

Elementor and Gutenberg writes return one bounded receipt, snapshot before
mutation, verify readback, and give rollback one owner. Elementor patches reject
new unknown settings, preserve untouched runtime controls, and enforce repeater
identity and responsive-scope rules.

Enforced by:

- `plugin/includes/Elementor/Write/ElementorWriteReceipt.php`
- `plugin/includes/Elementor/Schema/PatchValidator.php`
- `plugin/includes/Abilities/Gutenberg/BlocksBatchMutate.php`

Verify:

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Elementor/Schema/PatchValidatorTest.php
vendor/bin/phpunit tests/Unit/Elementor/Write/ElementorWriteReceiptTest.php
vendor/bin/phpunit tests/Unit/Gutenberg/BlocksBatchMutateTest.php
```

## Rule 13 - Rescue after risky changes

A risky write is journaled before it runs and checked afterwards. When the site stops
loading, the change is rolled back from the state recorded before it, and the outcome is
recorded on the change set and in the audit log. A rollback that cannot complete leaves an
open incident with a way back: an ability, an admin page, and a WP-CLI command, each held to
the same permission and confirmation rules. A health probe that cannot reach the site is
reported as unavailable, never as healthy, except that a check which answered before the write and
cannot be reached after it counts as failed. The journal file never creates a change set or a recipe.

Enforced by:

- `plugin/includes/Security/ChangeJournal.php`
- `plugin/includes/Security/RescueGuard.php`
- `plugin/includes/Security/HealthProbe.php`
- `plugin/includes/Security/RollbackRecipes.php`
- `plugin/includes/Abilities/Security/RescueRollback.php`

Verify:

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Security/ChangeJournalTest.php
vendor/bin/phpunit tests/Unit/Security/RescueGuardTest.php
vendor/bin/phpunit tests/Unit/Security/HealthProbeTest.php
vendor/bin/phpunit tests/Unit/Security/RollbackRecipesTest.php
vendor/bin/phpunit tests/Unit/Security/RescueAbilitiesTest.php
```

## Threat Model

In scope:

- Unintended writes from a misconfigured MCP prompt.
- A change that leaves the site unable to load.
- Privilege escalation through ability permission mistakes.
- Sandbox code injection.
- Generic PHP adapter or shell workaround outside `stonewright/php-execute`.
- WP-CLI entry point misuse.
- Token replay.
- Stale or missing task context.

Out of scope:

- WordPress core CVEs.
- Elementor core vulnerabilities.
- Hosting/network hardening.
- Composer or npm supply-chain compromise outside the pinned lockfiles.
