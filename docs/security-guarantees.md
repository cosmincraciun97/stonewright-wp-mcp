# Security Guarantees

This document states the hard rules from `AGENTS.md`, where they are enforced,
and how to verify them.

## Rule 1 - No Arbitrary PHP Execution

Stonewright forbids `eval()`, `create_function()`, `assert()` with string
arguments, and any dynamic dispatch that executes user-supplied PHP source.

Enforced by:

- `plugin/includes/Security/StaticGuard.php`
- `plugin/includes/Security/StaticAnalysis.php`

Verify:

```bash
cd plugin
composer test -- --filter StaticGuard
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

## Rule 6 - Production-Safe Mode

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

## Rule 7 - Companion Writes Only Through Guarded WP-CLI

The companion may execute WP-CLI commands for WordPress operations, including
write commands, but only through the guarded runner. It must not call WordPress
REST write endpoints and must not execute arbitrary PHP or shell commands.

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

Agents must call MCP tool `stonewright-context-bootstrap` at the start of every task.
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

## Threat Model

In scope:

- Unintended writes from a misconfigured MCP prompt.
- Privilege escalation through ability permission mistakes.
- Sandbox code injection.
- Unsafe WP-CLI command execution.
- Token replay.
- Stale or missing task context.

Out of scope:

- WordPress core CVEs.
- Elementor core vulnerabilities.
- Hosting/network hardening.
- Composer or npm supply-chain compromise outside the pinned lockfiles.
