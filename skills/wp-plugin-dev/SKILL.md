---
name: wp-plugin-dev
description: Use when writing or extending Stonewright abilities, including scaffolding, registration, security patterns, and tests.
---

# WordPress Plugin Development

Covers writing new Stonewright abilities, registering them with the kernel,
setting up MCP server transport, and applying the security patterns required
by the project. Use this skill when building features on top of the Stonewright
plugin or extending it with custom abilities.

## Namespace and prefix

All code in this repo lives under `Stonewright\WpMcp`. Abilities use the prefix
`stonewright/<category>-<verb>`. Match the existing naming convention exactly.

## Ability anatomy

Every ability extends `Stonewright\WpMcp\Abilities\AbilityKernel` and must
implement:
- `name(): string` - returns `stonewright/<category>-<verb>`
- `label(): string` - short human label (translated)
- `description(): string` - one-line description (translated)
- `category(): string` - groups abilities in the kernel
- `execute( array $args ): array|\WP_Error` - business logic

It also overrides these, whose kernel defaults fit only a no-argument read:
- `input_schema(): array` - JSON Schema for accepted args
- `output_schema(): array` - JSON Schema for returned data
- `permission_callback( array $args ): bool|\WP_Error` - gates execution (the
  default is `Permissions::read()`)

## Security rules

- No dynamic code execution of any kind (no eval equivalents).
- Never use `__return_true` as a permission callback.
- Always gate write operations with `Permissions::edit_post( $id )` or
  `Permissions::edit_theme_options()` as appropriate.
- For Elementor or theme.json writes: call `Backup::snapshot_post( $post_id )`
  before any mutation and return the `snapshot_id` in the response.
- Always validate specs with `DesignSpec\Validator::validate( $spec )` before
  passing to a renderer. Reject with `WP_Error` if invalid.
- Wrap write operations in `$this->audit_write( $args, fn )` and read-only ones
  in `$this->audit_read( $args, fn )`. Both record the audit row; `audit_write`
  also checks the production-safe confirmation token and declares the call a
  write.
- An ability that writes or runs code (theme files, snippets, custom CSS, PHP)
  is gated by a human-issued `custom_code_grant` (`Security\CustomCodeGrant`);
  its refusal carries the `approval_url`, and the agent shows it and stops.
  List it in `Core\FullProfileOnlyAbilities` when it runs or loads code.

## Confirmation token for destructive abilities

An ability that writes, deletes or replaces content declares an optional
`confirmation_token` string in `input_schema()` and runs its logic through
`$this->audit_write()`. In production-safe mode that wrapper refuses the call
without a valid token issued for the exact arguments
(`stonewright-security-issue-confirmation-token`). An ability whose dry run needs
no token checks it itself, after its own validation, with the
`Common\ConfirmationGuard` trait and `audit_write_with_own_token_gate()`. The
caller (skill or agent) still shows the user what will change before it asks
for the token.

## Test workflow

Write a failing test first under `plugin/tests/Unit/` (or `plugin/tests/Integration/`).
Fix. Verify. After adding or changing an ability, regenerate the generated files
with `composer docs:matrix` (the ability matrix and `data/ability-traits.php`)
and `composer contracts:generate` (`docs/contracts/*.json`); do not edit them by
hand.

```bash
cd plugin
composer test          # PHPUnit
composer phpstan       # static analysis
composer phpcs         # coding standards
```

## MCP server registration

`stonewright.php` boots `Stonewright\WpMcp\Core\PluginRegistration::boot()`.
`Core\ServerRegistration::register_server()` creates the MCP server on the
WordPress MCP Adapter, and abilities are registered by
`Stonewright\WpMcp\Core\AbilityRegistry::register_all()`. New ability classes
must be added to the registry class list. Which tools a client sees depends on
the tool profile; abilities that run or write code (`stonewright/php-execute`,
the sandbox writers, the WP-CLI runners) are listed only by the profiles that
name them, and `php-execute` only by `full`, which an administrator chooses in
wp-admin.

## Site state abilities (useful for dev)

| Ability | Purpose |
|---|---|
| `stonewright/site-capabilities` | List all registered abilities + integrations |
| `stonewright/site-info` | WordPress version, theme, home URL |
| `stonewright/site-environment` | PHP version, memory, WP_DEBUG |
| `stonewright/site-health` | Core WP health checks |
| `stonewright/site-plugins-list` | Installed plugins |
| `stonewright/ping` | Liveness check |
| `stonewright/site-backup-page` | Manual snapshot |
| `stonewright/site-create-revision` | Create WP revision |

## Composer scripts

```bash
composer test          # runs PHPUnit
composer phpstan       # runs PHPStan (level 5)
composer phpcs         # PHPCS with WordPress-Extra + WordPress-Docs
composer phpcbf        # auto-fix coding standards
```

See `references/ability-scaffold.md` for a copy-paste ability template.
See `references/security-patterns.md` for security code patterns with examples.
