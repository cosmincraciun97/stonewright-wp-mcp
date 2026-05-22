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
| Read | `read` + `is_user_logged_in` | Ping, Info, GetPage |
| Author | `edit_posts`, `edit_pages` | CreatePost, CreatePage, UpdatePost |
| Author + object | `edit_post( $post_id )` | UpdatePage, UpdateBlock |
| Upload | `upload_files` | UploadMedia |
| Theme | `edit_theme_options` | UpdateGlobalStyles, UpdateTemplate |
| Admin | `manage_options` | Environment, ListPlugins, Health |
| Destructive | `manage_options` + mode != `production-safe` | BulkCreate (with overwrite), delete abilities |

### production-safe mode

When `stonewright_mode` is set to `production-safe`, `Permissions::destructive()` returns `false`. Any ability that can overwrite or delete content checks this flag before proceeding. The default mode is `development`; set to `production-safe` on sites where you do not want delete or bulk-write operations reachable from an MCP client.

### Pre-write backups

Every ability that mutates Elementor data or a theme.json-adjacent structure calls `Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id )` before writing. The snapshot stores a copy of `post_content`, `post_title`, `post_status`, and the Elementor meta keys (`_elementor_data`, `_elementor_page_settings`, `_elementor_version`, `_elementor_edit_mode`, `_wp_page_template`) in post meta. WordPress native revisions are created in parallel if the post type supports them.

The backup history is capped at 10 snapshots per post by default. Override with the `stonewright_backup_history_limit` filter.

Restore a snapshot:

```php
Stonewright\WpMcp\Security\Backup::restore( $post_id, $snapshot_id );
```

### Confirmation tokens for destructive operations

Abilities that permanently delete or overwrite data issue a short-lived token via `Stonewright\WpMcp\Security\ConfirmationToken::issue()`. The token is tied to the ability name, a hash of the original arguments, and the user ID. The caller must echo the token back within five minutes. This prevents an MCP client from blindly retrying a delete operation after a network interruption.

Tokens are stored as WordPress transients, so they expire automatically and are not persisted to the database permanently.

### Audit log

All write ability executions are recorded in the `{prefix}stonewright_audit_log` custom table. Each row stores:

- ability name
- user ID
- SHA-256 hash of the sanitized arguments (not the raw values)
- result status (`ok` or an error code)
- SHA-256 hash of the client IP and user-agent (salted with `AUTH_SALT` -- actual values are never stored)
- request UUID
- timestamp

The log is append-only. Rows are never updated or deleted by the plugin. Read via `GET /wp-json/stonewright/v1/audit-log` (requires `manage_options`).

### Static analysis environment assertion

On plugin boot, `Stonewright\WpMcp\Security\StaticAnalysis::assert_environment()` checks whether dangerous PHP functions (`exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`) are available. If they are and `WP_DEBUG` is on, a warning is logged. Stonewright never calls any of those functions itself.

### What Stonewright never does

- No dynamic code execution functions in the plugin codebase.
- No dynamic `include` or `require` of user-supplied strings.
- No `__return_true` used as a `permission_callback`.
- No database queries outside of `wpdb` with prepared statements.
- No arbitrary file writes outside of designated WordPress upload paths.

### Transport security

The MCP endpoint (`/wp-json/stonewright/v1/mcp`) is a standard WordPress REST route. It inherits the same SSL, nonce, and Application Password infrastructure as the WordPress REST API. Use HTTPS in all environments.

The companion HTTP server enforces bearer token authentication (`COMPANION_BEARER_TOKEN`) and an origin allowlist (`COMPANION_ALLOWED_ORIGINS`). The companion never writes to WordPress directly; it communicates exclusively through the WordPress REST API using an Application Password.

## Threat model

**Authenticated agent with insufficient privileges writes to a post it does not own.** Blocked by `edit_post( $post_id )` checks on all per-post abilities.

**MCP client issues a delete command against a production site.** Blocked by `production-safe` mode. Enable it on any site where delete operations must not be reachable.

**Replay attack against a destructive ability.** Blocked by confirmation tokens. Each token is single-use and expires in five minutes.

**Log tampering to hide malicious writes.** The audit table is append-only. Deletion of audit rows requires direct database access beyond the WordPress application layer.

**Figma token leakage.** The `FIGMA_TOKEN` environment variable is read by the companion and never sent to the WordPress database unless the operator explicitly sets `stonewright_figma_token` as a fallback. Prefer the environment variable.
