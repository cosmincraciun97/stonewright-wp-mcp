# Security

The full security reference lives in [plugin/SECURITY.md](../plugin/SECURITY.md). This page provides additional context on the threat model and hardening recommendations.

## Summary

Stonewright runs direct WordPress automation with five operator-control layers:

1. **WordPress capabilities** — every ability checks a real capability before running, on the object it acts on where there is one.
2. **Pre-write backups** — every write that touches Elementor data or FSE styles snapshots the current state first.
3. **Confirmation tokens** — destructive operations require a short-lived, single-use token to proceed in `production-safe` mode.
4. **Audit log** — every write is recorded in an append-only database table.
5. **Human approval** — custom code, the undo of a change to code, a snippet rollback, promotion of knowledge to guidance, memory that reads as an instruction, and the Full tool surface wait for an administrator in wp-admin.

## Threat model

### Companion OAuth files

OAuth token files use private POSIX permissions or verified native Windows ACLs.
Windows support requires safe ancestors for both token storage and the process
temporary directory; replaceable paths fail closed without changing existing
ACLs or private state. See [Companion OAuth storage](companion-oauth-storage.md)
for the exact trusted-principal policy, host prerequisites, and restart recovery.

### Agent with excessive permissions

An MCP client that authenticates with an administrator account can call any ability, including destructive ones. Mitigations:

- Set `stonewright_mode` to `production-safe` so every destructive ability needs a confirmation token bound to its exact call, whatever the authentication level. A site with no stored mode starts in the mode of its environment type (`production` gives `production-safe`), on every site of a network.
- Create a dedicated WordPress user with the minimum capabilities needed for the intended workflow. The MCP servers admit an account only when it holds `edit_posts` (see [Who can use the MCP servers](#who-can-use-the-mcp-servers)), so a contributor is the smallest role that can connect. For content workflows, `editor` is usually enough.
- Rotate Application Passwords regularly.

### Who can use the MCP servers

Every WordPress account holds `read`, and WordPress lets every account create an Application Password, so `read` would let a subscriber or a shop customer call the abilities that only ask for it. Both MCP routes (`mcp/stonewright`, with an Application Password, and `mcp/stonewright-oauth`, with an OAuth bearer) therefore admit only an account that holds `edit_posts`: contributors, authors, editors and administrators. A subscriber's request gets the same refusal as any other request WordPress does not allow, and an OAuth grant is checked again at the consent screen, the code exchange, each refresh and each request, so the screen is not offered to an account that could not use the tools and a grant stops working when its account loses the capability.

A site can change the capability with the `stonewright_mcp_required_capability` filter, which receives `edit_posts` and returns a capability name (for example `read` to admit every account, or `publish_posts` to keep contributors out); a value that is not a non-empty string is ignored. The filter applies to both routes and to the OAuth consent pages.

Site knowledge is for administrators even when an account may connect: `task-start` and `context-bootstrap` return the matched site memory, custom instructions, user context, recurring errors and incident actions only to an account with `manage_options`, as `memory-list`, `memory-get` and `system-instructions-get` do; any other account gets the rest of the context with those sections empty.

### Permissions per object

A capability such as `edit_users` says that a user may manage accounts in general, not which account. wp-admin checks the capability on the object, and so do the abilities that act on one:

- **Users.** `user-update`, `user-create` and `user-delete` check `edit_user` or `delete_user` for the target. A role change also needs `promote_users`, `promote_user` for the target and a role from the editable roles. A user cannot change, delete or promote an account, or grant a role, that holds `manage_options`, `promote_users` or `edit_users` when the user lacks it, and on multisite only a super admin can change a super admin. The last administrator cannot be deleted or demoted, and `reassign` must name another existing user. `user-app-passwords` checks the target user for each action and only where application passwords are available for that user.
- **Settings.** `settings-update` checks every key before it writes any, and `default_role` cannot be `administrator` or a role with an elevated capability.
- **Comments and media.** The comment abilities check `edit_comment` for the comment, and `comment-list` returns only comments the user may edit. `media-get` and `media-list` check `read_post` for each attachment.
- **Theme Builder.** The abilities need `edit_theme_options` and, on a template, `edit_post` or `delete_post`. A template is moved to the trash; `force` is refused.
- **History and restore.** `change-log` lists only posts where `edit_post` holds, and `change-restore` needs the publish capability of the post type when the snapshot is published, scheduled or private.
- **Reads that held private data.** `memory-list`, `memory-get` and `system-instructions-get` need `manage_options`, and so does the site knowledge that `task-start` and `context-bootstrap` return.
- **Search and outbound requests.** `search-query` and `oembed-resolve` need `edit_posts`; `oembed-resolve` fetches third-party URLs from the server, as the oEmbed proxy of WordPress core does. `search-query` lists a post type the caller cannot edit only when the site shows that type to visitors (`is_post_type_viewable()`); otherwise it refuses with `stonewright_search_post_type_not_allowed`. `stock-image-search` needs `upload_files`, as `stock-image-import` does, because a search spends the site's stock-photo quota; the search terms are percent-encoded in the provider request.

### Compromised MCP client

If an MCP client is compromised, an attacker can issue ability calls on behalf of the authenticated user. Mitigations:

- Enable `production-safe` mode on all sites that are not development or staging.
- Monitor the audit log at `/wp-json/stonewright/v1/audit-log` for unexpected
  ability names or unusual argument patterns. Coverage is **Stonewright-owned
  mutations**: abilities that call `AbilityKernel::audit()` and
  POST/PUT/PATCH/DELETE routes under `stonewright/v1` (central middleware with
  dedupe), plus the write abilities of Elementor's own MCP server (`elementor/*`), which an
  observer records as `observed_native` after they ran (it never blocks them).
  Status vocabulary is `ok` | `error` | `blocked` | `auth`. Unrelated WordPress
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

### Web pages calling the MCP routes

The MCP transport asks a server to validate the `Origin` header, so a web page
cannot drive the server from a visitor's browser (DNS rebinding and cross-site
requests). Stonewright checks it on both routes, `mcp/stonewright` and
`mcp/stonewright-oauth`, before the OAuth bearer check and before any ability
runs.

- A request **without** an `Origin` header (or with an empty one) passes.
  Command-line, desktop, and server-side clients do not send one.
- A request from the site's own origin passes: the origin of the home URL or of
  the site URL, compared by scheme, host, and port. For a site at
  `https://example.com`, `https://example.com:443` is the same origin and
  `http://example.com` is not.
- A request from an origin the operator lists passes. Add a browser-based tool
  with the `stonewright_mcp_allowed_origins` filter, which receives a list of
  `scheme://host[:port]` strings and the request:

  ```php
  add_filter( 'stonewright_mcp_allowed_origins', static function ( array $origins ): array {
      $origins[] = 'http://localhost:6274';
      return $origins;
  } );
  ```

  A wildcard, a value with a path, and `null` are ignored.
- Any other `Origin`, including the opaque `null`, is refused with **403** and a
  JSON-RPC error body without a request id (`error.code` -32008), for every
  method including a CORS preflight.

The check is not authentication: a request that passes still needs valid
credentials and the permissions of its user. A refused request never reaches the
MCP transport or an ability.

### OAuth rate limits behind a reverse proxy

The OAuth endpoints are limited per client address, and the registering address
of a client is stored as a keyed hash. The address is the one the web server
reports for the connection (`REMOTE_ADDR`). Behind a reverse proxy or load
balancer every client then has the address of the proxy and shares one budget.

A site behind a proxy can name it. The setting is **off by default**: with no
trusted proxy configured, `X-Forwarded-For` is never read, so a client cannot
choose its own budget by sending that header.

```php
// wp-config.php: a list, or one comma-separated string.
define( 'STONEWRIGHT_TRUSTED_PROXIES', [ '10.0.0.0/8', '2001:db8:aaaa::/48' ] );

// Or from code. The filter receives the list the constant holds.
add_filter( 'stonewright_trusted_proxies', static function ( array $proxies ): array {
    $proxies[] = '192.0.2.10';
    return $proxies;
} );
```

- Each entry is an IP address or a CIDR range, IPv4 or IPv6. An entry that is
  not readable, and a range with a prefix length of 0, are skipped.
- Only when `REMOTE_ADDR` is inside the list is the header read. The client is
  the right-most `X-Forwarded-For` address that is not itself a trusted proxy,
  so values the client wrote on the left cannot replace the address the first
  trusted proxy saw. When every address in the chain is a trusted proxy, the
  connection address is used.
- A header that holds anything other than a comma-separated list of IP
  addresses (a name, `unknown`, a value with a port, an empty entry) is ignored
  as a whole and the connection address is used.
- The same address is used for every OAuth rate-limit and abuse-budget key. An
  IPv6 address still counts as its `/64` prefix and an IPv4-mapped IPv6 address
  as the IPv4 address it carries.

List only proxies you operate, and make sure they replace or append to
`X-Forwarded-For` rather than pass a client's header through unchanged at the
edge. Other uses of the connection address, such as the address a one-time
sign-in link is bound to, keep reading `REMOTE_ADDR`.

### Site policy filters on abilities

WordPress 7.1 runs lifecycle filters inside an ability call, so site policy and
security plugins can govern abilities. Stonewright abilities run the three
filters that sit in the methods Stonewright replaces, on every supported
WordPress version, and the core filters in the methods it does not replace on
WordPress 7.1 and later:

| Filter | Runs | What a filter can do |
|---|---|---|
| `wp_ability_validate_input` | after the input schema check | refuse a call, or reword a refusal |
| `wp_ability_permission_result` | after the ability's permission callback | withdraw a grant, or reword a refusal |
| `wp_ability_validate_output` | after the output schema check | withhold a result, or reword a refusal |
| `wp_pre_execute_ability`, `wp_ability_normalize_input`, `wp_ability_execute_result` | inside WordPress's own `execute()` (7.1 and later) | as WordPress documents them |

A filter can refuse or narrow a call. It cannot approve a call that Stonewright
refused: a failed schema check, a denied permission callback, and a failed
output check stay refusals whatever the filters return, so no filter can lift a
Stonewright gate (permission callbacks, confirmation tokens, modes, backups,
validation, audit). A permission callback that is missing, throws, or returns
anything other than `true` or an error also refuses the call.

Each filter runs once for one `execute()` call. The MCP transport checks
permissions before it executes, so the normalize-input, input-validation, and
permission filters run twice for one MCP tool call.

Two paths run an ability without its registered object and so without these
filters: the `stonewright-execute-ability` tool (the `discover-execute` profile)
and `POST /wp-json/stonewright/v1/abilities/run`. Both apply the same Stonewright
gates and the list of disabled abilities, and both validate the arguments against
the input schema of the target (a bad argument is a 400) before its permission
callback.

One check decides whether an ability may run at all, and every path that runs an
ability by name makes it before anything else: MCP tools, `execute-ability`, the
REST runner, and WordPress's own Abilities API route
(`/wp-json/wp-abilities/v1/abilities/<name>/run`). It covers the master toggle,
the list of disabled abilities (`stonewright_disabled_abilities`), and the tool
surface. An ability that runs or writes code (`php-execute`, the sandbox writers,
the WP-CLI command runners, `custom-code-provider`, the custom widget definers)
and an ability the surface hides are refused with
`stonewright_ability_not_on_surface` unless the surface an administrator chose
in wp-admin, or the session profile, lists them. A confirmation token never lifts
that refusal: the REST runner refuses such an ability before it reads a token, and
`security-issue-confirmation-token` issues no token for one (for the ability
itself, or as the target of the REST runner). The refusal carries the settings
link and `agent_must_stop`. A confirmation token still unlocks an ordinary
ability outside the active profile on the REST runner. To block an ability on
every path, disable it (`stonewright_disabled_abilities`).

The plugin has no REST route that writes, activates or deletes sandbox files.
Sandbox files are managed on the Sandbox Library admin page and by the `sandbox-*`
abilities, which are on the Full surface only.

### Tool annotations are hints

Every ability declares MCP tool annotations (read-only, destructive,
idempotent, open-world) so a client can tell a read from a write before it calls
a tool. They describe the ability for the client; they grant nothing and remove
nothing. Every gate is enforced by Stonewright on every call, whatever a client
does with the hints. See [Abilities](abilities.md#tool-annotations-and-exposure).

### Custom code and theme-file recovery

`stonewright/php-execute` is not a route for code files: a static scan refuses
common patterns that write theme, plugin or core files, but it is a best-effort
guard, not a barrier (see [php-execute runtime guards](#php-execute-runtime-guards)).
Theme PHP/CSS/JS changes use `stonewright/theme-file-patch`: dry-run validation, native-gap evidence,
authenticated wp-admin approval, a single-use grant bound to the exact site,
user, logical path, language, candidate hash, risk class, and byte budget, then
atomic replace, readback, fresh bootstrap smoke, and rollback.

Backups use opaque references. Stored files have a non-executable extension,
restricted permissions, and Apache/IIS access-denial files; the backup directory
also has a blank index. Recovery uses `stonewright/theme-backup-restore`, which
verifies the reference, target, and backup hash before entering the same
transaction and smoke gates. Do not expose or accept absolute backup paths.

Rolling back a WPCode or Code Snippets snippet through
`stonewright/custom-code-provider` rewrites code, so it needs the same
human-issued one-time grant as an apply, bound to the code the snapshot restores.
`rollback` with `dry_run: true` stages the restore at the approval page and
writes nothing. A rollback without the grant answers
`stonewright_custom_code_grant_required` with `approval_required`,
`agent_must_stop` and the approval link; the agent shows it and stops, and does
not issue or use a grant itself. The restore that runs after a failed apply, in
the same call, does not ask again.

Every refusal that waits for a person (a promotion to guidance, a custom-code
grant, a snippet rollback, an undo of code, the full profile) carries its
`code`, `approval_required`, `agent_must_stop` and `approval_url` in the error
text an MCP client receives, not only in the error data a REST client reads, so
the client can show the link and stop. A link that is not an http or https
address is left out.

### Memory that reads as an instruction

Memory is read back to agents as context, so a stored line that tells an agent to ignore the plugin's rules is an instruction. `memory-save`, `learning-record`, `memory-generalize`, `knowledge-import`, `incident-repair-record` and `POST /stonewright/v1/memory` screen the text the way imported skills are screened. Text that tells an agent to override the plugin rules, safety gates or confirmation tokens, or to send credentials elsewhere, is stored with the status `needs_review` (the answer says `needs_review: true`). Nothing is dropped silently: the entry is not offered at task start or as site context, it drafts no skill, and a later save under its key does not clear it. An administrator approves or discards it on the Memory page. Prohibitions and plain mentions of credentials are not flagged, and the entry form of the Memory page is not screened, because a person types there. In Direct mode the companion screens `stonewright-learning-record` and `stonewright-incident-repair-record` the same way; a person clears an entry there by deleting it or by setting its status to `active` in the local memory file.

### Rescue after a failed change

A change that leaves the site failing is recorded in the change journal and rolled back from the state Stonewright captured before the write. The parts that carry trust:

- The health probe signs in to wp-admin with an internal token, never with the administrator's cookie or an Application Password. A token is stored as a hash, bound to one path, one probe request, and one user. It works for GET only, lasts three minutes, and is used up by its first valid request. The login it creates exists only for that request and is destroyed when the request ends.
- The page of a published post, and the front page after a kit write, is requested as an anonymous visitor and carries a mark-only token instead. It is issued for nobody, bound to one path and one nonce, single use, lasts three minutes, and is checked like the login token. It never logs anyone in. Only a valid token marks a request as a probe request, which stops Elementor from printing Google fonts for it. A header, parameter or cookie that is not a valid token marks nothing.
- A request that carries the login token never follows a redirect. A custom URL to check must have exactly the home URL's scheme, host and port, and is not followed either, so the server is never made to request another host or port. The one exception is a request that carries a mark-only token: the probe follows a redirect that has exactly the home URL's scheme, host and port, at most twice, with a new mark-only token for each path. A redirect to another host, scheme or port is not followed.
- Anyone can send the token header. Only a token that exists is audited, when it is accepted or refused. A guess leaves no audit row and touches no transient.
- The journal file is input, not a source of entries: it can add a fatal to a change set the database already holds, and nothing else. It cannot create a change set or a recipe, so planting an entry in it does not put a rollback on the Rescue page. A file over 1 MB is not read.
- A rollback claims its change set under the journal lock before the recipe runs, so a double click, or the page and an ability together, run it once.
- Evidence holds leg names, statuses, HTTP codes, and short reasons. It never holds a URL, a header, or a response body, and the journal redacts secrets before it writes anything.
- `stonewright/rescue-rollback` needs `manage_options`, a confirmation token in production-safe mode (bound to the ability, the arguments, and the user), and records its outcome in the audit log and on the change set. The Rescue page and `wp stonewright rescue` hold the same rules.
- A verified change can be rolled back, but a verified change to code (a theme file, a custom-code snippet, a sandbox file or the Customizer CSS) is rolled back only by an administrator at the Rescue page. The ability, the REST route and WP-CLI refuse it with `stonewright_rescue_approval_required`, and a confirmation token does not lift the refusal.
- `stonewright/change-rollback` (undo or redo from the change history), `stonewright/change-history-list` and `stonewright/change-diff-get` need `manage_options`. The rollback engine verifies the production-safe confirmation token itself, once, over the change id, `force_drift`, `expected_current_sha256` and `permanent`; the ability does not verify it again. An undo of code is never run on an ability or WP-CLI call: the answer is `stonewright_rescue_approval_required`, and only an administrator pressing Undo on the Changes page approves it. The list and the diff return short rows and masked, capped diffs, never a stored image, a blob name or a whole hash.
- A rollback of a verified change, or of a change that was never verified, saves the current state first (the whole Elementor kit domain the snapshot covers included) and refuses with `stonewright_rescue_undo_capture_failed` when it cannot, probes the site before and after, and puts the saved state back with `stonewright_rescue_undo_reverted` when the rollback made a working site fail. A rollback over a later edit is refused with `stonewright_change_drift` before a confirmation token is looked at, until `force_drift` together with the `expected_current_sha256` of the dry run confirms it (`force_drift` alone is refused with `stonewright_change_drift_hash_required`); a change with an incident or a failed rollback recorded against it is recovered without that confirmation while a health check fails now, and needs no change history. Check again closes an incident only when every check that passed before the change passes again.
- The journal folder is closed to web access with `.htaccess`, `web.config`, and a blank `index.php`, and the journal file name is random.

See [Rescue](rescue.md).

### Section reuse

Section reuse copies content between posts, so its checks are about who may read what and what a copy may carry.

- **Reading.** `section-reuse-find` lists a source only when the current user holds both `read_post` and `edit_post` for it, whatever its status (publish, draft, pending, future or private), and does not count the posts it skipped, so the scan limit and `scan.truncated` do not depend on posts the user may not use. `section-reuse-extract` requires the same two capabilities for the source post, in the permission callback and again when it runs, and answers a post the user may not edit exactly as it answers one that does not exist (`stonewright_not_found`), so a private post is neither readable nor confirmed to exist. Nothing of a source is stored apart from the signature cache, and the cache is read only for a source the current user may use. The page being built is never offered. An insert is refused (`stonewright_section_source_not_permitted`) when the user cannot read and edit the source its payload names, and `reuse_source` records only sources the user may read and edit.
- **The payload is untrusted.** An insert checks the payload's shape, builder, size, and depth, regenerates every element id, and never takes an id, a style id, or a post id from it as given. A payload for another builder, or one that mixes V3 and V4 nodes, is refused: sections are never converted.
- **Nothing is relaxed.** A copy goes through the same write closure as any write of its family: permission, mode, confirmation token where the family requires one, `Backup::snapshot_post()`, write lock, readback, post-scoped CSS only through `elementor-css-regenerate`, ChangeSetV1, audit. Custom CSS, HTML widgets, and raw HTML or script in a copied section need the same approvals as when they are written by hand, and CSS classes must be in the `stonewright_approved_css_classes` option of the site; `section-reuse-extract` warns about each before the copy. A placeholder widget (a plugin widget whose plugin is not active) is never inserted. V4 writes stay blocked in `production-safe`. A copy never writes to the source post or its meta; the signature cache is one non-autoloaded option. Settings of an Elementor V3 copy that the live schema rejects are removed only when the operation lists exactly those settings in `drop_settings`; the list must equal the rejected settings, the confirmation token of `production-safe` covers it as part of the call, and the custom code gate still refuses every CSS class and custom CSS the list does not name.
- **The setting is a policy, not a hint.** Hiding the tools is a convenience; every reuse ability and insert operation reads the live option when it runs and fails with `stonewright_section_reuse_off`. A change of the option is audited and needs `manage_options` and the nonce of the Setup form.
- **Notices carry no secrets.** The fifteen-minute line is a fixed sentence that names the setting value.

### Values that reach generated CSS

Elementor prints colour, typography, unit and spacing values into the generated stylesheet without escaping them, so a value such as `red;}body{display:none}` would hide a page and would carry CSS past the custom-CSS approval gate, which guards only `custom_css` keys. Stonewright accepts these values only in their real forms:

- **Colour controls**: hex with 3, 4, 6 or 8 digits; `rgb()`, `rgba()`, `hsl()` and `hsla()` with numeric arguments; a CSS colour name; `transparent`; `currentColor`; an Elementor global colour variable (`var(--e-global-color-<id>)`); or an empty string, which clears the colour. A `__globals__` binding is the stored `globals/<type>?id=<id>` form or an empty string.
- **Typography**: font families are plain names (letters, digits, spaces and `. , + & -`); weight is `normal`, `bold`, `bolder`, `lighter` or 1 to 1000; text transform, font style and text decoration come from fixed lists.
- **Slider, dimension and shadow values**: numeric parts and a unit from a fixed list; shadow colours follow the colour rule.
- **Anything else** is refused with `stonewright_elementor_settings_invalid` and the key. The check runs in every Elementor element write, in `elementor-v3-update-kit-colors`, `elementor-v3-update-kit-typography`, `elementor-v3-kit-batch-mutate` and `elementor-v3-update-page-settings`.
- **Before generation**, `elementor-css-regenerate` scans the stored settings of the post (and its page or kit settings) for `;`, braces, `<`, `>`, `url(`, `expression(`, `@import`, comment markers and backslashes under colour, typography, unit and numeric-side keys. A match is refused with `stonewright_elementor_css_unsafe_value` and the paths, before any backup, lock or generation. Custom CSS keys are not scanned there; they stay under the custom-code approval gate.

Atomic (`e-*`) widgets keep structure-only validation, as before.

### Elementor's own abilities

`stonewright-elementor-native-execute` runs one certified ability of Elementor's own MCP integration in-process, inside Stonewright's snapshot, write lock, readback, rollback and audit. The tool exists when the native Elementor state is `available` and at least one ability is certified; the experimental V4 flag plays no part. It plans first (`dry_run` is on by default), and its permission follows the family of the ability: `edit_theme_options` for the kit defaults, `edit_post` on the post for a tree composition or a structure read. A read and a dry run need no confirmation token in any mode; a real write needs one in `production-safe` mode, bound to its arguments. An ability that clears generated CSS for the whole site is refused, and a change to a published page that Elementor stages in an autosave is reported as `staged_in_autosave`, never as applied.

Elementor's own write abilities, when something other than this tool runs them (for example Elementor's own MCP server), are not blocked or changed. An observer takes a snapshot first, and after the call records a change-history row and an audit row marked `observed_native`; it opens no Rescue frame, so such a call is not probed or rolled back automatically.

### Design mirror export

`stonewright-design-mirror-export` returns the Elementor JSON of the posts the caller can edit in the ability result; it writes no file. Nothing is published under `wp-content/uploads`, so a private or draft page is never readable without authentication. The JSON per call is capped at 1.5 MB; a post that would pass the cap is reported with `response_too_large`.

Earlier versions wrote `uploads/stonewright-mirror/<slug>.json`. On plugin update, and on each export call, that folder gets `index.php`, `.htaccess` and `web.config` deny rules (existing guard files are kept), and the regular `.json` files directly inside it that carry the export format (`post_id` and `elementor` keys) are deleted. Symbolic links, subfolders and every other file stay. The outcome is logged as counts only. An export can be run again at any time to recreate the JSON.

### Elementor knowledge store

`elementor-knowledge-refresh` stores articles in `uploads/stonewright-private/knowledge/elementor/<hub>/<slug>.md`, and `elementor-knowledge-search`, `elementor-explain-editor` and `elementor-describe-widget` read only that folder. The plugin package ships no articles, so the readers return nothing, with a hint that names the refresh ability, until the first refresh.

- **Guarded folders.** Every folder level the store creates (`stonewright-private`, `knowledge`, `elementor` and the hub folder) gets `index.php`, `.htaccess` and `web.config` deny rules; existing guard files are kept. If the folder cannot be created or guarded, the refresh writes nothing and reports `stonewright_store_unavailable`.
- **Hosts.** The URL host must be `elementor.com` or end in `.elementor.com`. A host such as `evilelementor.com` or `elementor.com.example.test` is refused before anything is fetched.
- **Hubs and names.** `hub` must be one of `widgets`, `editor`, `theme`, `developer`, `custom-widget` and `help-root`, checked in code before a path is built. The file name comes from the last URL segment reduced to letters, digits, dot, dash and underscore, and it starts with a letter or digit. A path that is a link, or that resolves outside the store, is refused.
- **Permissions.** The refresh needs `manage_options` and is audited; the three readers need only the read permission.
- **Older files.** Articles that an earlier version wrote next to the plugins directory are not read, moved or deleted.

### Confirmation token details

A token is issued by `security-issue-confirmation-token` for one ability and its exact arguments (every argument except `confirmation_token`), works once, and lives 60 to 3600 seconds (`ttl_seconds`, default 300; a value outside that range is refused by the input schema, and the lifetime is never shorter than 60 seconds). `expires_at` in the answer is the real expiry. `elementor-create-custom-widget` follows the same rule: its token covers the full argument object.

A dry run of an Elementor write ability needs no token in `production-safe` mode because it writes nothing: no snapshot, no post meta and no write lock. This holds for `elementor-v3-update-element`, `elementor-v3-batch-mutate` and `elementor-v3-build-page-from-spec`. The write itself still needs a token bound to its arguments, and a token issued for a dry run does not authorize the write (`dry_run` is one of the bound arguments).

### Supply chain

Stonewright depends on `wordpress/mcp-adapter` ^0.6.1 (which brings
`wordpress/php-mcp-schema`), `wordpress/abilities-api`,
`automattic/jetpack-autoloader` ^5.0, `defuse/php-encryption` ^2.4, and
`opis/json-schema`. Check these dependencies for security advisories on each
update. The Composer `composer.lock` file pins exact versions; review it when
updating.

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
active owner/version separately without exposing filesystem paths. A copy of the adapter that
another plugin loaded first is accepted only when it passes the same signature checks from version
0.5.0; otherwise Stonewright registers nothing through it, says which plugin owns it, and the two MCP routes answer 503.

### Companion exposure

The companion Node server must not be exposed to the public internet. Run it on a private network or loopback interface and set `COMPANION_BEARER_TOKEN` and `COMPANION_ALLOWED_ORIGINS` before starting it. The companion can run tokenized WP-CLI commands, including write commands, so treat access to it like access to a privileged local operator. Use `stonewright/php-execute` for PHP runtime snippets; the companion blocks WP-CLI PHP/shell entry points such as `eval`, `eval-file`, and `shell`. In Plugin mode the companion does not call WordPress REST write endpoints; it proxies to the plugin's MCP endpoint. Direct mode (no plugin) is the exception: it writes through core WordPress REST, and through local WP-CLI where the target check passes, using only its typed Direct tools.

Direct writes require a recent task-start bound to the resolved alias and its
canonical target identity. Repointing the alias invalidates that write latch;
the old context cannot create or revoke Application Passwords or perform any
other write against the new target. Direct audit idempotency and incidents use
the same canonical identity rather than the alias, and so does Direct memory: a
lesson is filed under the site's canonical URL, so an alias pointed at another
site does not inherit the old site's memory. Audit rows for local work
(`stonewright-command-*`, `stonewright rescue`) carry `backend: local` and open no
Direct incident; `task-start` counts only the recurring errors of its own site
from the last 30 days. Audit-lock recovery checks
boot/process-start ownership and quarantines a stale lock atomically before
removing it, so PID reuse and replacement-lock races fail closed.

Direct mode has no plugin and no authenticated wp-admin grant boundary, so its
write protections are the companion's own and narrower than Plugin mode's:

- A session started for one site (`STONEWRIGHT_SITE_ALIAS`) acts on that site
  only; a `site` argument naming another alias is refused.
- It refuses to write on a site where the Stonewright plugin is active, so a
  Direct write cannot skip the plugin's tokens, snapshots, Rescue ledger and
  audit. The plugin is detected through the REST index and the answer is cached
  for five minutes per site. Reads are not affected. For recovery only,
  `STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE=1` together with `confirm: true` on the
  call lets a write through; each use is audited and reported in the result.

- It refuses custom code (theme.json and Elementor CSS, HTML widgets, script,
  style, iframe, object, embed, link and meta http-equiv markup, inline event
  handlers, `javascript:` URLs) in global styles, Elementor data, content,
  template, blueprint and widget writes, in what a block edit
  (`stonewright-blocks-batch-mutate`) adds, in the markup
  `stonewright-gutenberg-compose` returns (`html` and `raw` blocks included), and
  in the text of ACF, media, menu, comment, taxonomy-term, settings, user and
  Application Password writes.
- It never deactivates or deletes Stonewright or the MCP adapter, never deletes
  the account it signs in as or the last administrator, and writes only
  allowlisted settings (never the site address or administrator email;
  `default_role` only for a non-administrative core role).
- It sends the Application Password only over https, or over http to a local
  host (`STONEWRIGHT_DIRECT_ALLOW_HTTP=on` for a trusted private network), and
  never retries a write after a network error.
- It saves a pre-image before template, template-part, global-styles, content,
  block-edit and blueprint-over-page writes and refuses the write when that fails.
  A block edit also needs the hash of the content it was planned against and is
  refused if the content changed.
  `stonewright-direct-backup-restore` puts a saved copy (or an Elementor file
  backup) back only after a confirmation, after checking the hash stored in the
  file and that the resource still exists, and after saving the current state so
  the restore can be undone.
- It cannot activate a skill. A skill saved through Direct is a disabled
  candidate, `enabled:true` and any change to an active skill are refused, and a
  lesson can be deleted only after a confirmation. Activation is an
  administrator's approval in Plugin mode.
- It asks for a confirmation on destructive tools in `confirm` write mode, and
  for theme activation, plugin and user deletes, Application Password create and
  revoke and backup restore in every mode. The refused call returns a
  `confirmation_id`: an HMAC over the tool, the site and the exact arguments, kept
  in the companion's memory for 10 minutes, usable once, and refused for other
  arguments, another site or another tool. A plain `confirm: true` counts only on
  a local site or with `STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on`. This is local to the
  companion; Direct has no plugin confirmation tokens.
- `elementor-data-update` replaces a whole tree only after structure checks, an
  optional `expected_hash` match and a refusal to rewrite Atomic (V4) documents;
  `force_destructive`, `allow_widget_type_remap` and `allow_atomic_raw` need a
  confirmation and are audited.
- `stonewright-media-upload` limits local paths, URL addresses, file types and
  size, and connects to the address it checked for each hop.
- In a Direct session `stonewright-wp-cli-run` and `stonewright-wp-cli-job-start`
  treat a command that changes the site as a Direct write: task-start,
  `confirm` in `confirm` mode, a local install that is the Direct site, and an
  audit row.
- It reads every typed write back and compares the fields that were sent; a
  difference is reported as `ok:false`, never as success. After a page, post or
  template write it can fetch the public page (the pinned, redirect-checked
  fetch used for media downloads) and report a failure; it restores the saved
  copy only when the call asks for `rollback_on_probe_failure`.

[Direct mode E2E](direct-mode-e2e.md) lists each protection and what is not done
yet.

### Plugin data when the plugin is deleted

Deleting the plugin keeps its data: OAuth grants and keys, memory, skills, audit
history, and settings stay in the database. Defining
`STONEWRIGHT_REMOVE_ALL_DATA` as `true` before deleting removes every plugin
table, every `stonewright_` option (the OAuth signing and encryption keys
included), every `stonewright_` and `sw_cc_` transient, and the scheduled
events, on every site of a network, and the change journal files and change
history blobs in `uploads/stonewright-state/` (a file in those folders that
Stonewright did not write stays). See
[Updating Stonewright](updates.md#roll-back-reinstall-or-remove-the-plugin).
Leave the constant undefined unless the data is meant to go.

The rescue helper is not data. It is the file `wp-content/mu-plugins/stonewright-rescue.php`,
which the plugin installed, and deleting the plugin always removes it. Deactivating the
plugin leaves it in place, where it does nothing until the plugin is active again.

## Stored secrets on the Setup page

The Setup page never writes a stored API key (Unsplash, Pexels) or the bridge
token into the page. The fields stay empty and show that a value is stored; the
bridge launch values use a placeholder until a new token is generated in the
browser. Saving with an empty field keeps the stored value, typing a value
replaces it, and the **Remove the stored value when saving** checkbox clears
it. The values are plain options in the database, readable by anyone with
database or `manage_options` access; keep the site database private and prefer
short-lived keys.

## Sandbox draft storage

Sandbox drafts and their backups are stored without a PHP extension, the folder
denies web requests on Apache, IIS and servers that ignore `.htaccess`
(nothing in it can run as PHP), and activation writes the only executable copy
with an `ABSPATH` guard as its first statement. See [Sandbox](admin/sandbox.md).

## php-execute runtime guards

`stonewright/php-execute` is on the **full** MCP profile only. During a snippet
the plugin wraps the live `$wpdb` handle with `ProtectedWpdbProxy`, installed
by `ProtectedWpdbWriteGuard`:

- The proxy is a `wpdb` only for type checks. Every method and every property
  read or write is forwarded to the live handle, so a driver subclass (for
  example the SQLite integration) keeps its own escaping, query code and state
  and `esc_sql()`, `wp_count_posts()` and `get_posts()` behave as outside
  php-execute. The write policy runs before a call is forwarded: `query()`,
  `get_var()`, `get_row()`, `get_col()` and `get_results()` check the SQL they
  run, and `insert()`, `replace()`, `update()` and `delete()` check the table
  and payload. The original global is restored in `finally`.
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

The scan of the source is a best-effort guard, not a runtime barrier: a snippet
that reaches a write through an indirect call can pass it. `php-execute` and the
WP-CLI runners (`wp-cli-run`, `wp-cli-batch-run`, `wp-cli-job-start`) need
`edit_plugins` and `manage_options`, the sandbox capability pair, and a super
admin on multisite. `wp-cli-batch-run` runs read-oriented commands only: one
command outside its allowlist refuses the whole batch before any runs. While `DISALLOW_FILE_MODS` is set, php-execute also refuses
snippets that call the plugin, theme and core installers and updaters, and the
WP-CLI abilities refuse commands that write files.

`stonewright-tool-profile` `extras` cannot add these abilities to a surface: an entry
that names `php-execute`, a sandbox writer, a WP-CLI runner, `custom-code-provider`
or a custom widget definer is refused with
`stonewright_tool_profile_extras_restricted`, and a stored extra that names one is
ignored. They come only from the profile that lists them (`full` for
`php-execute`).

The `full` profile is a person's choice. An administrator sets the MCP tool
surface to Full in wp-admin (Stonewright > Setup > Settings); an agent cannot
switch to it. `stonewright-tool-profile` with `action=activate` and
`profile=full` returns `stonewright_tool_profile_full_requires_admin` (HTTP 403,
`approval_required`, `agent_must_stop`, the settings link) in development,
staging and production-safe mode, with or without a confirmation token, and
writes no session profile. The other profiles still switch. A site whose
administrator already chose Full has nothing to switch. In the companion,
`STONEWRIGHT_MCP_TOOL_PROFILE=full` is the choice of the person who runs it; no
tool changes it, and a profile hint in a tool result cannot move the companion
onto `full` unless the site surface is already Full.

No other route changes the surface for an agent either. `POST /stonewright/v1/settings`
with `essential_tools_mode:false`, which puts the Full surface in effect, is accepted
only from a person in wp-admin: a cookie sign-in with a valid REST nonce
(`X-WP-Nonce`). An Application Password, an OAuth bearer and any other sign-in get
`stonewright_full_surface_requires_admin` (403, `approval_required`,
`agent_must_stop`, the settings link) and nothing in the request is written; the
other settings in that route keep working with an Application Password. Writing the
Full surface back from the change ledger (an undo or redo of a tool-profile row)
needs an admin screen, so `stonewright-change-rollback` refuses it. The Setup
screen applies the surface through admin-ajax with a nonce, and `options.php`
saves it from the Settings form; both are cookie requests. No ability, settings
import or blueprint writes the surface options.

An agent does not loosen its own leash either. Making the site mode less strict
(production-safe to staging or development, or staging to development), switching an
ability back on, and setting the feature flags need a person in wp-admin, the same
cookie sign-in with a valid REST nonce. `POST /stonewright/v1/settings` with a looser
`mode` or any `feature_flags` and `POST /stonewright/v1/abilities/toggle` with
`enabled:true` (a confirmation token alone does not stand in for the person) answer
`stonewright_mode_requires_admin`, `stonewright_feature_flags_require_admin` or
`stonewright_abilities_require_admin` (403, `approval_required`, `agent_must_stop`, the
link where the person decides) and write nothing; the `/admin/abilities/*` routes of the
AI Abilities page need the same sign-in. Making the mode stricter and switching an
ability off keep working with an Application Password. `abilities/toggle` refuses a name
that is not a registered Stonewright ability (400), and the feature flags are stored as a
flat map of at most 32 boolean flags, not autoloaded. A filter on the two options
themselves keeps the old value when any other REST request that is not a person in
wp-admin tries to loosen them (for example through a restore), and leaves an event in
the change ledger; WP-CLI, cron, activation and admin screens are not affected. Custom
instructions, which every agent context carries, go through the screen a memory save
goes through when they are set by the instructions route, `stonewright-system-instructions-set`
or a knowledge import: text that tells an agent to override the plugin rules or move
credentials is refused (`stonewright_instructions_need_review`), and an import reports it
as `instructions_rejected`.

A companion in front of a plugin older than this rule does not offer or forward a
switch to `full` either. `stonewright-tool-profile` in the companion refuses a
request for `full` (an activation, or a call with no `action`, which the plugin
takes as an activation) before it reaches the plugin, unless the companion
already runs `full` or the site surface is Full; a read-only `action=resolve` is
still forwarded. The companion itself only ever calls `action=resolve`, never
with `full`, and never activates a profile.

Every `wp_die()` in the plugin's admin pages and handlers that refuses a user (no
permission, a failed security check) answers 403 through `AccessDenied::deny()`,
with the same message as before.

These guards do not make php-execute a sandbox. Prefer typed abilities for
Elementor, FSE, options, and post writes. A successful call whose snippet uses
post meta, option, Elementor data, or menu patterns returns a `routing_hint`
that names the typed tool. The hint is advice built from fixed text after the
guards and the snippet have run; it does not block, change, or repeat the
snippet.

Direct credentials belong only in private environment configuration or a
permission-restricted `~/.stonewright/sites.json`. Plugin and Direct
memory/skill writes reject high-confidence credential material, and Direct
audit diagnostics redact authorization headers, tokens, and Application
Passwords. Plugin audit persistence recursively redacts the same credential
patterns from every free-text value, including nested error metadata. Release
archives exclude Direct sites config, memory, and audit state.

## Hardening checklist

- [ ] HTTPS enabled on the WordPress installation.
- [ ] `stonewright_mode` set to `production-safe` on production sites (a site with no stored mode starts in the mode of its environment type).
- [ ] Dedicated Application Password for the MCP client with the minimum required role.
- [ ] `COMPANION_BEARER_TOKEN` set to a strong random value.
- [ ] `COMPANION_ALLOWED_ORIGINS` restricted to known request origins.
- [ ] Companion running on a private network only.
- [ ] `stonewright_mcp_allowed_origins` lists only browser-based MCP tools that are in use; command-line and desktop clients need no entry.
- [ ] Audit log monitored or exported to a centralized logging system.
- [ ] `STONEWRIGHT_REMOVE_ALL_DATA` not defined, unless the plugin's data is meant to be removed when the plugin is deleted.
- [ ] `WP_DEBUG` off in production (prevents diagnostic information leakage).

## Reporting

See [plugin/SECURITY.md](../plugin/SECURITY.md) for the vulnerability reporting process.
