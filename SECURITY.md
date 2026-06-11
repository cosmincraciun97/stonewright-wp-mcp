# Security Policy

## Supported Versions

Stonewright is pre-1.0. Security fixes target the latest public release and the
main branch until a stable support matrix exists.

## Reporting a Vulnerability

Please report security issues privately to the project maintainer before opening
a public issue. Include affected version, reproduction steps, impact, and any
logs or screenshots that do not contain secrets.

## Security Model

Stonewright does not execute arbitrary PHP source. Write abilities use explicit
permission callbacks, destructive production-safe operations require
confirmation tokens, Elementor/theme writes snapshot first, and the companion
runs WP-CLI through tokenized argv only.

## Principles

- Stonewright never executes arbitrary PHP supplied by an agent.
- Every ability declares an explicit `permission_callback`. Defaults map to WordPress capabilities.
- Writes to Elementor / Gutenberg content require a revision or backup first.
- Destructive actions require a two-step confirmation token.
- The plugin supports three modes: `development`, `staging`, `production-safe`.

## Capability map

| Domain                     | Required capability                 |
|----------------------------|-------------------------------------|
| Read-only site info        | `read`                              |
| Create / update own posts  | `edit_posts` / `edit_pages`         |
| Update Elementor page      | `edit_post( $page_id )`             |
| Update Elementor kit       | `manage_options`                    |
| Update theme.json / styles | `edit_theme_options`                |
| Upload media               | `upload_files`                      |
| Scaffold plugin / block    | `manage_options`                    |
| Destructive (delete)       | `manage_options` + confirmation     |

## HTTP transport

When exposing the MCP server over HTTP:

- bearer token required (short-lived JWT or WordPress Application Password)
- `Origin` header validated against an allowlist
- requests rate-limited per token
- DNS rebinding mitigation (host check)
- session identifiers generated via `random_bytes(32)`
- outbound HTTP calls restricted to an allowlist

## Banned PHP constructs

The plugin and any code it generates must avoid the dynamic execution patterns banned by `Stonewright\WpMcp\Security\StaticAnalysis`:

- runtime code interpretation primitives (`eval`-family) — never used
- `create_function` — never used
- shell execution primitives (`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`) — never used
- `assert` with string argument — never used

PHPStan and PHPCS rules in the repository enforce this and fail CI if any of these appear.

## Confirmation tokens

For any ability that deletes content, removes Elementor elements, or writes theme.json, the agent must:

1. Call the ability once with `confirm: false`. Stonewright returns `requires_confirmation` and a token.
2. Call the ability again with the same token and `confirm: true` within 5 minutes.

## Audit log

All write abilities log to `wp_stonewright_audit_log`:

- ability name
- user ID
- sanitized arguments JSON
- result status
- IP hash (SHA-256 + site salt)
- request UUID
- timestamp

The log table is created on activation. It is read-only via REST.
