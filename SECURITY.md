# Security Policy

## Supported Versions

Stonewright is pre-1.0. Security fixes target the latest public release and the
main branch until a stable support matrix exists.

## Reporting a Vulnerability

Please report security issues privately to the project maintainer before opening
a public issue. Include affected version, reproduction steps, impact, and any
logs or screenshots that do not contain secrets.

## Security Model

Typed write abilities use explicit permission callbacks. Destructive
production-safe operations require confirmation tokens. Elementor/theme and
many content writes snapshot first. The companion runs WP-CLI through tokenized
argv only.

Rescue journals a risky write before it runs, checks that the site still loads
afterwards, and rolls the change back when it does not. Undoing a verified
change to code needs an administrator in wp-admin. See
[docs/rescue.md](docs/rescue.md).

`stonewright/php-execute` intentionally runs short PHP inside the loaded
WordPress runtime. It is permission- and mode-gated and audited, but it is
**not** a strict sandbox and does not receive the same structural guarantees as
typed DesignSpec or validated mutation workflows. Prefer typed abilities when
they exist. Stonewright is not a replacement for staging environments, human
review, or normal WordPress security practice.

## Principles

- Prefer typed abilities over unrestricted runtime PHP.
- Every ability declares an explicit `permission_callback`. Defaults map to WordPress capabilities.
- Writes to Elementor / Gutenberg content require a revision or backup first where the ability path supports it.
- Destructive actions require a two-step confirmation token in production-safe mode.
- The plugin supports three modes: `development`, `staging`, `production-safe`.

## Capability map

| Domain                     | Required capability                 |
|----------------------------|-------------------------------------|
| Read-only site info        | `read`                              |
| Create / update own posts  | `edit_posts` / `edit_pages`         |
| Update Elementor page      | `edit_post( $page_id )`             |
| Update Elementor kit       | `edit_theme_options`                |
| Update templates / styles  | `manage_options` + `edit_theme_options` |
| Upload media               | `upload_files`                      |
| Sandbox plugin code        | `edit_plugins` + `manage_options`, and file changes allowed |
| Destructive (delete)       | the capability of that domain, plus a confirmation token in production-safe mode |

## HTTP transport

When exposing the MCP server over HTTP:

- authentication required: `mcp/stonewright` takes an Application Password (HTTP Basic) and `mcp/stonewright-oauth` takes a bearer OAuth access token (a signed JWT)
- `Origin` header validated on the MCP routes `mcp/stonewright` and `mcp/stonewright-oauth`: a request
  without an `Origin` passes, the site's own origin (home URL and site URL: scheme, host, and port) and
  origins listed through the `stonewright_mcp_allowed_origins` filter pass, and any other origin is
  refused with 403 and a JSON-RPC error body
- the OAuth endpoints are rate-limited per client address
- the `Origin` check also covers DNS rebinding and cross-site requests
- outbound HTTP requests use the WordPress safe request functions, which refuse private and local addresses

## Banned PHP constructs

Outside the dedicated PHP runtime executor used by `stonewright/php-execute`,
the plugin must avoid these dynamic execution patterns:

- runtime code interpretation primitives (`eval`-family) — only allowed in the dedicated runtime executor
- `create_function` — never used
- shell execution primitives (`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`) — never used for agent shell escape
- `assert` with string argument — never used

The security audit (`composer security:audit`) fails the build on `eval` outside the
executor, on `create_function`, and on `assert` with a string argument. At runtime,
`StaticAnalysis` logs a warning when PHP leaves the shell functions enabled.

## Confirmation tokens

In production-safe mode, an ability that deletes content, removes Elementor elements, or writes theme.json needs a `confirmation_token`:

1. Call `stonewright/security-issue-confirmation-token` with the `ability` name and its exact `args`.
2. Call the ability with the returned token as `confirmation_token`.

The token works once, only for that user, ability and arguments. It lasts 5 minutes by default (`ttl_seconds` sets 60 to 3600). Without a token, the ability returns `stonewright_confirmation_required`.

## Audit log

All write abilities log to the `stonewright_audit_log` table (with the site's table prefix):

- ability name
- user ID
- sanitized arguments JSON
- result status
- IP hash (SHA-256 + site salt)
- request UUID
- timestamp
- outcome fields: execution and verification status, rollback status, change set id, and error code

The log table is created on activation. It is read-only via REST.
