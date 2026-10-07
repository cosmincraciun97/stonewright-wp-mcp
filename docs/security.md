# Security

The full security reference lives in [plugin/SECURITY.md](../plugin/SECURITY.md). This page provides additional context on the threat model and hardening recommendations.

## Summary

Stonewright runs direct WordPress automation with four operator-control layers:

1. **WordPress capabilities** — every ability checks a real capability before running.
2. **Pre-write backups** — every write that touches Elementor data or FSE styles snapshots the current state first.
3. **Confirmation tokens** — destructive operations require a short-lived, single-use token to proceed.
4. **Audit log** — every write is recorded in an append-only database table.

## Threat model

### Companion OAuth files

OAuth token files use private POSIX permissions or verified native Windows ACLs.
Windows support requires safe ancestors for both token storage and the process
temporary directory; replaceable paths fail closed without changing existing
ACLs or private state. See [Companion OAuth storage](companion-oauth-storage.md)
for the exact trusted-principal policy, host prerequisites, and restart recovery.

### Agent with excessive permissions

An MCP client that authenticates with an administrator account can call any ability, including destructive ones. Mitigations:

- Set `stonewright_mode` to `production-safe` to block all destructive abilities regardless of authentication level.
- Create a dedicated WordPress user with the minimum capabilities needed for the intended workflow. For read-only agents, `read` is sufficient. For content workflows, `editor` is usually enough.
- Rotate Application Passwords regularly.

### Compromised MCP client

If an MCP client is compromised, an attacker can issue ability calls on behalf of the authenticated user. Mitigations:

- Enable `production-safe` mode on all sites that are not development or staging.
- Monitor the audit log at `/wp-json/stonewright/v1/audit-log` for unexpected
  ability names or unusual argument patterns. Coverage is **Stonewright-owned
  mutations only**: abilities that call `AbilityKernel::audit()` and
  POST/PUT/PATCH/DELETE routes under `stonewright/v1` (central middleware with
  dedupe). Status vocabulary is `ok` | `error` | `blocked`. Unrelated WordPress
  REST traffic is not logged. Successful finalizer heartbeats stay out of the
  stream. Repeated identical permission and safety denials are scoped by site,
  ability, and error: the first blocked event and bounded count summaries retain
  severity while routine repeats are coalesced under an atomic, stale-recoverable
  option lock. Terminal receipts exist as soon as the audit row is authoritative;
  an incident-store failure is reported as bounded secondary metadata and cannot
  cause a duplicate fallback row.
- Treat the Audit page degraded-state notice as a failed safety control, not a
  cosmetic warning. Effect fields distinguish execution, verification, and
  rollback, and the Incidents view isolates failed verification or rollback.
- Use the `ConfirmationToken` mechanism for any custom destructive abilities you add.
- If the client signed in with OAuth, disconnect it under **Stonewright →
  Setup → Connected OAuth clients**. Every live grant of that client closes at
  once, and its pending approvals and unused authorization codes are closed
  first. If it uses an Application Password, revoke that password.

### Custom code and theme-file recovery

`stonewright/php-execute` cannot mutate code files. Theme PHP/CSS/JS changes use
`stonewright/theme-file-patch`: dry-run validation, native-gap evidence,
authenticated wp-admin approval, a single-use grant bound to the exact site,
user, logical path, language, candidate hash, risk class, and byte budget, then
atomic replace, readback, fresh bootstrap smoke, and rollback.

Backups use opaque references. Stored files have a non-executable extension,
restricted permissions, and Apache/IIS access-denial files; the backup directory
also has a blank index. Recovery uses `stonewright/theme-backup-restore`, which
verifies the reference, target, and backup hash before entering the same
transaction and smoke gates. Do not expose or accept absolute backup paths.

### Rescue after a failed change

A change that leaves the site failing is recorded in the change journal and rolled back from the state Stonewright captured before the write. The parts that carry trust:

- The health probe signs in to wp-admin with an internal token, never with the administrator's cookie or an Application Password. A token is stored as a hash, bound to one path, one probe request, and one user. It works for GET only, lasts three minutes, and is used up by its first valid request. The login it creates exists only for that request and is destroyed when the request ends.
- A request that carries the token never follows a redirect. A custom URL to check must have exactly the home URL's scheme, host and port, and is not followed either, so the server is never made to request another host or port.
- Anyone can send the token header. Only a token that exists is audited, when it is accepted or refused. A guess leaves no audit row and touches no transient.
- The journal file is input, not a source of entries: it can add a fatal to a change set the database already holds, and nothing else. It cannot create a change set or a recipe, so planting an entry in it does not put a rollback on the Rescue page. A file over 1 MB is not read.
- A rollback claims its change set under the journal lock before the recipe runs, so a double click, or the page and an ability together, run it once.
- Evidence holds leg names, statuses, HTTP codes, and short reasons. It never holds a URL, a header, or a response body, and the journal redacts secrets before it writes anything.
- `stonewright/rescue-rollback` needs `manage_options`, a confirmation token in production-safe mode (bound to the ability, the arguments, and the user), and records its outcome in the audit log and on the change set. The Rescue page and `wp stonewright rescue` hold the same rules.
- The journal folder is closed to web access with `.htaccess`, `web.config`, and a blank `index.php`, and the journal file name is random.

See [Rescue](rescue.md).

### Supply chain

Stonewright depends on `wordpress/mcp-adapter` ^0.6.1,
`wordpress/php-mcp-schema`, `wordpress/abilities-api`,
`automattic/jetpack-autoloader` ^5.0, and `opis/json-schema`. Check these
dependencies for security advisories on each update. The Composer
`composer.lock` file pins exact versions; review it when updating.

`wordpress/abilities-api` is kept as a compatibility package for WordPress
versions that do not yet ship the Abilities API in core. Packagist marks the
package as abandoned with no replacement, so Stonewright configures Composer
audit to report abandoned packages without failing when there are zero security
advisories. Remove the compatibility package only when Stonewright's supported
WordPress floor includes the core Abilities API.

On WordPress 6.9 and newer, core owns `WP_Ability` and
`WP_Abilities_Registry`; Stonewright's package remains a guarded fallback and
is not a competing loaded owner. The startup preflight considers Stonewright
and active plugins only, ignores inactive plugin manifests, and checks exact
class names, visibility/static modifiers, required and maximum arity,
parameter and return types, nullability/unions, constants, and versions before
any adapter method can run. Troubleshoot reports each blocked symbol and its
active owner/version separately without exposing filesystem paths.

### Companion exposure

The companion Node server must not be exposed to the public internet. Run it on a private network or loopback interface and set `COMPANION_BEARER_TOKEN` and `COMPANION_ALLOWED_ORIGINS` before starting it. The companion can run tokenized WP-CLI commands, including write commands, so treat access to it like access to a privileged local operator. Use `stonewright/php-execute` for PHP runtime snippets; the companion blocks WP-CLI PHP/shell entry points such as `eval`, `eval-file`, and `shell`, and it does not call WordPress REST write endpoints.

Direct writes require a recent task-start bound to the resolved alias and its
canonical target identity. Repointing the alias invalidates that write latch;
the old context cannot create or revoke Application Passwords or perform any
other write against the new target. Direct audit idempotency and incidents use
the same canonical identity rather than the alias. Audit-lock recovery checks
boot/process-start ownership and quarantines a stale lock atomically before
removing it, so PID reuse and replacement-lock races fail closed.

### Plugin data when the plugin is deleted

Deleting the plugin keeps its data: OAuth grants and keys, memory, skills, audit
history, and settings stay in the database. Defining
`STONEWRIGHT_REMOVE_ALL_DATA` as `true` before deleting removes every plugin
table, every `stonewright_` option (the OAuth signing and encryption keys
included), every `stonewright_` and `sw_cc_` transient, and the scheduled
events, on every site of a network, and the change journal files in
`uploads/stonewright-state/` (a file in that folder that Stonewright did not write
stays). See
[Updating Stonewright](updates.md#roll-back-reinstall-or-remove-the-plugin).
Leave the constant undefined unless the data is meant to go.

The rescue helper is not data. It is the file `wp-content/mu-plugins/stonewright-rescue.php`,
which the plugin installed, and deleting the plugin always removes it. Deactivating the
plugin leaves it in place, where it does nothing until the plugin is active again.

## php-execute runtime guards

`stonewright/php-execute` is on the **full** MCP profile only. During a snippet
the plugin wraps the live `$wpdb` handle with a real `wpdb` subclass
(`ProtectedWpdbProxy extends wpdb` via `ProtectedWpdbWriteGuard`):

- The proxy copies the live connection and table prefix, intercepts `query()`
  as the write choke point, and restores the original global in `finally`.
  Type compatibility does not weaken the guard.
- `read_only:true` rejects any WordPress state mutation.
- Direct `$wpdb` `insert` / `update` / `replace` / `delete` / write `query` calls
  against core tables (`posts`, `postmeta`, `options`, `users`, `usermeta`) are
  blocked. Use typed abilities so backup, permission, and audit gates still run.
- Protected Elementor and related meta keys are blocked even when concatenated
  or passed through an aliased `$wpdb` handle. Source-regex cannot see those
  writes; the proxy inspects the resolved table and payload at call time.
- WordPress code-file mutation is blocked by `ProtectedFilesystemWriteGuard`.
- Generic content abilities reject provider-owned executable-code post types
  (`stonewright_custom_code_provider_required`) and never skip KSES to preserve
  PHP. Route those writes through the approval-gated custom-code provider.

These guards do not make php-execute a sandbox. Prefer typed abilities for
Elementor, FSE, options, and post writes.

Direct credentials belong only in private environment configuration or a
permission-restricted `~/.stonewright/sites.json`. Plugin and Direct
memory/skill writes reject high-confidence credential material, and Direct
audit diagnostics redact authorization headers, tokens, and Application
Passwords. Plugin audit persistence recursively redacts the same credential
patterns from every free-text value, including nested error metadata. Release
archives exclude Direct sites config, memory, and audit state.

## Hardening checklist

- [ ] HTTPS enabled on the WordPress installation.
- [ ] `stonewright_mode` set to `production-safe` on production sites.
- [ ] Dedicated Application Password for the MCP client with the minimum required role.
- [ ] `COMPANION_BEARER_TOKEN` set to a strong random value.
- [ ] `COMPANION_ALLOWED_ORIGINS` restricted to known request origins.
- [ ] Companion running on a private network only.
- [ ] Audit log monitored or exported to a centralized logging system.
- [ ] `STONEWRIGHT_REMOVE_ALL_DATA` not defined, unless the plugin's data is meant to be removed when the plugin is deleted.
- [ ] `WP_DEBUG` off in production (prevents diagnostic information leakage).

## Reporting

See [plugin/SECURITY.md](../plugin/SECURITY.md) for the vulnerability reporting process.
