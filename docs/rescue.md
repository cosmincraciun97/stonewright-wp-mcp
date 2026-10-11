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
| `verified` | A passing check followed the change, or the call changed nothing. It can still be rolled back while the journal keeps it (the last 50 changes). An undo that broke the site and was put back leaves it `verified` with the note `undo_reverted` |
| `rolled_back` | The recipe ran after a failed check, or on request. The outcome is stored on the change set, with the way it ran (`admin-page`, `ability`, `wp-cli` or `auto`) and the user who ran it. A verified change that was undone also carries the note `undone_after_verified` |
| `rollback_failed` | The recipe failed or could not run. This is an open incident |
| `incident` | A PHP fatal was recorded against the change set. This is an open incident |

While an incident is open, every ability response carries a banner (below) and the tool list includes `stonewright-rescue-status` and `stonewright-rescue-rollback`.

## Undoing a verified change

A change that passed its health check is not final. Until the journal drops it (it keeps the last 50 changes, oldest settled first), an administrator can roll it back from **Stonewright > Activity > Rescue**, where **Recent changes** lists every change the journal holds with a **Roll back** button, and `stonewright-rescue-rollback` can roll it back with its `incident_id`.

The rollback of a verified change runs the same steps as any other rollback:

- it claims the change set first, so a double click, or the page and an agent together, run it once;
- it saves the current state of what the recipe overwrites, and probes the site before the recipe runs (see below);
- it runs the recorded recipe, then probes the site, once more after a slow first answer;
- it warns when a newer change to the same item exists, because the rollback overwrites it;
- in production-safe mode it needs a confirmation token;
- the outcome is written to the change set and to the audit log, and the change becomes `rolled_back`.

If the recipe fails, the change becomes `rollback_failed`, an open incident, like any rollback that fails. A change that has been rolled back cannot be rolled back again, and a change the journal has dropped cannot be rolled back from Rescue.

**An undo that breaks the site is put back.** The undo of a verified change replaces a state that works with an older one, and the older one can fail (an earlier `functions.php` that fatals, a plugin the site now depends on). So the undo is guarded the way a protected write is:

1. The current state is saved first, by recipe: a snapshot of the post, a restore point of the options, a backup of the theme file (or the marker for a file that is empty or absent), the provider snapshot of the snippet, a byte-for-byte copy of the active sandbox file, the activation state of the plugin. If it cannot be saved, the undo is refused with `stonewright_rescue_undo_capture_failed` before anything changes, and the probe does not run.
2. The site is probed (the baseline), the recipe runs, and the site is probed again.
3. If the baseline passed and the probe afterwards fails, the saved state is put back and the site is probed once more. The change stays `verified`, with the note `undo_reverted`, the audit row is written, and the call returns `stonewright_rescue_undo_reverted` with the probe that failed. The Rescue page shows that the undo was put back. The change can be undone again.
4. If the saved state cannot be put back either, the change becomes `rollback_failed`, an open incident, and the call returns `stonewright_rescue_undo_revert_failed`.

The guard applies only to an undo of a verified change. An incident, a change that was never verified and a `rollback_failed` change are rolled back as before, with no state saved and nothing put back: the site is already failing and the rollback is the cure. When the baseline of a verified undo already fails, the rollback is kept and the result says so (`undo_guard` is `site_already_failing`). When no probe can run, the undo is kept and `undo_guard` is `unchecked`. A kept undo reports `undo_guard` `kept`. The saved state is a way back, not a new change: it does not add an entry to the journal.

**Code needs the page.** A verified change to a theme file, a custom-code snippet (WPCode or Code Snippets), a sandbox file or the Customizer CSS is rolled back only by an administrator pressing **Roll back** on the Rescue page. The ability, the REST route and `wp stonewright rescue rollback` refuse it with `stonewright_rescue_approval_required`, which carries the approval URL and tells the agent to ask the administrator to use **Stonewright > Activity > Rescue**; a confirmation token does not change that. A `dry_run` still returns the plan, with `approval_required` true. This applies only to a verified change: an incident, or a change that was never verified, is rolled back by an agent as before, because the site is failing.

## A failed backup stops the write

A write that mutates a post takes a snapshot of the post first. When the snapshot cannot be stored and read back, the write does not run: the ability returns `stonewright_backup_failed` before it changes anything, and releases the write lock it took. This holds for the Elementor V3 abilities that add, move, remove or update elements, build a page from a spec, change page settings, kit colors or kit typography, for the per-widget `elementor-add-*` abilities, for `design-spec-to-elementor-v3` and for `elementor-v4-migrate`. `change-restore` takes a snapshot of the current state before it restores, returns it as `pre_restore_snapshot_id`, and refuses with `stonewright_backup_failed` when it cannot be stored. The history limit (10 snapshots per post) never drops the snapshot being restored.

## Change history (ledger)

The change history keeps the changes Stonewright makes, with the content before and after each one, for longer than the journal. The journal above is the short record Rescue needs to check and undo a change that has just been made; the ledger is a separate store. The [Changes page](admin/changes.md) lists the history, shows each change as a diff and undoes or redoes it. Three abilities and `wp stonewright changes` do the same for an agent or an operator (see [Abilities and WP-CLI](#abilities-and-wp-cli)).

### What is recorded

- **One row per change** in the table `stonewright_changes` (with the site prefix). A row holds the change id (`cs-` and 24 hex characters), the kind (`change`, `rollback` or `redo`), the id of the row it acts on (`parent_id`: a rollback or a redo points at the row it undoes), the ability, the user, the label of the client when one is known, the time it was recorded and settled, the family (the table under [Families](#families)), the resource type and id, the status, the size and sha256 of the content before and after, whether the change can be restored and why not, a short summary and the id of the change set.
- **The status** is `armed` until the change settles, then `verified`, `failed`, `probe_unavailable`, `incident` or `rollback_failed`. A row that a later rollback or redo undid is `rolled_back_by`. A change that the journal also tracks has the same id in both stores, and its row follows the journal's outcome (`rolled_back` after an automatic rollback).
- **The content** before and after (an image), as gzip blobs in `wp-content/uploads/stonewright-state/blobs/`. A blob is named by the sha256 of its content, so content that appears twice is stored once. Every folder the ledger creates, and the one it sits in, has `.htaccess`, `web.config` and `index.php` deny rules. A blob is read back only after its content is checked against its name, and the ledger refuses links and any path outside that folder.
- **Limits:** one image of at most 4 MB (1 MB compressed), and the total of the retention limit (100 MB by default). An image over a limit, or one that cannot be written, is left out: the change is still recorded and marked not restorable with the reason (`too_large`, `store_full` or `store_unavailable`). A row recorded with no image before it, other than a creation, is not restorable (`no_before_image`).

**When a row is written.** Posts and code are recorded before the write, under the id the journal uses when it has an entry for the write, and the row is settled with the image after and the outcome when the ability call ends. Options, menus, widgets and the other families are written when the call ends, one row for each resource that changed, with the image taken when the call began; a request that stops in the middle of such a write leaves no row. A call that changed nothing, a dry run, and a call that failed without a change leave no row.

**What is outside the history.** Rows come from ability calls, and from the services named in the families table that record their own writes: sandbox files (the abilities and the admin screens), memory deletes (the ability, the admin screen and the REST route), skills and design directions (their stores), and the settings writes of admin screens and routes. Any other write made outside an ability call, for example from an admin screen or by the code of a `php-execute` snippet, is not recorded. A ledger that cannot record, for example because its table is missing, is logged and never stops or changes the write or its result.

### What is never stored

- `wp-config.php` and files that hold credentials: `.env` files, private keys and certificates (`.pem`, `.key`, `id_rsa` and the like) and `.htpasswd`.
- Keys and salts, and options on a list of secret names, including Stonewright's own signing, encryption and confirmation secrets. A name is secret when it contains `secret`, `password`, `token`, `credential`, `oauth`, `apikey`, `privatekey`, `licensekey` or `salt`, or has a part such as `key`, `pass`, `auth`, `license`, `nonce`, `cookie`, `private` or `signature`.
- User passwords, application passwords, session tokens and OAuth tokens and keys, as resources and as values inside any other content.
- Any other credential found in content: private key blocks, bearer and basic authorization values, passwords and tokens written as `name: value`, URLs with credentials, and Stonewright tokens. These are replaced by a mask before anything is written. A change whose content was masked, before or after it, is marked not restorable (`masked_secret`), in every family.

For a refused resource the ledger keeps the row but no content and no hash of it, with the reason `secret_file`, `secret_option` or `secret_resource`.

### Retention

By default 90 days, 500 changes and 100 MB of blobs, whichever is reached first. A daily event (`stonewright_change_ledger_prune`) deletes the oldest changes first. It never deletes:

- an open change (`armed`, `incident` or `rollback_failed`), whatever its age; open changes do not count against the limit of changes;
- a change that a kept rollback or redo follows, so a chain can leave the count a little above its limit;
- a blob that another change still uses. Blobs that no row uses are removed once they are five minutes old.

Every run leaves a receipt in the `stonewright_change_ledger_prune_receipt` option, and a run that deletes something writes one short audit row.

An administrator can change the limits with the options `stonewright_change_ledger_days`, `stonewright_change_ledger_max_changes` and `stonewright_change_ledger_max_bytes`, or with the filters `stonewright_change_ledger_retention_days`, `stonewright_change_ledger_retention_max_changes` and `stonewright_change_ledger_retention_max_bytes`. A value that is not a positive number goes back to the default, and each limit has an upper bound (3650 days, 100,000 changes, 10 GB), so a setting cannot turn retention off. The byte limit is also the most the blob folder holds.

### Families

Each restore writes the image before the change back through the functions or the path the ability used, so the checks of that path still apply, reads the resource back and reports what still differs. A restore that is given `expected_current_sha256` writes nothing when the resource changed since. The undo of a change that created its resource removes it in the gentlest way the family has. The restores of users, comments, media, WooCommerce, themes, memory, skills and design directions also check the capability that the writes of the family need (users `edit_users`, `create_users` or `delete_users`, and `promote_users` when a role is written; comments `moderate_comments`; media `upload_files`; WooCommerce `manage_woocommerce`; themes `switch_themes`; memory and skills `manage_options`; design directions the design capability). Every other gate (permission, the approval that code needs, drift, the token, the claim, the probe) is the rollback engine's, below.

| Family | What is recorded | What an undo can and cannot do |
|---|---|---|
| Posts (`post`, `elementor`, `gutenberg`, `fse`, `global_styles`) | Every ability that snapshots a post before it writes: Elementor V3 and V4 writers, page settings and the kit, Gutenberg, FSE templates, template parts, navigation and global styles, patterns, Theme Builder templates, section reuse inserts, and SEO and ACF value updates; also `content-update-page`, `content-update-post` and `content-bulk-upsert-posts`. The image holds the title, status, content, excerpt, slug, parent, menu order and dates; the featured image; the terms of every taxonomy of the post type; the Elementor keys (`_elementor_data`, page settings, version, edit mode, conditions and the section record of a built page); the page template; the keys of the supported SEO plugins; the ACF values with their field references; and the custom fields the write itself names, when the user may edit them. Never the post password or any other meta key. Revisions, attachments, menu items, the Customizer CSS post and changesets are left to their own families or not recorded. | Writes the image back. Refuses a masked image, an image of another post type and a missing post, and writes nothing then. A protected meta key is skipped and reported. A term or a featured image that no longer exists is skipped and named as a limit. |
| Created posts | `content-create-page`, `content-create-post`, `content-bulk-create`, `content-duplicate-page`, the creating paths of `content-bulk-upsert-posts`, `patterns-create`, `fse-navigation`, `fse-create-template-part`, `elementor-v3-save-template`, and the template stores behind `fse-write-template`, `fse-update-template` and Theme Builder template creation. No image before. | Moves the post to the trash and never deletes it; refuses when the site keeps no trash (`EMPTY_TRASH_DAYS` is 0). A redo takes it out of the trash. |
| Options (`option`) | `settings-update`, `site-set-front-page`, `system-instructions-set`, `tool-profile`, `theme-chrome-update` and `brand-kit-apply` (the options and theme mods their restore point names), and `cpt-register`, `taxonomy-register` and `acf-field-group-save` (only the one post type, taxonomy or field group the call adds or replaces, not the other entries of the shared option). Each ability has a list of the options it may write; any other option is neither imaged nor restored. A name on the secret list, or one the ledger would mask as a key, is never read: the image lists the name with no value and no hash. The limit of ten option restore points does not apply here, and the restore points are as they were. | Writes the options back. Not restorable: `secret_option` when only secret names were written, `secret_option_skipped` when other options were imaged beside them. |
| Theme switch (`option`, resource type `theme_switch`) | `theme-activate`: the stylesheet and template that were active. | Switches back with the call `theme-activate` makes. It needs no administrator at wp-admin: it writes no code, and the engine probes the site and puts the earlier theme back when the site stops loading. |
| Menus (`menu`) | `menu-create`, `menu-add-item`, `menu-delete`, `menu-assign-location`: the items in order, with parents, titles, URLs, object links, classes and the theme locations that show them. A deleted menu keeps its full image. | Builds the menu again with its items, order, parents and locations; a deleted menu comes back under a new term id, which the rollback row names, and a redo deletes it again. The menu is read back by what identifies each item (title, URL, type, parent, order and its other fields), not by item ids, which a rebuilt menu does not keep. A restore assigns locations and never unassigns one. |
| Widgets (`widget`) | `widget-save`, `widget-delete`: the widget list of the sidebar and the settings of its widgets. Widget settings whose option name is secret are left out the same way as options. | Writes the sidebar and the widget settings back. |
| Theme files (`theme_file`) | Theme file patch, `theme.json` and backup restore: the content before and after. A file the change created has no image before. | Writes through the theme file transaction (path allowlist, PHP syntax check, byte budget, read-back, health probe). The undo of a created file deletes it, inside a theme folder only, and keeps a backup of it first. Needs an administrator. |
| Custom code (`custom_code`) | Customizer CSS updates, and WPCode and Code Snippets saves: the content before and after, and for a snippet its title, language, active state and scope. The first save of the Customizer CSS has an empty image before. | Writes through the snippet provider or the Customizer CSS post. The undo of a first Customizer CSS save empties the CSS. Needs an administrator. |
| Sandbox (`sandbox`) | Draft write, edit and delete, and activate and deactivate of the active copy, from the abilities and the admin screens. | Writes through the sandbox files, with the same guard as an activation. Needs an administrator. |
| Users (`user`) | `user-create`, `user-update`, `user-delete`: the account fields (login, nicename, email, URL, display name, registration date), the first and last name, nickname, bio and locale, the roles, and the capabilities a user has on top of its roles. Never the password hash, the activation key, session tokens, application passwords or any other user meta. | Restores the fields, roles and capabilities, never the password, and sends no email about the change. A deleted user is created again with a new id and a random password nobody knows: the row says it is partly restorable, and the person resets the password. Its sessions and application passwords are gone. The undo of a created user deletes it, unless it is the user who asks or has written content (drafts and the trash included). |
| Passwords | A changed password, as an event on `user_password`; an application password that was created or revoked, as an event on `application_password` with its name or uuid. No image and no hash. The password is never read from the call. | Not restorable (`secret_resource`). An application password made or revoked on the Connect screen or by its REST route is not recorded. |
| Comments (`comment`) | `comment-create`, `comment-update`, `comment-delete`: the comment fields, status included. A delete keeps the full row. | Restores the fields and the status. A deleted comment is inserted again with a new id, so replies keep the old parent id; comment meta is not restored. The undo of a created comment moves it to the trash, never deletes it, and refuses when the site keeps no trash. |
| Media (`media`) | `media-set-alt`, `media-optimize`, `media-upload` (a batch through its uploads), `stock-image-import`, and the attachments that `design-normalize-assets`, `design-apply-to-post` and `design-spec-to-elementor-v3` sideload: the title, caption, description, slug, parent, alt text, attachment metadata and stock attribution, and the path and size of the file. An upload is a create. | Restores the fields, alt text and metadata, never the path or the bytes of the file. `media-optimize` regenerates the sizes from the original file and keeps no earlier size files, so its row says "metadata only". The undo of an upload deletes the attachment and its files: with `MEDIA_TRASH` it goes to the trash, and otherwise the caller must ask for the permanent delete (`permanent`). |
| WooCommerce (`woocommerce`) | `wc-product-*`, `wc-variation-*`, `wc-term-*`, `wc-attribute-*`: the catalog fields of a product or variation; the name, slug, description and parent of a term; the label, slug, type, sort order and archives flag of a global attribute. A delete keeps the full image. Dry runs leave no row. | Restores through the WooCommerce objects and functions. A product in the trash is restored by setting its status back. A product, term or attribute deleted for good is created again with a new id, without its variations, its relations to products or its terms, and the row says so. The undo of a created product moves it to the trash; of a created term or attribute deletes it only while nothing uses it. |
| Plugins (`plugin`) | `plugin-delete`: the plugin file that was deleted, as an event with no image. | Not restorable (`plugin_files_deleted`): Stonewright cannot bring back the files of a plugin. Its handler asks for an administrator in case it ever restores files, because that would put code on the site. |
| Site memory (`memory`) | `memory-delete`, and the admin screen and route that delete an entry: the full row before the delete. | Inserts the row again under its id with its dates, and refuses when that id, or the scope and key, are taken by another entry. A redo deletes it again. |
| Skills (`skill`) | A link to the revision the skill store keeps: the slug, the revision, the hash of the content, the status and whether it is enabled. No copy of the text. | A save that made a new revision is undone by rolling the skill back to the earlier revision, which writes a new one. A skill that a save created is moved to the trash. Switching a skill on or off, the trash and bringing it back keep no revision and are not restorable (`no_revision_stored`). A permanent delete takes the history with it (`skill_history_deleted`). |
| Design directions (`design_direction`) | A link to the revision the direction store keeps: the id, the revision and the hash of the contract, and the id of the active direction. No copy of the contract. A save that changed only the status, and archiving, are not recorded. | A save or restore that made a new revision is undone through the direction service, which writes a new revision. A direction that a save created is archived. The active direction is restored by activating it again, or by clearing it. |
| `php-execute` (`other`) | That a snippet ran with writes allowed, with the sha256 and length of its code. The code, its output and its result are not stored. A read-only run, a snippet that did not parse and one that a guard refused leave no row. | Not restorable (`php_execute_not_undoable`): a snippet can change anything, and Stonewright cannot say what it changed. |
| Settings writes of admin screens and routes (`other`) | The name of the setting and a short sentence, for the ability switches, the mode of the setup screen and of the settings route, the feature flags (names only), the essential tools mode and the custom instructions (length and a hash of the text). | Not restorable (`admin_write_not_tracked`). Screens that use the WordPress settings API are not recorded. |

The provider snapshot that Rescue uses to roll back a snippet is kept for 24 hours; after that the Rescue entry reads as not available, and the ledger keeps the snippet body. Theme file backups (`uploads/stonewright-theme-backups/*.swbak`) stay the journal's rollback of a theme file write; when their index of 100 entries trims, the files that no index entry and no journal entry refers to, and that are older than an hour, are deleted.

### Rolling back a change

`Security\ChangeRollback::run( $change_id, $options )` puts a resource back as a ledger row recorded it. It works on any row, whatever its status, for every family above except `other`, whose rows are events with nothing to restore (`stonewright_change_family_unsupported`). The Changes page calls it when an administrator presses **Undo** or **Redo**, and so do the `stonewright/change-rollback` ability and `wp stonewright changes rollback`. A redo is the same call on a rollback row.

**The gates, in order**

1. **Permission.** The caller has `manage_options` (`stonewright_change_forbidden`), for a dry run too.
2. **The row.** It exists (`stonewright_change_not_found`: retention may have removed it); it is a change, a rollback or a redo (`stonewright_change_kind_unsupported`); it has not been rolled back already (`stonewright_change_already_rolled_back`, with the `rollback_change_id` to redo instead); it is restorable (`stonewright_change_not_restorable` with the reason, for example `too_large`, `masked_secret` or `secret_option`); and its family has a handler.
3. **The plan.** The live state of the resource, the diff from the live state to the image before the change (what the undo would change), whether the live state still hashes to the image after the change, the newer changes to the same resource, whether code needs a person and whether a token is needed. A live state that the family cannot read now, or a stored image that is gone, can refuse the run (`stonewright_change_live_unreadable`, `stonewright_change_image_unreadable`).
4. **Dry run.** `dry_run` returns the plan and stops. It writes nothing but one audit row.
5. **Code needs a person.** Theme files, custom code (snippets and the Customizer CSS) and sandbox files, for an undo and for a redo, need `human_approved`. The Changes page passes it because an administrator pressed the button. An agent, REST or WP-CLI call gets the same `stonewright_rescue_approval_required` answer as an agent that tries to roll back a verified code change from Rescue, with the `approval_url` of the change on the Changes page, and stops; a confirmation token does not change that. In an MCP client the text of the error carries the code and the `approval_url` beside the message. A handler cannot lower this: the code families always ask.
6. **Nothing to undo.** When the live state already equals the image before the change, the run is refused with `stonewright_change_already_restored`.
7. **Drift.** If the live state no longer equals the image after the change, someone or something changed the resource since. The plan warns, and the run is refused with `stonewright_change_drift` unless `force_drift` is set. A row that never settled has no image after it to compare, and needs `force_drift` too. A family whose live state cannot be read cannot be compared; the plan says so and does not guess.
8. **The previewed state.** `expected_current_sha256` (the whole hash, or a start of it of at least 32 characters) refuses a state that is not the one previewed (`stonewright_change_changed_since_preview`).
9. **Production-safe mode** needs a `confirmation_token` (`stonewright_confirmation_required` without one) issued for `stonewright/change-rollback` over the change id, `force_drift`, `expected_current_sha256` and `permanent` (`ChangeRollback::confirmation_args()`). It is verified only now, so a refusal above does not use it up.
10. **The claim.** A second run of the same change, or of any change to the same resource, while one runs is refused with `stonewright_change_in_progress`. A claim older than five minutes is taken to belong to a dead request. After the claim the live state is read again, and a state that changed in between is refused (`stonewright_change_changed_since_preview`).
11. **The restore**, by the journal or by the family handler (see the journal link below), with the probe and the revert (next section).

Gates 5 to 7 do not apply to a change whose journal entry is open: the site is failing, and the journal's rollback runs (see the journal link).

**The rows.** A restore writes a rollback row (`kind` rollback, `parent_id` the change; a redo of a rollback row has `kind` redo). It holds the state before the restore as its image before and the state after as its image after, so a redo writes the first change back. The change gets the status `rolled_back_by`. A redo marks the rollback `rolled_back_by` and the change is `verified` again. A resource that a restore creates again under a new id (a deleted menu, comment, user or product) is named in the answer (`resource_id`). A restore that finds nothing to change answers with `rollback_status` `noop` and writes no row.

**The journal link.** If the Rescue journal has an entry with the same id and the entry is open (`armed`, `incident` or `rollback_failed`), the rollback goes through `RescueRollback::run()`, the path the Rescue page and `stonewright-rescue-rollback` use, and the answer has `path` `journal`. The journal entry settles as it always did, an open incident needs neither force nor an approval because the site is failing, and the ledger gets its rollback row too. Any other change, a `verified` one included, goes through the family handler (`path` `ledger`), with the probe and the revert below. A change that the journal still lists as `verified` is settled there as `rolled_back` once the restore is kept, and stays `verified` when the restore is taken back, so the two stores agree. The Rescue page and ability call `RescueRollback` directly and are not changed.

**The audit.** Every call writes one audit row for `stonewright/change-rollback`, a dry run and a refusal included, with `change_set_id` the id of the new row (or of the change when there is none) and the way it ran (`ability`, `wp-cli` or `admin-page`). The answer carries a `receipt` with that id. Viewing a change on the Changes page computes the plan without an audit row; pressing the button is a run.

**Families that plug in.** `Security\Rollback\RollbackFamilies::register()` takes a handler for one or more ledger families, or a `CallableRollbackFamily` around a function `restore( string $change_id, array $options ): array` that returns `status`, `detail`, `limits` and `rollback_change_id` and writes its own rollback row. The engine keeps every gate for them; the handler only reads and writes the resource. The users, comments, media, WooCommerce, plugin, skill, design direction and memory families, and theme switches, are registered this way around `OtherFamilies::restore()`; the options handler sends a row of the resource type `theme_switch` to the theme handler. The engine passes `kind` (rollback or redo), `expected_current_sha256` and `permanent` to the restore function, so the row it writes is a redo row when the row acted on is a rollback row. Each of these handlers except the plugin one also reads the live resource (`OtherFamilies::live_image()`), so the plan shows the diff of the undo and tells drift, and the run checks that the resource is still in the previewed state.

### Probe and revert

A baseline probe is taken before the restore, and the site is probed again after it, with the retry the writes use. A post is checked on the home page and its own page, code on the home page, a wp-admin screen and the REST index, anything else on the home page.

- If the probe after the restore fails and the site was not already failing, the state from before the restore is put back and the site is probed once more. Both rows stay in the history (the rollback as `rolled_back_by`, the revert as a redo row), and the call answers `stonewright_change_rollback_reverted` with the probe evidence. When that state cannot be put back, the answer is `stonewright_change_revert_failed`: check the site.
- A theme file restore goes through the theme file transaction, which probes the site itself. When the site fails after the write, the transaction puts the earlier file back and the engine answers `stonewright_change_rollback_reverted` with the evidence of the failing probe, as for any other family (`reverted` is true, `site_after_revert` says how the site was judged once the file was back, and `revert_change_id` is empty). The engine restores nothing a second time and writes no row of its own: the rollback row the transaction wrote for the attempt, and the journal entry of the same id, both end `rolled_back`, so the two stores agree, and the change stays `verified` (a redo that failed the same way leaves the rollback it acted on as it was). The attempt is not an open incident: the fatal that the failing check caused is recorded on that entry, which is closed, so Rescue, the Overview and the start of a task show nothing open and safe mode stays off. The row has no content after it and the Changes page says the write was taken back instead of showing a diff. A restore that failed for another reason (a file that does not pass the checks, one that changed, one that cannot be written) answers `stonewright_change_rollback_failed`.
- A probe that could not run keeps the rollback and marks it `probe_unavailable`. A site that was failing before and still fails keeps the rollback, and the answer says so.
- A restore that did not complete answers `stonewright_change_rollback_failed`. When the restore left the item half changed, the earlier state is put back when it can be (`reverted` says whether), and the attempt is recorded as a `failed` row.

### Abilities and WP-CLI

Three abilities and one WP-CLI command give an agent or an operator the history without the page. All need `manage_options`, run the same engine as the Changes page, and never return a stored copy of the content: a diff is masked and capped, a row is a short summary. See [Abilities](abilities.md#change-history) for their place in the catalog.

| Ability | Kind | Notes |
|---|---|---|
| `stonewright/change-history-list` (`stonewright-change-history-list`) | Read | Short rows, newest first: `change_id`, `time`, `kind`, `family`, `resource_type`, `resource_label`, `ability`, `actor` and `actor_name`, `status`, `summary`, `restorable` with `restorable_reason`, `parent_id` and `children` (how many rollbacks and redos act on the row), with `total`, `pages` and `has_more`. Filters as on the page: `family`, `resource`, `ability` (the `stonewright/` prefix may be left out), `actor` (user id or login), `status` (`verified`, `rolled_back`, `incident`, `failed`, `unchecked`), `from` and `to` (`YYYY-MM-DD`, UTC), `restorable` and `kind`. Paged with `page` and `per_page` (1 to 100, 25 by default). A value that is not allowed is refused with `stonewright_change_history_invalid`; it never widens the list, and an account that does not exist matches no change |
| `stonewright/change-diff-get` (`stonewright-change-diff-get`) | Read | The row and the diff of one change: the lines, blocks, elements or fields that differ, secrets masked, capped by `max_lines` (20 to 2000, 400 by default; `truncated` says when anything was left out). `masked` counts the values replaced by `[redacted]`, including those masked when the change was stored and those with a secret-named key inside them at any depth, and `deleted` is true when the change removed the resource and the diff is the removed content. Beside it, `plan`, what an undo (or a redo, for a rollback row) would do: `restorable`, `kind`, `path`, `drift`, `newer_changes`, `warnings`, `approval_required` with `approval_url` (code), `requires_force`, `already_restored`, `confirmation_required`, `would_apply` and the start of `current_sha256`. When the undo cannot run, `plan.available` is false with `error_code` (for example `stonewright_change_not_restorable`, or `stonewright_change_already_rolled_back` with `redo_change_id`). The plan writes no rollback audit row |
| `stonewright/change-rollback` (`stonewright-change-rollback`) | Write | Input `change_id`, `dry_run`, `force_drift`, `expected_current_sha256`, `permanent` and `confirmation_token`. It calls `ChangeRollback::run()` with the caller as the actor and `ability` as the way, and never marks the call as approved by a person. A dry run returns the plan with a short diff (counts per part, not lines) and, in production-safe mode, `confirmation_args`: the four arguments to issue the token for. The engine verifies the token once; the ability does not verify it again, and writes no audit row of its own, because the engine writes one for every call. To redo, call it with the `rollback_change_id` of an earlier run |

**Code needs the administrator.** An undo or redo of a theme file, custom code, a sandbox file or the Customizer CSS is not run on an ability call, with or without a token: the answer is `stonewright_rescue_approval_required` with the `approval_url`, which an agent shows to the user and then stops. The administrator opens **Stonewright > Activity > Changes**, opens the change and presses **Undo**.

**Finding and undoing a change.** Call `stonewright-change-history-list` (for example with `resource` set to a post id), read the row, call `stonewright-change-diff-get` to see what changed and whether the item was edited since, then call `stonewright-change-rollback` with `dry_run` true and tell the user what it would do. A run needs `force_drift` when the item was edited after the change, and a `confirmation_token` in production-safe mode. See the [stonewright-rescue skill](../skills/stonewright-rescue/SKILL.md).

**WP-CLI.** All three commands need an administrator (`--user=<login or id>`):

- `wp stonewright changes list`, with the filters as options: `--family`, `--resource`, `--ability`, `--actor`, `--status`, `--from`, `--to`, `--restorable`, `--kind`, `--page`, `--per-page` and `--format`.
- `wp stonewright changes diff <change>`, with `--max-lines` and `--format=json`.
- `wp stonewright changes rollback <change>`, with `--dry-run`, `--force-drift`, `--expected-sha256`, `--permanent`, `--yes` and `--format=json`. A run asks for confirmation unless `--yes` is given; `--dry-run` prints the plan and changes nothing. In production-safe mode a run needs a confirmation token: `--issue-token` prints one for that change and those options, valid for five minutes, and `--confirmation-token=<token>` (or `STONEWRIGHT_CONFIRMATION_TOKEN`) confirms the run; exit code 2 means a token is needed.

The command line is not an administrator pressing Undo: for code the command prints the approval-required answer and the address of the Changes page, and changes nothing. The audit row says the way was `wp-cli`.

### Removal

With `STONEWRIGHT_REMOVE_ALL_DATA` defined as `true`, deleting the plugin drops the table, unschedules the daily event and deletes the blobs with their deny files, on every site of a network. A file in the blob folder that the ledger did not write stays.

## The health probe

A probe is up to five short requests, called legs:

| Leg | What it asks |
|---|---|
| `home` | The home page |
| `admin` | A wp-admin screen, reached with a one-time internal token that is bound to one path and one user and lasts three minutes. The user's cookies and Application Passwords are never used |
| `rest` | The REST index |
| `post` | The page of the post that was written (a preview link for a draft). For an Elementor kit, which has no page of its own, the public front page. A draft carries the login token. A published page and the front page are requested as an anonymous visitor and carry a mark-only token |
| `custom` | A URL the write asked to have checked. It must have exactly the home URL's scheme, host and port. It is not followed if it redirects |

A leg fails on HTTP 500, on the WordPress critical error page, or on PHP's own fatal text in the response. A blocked loopback request, a login wall, a redirect, a gateway error or a timeout is not a failure and not a success: the leg is `unavailable`, and a probe with no passing leg is `unavailable` as a whole, unless the same leg passed in the baseline (see above). A request that carries the internal token is never followed to another place by the HTTP client, so the token cannot be sent to wherever a redirect points. The one exception is the `post` leg of a published page or a kit write, which carries the mark-only token: when it is answered with a redirect whose `Location` has exactly the home URL's scheme, host and port, the probe follows it itself, at most twice, and sends a new mark-only token bound to the new path with each hop. A token is never sent twice and never to another host, scheme or port: such a redirect is not followed and the leg is `unavailable` with the reason `redirect`, as is a leg that is still redirected after two hops. The login token of a draft's `post` leg and of the `admin` leg is never sent to a redirect target. The first thing a request with a valid token does is become the user the token was issued for, before anything about the request is recorded. It stays that user for that request only: the identity and the short session behind it end with the request, and a token that is expired, already used, or sent to another path or with another nonce logs nobody in. A probe request is marked by a single-use token on every leg that needs it: the login token on the `admin` leg and on a draft's `post` leg, the mark-only token on the `post` leg of a published page or a kit write. The mark-only token is issued for nobody: it is single use, bound to one path and one nonce, lives three minutes, is validated exactly like the login token and never logs anyone in, so the page renders as an anonymous visitor sees it. Only a token that passed that check marks a request. A header, a query parameter or a cookie that is not a valid token marks nothing, because anyone can send those and a page cache could store, and serve to visitors, a render made without fonts. Elementor's Google fonts are not loaded in a probe request, because the first download of a font family can take minutes, longer than a probe request waits: the filter `elementor/frontend/print_google_fonts` returns `false` for a marked request and only for it, so a normal visit prints the fonts as before. Outbound HTTP is not blocked in a probe request. A leg that was tried a second time is marked `retried`, with the reason of the first attempt; the second attempt waits at most 15 seconds, the retries of one probe together stay inside its 30 second budget, and once a second attempt also gets no answer the other legs are not tried again. The evidence keeps leg names, statuses, HTTP codes and short reasons. It never holds a URL, a header or a response body.

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
