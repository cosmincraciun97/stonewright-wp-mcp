# Security

## Reporting a vulnerability

Email **security@stonewright.dev** with:

- a description of the vulnerability
- steps to reproduce it
- the WordPress version, PHP version, and Stonewright version you tested against

Do not open a public GitHub issue for security reports. You will receive an acknowledgment within two business days. Critical vulnerabilities are patched on a best-effort basis within seven days of confirmation.

## Security model

### Permissions

Every ability delegates to `Stonewright\WpMcp\Security\Permissions` before executing. That class calls the real WordPress capability functions (`current_user_can`, `is_user_logged_in`). There are no `__return_true` shortcircuits and no inline closures that bypass it.

Capability requirements by tier:

| Tier | Capabilities checked | Example abilities |
|---|---|---|
| Read | `read` + `is_user_logged_in` | Ping, Info |
| Author | `edit_posts`, `edit_pages` | CreatePost, CreatePage, Environment |
| Author + object | `edit_post( $post_id )`, and the publish capability of the post type when the status is `publish`, `future` or `private` | GetPage, UpdatePage, UpdateBlock |
| Object | The capability on the object the call acts on, in addition to the generic one: `edit_comment` for a comment, `read_post` for an attachment read, `edit_post` / `delete_post` for a Theme Builder template, `edit_user` / `delete_user` for a user, and for a role change `promote_users` and a role from the editable roles | CommentUpdate, MediaGet, DeleteTemplate, UserUpdate, UserDelete |
| Upload | `upload_files` | UploadMedia |
| Theme | `edit_theme_options` (Full Site Editing writes also need `manage_options`) | UpdateGlobalStyles, UpdateTemplate |
| Admin | `manage_options` | ListPlugins, Health, memory and instruction reads |
| Code | `edit_plugins` + `manage_options`; PhpExecute and the WP-CLI runners also need a super admin on multisite | PhpExecute, the WP-CLI runners, sandbox writers |
| Destructive | The capability of its domain, plus a confirmation token in `production-safe` mode | CommentDelete, UserDelete, DeleteTemplate |

A user cannot change, delete or promote an account, or grant a role, that holds `manage_options`, `promote_users` or `edit_users` when the user lacks it. The last administrator cannot be deleted or demoted.

### production-safe mode

When `stonewright_mode` is set to `production-safe`, every destructive ability, and every real write of an ability that asks for it, needs a confirmation token (see below) and answers `stonewright_confirmation_required` without one. A dry run writes nothing and needs none. The mode is `development`, `staging` or `production-safe`. A site with no stored mode starts in the mode of its environment type: `production` gives `production-safe`, `staging` gives `staging`, anything else gives `development`. Set `production-safe` on sites where a delete or a bulk write must not run from an MCP client without a token bound to that exact call.

### Pre-write backups

Every ability that mutates Elementor data or a theme.json-adjacent structure calls `Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id )` before writing. The snapshot stores a copy of `post_content`, `post_title`, `post_status`, `post_excerpt`, and the Elementor meta keys (`_elementor_data`, `_elementor_page_settings`, `_elementor_version`, `_elementor_edit_mode`, `_wp_page_template`) in post meta; a snapshot of the active kit can also hold the Elementor global classes, global variables and default styles. WordPress native revisions are created in parallel if the post type supports them. A snapshot is stored and read back before the write runs: when that fails, the write does not run and the ability answers `stonewright_backup_failed`.

The backup history is capped at 10 snapshots per post by default. Override with the `stonewright_backup_history_limit` filter.

Restore a snapshot:

```php
Stonewright\WpMcp\Security\Backup::restore( $post_id, $snapshot_id );
```

### Confirmation tokens for destructive operations

In `production-safe` mode, abilities that permanently delete or overwrite data need a short-lived token issued by `stonewright/security-issue-confirmation-token` (`Stonewright\WpMcp\Security\ConfirmationToken::issue()`). The token is signed (HMAC) over the ability name, a hash of the exact arguments, and the user ID. The caller must echo the token back before it expires (five minutes by default; `ttl_seconds` accepts 60 to 3600). This prevents an MCP client from blindly retrying a delete operation after a network interruption.

Tokens are not stored. The nonce of a token that has been used is kept in a transient until the token would have expired, so a second use is refused.

### Human approval

Some changes stop until a person approves them in wp-admin. An agent shows the approval link and stops; it does not open the page or issue a grant itself. The refusal carries `approval_required`, `agent_must_stop` and `approval_url` in the error text a client receives.

- **Custom code** (theme files, Customizer CSS, WPCode and Code Snippets snippets, Elementor custom CSS, theme.json CSS, raw HTML): the typed dry run returns the approval link; an administrator issues a single-use grant bound to the exact site, user, path, language, candidate hash and byte budget. Rolling back a snippet needs the same grant.
- **Undo of a verified change to code** (a theme file, a snippet, a sandbox file or the Customizer CSS): only an administrator pressing Roll back or Undo on the Rescue or Changes page. The ability, the REST route and WP-CLI answer `stonewright_rescue_approval_required`, and a confirmation token does not lift it.
- **Promotion to guidance**: a knowledge candidate becoming an active skill, or an expertise pack becoming `verified` or `stable`, waits for an administrator on the Skills screen. The approval works once, for that exact subject and target, and expires after an hour.
- **Memory that reads as an instruction** to override the plugin rules or move credentials is stored as `needs_review`, is never offered to agents, and waits for an administrator on the Memory page.
- **The Full tool surface** (the `full` profile, with `php-execute`) is chosen by an administrator in wp-admin only.

### Audit log

All write ability executions are recorded in the `{prefix}stonewright_audit_log` custom table. Healthy finalizer heartbeat traffic is excluded. Repeated identical permission and safety denials are scoped by site, ability, and error: the first blocked row and bounded count summaries retain severity while routine repeats are coalesced. Every nested free-text value is credential-redacted before persistence. Each row stores:

- ability name
- user ID
- the sanitized arguments (credentials redacted) and their SHA-256 hash
- result status (`ok`, `error`, `blocked` or `auth`) and the error code
- SHA-256 hash of the client IP and user-agent (salted with `AUTH_SALT` -- actual values are never stored)
- request UUID
- timestamp

Normal writes are append-only. Audit rows are deleted only by an explicitly
configured bounded retention policy. Retention records success only when both
audit and incident batches complete; a failed incident delete leaves the daily
retry eligible. The runtime-history purge (`stonewright/security-runtime-data-purge`)
clears memory, incident and recurring-error history only, and never the audit log:
a call that names the audit log is refused
(`stonewright_runtime_data_purge_audit_requires_administrator`). The purge requires
`manage_options`, a count-only dry run, matching state and plan hashes, a
production-safe confirmation token when applicable, and preserves one cleanup receipt. Read via
`GET /wp-json/stonewright/v1/audit-log` (requires `manage_options`).

### OAuth authorization

Browser OAuth uses Authorization Code with mandatory PKCE S256. Authorization,
token exchange, refresh, and bearer validation bind to the exact canonical MCP
resource; a token issued for another audience is rejected. Protected Resource
Metadata advertises only the `mcp` resource scope, while authorization-server
metadata may advertise `offline_access` for refresh grants.

Refresh tokens rotate after every successful use. Reuse of a revoked token
revokes its complete refresh-token family and all access tokens associated with
that grant. Authorization and token endpoints have atomic rate limits per
connection address (the authorization page per signed-in user); forwarding
headers are never read, and an IPv6 address counts as its /64 prefix. OAuth
secrets and bearer values are never returned by diagnostics or written to the
audit log.

### Runtime execution and environment assertion

On plugin boot, `Stonewright\WpMcp\Security\StaticAnalysis::assert_environment()` checks whether dangerous PHP functions (`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`) are available. If they are and `WP_DEBUG` is on, a warning is logged. Stonewright never calls any of those functions itself.

Stonewright exposes direct PHP runtime execution through the dedicated `stonewright/php-execute` ability. It requires `edit_plugins` and `manage_options` (and a super admin on multisite), captures stdout and returned values, redacts submitted code from audit payloads, and is intended for short WordPress/plugin API snippets where full runtime access is faster than many typed calls. The ability is on the **full** MCP profile only, and no tool-profile `extras` entry, REST call or ability puts it on another profile. An administrator chooses the Full tool surface in wp-admin (Stonewright > Setup > Settings); an agent cannot switch to it. The WP-CLI runners (`wp-cli-run`, `wp-cli-batch-run`, `wp-cli-job-start`) need the same capabilities, and `wp-cli-batch-run` runs read-oriented commands only.

During a snippet, Stonewright wraps `$wpdb` with `ProtectedWpdbWriteGuard`. Direct writes to WordPress core tables (`posts`, `postmeta`, `options`, `users`, `usermeta`) are blocked. Concatenated or aliased protected Elementor/meta keys are blocked at call time, not by source regex. `read_only:true` rejects any mutation. Filesystem mutation of WordPress code files is blocked separately by `ProtectedFilesystemWriteGuard`. Use typed abilities for those writes so backup, permission, and audit gates still run.

### What Stonewright never does

- No generic PHP adapter or shell workaround outside the dedicated
  `stonewright/php-execute` runtime ability.
- No dynamic `include` or `require` of user-supplied strings.
- No `__return_true` used as a `permission_callback`.
- No database queries outside of `wpdb` with prepared statements.
- No file writes outside the places it owns: its folders under the uploads directory, the sandbox's `mu-plugins` copy, the rescue helper in `mu-plugins`, and theme files that a human-issued one-time grant covers.

### Transport security

The Application Password MCP endpoint (`/wp-json/mcp/stonewright`) and the
dedicated OAuth resource (`/wp-json/mcp/stonewright-oauth`) use WordPress REST
routing. Use HTTPS in all non-local environments.

Both routes validate the `Origin` header before anything else runs: a request
without one passes, the site's own origin (home URL and site URL: scheme, host,
and port) and the origins listed through the `stonewright_mcp_allowed_origins`
filter pass, and any other origin is refused with 403 and a JSON-RPC error body.
See [Security](../docs/security.md#web-pages-calling-the-mcp-routes).

The companion HTTP server enforces bearer token authentication (`COMPANION_BEARER_TOKEN`) and an origin allowlist (`COMPANION_ALLOWED_ORIGINS`). The companion writes to WordPress through tokenized WP-CLI execution. It uses `execFile` with argv tokens. Use `stonewright/php-execute` for PHP runtime snippets; WP-CLI PHP and shell entry points such as `wp eval`, `wp eval-file`, `wp shell`, `wp package`, `--exec`, and `--require` remain blocked. In Plugin mode the companion does not call WordPress REST write endpoints. Direct mode (no plugin) is the exception: it writes through core WordPress REST with typed tools and its own protections; see [Security](../docs/security.md#companion-exposure).

## Threat model

**Authenticated agent with insufficient privileges writes to a post it does not own.** Blocked by `edit_post( $post_id )` checks on all per-post abilities.

**MCP client issues a delete command against a production site.** In `production-safe` mode the delete needs a confirmation token bound to that exact call. Enable it on any site where a delete must not run without one.

**Replay attack against a destructive ability.** Blocked by confirmation tokens. Each token is single-use and expires after five minutes by default (`ttl_seconds` accepts 60 to 3600).

**Replay of a rotated OAuth refresh token.** Revokes the full refresh family
and all access tokens for the grant, forcing explicit reauthorization.

**Log tampering to hide malicious writes.** Ordinary operations cannot edit or
delete audit rows, and the runtime-history purge refuses the audit log. Rows
leave the table only through the configured retention window.

**WP-CLI entry point misuse.** The companion rejects WP-CLI PHP and interactive shell entry points before process spawn, runs without a shell, and can restrict working directories with `STONEWRIGHT_WP_ROOT` or `STONEWRIGHT_WP_ALLOWED_ROOTS`.
