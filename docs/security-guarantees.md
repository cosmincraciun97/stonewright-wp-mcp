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

`php-execute` is registered on the **full** MCP profile only. Every other profile
omits it, including bootstrap, essential, low-tools, the specialist profiles,
`discover-execute`, and the read-only `inspect` profile. The companion applies
the same rule to its own profile lists, and keeps php-execute registered for the
full profile only.

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

A token is bound to the ability and to every other argument of the call, works once, and lives 60 to 3600 seconds. A dry run that writes nothing (for example `elementor-v3-update-element` with `dry_run: true`) needs no token; the write does. This includes every `elementor-v3-batch-mutate` and `elementor-v3-build-page-from-spec` write that is not a dry run, whatever its `mode`; `elementor-v3-apply-bundle` takes one top-level token for the whole call; and `design-normalize-assets` needs one while it sideloads (`sideload: false` needs none).

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
- `plugin/includes/Admin/ConfigurationPage.php`

Verify:

```bash
cd plugin
composer test -- --filter PermissionsTest
composer test -- --filter SettingsSanitizer
```

## Rule 7 - Companion WP-CLI Stays Tokenized

The companion may execute WP-CLI commands for WordPress operations, including
write commands, but only through the tokenized runner. PHP snippets go through
`stonewright/php-execute`; WP-CLI PHP and shell entry points stay blocked.

In Plugin mode the companion must not call WordPress REST write endpoints; it
stays a thin proxy to the plugin's MCP endpoint.

Direct mode is the exception. With no plugin installed, the companion writes
through core WordPress REST (and through local WP-CLI where the target check
passes), and only through the typed Direct tools; `stonewright-rest-request` is
GET only. Those tools act on the session's site only, refuse to write on a site
where the Stonewright plugin is active (a recovery-only override needs
`STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE=1` plus `confirm: true` and is
audited), require a recent `stonewright-task-start`, honor
`STONEWRIGHT_DIRECT_WRITES`, refuse custom code in the writes that carry
markup, text or CSS (including block edits and the markup of the compose helper), save a pre-image before whole-document writes and block edits and restore it only after a confirmation and a hash check, read every typed write back, never activate a skill (a saved skill is a disabled candidate), limit
`stonewright-media-upload` (and connect to the address they checked), refuse to
remove Stonewright, the connection plugin, the signed-in account or the last
administrator, send the Application Password only over https or to a local
host, and never retry a write. In a Direct session the local
`stonewright-wp-cli-run` and `-job-start` hold a command that changes the site
to the same latch, confirmation and audit. Destructive Direct tools need a confirmation bound to the
call (a local `confirmation_id`; a plain `confirm: true` only on a local site) and the Elementor
whole-tree write is checked and its overrides audited. They do not provide change sets or the
plugin's confirmation tokens. See
[Direct mode E2E](direct-mode-e2e.md#what-direct-mode-does-not-do-yet).

Enforced by:

- `companion/src/wp-cli.ts`
- `plugin/includes/Abilities/WpCli/Run.php`
- `plugin/tests/Unit/WpCli/WpCliAbilitiesTest.php`
- `companion/tests/wp-cli.test.ts`
- Direct mode: `companion/src/direct/writes.ts`,
  `companion/src/direct/plugin-guard.ts`, `companion/src/direct/plugin-presence.ts`,
  `companion/src/direct/sites-config.ts`,
  `companion/src/direct/custom-code-guard.ts`, `companion/src/direct/block-markup.ts`,
  `companion/src/direct/block-guard.ts`, `companion/src/direct/tools/blocks.ts`,
  `companion/src/direct/preimage.ts`,
  `companion/src/direct/backups.ts`, `companion/src/direct/verify.ts`,
  `companion/src/direct/probe.ts`,
  `companion/src/direct/elementor-target.ts`, `companion/src/direct/media-source.ts`,
  `companion/src/direct/media-transport.ts`, `companion/src/direct/media-limits.ts`,
  `companion/src/direct/wp-rest-client.ts` and `companion/src/direct/wp-cli-gate.ts`

Verify:

```bash
cd companion
npm test -- tests/wp-cli.test.ts
cd ../plugin
vendor/bin/phpunit tests/Unit/WpCli/WpCliAbilitiesTest.php
```

## Rule 8 - Context Before Task Work

Agents must call MCP tool `stonewright-task-start` at the start of every task.
Write abilities require the returned `stonewright_context_token`. A name never exempts a write: `elementor-add-icon-list`, `elementor-add-price-list`, `elementor-add-read-more` and `elementor-add-search` need it although their names end like read tools.

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
`Retry-After` behavior. Server throttles read a forwarded header only when the
connection comes from a proxy the site lists in `STONEWRIGHT_TRUSTED_PROXIES`
or the `stonewright_trusted_proxies` filter (none by default). Otherwise a
request counts under the connection address the web server reports. An IPv6
address counts as its /64 prefix.

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
A verified change can be rolled back, but a verified change to code is rolled back only by an
administrator on the Rescue page; an agent's call is refused with `stonewright_rescue_approval_required`.
The undo of a verified change saves the current state first and puts it back when the undo makes a
site that worked fail to load.

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
