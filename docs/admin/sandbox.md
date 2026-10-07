# Sandbox

**Stonewright → Custom code** is a file manager for PHP drafts stored in
`wp-content/stonewright-sandbox/`. Drafts are never auto-loaded — they only
run after an explicit admin activation step that writes the file into
`mu-plugins/` under a prefixed name.

Drafts and their backups are stored without a PHP extension, so no web server
can run them: a draft `slug.php` is the file `slug.draft`, and its backups are
`slug.<unix time>.bak`. Activation is the only step that produces executable
code.

The page is a hub: its tab bar holds Drafts, Library, Active (MU plugins) and Crash
recovery, plus Approvals (the custom-code approval page). The address is
`admin.php?page=stonewright-sandbox`, with `tab=drafts`, `library`, `mu-plugins` or
`crash-recovery`; the page was named Sandbox before, and the address did not change.

Sources:
- `plugin/includes/Admin/SandboxPage.php`
- `plugin/includes/Sandbox/SandboxFiles.php`
- `plugin/includes/Sandbox/StaticGuard.php`

Audit events are not duplicated inside Sandbox. Use the dedicated
**Stonewright → Activity → Audit log** page; old `?tab=audit` links show a short move
notice and a direct link there.

---

## File lifecycle

```
Draft (sandbox dir)
  │
  └──[Activate]──> Static analysis ──pass──> Active (mu-plugins copy)
                                   │
                                   └──fail──> Error displayed, draft untouched
Active
  ├──[Disable]──> Disabled (.disabled suffix on mu-plugins file)
  ├──[Deactivate]──> Draft only (mu-plugins copy removed)
  └──[Crash]──> Crashed (.crashed suffix via CrashRecovery shutdown handler)

Disabled
  └──[Enable]──> Active (suffix removed)

Crashed
  └──[Manual review required; file must be edited or deleted]
```

### Status meanings

| Status | Where the file lives | PHP loads it? |
|---|---|---|
| **Draft** | `wp-content/stonewright-sandbox/slug.draft` only | No |
| **Active** | Draft + `wp-content/mu-plugins/stonewright-sandbox-slug.php` | Yes, on every request |
| **Disabled** | Draft + `mu-plugins/stonewright-sandbox-slug.php.disabled` | No |
| **Crashed** | Draft + `mu-plugins/stonewright-sandbox-slug.php.crashed` | No |

### Filename rules

Filenames must match the pattern `^[a-z0-9_\-]+\.php$`; the file name field on
the page uses the same pattern. Uppercase letters, spaces, dots other than the
terminal `.php`, and path separators are all rejected. Maximum file size is
200 KB (204,800 bytes).

### Storage and web access

- Drafts are stored as `<name>.draft` and backups as `<name>.<unix time>.bak`.
  A backup is made before every write, edit, and rollback; the last ten are
  kept. **Delete** removes the draft, all of its backups, and its `mu-plugins/`
  copies.
- The folder carries an `.htaccess` that denies every request on Apache 2.4
  (`Require all denied`) and 2.2 (`Order deny,allow` / `Deny from all`), a
  `web.config` that denies every request on IIS, and an `index.php` whose first
  statement is `defined( 'ABSPATH' ) || exit;`. The guard files are written again when one is missing
  and brought up to date the first time the folder is used after an update.
- A server that ignores `.htaccess` (nginx, PHP's built-in server) does not run
  these files, but it can still serve their text by URL. Deny the folder in the
  server configuration as well, for example `location /wp-content/stonewright-sandbox/ { deny all; }`
  on nginx.
- Activation runs the static analysis gate and then writes
  `mu-plugins/stonewright-sandbox-slug.php` with `defined( 'ABSPATH' ) || exit;`
  as its first statement (after any leading `declare` and `namespace`
  statements, on the opening line, so line numbers do not move). The active file
  stops at once when a web server runs it outside WordPress. A draft that opens
  with a `declare(...) { }` block cannot take that statement and is not
  activated.
- Elementor widget files written by the widget abilities are stored the same
  way (`widget-slug.draft`, pending ones as `widget-slug.pending.draft`); the
  widget loader reads them only inside WordPress, after the static analysis gate.
- The first time the folder is used after an update, drafts and backups written
  by earlier versions (`slug.php`, `slug.php.<time>.bak.php`,
  `widget-slug.pending.php`) are renamed to the current names. A file that
  cannot take its new name because the name is taken, and any other file in the
  folder that a web server could run as PHP, is renamed to `<name>.quarantined`
  (its `.php` becomes `_php`). Nothing is overwritten or deleted. Backups whose
  draft no longer exists are kept under the new name; **Delete** on that name
  removes them.

---

## Static analysis gate

Before a draft is copied to `mu-plugins/`, `StaticGuard::scan()` checks the
source for dangerous constructs. Any match returns a human-readable error and
aborts the activation. The blocked patterns are:

| Pattern | Detection method |
|---|---|
| `eval` keyword | PHP tokenizer (`T_EVAL`) |
| `assert(string)` | Regex heuristic on `assert\s*\(\s*['"]` |
| `create_function` | Tokenizer — `T_STRING` + `(` lookahead |
| `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `pcntl_exec` | Same as above |
| Short-echo tag `<?=` | Regex |
| Backtick execution operator | Regex — backtick following whitespace/operator |
| `include`/`require` of `http(s)://` URLs | Tokenizer + lookahead for remote URL |
| `base64_decode` combined with any execution function | Tokenizer — 50-token lookahead |

Static analysis does not catch every possible obfuscation; it is one layer in
a defence-in-depth strategy that also includes crash recovery and the
confirmation token.

### Confirmation token in production-safe mode

When `stonewright_mode` is `production-safe`, the Activate, Delete, and
Enable actions require a `confirmation_token` obtained from the
`stonewright/security-issue-confirmation-token` MCP ability. Attempting the
action without a valid token returns an error. This forces a deliberate
decision rather than an accidental click.

---

## Inline editor

Clicking **Edit** for any file opens an inline textarea below the table,
pre-populated with the draft's current contents. Saving posts to
`admin-post.php?action=stonewright_sandbox_save` with a valid nonce. The
handler calls `SandboxFiles::write()` which enforces size and name rules.

### Creating a new file

The **+ New File** button reveals a hidden form. Enter a filename (e.g.
`my-hook.php`) and initial contents, then click **Create File**. The new
draft appears in the table with status **Draft**.

---

## Crash recovery

A `register_shutdown_function` handler fires on fatal errors. If the fatal
trace references a file matching `mu-plugins/stonewright-sandbox-*.php`, the
handler:

1. Renames the file to `mu-plugins/stonewright-sandbox-slug.php.crashed`.
2. Logs the error to the Stonewright audit log.
3. Schedules an admin notice shown on the next page load.

The file is no longer loaded on subsequent requests. To recover, open the
draft in the editor, fix the error, and re-activate (which re-runs static
analysis before copying back to `mu-plugins/`).

---

## Stonewright sandbox vs other plugin sandboxes

Some plugins auto-load every file placed in a designated directory. Stonewright
deliberately does not. A file in `wp-content/stonewright-sandbox/` never
executes until an admin explicitly clicks **Activate**, the static analysis
gate passes, and (in production-safe mode) a confirmation token is provided.
This separation between "draft storage" and "execution" is intentional.
