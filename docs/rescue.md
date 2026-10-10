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

This section describes the storage layer under change history and how a change is rolled back from it. The [Changes page](admin/changes.md) lists the recorded changes, shows each one as a diff and undoes or redoes it; abilities that record and undo changes build on the same storage and are documented with them.

The ledger is separate from the journal above. The journal is the short record Rescue needs to check and undo a change that has just been made. The ledger keeps history for longer, with the content that came before and after each change.

### What it stores

- One row per change in the table `stonewright_changes` (with the site prefix). A row holds a change id, the id of the change it follows (a rollback or a redo points at the change it acts on), the ability, the user, the label of the client when one is known, the time, the resource type and id, the family (post, Elementor, theme file, custom code, sandbox, option, menu, widget, user, media, WooCommerce and others), the status, the size and sha256 of the before and after content, whether the change can be restored and why not, a short summary and the id of the change set.
- The before and after content, as gzip blobs in `wp-content/uploads/stonewright-state/blobs/`. A blob is named by the sha256 of its content, so content that appears twice is stored once. Every folder the ledger creates, and the one it sits in, has `.htaccess`, `web.config` and `index.php` deny rules. A blob is read back only after its content is checked against its name, and the ledger refuses links and any path outside that folder.
- Limits on storage: one image of at most 4 MB (1 MB compressed), and 100 MB of blobs in total. A change whose image is over a limit is still recorded, without the image, and marked not restorable with the reason.

### What it records for posts

When an ability changes a post, the ledger records the post as it was before the write and as it is after it, and the status of the write (verified, failed, rolled back, or probe unavailable). A change that the journal also tracks uses the same change id in both. This covers pages, posts, Elementor documents (V3 and V4), page settings and the kit, Gutenberg content, FSE templates, template parts, navigation and global styles, patterns, Theme Builder templates, section reuse inserts, and the SEO and ACF value updates. An image of a post holds the title, status, content, excerpt, slug, parent, menu order and dates; the featured image; the terms of every taxonomy of the post type; the Elementor keys (`_elementor_data`, page settings, version, edit mode, conditions and the section record of a built page); the page template; the keys of the supported SEO plugins; the ACF values with their field references; and the custom fields that the write itself names. The post password and any other meta key stay out.

An ability that creates a post (`content-create-page`, `content-create-post`, `content-bulk-create`, `content-duplicate-page`, and the creating paths of `content-bulk-upsert-posts`, patterns, navigation, template parts, templates and Theme Builder templates) is recorded with no before image. Its undo is to move the post to the trash. It never deletes the post, and it refuses when the site keeps no trash. `content-bulk-upsert-posts` records the before image of every post it overwrites, including the custom fields it sets. It still takes no snapshot and arms no journal entry.

A post write made outside an ability call is not recorded. A ledger that cannot record, for example because its table is missing, is logged and never changes the write or its result. The Changes page reads these records, and its Undo and Redo use them (see below).

### What it records for options, menus and widgets

When an ability writes options, the ledger records the options it may write as they were before the call and as they are after it: `settings-update`, `site-set-front-page`, `system-instructions-set`, `tool-profile`, `theme-chrome-update` and `brand-kit-apply` (the options and theme mods their restore point names), and `cpt-register`, `taxonomy-register` and `acf-field-group-save` (only the one post type, taxonomy or field group the call adds or replaces, not the other entries of the shared option). Each ability has a list of the options it may write; any other option is neither imaged nor restored. A name on the secret list below, or one the ledger would mask as a key, is never read: the image lists the name with no value and no hash, and the row is marked not restorable (`secret_option` when only secret names were written, `secret_option_skipped` when other options were imaged beside them). Menus (`menu-create`, `menu-add-item`, `menu-delete`, `menu-assign-location`) are imaged with their items in order, with parents, titles, URLs, object links, classes and the theme locations that show them; a deleted menu keeps its full image, and its restore builds the menu again with its items, order, parents and locations, under a new term id. Sidebars (`widget-save`, `widget-delete`) are imaged with their widget list and the settings of their widgets.

These rows are written when the ability call ends, one for each resource that changed; a call that changed nothing leaves no row. The limit of ten restore points for options does not apply to the ledger, which keeps its own images, and the restore points are as they were. The restore of each family writes an image back with the functions the abilities use, reads the result back and reports what still differs. It checks no permission, token or newer change: the rollback engine below does.

### How long it keeps it

By default 90 days, 500 changes and 100 MB of blobs, whichever is reached first. A daily event deletes the oldest changes first. It never deletes an open change (`armed`, `incident` or `rollback_failed`), a change that a kept change follows, or a blob that another change still uses. Each run that deletes something writes one short audit row and leaves a receipt in the `stonewright_change_ledger_prune_receipt` option.

An administrator can change the limits with the options `stonewright_change_ledger_days`, `stonewright_change_ledger_max_changes` and `stonewright_change_ledger_max_bytes`, or with the filters `stonewright_change_ledger_retention_days`, `stonewright_change_ledger_retention_max_changes` and `stonewright_change_ledger_retention_max_bytes`. A value that is not a positive number goes back to the default, and each limit has an upper bound.

### What it never stores

- `wp-config.php` and files that hold credentials (`.env` files, private keys, `.htpasswd`).
- Keys and salts, and options on a list of secret names (names that contain key, secret, token, password, salt, auth, license, credential or oauth), including Stonewright's own signing, encryption and confirmation secrets.
- User passwords, application passwords, session tokens and OAuth tokens and keys, as resources and as values inside any other content.
- Any other credential found in content: private key blocks, bearer and basic authorization values, passwords and tokens written as `name: value`, URLs with credentials, and Stonewright tokens. These are replaced by a mask before anything is written, and content that was masked is marked not restorable.

For a refused resource the ledger keeps the row but no content and no hash of it.

### Code families

Changes to code are recorded in the families `theme_file` (theme file patch, `theme.json` and backup restore), `custom_code` (the Customizer CSS, WPCode and Code Snippets snippets) and `sandbox` (draft write, edit and delete, and activate and deactivate of the active copy). A row keeps the content before and after the change, and for a snippet its title, language, active state and scope. A file the change created has no before image, and undoing it deletes the file; the first save of the Customizer CSS has an empty before image. The record is made before the write, under the id the journal uses when it has an entry for the write, and a ledger that cannot record never stops or changes the write. The restore function of each family writes the before image back through the path the original write used (the theme file transaction, the snippet provider, the sandbox files, the Customizer CSS post), so the checks of that path still apply, and records the restore as a rollback row under the change (a redo when the row it acts on is a rollback row). They do not ask for the approval that code needs: the rollback engine does, and the Changes page holds it.

The provider snapshot of a snippet is kept for 24 hours; after that the Rescue entry reads as not available, and the ledger keeps the snippet body. Theme file backups (`uploads/stonewright-theme-backups/*.swbak`) stay the journal's rollback of a theme file write; when their index of 100 entries trims, the files that no index entry and no journal entry refers to, and that are older than an hour, are deleted.

### Other families

Users, comments, media, WooCommerce, themes and plugins, site memory, skills, design directions, `php-execute` and some settings writes are recorded as well. The row of an ability call is written when the call ends, with the image taken when it began: it is not written before the write, so a request that stops in the middle of a write leaves no row. A call that changed nothing, a dry run, and a call that failed without a change leave no row. A ledger that cannot record never changes the write or its result. The Changes page, the change history abilities and `wp stonewright changes` read these rows, and the rollback engine restores them through the restore function of each family (see "Rolling back a change").

The restore function of a family writes the before image back through the functions the ability used, checks the capability that the writes of the family need (for users `edit_users`, `create_users` or `delete_users`, and `promote_users` when a role is written; for comments `moderate_comments`; for media `upload_files`; for WooCommerce `manage_woocommerce`; for themes `switch_themes`; for memory and skills `manage_options`; for design directions the design capability), reads the resource back to confirm, and records a rollback row under the change. A restore of a row that created its resource removes the resource in the gentlest way the family has. A restore that has `expected_current_sha256` writes nothing when the resource was changed since.

| Family | What is recorded | What a restore can and cannot do |
|---|---|---|
| Users (`user-create`, `user-update`, `user-delete`) | The account fields (login, nicename, email, URL, display name, registration date), the first and last name, nickname, bio and locale, the roles, and the capabilities a user has on top of its roles. Never the password hash, the activation key, session tokens, application passwords or any other user meta. | Restores the fields, roles and capabilities, and never the password; it sends no email about the change. A user that was deleted is created again with a new id and a random password nobody knows: the row says it is partly restorable, and the person resets the password. Its sessions and application passwords are gone. The undo of a created user deletes it, unless it is the user who asks or has written content. |
| Passwords | A changed password is recorded as an event on `user_password`. An application password that was created or revoked is recorded as an event on `application_password`, with its name or uuid. Neither has an image or a hash. | Not restorable (`secret_resource`). The password is never read from the call. An application password made or revoked on the Connect screen or by its REST route is not recorded. |
| Comments (`comment-create`, `comment-update`, `comment-delete`) | The comment fields, status included. A delete keeps the full row. | Restores the fields and the status. A comment that was deleted is inserted again with a new id, so replies keep the old parent id; comment meta is not restored. The undo of a created comment moves it to the trash and never deletes it. |
| Media (`media-set-alt`, `media-optimize`, `media-upload`, `stock-image-import`, and the attachments that `design-normalize-assets`, `design-apply-to-post` and `design-spec-to-elementor-v3` sideload) | The title, caption, description, slug, parent, alt text, attachment metadata and stock attribution, and the path and size of the file. An upload is a create, recorded through `media-upload` (a batch is recorded through its uploads). | Restores the fields, alt text and metadata, never the path of the file and never any file bytes. `media-optimize` regenerates the sizes of an image from the original file, which it leaves as it is, and does not keep the size files it replaces: its row says "metadata only". The undo of an upload deletes the attachment and its files; with `MEDIA_TRASH` it goes to the trash, and otherwise the caller must ask for the permanent delete. |
| WooCommerce (`wc-product-*`, `wc-variation-*`, `wc-term-*`, `wc-attribute-*`) | The catalog fields of a product or variation, the name, slug, description and parent of a term, and the label, slug, type, sort order and archives flag of a global attribute. A delete keeps the full image. Dry runs leave no row. | Restores through the WooCommerce objects and functions. A product moved to the trash is restored by setting its status back. A product, term or attribute that was deleted for good is created again with a new id, without its variations, its relations to products or its terms, and the row says so. The undo of a created product moves it to the trash; the undo of a created term or attribute deletes it only while nothing uses it. |
| Themes (`theme-activate`) | The stylesheet and template that were active. | Switches back with `switch_theme()`. |
| Plugins (`plugin-delete`) | The plugin file that was deleted, as an event with no image. | Not restorable (`plugin_files_deleted`): Stonewright cannot bring back the files of a plugin. |
| Site memory (`memory-delete`, and the admin screen and route that delete an entry) | The full row of an entry before it is deleted. | Inserts the row again under its id with its dates. It refuses when the id or the scope and key are taken by another entry. |
| Skills | A link to the revision the skill store keeps: the slug, the revision, the hash of the content, the status and whether it is enabled. No copy of the text. | A save that made a new revision is restored by rolling the skill back to the earlier revision, which writes a new one. A skill that a save created is undone by moving it to the trash. Switching a skill on or off, the trash and bringing it back keep no revision: they are recorded as not restorable (`no_revision_stored`). A permanent delete takes the history with it (`skill_history_deleted`). |
| Design directions | A link to the revision the direction store keeps: the id, the revision and the hash of the contract; and the id of the active direction. No copy of the contract. | A save or restore that made a new revision is restored through the direction service, which writes a new revision. A direction that a save created is undone by archiving it. The active direction is restored by activating it again, or by clearing it. A save that changed only the status, and archiving, are not recorded. |
| `php-execute` | That a snippet ran with writes allowed, with the sha256 and length of its code. A read-only run, a snippet that did not parse and one that a guard refused leave no row. | Not restorable (`php_execute_not_undoable`). The code, its output and its result are not stored: only the hash. A snippet can change anything, and Stonewright cannot say what it changed. |
| Settings writes of admin screens and routes | The name of the setting and a short sentence, for the ability switches, the mode of the setup screen and of the settings route, the feature flags (names only), the essential tools mode and the custom instructions (length and a hash of the text). | Not restorable (`admin_write_not_tracked`). Other screens that use the WordPress settings API are not recorded. |

A change that carries a credential in its content (for example a comment or a product note with `api_key: ...`) is stored masked and marked not restorable, as in every family.
### Rolling back a change

`Security\ChangeRollback::run( $change_id, $options )` puts a resource back as a ledger row recorded it. It works on any row, whatever its status, for the families that have a handler: posts and their kinds (Elementor, Gutenberg, FSE, global styles), options and theme switches, menus, widgets, the code families, users, comments, media, WooCommerce, plugins, skills, design directions and memory. The Changes page calls it when an administrator presses **Undo** or **Redo**, and the `stonewright/change-rollback` ability and `wp stonewright changes rollback` call it too. A family with no handler is refused with `stonewright_change_family_unsupported`; the family `other` has none, because its rows (a snippet that ran, a setting written from an admin screen) are events with nothing to restore.

**What it checks, in order**

1. The caller has `manage_options`.
2. The row exists, is a change, a rollback or a redo, has not been rolled back already (`stonewright_change_already_rolled_back` names the rollback to redo instead), is restorable (a row that is not says why, for example `too_large`, `masked_secret` or `secret_option`) and has a handler.
3. The plan: the live state of the resource, the diff from the live state to the before image (what the undo would change), whether the live state still hashes to the after image of the row, and the newer changes to the same resource.
4. A **dry run** (`dry_run`) returns the plan and stops. It writes nothing but one audit row.
5. **Code needs a person.** Theme files, custom code (snippets and the Customizer CSS) and sandbox files, for an undo and for a redo, need `human_approved`. The Changes page passes it because an administrator pressed the button. An agent, REST or WP-CLI call gets the same `stonewright_rescue_approval_required` answer as an agent that tries to roll back a verified code change from Rescue, with the approval URL, and stops. A handler cannot lower this: the code families always ask.
6. **Drift.** If the live state no longer equals the after image of the row, someone or something changed the resource since. The plan warns that it has changed since, and the run is refused with `stonewright_change_drift` unless `force_drift` is set. A row that never settled has no after image to compare, and needs `force_drift` too. A family whose live state cannot be read cannot be compared; the plan says so and does not guess. `expected_current_sha256` (the whole hash, or its first 32 characters) refuses a state that is not the one previewed.
7. **Production-safe mode** needs a `confirmation_token` issued for `stonewright/change-rollback` over the change id, `force_drift`, `expected_current_sha256` and `permanent` (`ChangeRollback::confirmation_args()`). The token is verified after the refusals above, so a refusal does not use it up.
8. The resource is **claimed**: a second run of the same change, or of any change to the same resource, while one runs is refused with `stonewright_change_in_progress`. A claim older than five minutes is taken to belong to a dead request.
9. The restore runs, a **rollback row** is written (`kind` rollback, `parent_id` the change; a redo is a rollback of a rollback row and has `kind` redo), and the site is probed with the retry the writes use.

**The rows.** The change gets the status `rolled_back_by`. The rollback row holds the state before the restore as its before image and the state after as its after image, so redoing it writes the first change back. A redo marks the rollback `rolled_back_by` and the change is `verified` again. A deleted menu comes back with a new id; the rollback row names that menu, and a redo deletes it again. A post that a change created is undone by moving it to the trash, and a redo takes it out of the trash.

**The probe.** A baseline probe is taken before the restore. If the probe after it fails and the site was not already failing, the state from before the restore is put back, both rows stay in the history (the rollback as `rolled_back_by`, the revert as a redo row) and the call answers `stonewright_change_rollback_reverted` with the probe evidence. A probe that could not run keeps the rollback and marks it `probe_unavailable`. A site that was failing before and still fails keeps the rollback and says so.

**The journal link.** If the Rescue journal has an entry with the same id and the entry is open (`armed`, `incident` or `rollback_failed`), or is `verified` and its recipe can still run, the rollback goes through `RescueRollback::run()`, the path the Rescue page and `stonewright-rescue-rollback` use. The journal entry settles as it always did, an open incident needs neither force nor an approval because the site is failing, and the ledger gets its rollback row too. Any other change goes through the family handler, and a change that the journal still lists as `verified` is settled as `rolled_back`, so the two stores agree. The Rescue page and ability call `RescueRollback` directly and are not changed.

**The audit.** Every call writes one audit row for `stonewright/change-rollback`, a dry run and a refusal included, with `change_set_id` the id of the new row (or of the change when there is none). The answer carries a `receipt` with that id. Viewing a change on the Changes page computes the plan without an audit row; pressing the button is a run.

**Families that plug in.** `Security\Rollback\RollbackFamilies::register()` takes a handler for one or more ledger families, or a `CallableRollbackFamily` around a function `restore( string $change_id, array $options ): array` that returns `status`, `detail`, `limits` and `rollback_change_id` and writes its own rollback row. The engine keeps every gate for them; the handler only reads and writes the resource.

The users, comments, media, WooCommerce, plugin, skill, design direction and memory families are registered this way around `OtherFamilies::restore()`, and so are theme switches, which the ledger keeps in the family `option` (the options handler sends a row of the resource type `theme_switch` to the theme handler). The engine passes `kind` (rollback or redo) to the restore function, so the row it writes is a redo row when the row acted on is a rollback row, and it passes `expected_current_sha256` and `permanent`. Each of these handlers also reads the live resource (`OtherFamilies::live_image()`), so the plan shows the diff of the undo and tells drift, and the run checks that the resource still is in the state that was previewed. A resource that a restore creates again under a new id (a deleted comment, user or product) is named in the answer (`resource_id`).

A theme switch needs no administrator at wp-admin: the undo only activates the theme that was active before, through the call that the `theme-activate` ability makes, and the engine probes the site afterwards and puts the earlier theme back when the site stops loading. A plugin delete is recorded as not restorable (`plugin_files_deleted`), so no plugin row reaches a restore; the plugin handler still asks for an administrator, because restoring plugin files would put code on the site. The undo of a memory delete inserts the entry again under its id, and the redo deletes it again.

### Reading and undoing from an agent or the command line

Three abilities and one WP-CLI command give an agent or an operator the history without the page. All of them need `manage_options`, run the same engine as the Changes page, and never return a stored copy of the content: a diff is masked and capped, a row is a short summary.

| Ability | Kind | Notes |
|---|---|---|
| `stonewright/change-history-list` (`stonewright-change-history-list`) | Read | Short rows, newest first: `change_id`, `time`, `kind`, `family`, `resource_label`, `ability`, `actor`, `status`, `summary`, `restorable` with `restorable_reason`, `parent_id` and `children` (how many rollbacks and redos follow the row). Filters as on the page: `family`, `resource`, `ability` (the `stonewright/` prefix may be left out), `actor` (user id or login), `status` (`verified`, `rolled_back`, `incident`, `failed`, `unchecked`), `from` and `to` (`YYYY-MM-DD`, UTC), `restorable` and `kind`. Paged with `page` and `per_page` (1 to 100, 25 by default). A value that is not allowed is refused with `stonewright_change_history_invalid`; it never widens the list, and an account that does not exist matches no change |
| `stonewright/change-diff-get` (`stonewright-change-diff-get`) | Read | The diff of one change (the lines, blocks, elements or fields that differ, secrets masked, capped by `max_lines`, 400 by default) and `plan`, a summary of what an undo would do: `restorable`, `drift`, `newer_changes`, `approval_required` (code), `requires_force`, `confirmation_required`, `would_apply` and the start of `current_sha256`. When the undo cannot run, `plan.available` is false with `error_code` (for example `stonewright_change_not_restorable`, or `stonewright_change_already_rolled_back` with `redo_change_id`). The plan is read without an audit row |
| `stonewright/change-rollback` (`stonewright-change-rollback`) | Write | Input `change_id`, `dry_run`, `force_drift`, `expected_current_sha256`, `permanent` and `confirmation_token`. It calls `ChangeRollback::run()` with the caller as the actor and `ability` as the way, and it does not mark the call as approved by a person. A dry run returns the plan with a short diff (counts per part, not lines) and, in production-safe mode, `confirmation_args`: the four arguments to issue the confirmation token for. The engine verifies the token once; the ability does not verify it again, and writes no audit row of its own, because the engine writes one for every call. To redo, call it with the `rollback_change_id` of an earlier run |

**Code needs the administrator.** An undo or redo of a theme file, custom code, a sandbox file or the Customizer CSS is not run on an ability call, with or without a token: the answer is `stonewright_rescue_approval_required` with the `approval_url`, which an agent shows to the user and then stops. The administrator opens **Stonewright > Activity > Changes**, opens the change and presses **Undo**.

**Finding and undoing a change.** Call `stonewright-change-history-list` (for example with `resource` set to a post id), read the row, call `stonewright-change-diff-get` to see what changed and whether the item was edited since, then call `stonewright-change-rollback` with `dry_run` true and tell the user what it would do. A run needs `force_drift` when the item was edited after the change, and a `confirmation_token` in production-safe mode. See the [stonewright-rescue skill](../skills/stonewright-rescue/SKILL.md).

**WP-CLI.** `wp stonewright changes list` (the same filters as options: `--family`, `--resource`, `--ability`, `--actor`, `--status`, `--from`, `--to`, `--restorable`, `--kind`, `--page`, `--per-page`, `--format`), `wp stonewright changes diff <change>` (`--max-lines`, `--format=json`) and `wp stonewright changes rollback <change>` (`--dry-run`, `--force-drift`, `--expected-sha256`, `--permanent`, `--yes`, `--format=json`) need an administrator (`--user=<login or id>`). A run asks for confirmation unless `--yes` is given; `--dry-run` prints the plan and changes nothing. In production-safe mode a run needs a confirmation token: `--issue-token` prints one for that change and those options, and `--confirmation-token=<token>` (or `STONEWRIGHT_CONFIRMATION_TOKEN`) confirms the run; exit code 2 means a token is needed. The command line is not an administrator pressing Undo: for code the command prints the approval-required answer and the address of the Changes page, and changes nothing. The audit row says the way was `wp-cli`.

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

`wp stonewright changes list`, `diff` and `rollback` read and undo the change history (see above). `wp stonewright rescue status` lists the open incidents. `wp stonewright rescue rollback <incident>` runs the same rollback as the ability and the page, probes the site afterwards and records the outcome. Both need an administrator (`--user=<login or id>`). In production-safe mode the rollback needs a confirmation token: `--issue-token` prints one for that incident, and `--confirmation-token=<token>` (or the `STONEWRIGHT_CONFIRMATION_TOKEN` environment variable) confirms the rollback. Exit code 2 means a token is needed. The command needs only WordPress core and Stonewright, so it works with every other plugin and the theme skipped:

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
- A custom-code snippet without a provider snapshot, or whose snapshot has expired (it is kept for 24 hours), has no rollback from Rescue. It is listed as not available, so it can be undone by hand and checked again.
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
