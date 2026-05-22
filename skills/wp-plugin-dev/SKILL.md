---
name: wp-plugin-dev
description: >
  Generic WordPress + Stonewright plugin development helper. Scaffold abilities,
  register MCP server hooks, apply security patterns, and run the test suite.
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
- `input_schema(): array` - JSON Schema for accepted args
- `output_schema(): array` - JSON Schema for returned data
- `permission_callback( array $args ): bool|\WP_Error` - gates execution
- `execute( array $args ): array|\WP_Error` - business logic

## Security rules

- No dynamic code execution of any kind (no eval equivalents).
- Never use `__return_true` as a permission callback.
- Always gate write operations with `Permissions::edit_post( $id )` or
  `Permissions::edit_theme_options()` as appropriate.
- For Elementor or theme.json writes: call `Backup::snapshot_post( $post_id )`
  before any mutation and return the `snapshot_id` in the response.
- Always validate specs with `DesignSpec\Validator::validate( $spec )` before
  passing to a renderer. Reject with `WP_Error` if invalid.
- Wrap destructive operations in `$this->audit( $args, fn )` to enable audit
  logging.

## Confirmation token for destructive abilities

Abilities that delete or replace content must document the confirmation token
flow in their docblock. The caller (skill or agent) is responsible for issuing
the token and gating on user acknowledgement; the ability itself does not block.

## Test workflow

Write a failing test first under `plugin/tests/`. Fix. Verify.

```bash
cd plugin
composer test          # PHPUnit
composer phpstan       # static analysis
composer phpcs         # coding standards
```

## MCP server registration

The MCP HTTP transport is registered in `stonewright.php` via
`Stonewright\WpMcp\Core\McpServer::boot()`. Abilities are registered in
`Stonewright\WpMcp\Core\AbilityRegistry::register()`. New ability classes
must be added to the registry map.

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
composer phpstan       # runs PHPStan (level 8)
composer phpcs         # PHPCS with WordPress-Extra + WordPress-Docs
composer phpcbf        # auto-fix coding standards
```

See `references/ability-scaffold.md` for a copy-paste ability template.
See `references/security-patterns.md` for security code patterns with examples.
