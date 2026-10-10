# Rescue

Rescue is Stonewright's way back from a change that leaves a site failing. Before a risky change is made, Stonewright records how to undo it. After the change, it asks the site whether it still loads. When the site does not load, it undoes the change, asks again, and tells the agent what happened. When it cannot undo the change, the incident stays open until an administrator or an agent finishes the job from **Stonewright > Activity > Rescue**, the `stonewright-rescue-rollback` ability, or WP-CLI.

Rescue works on changes Stonewright makes through its abilities. It does not watch changes made by anything else.

## What Rescue covers

| Change | Undone by | Checked afterwards |
|---|---|---|
| Post content written through an ability that snapshots the post (Elementor, Gutenberg, content writes) | The post snapshot taken before the write | The post's own page. A draft is checked through a preview link |
| Options and theme settings | The restore point taken before the write | The home page |
| Theme file write or patch | The backup of the file, or removal of a file the write created | The home page for files that are not PHP. The home page, a wp-admin screen and the REST index for PHP, plus an optional URL on the same site |
| Plugin activation and deactivation | The plugin's previous state. Stonewright itself is never touched | The home page, a wp-admin screen and the REST index |
| Sandbox file activation | The active copy is disabled; the draft stays for review | The home page, a wp-admin screen and the REST index |
| Custom-code snippet saved through WPCode or Code Snippets | The provider's snapshot, when it took one. Without one, no rollback is offered | The home page, a wp-admin screen and the REST index |

A call that changes several of these is checked once and rolled back in reverse order when the check fails.

A change that passed its check can be rolled back later too: see [Undoing a verified change](#undoing-a-verified-change).

`content-update-page` refuses the id of a revision (`stonewright_invalid_post_type`) before it snapshots or writes: a revision is a saved copy of a page, so there is no page to check.

## How a protected write runs

1. **Arm.** The write site records a change set: an id, the ability, what is touched, and the recipe that undoes it. This happens before anything changes.
2. **Baseline.** Before the write, Stonewright asks the site the same questions it will ask afterwards, and keeps the answers on the change set. A call checks each leg once, and the page of the first post only. The cost is one more probe for each risky write.
3. **Write.** The ability runs as it always did.
4. **Probe.** When the call finishes, Stonewright asks the site, over HTTP from the site, whether it still loads. A call that changed nothing is closed as verified without a probe.
5. **Settle.**
   - The probe passes: the change set is `verified`.
   - The probe fails: the recipe runs, the site is probed again, and the change set is `rolled_back`. `site_status` says whether the site loads again. `still_failing` means the fault may not come from this change, and the change set records that. If the recipe fails, the change set is `rollback_failed` and the incident stays open.
   - A leg that passed in the baseline and cannot be reached now (a refused connection, a timeout, an empty answer, or any 5xx) counts as failed, so a change that hangs or crashes the server is rolled back like a fatal and the site is checked again.
   - A leg that passed in the baseline and gets no answer at all after the write (a timeout, a refused connection) is tried once more, with a longer wait of up to 15 seconds and a fresh token, before the write is rolled back: the first render of a page that was just written can be slow. A server error, the critical error page and a PHP fatal are not tried again. They fail at once.
   - The probe is unavailable and the baseline was too (the host blocks requests from the site to itself, or nothing answered): the change set stays `armed`. It is listed as not verified and a short notice tells the agent. Rescue never reports a healthy site without a passing check.
6. **Report.** A failed check turns the ability's result into an error that carries the evidence, so an agent that only reads error messages still sees it.

| Error code | Meaning |
|---|---|
| `stonewright_rescue_write_rolled_back` | The site stopped loading and the change was rolled back. `site_status` says whether the site loads again |
| `stonewright_rescue_rollback_failed` | The site stopped loading and the rollback failed. The message names the `incident_id` and the ability to call |

Both carry `change_set_id`, `incident_id`, `rollback_status`, `site_status` (`healthy`, `still_failing` or `unknown`), a compact `probe`, and `original_error_code` when the ability had already failed.

An MCP client receives only the error message, so the message carries the evidence (the failed legs of the probe included) and ends with a compact JSON object holding `code`, `change_set_id`, `incident_id`, `rollback_status`, `site_status` and `original_error_code`. The REST error carries the code and the full data.

A theme-file write keeps its own receipt and adds `site_probe` (`passed`, `failed`, `unavailable` or `skipped`). It reports `verification_status` `verified` and `effect_verified` true only when the check after the write passed; when the check could not run or was skipped it reports `unverified`, and `probe_unavailable` when the site could not be reached. A write the journal cannot describe is still checked and undone from memory; there is just no change set to point at.

## The change journal

The journal is the record Rescue keeps for each change set. It has two copies that are kept in step:

- A compact JSON file in `wp-content/uploads/stonewright-state/`, named `journal-` followed by 32 random hex characters. It holds the id, ability, resource, recipe reference, the paths the change touches, the armed time and the state, and it is where a fatal is recorded. It can be read without WordPress or the database. The folder is protected with `.htaccess`, `web.config` and a blank `index.php`. A write takes an exclusive lock on a `.lock` file, writes a temporary file and renames it over the journal, so a reader never sees half a document. The file holds at most 50 entries and drops the oldest settled one first. Secrets are redacted before anything is written.
- A database option (`stonewright_change_journal`) with the same entries plus what the file never carries: who made the change, the exact recipe detail, the probe evidence and the rollback outcome.

If the uploads folder cannot be written, the journal keeps working from the database and **Stonewright > Activity > Rescue** says so. A fatal recorded in the file while the database was down is imported by the next wp-admin or REST request that loads WordPress with the database available.

The database copy is the authority, and the file is input. The file can add a fatal to a change set the database already holds under the same id, ability and resource. It never creates a change set, a recipe or a path. Anything else it carries (an entry nobody armed, another recipe, a stale fatal on a change that was rolled back) is dropped, and the file is written again from the database copy. A file larger than 1 MB, the most the rescue helper reads, is not read at all and is replaced.

### States

| State | Meaning |
|---|---|
| `armed` | Recorded and the change made, not yet verified |
| `verified` | A passing check followed the change, or the call changed nothing. It can still be rolled back while the journal keeps it (the last 50 changes) |
| `rolled_back` | The recipe ran after a failed check, or on request. The outcome is stored on the change set, with the way it ran (`admin-page`, `ability`, `wp-cli` or `auto`) and the user who ran it. A verified change that was undone also carries the note `undone_after_verified` |
| `rollback_failed` | The recipe failed or could not run. This is an open incident |
| `incident` | A PHP fatal was recorded against the change set. This is an open incident |

While an incident is open, every ability response carries a banner (below) and the tool list includes `stonewright-rescue-status` and `stonewright-rescue-rollback`.

## Undoing a verified change

A change that passed its health check is not final. Until the journal drops it (it keeps the last 50 changes, oldest settled first), an administrator can roll it back from **Stonewright > Activity > Rescue**, where **Recent changes** lists every change the journal holds with a **Roll back** button, and `stonewright-rescue-rollback` can roll it back with its `incident_id`.

The rollback of a verified change runs the same steps as any other rollback:

- it claims the change set first, so a double click, or the page and an agent together, run it once;
- it runs the recorded recipe, then probes the site;
- it warns when a newer change to the same item exists, because the rollback overwrites it;
- in production-safe mode it needs a confirmation token;
- the outcome is written to the change set and to the audit log, and the change becomes `rolled_back`.

If the recipe fails, the change becomes `rollback_failed`, an open incident, like any rollback that fails. A change that has been rolled back cannot be rolled back again, and a change the journal has dropped cannot be rolled back from Rescue.

**Code needs the page.** A verified change to a theme file, a custom-code snippet (WPCode or Code Snippets), a sandbox file or the Customizer CSS is rolled back only by an administrator pressing **Roll back** on the Rescue page. The ability, the REST route and `wp stonewright rescue rollback` refuse it with `stonewright_rescue_approval_required`, which carries the approval URL and tells the agent to ask the administrator to use **Stonewright > Activity > Rescue**; a confirmation token does not change that. A `dry_run` still returns the plan, with `approval_required` true. This applies only to a verified change: an incident, or a change that was never verified, is rolled back by an agent as before, because the site is failing.

## A failed backup stops the write

A write that mutates a post takes a snapshot of the post first. When the snapshot cannot be stored and read back, the write does not run: the ability returns `stonewright_backup_failed` before it changes anything, and releases the write lock it took. This holds for the Elementor V3 abilities that add, move, remove or update elements, build a page from a spec, change page settings, kit colors or kit typography, for the per-widget `elementor-add-*` abilities, for `design-spec-to-elementor-v3` and for `elementor-v4-migrate`. `change-restore` takes a snapshot of the current state before it restores, returns it as `pre_restore_snapshot_id`, and refuses with `stonewright_backup_failed` when it cannot be stored. The history limit (10 snapshots per post) never drops the snapshot being restored.
## Change history (ledger)

This section describes the storage layer under change history. The pages and abilities that record, show and undo changes build on it and are documented with them.

The ledger is separate from the journal above. The journal is the short record Rescue needs to check and undo a change that has just been made. The ledger keeps history for longer, with the content that came before and after each change.

### What it stores

- One row per change in the table `stonewright_changes` (with the site prefix). A row holds a change id, the id of the change it follows (a rollback or a redo points at the change it acts on), the ability, the user, the label of the client when one is known, the time, the resource type and id, the family (post, Elementor, theme file, custom code, sandbox, option, menu, widget, user, media, WooCommerce and others), the status, the size and sha256 of the before and after content, whether the change can be restored and why not, a short summary and the id of the change set.
- The before and after content, as gzip blobs in `wp-content/uploads/stonewright-state/blobs/`. A blob is named by the sha256 of its content, so content that appears twice is stored once. Every folder the ledger creates, and the one it sits in, has `.htaccess`, `web.config` and `index.php` deny rules. A blob is read back only after its content is checked against its name, and the ledger refuses links and any path outside that folder.
- Limits on storage: one image of at most 4 MB (1 MB compressed), and 100 MB of blobs in total. A change whose image is over a limit is still recorded, without the image, and marked not restorable with the reason.

### What it records for posts

When an ability changes a post, the ledger records the post as it was before the write and as it is after it, and the status of the write (verified, failed, rolled back, or probe unavailable). A change that the journal also tracks uses the same change id in both. This covers pages, posts, Elementor documents (V3 and V4), page settings and the kit, Gutenberg content, FSE templates, template parts, navigation and global styles, patterns, Theme Builder templates, section reuse inserts, and the SEO and ACF value updates. An image of a post holds the title, status, content, excerpt, slug, parent, menu order and dates; the featured image; the terms of every taxonomy of the post type; the Elementor keys (`_elementor_data`, page settings, version, edit mode, conditions and the section record of a built page); the page template; the keys of the supported SEO plugins; the ACF values with their field references; and the custom fields that the write itself names. The post password and any other meta key stay out.

An ability that creates a post (`content-create-page`, `content-create-post`, `content-bulk-create`, `content-duplicate-page`, and the creating paths of `content-bulk-upsert-posts`, patterns, navigation, template parts, templates and Theme Builder templates) is recorded with no before image. Its undo is to move the post to the trash. It never deletes the post, and it refuses when the site keeps no trash. `content-bulk-upsert-posts` records the before image of every post it overwrites, including the custom fields it sets. It still takes no snapshot and arms no journal entry.

A post write made outside an ability call is not recorded. A ledger that cannot record, for example because its table is missing, is logged and never changes the write or its result. Nothing in the admin or in the abilities reads these records yet.

### How long it keeps it

By default 90 days, 500 changes and 100 MB of blobs, whichever is reached first. A daily event deletes the oldest changes first. It never deletes an open change (`armed`, `incident` or `rollback_failed`), a change that a kept change follows, or a blob that another change still uses. Each run that deletes something writes one short audit row and leaves a receipt in the `stonewright_change_ledger_prune_receipt` option.

An administrator can change the limits with the options `stonewright_change_ledger_days`, `stonewright_change_ledger_max_changes` and `stonewright_change_ledger_max_bytes`, or with the filters `stonewright_change_ledger_retention_days`, `stonewright_change_ledger_retention_max_changes` and `stonewright_change_ledger_retention_max_bytes`. A value that is not a positive number goes back to the default, and each limit has an upper bound.

### What it never stores

- `wp-config.php` and files that hold credentials (`.env` files, private keys, `.htpasswd`).
- Keys and salts, and options on a list of secret names (names that contain key, secret, token, password, salt, auth, license, credential or oauth), including Stonewright's own signing, encryption and confirmation secrets.
- User passwords, application passwords, session tokens and OAuth tokens and keys, as resources and as values inside any other content.
- Any other credential found in content: private key blocks, bearer and basic authorization values, passwords and tokens written as `name: value`, URLs with credentials, and Stonewright tokens. These are replaced by a mask before anything is written, and content that was masked is marked not restorable.

For a refused resource the ledger keeps the row but no content and no hash of it.

### Removal

With `STONEWRIGHT_REMOVE_ALL_DATA` defined as `true`, deleting the plugin drops the table, unschedules the daily event and deletes the blobs with their deny files, on every site of a network. A file in the blob folder that the ledger did not write stays.

## The health probe

A probe is up to five short requests, called legs:

| Leg | What it asks |
|---|---|
| `home` | The home page |
| `admin` | A wp-admin screen, reached with a one-time internal token that is bound to one path and one user and lasts three minutes. The user's cookies and Application Passwords are never used |
| `rest` | The REST index |
| `post` | The page of the post that was written (a preview link for a draft). For an Elementor kit, which has no page of its own, the public front page, requested without the internal token |
| `custom` | A URL the write asked to have checked. It must have exactly the home URL's scheme, host and port. It is not followed if it redirects |

A leg fails on HTTP 500, on the WordPress critical error page, or on PHP's own fatal text in the response. A blocked loopback request, a login wall, a redirect, a gateway error or a timeout is not a failure and not a success: the leg is `unavailable`, and a probe with no passing leg is `unavailable` as a whole, unless the same leg passed in the baseline (see above). A request that carries the internal token never follows a redirect, so the token cannot be sent to wherever a redirect points. The first thing a request with a valid token does is become the user the token was issued for, before anything about the request is recorded. It stays that user for that request only: the identity and the short session behind it end with the request, and a token that is expired, already used, or sent to another path or with another nonce logs nobody in. A leg that was tried a second time is marked `retried`, with the reason of the first attempt; the second attempt waits at most 15 seconds, the retries of one probe together stay inside its 30 second budget, and once a second attempt also gets no answer the other legs are not tried again. The evidence keeps leg names, statuses, HTTP codes and short reasons. It never holds a URL, a header or a response body.

When every leg of a probe fails to connect, later probes send one request with a three second timeout for ten minutes, so a host that cannot call itself never makes writes wait, and a leg that gets no answer then is not tried a second time. Any answer ends that.

Three filters adjust the probe: `stonewright_rescue_probe_enabled` (return `false` to turn it off), `stonewright_rescue_probe_args` and `https_local_ssl_verify`. On a slow host, raise `timeout` through `stonewright_rescue_probe_args`: a leg that passed before a write and times out twice after it counts as failed.

## What an agent sees

- **After a failed write:** the error codes above.
- **While an incident is open:** every ability response carries `pending_incident` with `id`, `ability`, `since` and `rollback`, the name of the ability to call. A response whose output schema forbids extra properties declares the field, so it still validates.
- **Short notices:** a `notices` list carries one-line messages with a lifetime, for example that the probe is unavailable on this host. At most five lines of 160 characters are kept. The notice that the probe is unavailable is removed as soon as a probe passes.

## Abilities

| Ability | Kind | Notes |
|---|---|---|
| `stonewright/rescue-status` (`stonewright-rescue-status`) | Read | Needs `manage_options`. Lists open incidents, changes that were never verified and the latest changes, each with the rollback it would run, and `helper` (`state` and `safe_mode`): whether the rescue helper is in place and a safe mode link can be issued. Changes nothing and runs no probe |
| `stonewright/rescue-rollback` (`stonewright-rescue-rollback`) | Write | Needs `manage_options`. Input `incident_id`, `action` (`rollback`, the default, or `recheck`), `dry_run` and, in production-safe mode, a `confirmation_token`. `rollback` runs the recorded recipe and probes the site afterwards. It works on an incident, on a change that was never verified and on a verified change, except that a verified change to code answers `stonewright_rescue_approval_required` (see above). `recheck` only probes again and closes the incident when the site loads, for a change someone undid by hand. A `dry_run` of the rollback returns the plan, which says how old the change is and warns when a newer change to the same item exists (the rollback would overwrite it), and needs no token. A `recheck` changes the incident, so it needs the token even with `dry_run`. The rollback claims the change set first, so a double click, or the page and an agent together, run it once: the second caller gets `stonewright_rescue_in_progress` (a claim older than five minutes is ignored). The outcome is written to the change set and to the audit log |

The rollback restores a state Stonewright recorded itself, and only for a change set in the journal. It is not a way around the permission model.

## The Rescue page

**Stonewright > Activity > Rescue** (administrators only) lists the change sets that need attention in one table: the short id, when, what changed and who changed it, the health check evidence, the rollback that would run, the state and the actions. **Recent changes** below it lists the other changes the journal keeps, up to 50. A verified change has **Roll back**; a change that was rolled back says how, and by whom.

- **Roll back** opens a confirmation that names the change set, shows what changed and what is restored, and puts Cancel first. It works for a verified change too, code included: the administrator who presses it is the approval that a change to code needs. In production-safe mode the confirmation also asks for the words ROLL BACK and carries a confirmation token that was issued for that one change set and expires after ten minutes.
- **Check again** probes the site again and closes the incident when it loads.
- Each row says how long ago the change was made. The confirmation warns when a newer change to the same item exists, because the rollback would overwrite it. While a rollback of a change set is running, its row says so instead of offering the buttons.
- **Prompt for your agent** holds the words to hand an agent that should finish the job.
- **Rescue helper** in the summary shows whether the helper is installed (`Installed`, `Installed, loads on the next request`, `Changed on disk` or `Not installed` with the reason). When safe mode cannot start, the page says so under the summary.
- **Open in safe mode** starts safe mode, in which WordPress loads only Stonewright and a default theme, through a link that works once. The button is shown only while the rescue helper is installed and loaded. You sign in on the site's normal sign-in page first, and safe mode starts after that (see below).

Every action is a plain form post with a nonce, so the page works without scripts. Scripts add the dialog, the copy buttons and a busy state. After an action the page shows what happened, with a receipt id that matches the audit log. The page works at 400 px.

## Safe mode and the command line

A small must-use helper records a PHP fatal that follows a change against its change set, and opens a short-lived safe mode for the administrator a one-time link was issued to. `wp stonewright rescue status` and `wp stonewright rescue rollback` list incidents and roll one back from the command line. Both read the same journal and run the same rollback as the ability and the page, with the same permission and confirmation rules.

### The rescue helper

Stonewright installs a small must-use plugin, `wp-content/mu-plugins/stonewright-rescue.php`, when it is activated and again after an update. On every wp-admin page load Stonewright compares it with its own copy and writes it again when it is missing or was changed. When the folder cannot be written, or file changes are turned off for the site, an admin notice says so and Stonewright goes on without the helper: a fatal error after a change is then not recorded and safe mode is not available. Deactivating Stonewright leaves the file where it is, and it does nothing; deleting Stonewright from the Plugins screen, or with `wp plugin uninstall`, removes it. `wp plugin delete` removes the plugin folder without running the uninstall step, so it leaves `wp-content/mu-plugins/stonewright-rescue.php` behind. The file does nothing without the plugin and can be deleted by hand. Stonewright cannot remove it on that path, because nothing of Stonewright runs when WP-CLI deletes the folder.

When a PHP fatal error happens, the helper matches the file of the error with the paths of the open change sets, and of the ones settled in the last 15 minutes, and records an incident on the matching change set. It never rolls anything back and reads no database.

### Safe mode

Safe mode is a short browser session in which WordPress loads only Stonewright and the default theme. Open it with **Stonewright > Activity > Rescue > Open in safe mode**, or with the link in the WordPress recovery mode email.

- The link works once, for 15 minutes, for one administrator. Only a hash of it is stored.
- Opening the link starts a 30 minute session in the browser. It signs nobody in and changes nothing about the sign-in page: signing in always runs with the site's normal plugins, so two-factor, login limiting and captcha plugins work as they always do.
- Safe mode starts once the administrator the link was issued for has signed in. If that administrator is already signed in, opening the link sends the browser on to the page the link names (the Rescue page) instead of showing the sign-in form, and safe mode starts there. Only a plain visit of the sign-in page is sent on, only to an address on the site, and only for the signed-in user the link was issued for. From then on, wp-admin and admin-ajax requests, and that administrator's REST and front-end requests, load in safe mode. A different user signing in ends the session, and so do signing out, **Leave safe mode** in the notice, and the 30 minutes.
- The session is a cookie that is HttpOnly, Secure on HTTPS sites and SameSite=Lax. Only wp-admin and the front controller (`index.php`) are loaded in safe mode. The sign-in page, XML-RPC, cron, every other entry script and every request without the cookie, anonymous front-end traffic included, never are.
- Changes to the active plugins and to the theme are paused while the session lasts. A rollback from the Rescue page is the exception: it works on the real selection.
- Settings > General > "Safe mode on the MCP route" is off by default. While an incident is open, a REST request that WordPress serves through `index.php` to `/wp-json/mcp/stonewright` with a Basic credential, or to `/wp-json/mcp/stonewright-oauth` with a Bearer credential, loads the same way, with Stonewright's own authentication and permission checks unchanged. Other plugins, including security plugins, do not run on those requests. A request to the sign-in page, to wp-admin, to `xmlrpc.php` or to any other script is never loaded this way.

If the sign-in page itself does not load, safe mode cannot start. Use WordPress recovery mode (its link is in the same email), WP-CLI or the companion.

### WP-CLI

`wp stonewright rescue status` lists the open incidents. `wp stonewright rescue rollback <incident>` runs the same rollback as the ability and the page, probes the site afterwards and records the outcome. Both need an administrator (`--user=<login or id>`). In production-safe mode the rollback needs a confirmation token: `--issue-token` prints one for that incident, and `--confirmation-token=<token>` (or the `STONEWRIGHT_CONFIRMATION_TOKEN` environment variable) confirms the rollback. Exit code 2 means a token is needed. The command needs only WordPress core and Stonewright, so it works with every other plugin and the theme skipped:

```text
wp stonewright rescue status --user=admin --skip-plugins=<every active plugin but stonewright> --skip-themes
```

### Companion

On a site with a local WordPress root, `stonewright rescue status` and `stonewright rescue rollback <incident>` run that command with every active plugin but Stonewright skipped, so the site does not have to load. Every process starts through the tokenized WP-CLI runner (`execFile`, argv tokens, no shell). See the companion README.

## Limits

- Rescue cannot fix a fatal in WordPress core, in `wp-config.php` or in a drop-in, or a database that is down: the helper does not run, or WordPress cannot start.
- Rescue needs the site to be able to call itself over HTTP. When it cannot, changes stay `armed` and are listed as not verified.
- Only writes made inside an ability call are journaled. A write made from an admin screen or by WP-CLI is not.
- A call that writes several posts checks the page of the first one, and takes the baseline for it only.
- Each risky write costs one more probe, taken before it. On a host that cannot call itself the first one waits for its timeouts; later ones are quick for ten minutes.
- A custom-code snippet without a provider snapshot has no rollback. It is listed so it can be undone by hand and checked again.
- The journal keeps the last 50 changes. A verified change older than that cannot be rolled back from Rescue.
- Rolling back an older verified change restores the item as it was before that change, so a later change to the same item is overwritten. The confirmation and the dry run name the later changes.
- Safe mode cannot skip other must-use plugins, because WordPress offers no way to skip them. A fatal error in one of them, an active Stonewright sandbox file included, stops the requests that load it, safe mode and WP-CLI included. The health check that follows the write rolls the change back in the usual case; otherwise remove the file over SFTP.
- An incident is recorded only when the file of the fatal error is, or lies inside, a path the change touched, and the error is still the last PHP error at shutdown. A fatal error in an included file that the write did not list, a memory or time limit hit in unrelated code, and a change without paths are not recorded by the shutdown handler.
- Signing in always uses the site's normal sign-in page with all its plugins, so safe mode cannot start while that page fails to load. Use WordPress recovery mode (its link is in the same email), WP-CLI or the companion then.
- The rescue link is a credential: it works once, expires after 15 minutes and is bound to one administrator, and the web server may log the URL of the request that uses it.
- Other plugins do not run on the requests that safe mode covers, so rules they add for wp-admin or REST requests, such as an IP allow list, do not apply to those requests.
- A rollback that runs in safe mode does not run the hooks of the plugins that safe mode left out, such as a cache purge or a style rebuild they would do after a save.
- The recovery mode email is sent by WordPress at most once a day by default, not on multisite networks, and only for a fatal error that WordPress attributes to a plugin or theme. The Rescue page can give a link whenever the helper is installed.
- The MCP route setting recognises the default REST prefix (`/wp-json/` and `?rest_route=`). A site that changes the prefix is not covered.
- The helper cannot be installed where the must-use plugins folder is read-only or file changes are disabled. Then there is no record of fatal errors and no safe mode, and an admin notice says so.

## Related

- [Transactions and recovery](transactions.md#rescue-after-risky-changes)
- [Security guarantees](security-guarantees.md#rule-13---rescue-after-risky-changes)
- [Security](security.md#rescue-after-a-failed-change)
