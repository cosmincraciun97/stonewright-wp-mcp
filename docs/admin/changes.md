# Changes

The Changes page (**Stonewright → Activity → Changes**) lists the changes Stonewright recorded in
its change history, newest first, shows what each one changed as a diff, and undoes or redoes a
change. It is marked **EXP** like the other pages that are still changing.

Rescue stays the page for a change that stopped the site from loading and for safe mode. The
header of Changes has a **Rescue** button; the header of Rescue links back (**View changes**), and
each change Rescue lists that has a history record links to its diff (**View diff**).

The page needs the `manage_options` capability. The list and the drawer only read, with no
background request. The one thing that writes is **Undo** and **Redo** (below). What the change
history stores, for how long, and how a change is rolled back is described in
[Rescue](../rescue.md#change-history-ledger).

## The timeline

Each row shows when the change happened (site time, with the UTC time in the tooltip), the kind of
thing that changed (Post, Elementor, Theme file, Option and so on) and its name, the ability that
made the change and the client it came through, the user, the result and the short summary the
writer gave. The last column has **View diff**.

- The name of a post links to its edit screen while the post exists. Anything else (an option, a
  file, a menu) shows its name as text.
- The result is a word and an icon: **Verified**, **Rolled back**, **Incident**, **Rollback
  failed**, **Failed** or **Not verified**. A rollback or a redo row also carries its kind.
- A change that cannot be undone says why: **Not restorable: the content was too large to store**,
  or that it held a secret that was masked, or that Stonewright keeps no copy of that kind of
  content.
- The list shows 25 changes a page. **Newer changes** and **Older changes** keep the filters.

With no history yet the page says so; with filters that match nothing it offers **Reset filters**.

### Filters

The form is a plain GET form, so a filtered list has an address you can bookmark. Each field states
how it matches:

- **Family**, **Status** and **User** (an ID or a login), **Resource** (a post ID, an option name
  or a file path) and **Ability** match exactly. The `stonewright/` prefix of an ability may be
  left out.
- **From** and **To** are whole days in UTC.
- **Status** groups: Verified; Rolled back (including a change that a later rollback undid);
  Incident (including a failed rollback); Failed; Not verified.
- **Restorable only** hides changes that cannot be undone.

A value that is not understood is dropped, and an unknown user or ability matches nothing; it
never means "any".

## The drawer

**View diff** opens one drawer for that change. The address names the change
(`...&change=<change id>`), so the drawer works without script, can be bookmarked and is
rendered on the server for that one change only: opening the list never reads or prints the
content of any change. If the change is not in the history (retention removed it, or the address
is not a change id), the page says so.

The drawer has three tabs, which are links:

- **Diff** shows the content before against after, with the kind of diff that fits what changed:
  - **Text and code** (theme files, custom code, sandbox files, post content without blocks): lines
    with the old and the new line number, a `+` or `-` marker and words for assistive
    technology, in hunks with three lines of context. The lines scroll inside their own block,
    which can be reached with the keyboard.
  - **Block content**: one entry per block that was added, removed, moved or changed, with its
    attribute changes and the text diff of its own markup.
  - **Elementor pages**: one entry per element, with a table of its setting changes.
  - **Options, settings and other fields**: a table of the changed key paths with the value before
    and after.

  A change that deleted something (a menu, a memory entry) has no content after it: the diff
  shows what was removed against nothing, under a **Deleted** label. A change that never settled
  has nothing to compare and says so.

  A callout above the diff says when it was cut to keep the page small (**Not everything is
  shown**), and when values were masked (**Values were masked**), including values that were
  already masked when the change was stored. A change that cannot be restored says so here too.
- **Details** lists the change id, kind, family, resource, ability, user, client, status, the times
  it was recorded and settled, the size and the first 12 characters of the hash of the content before
  and after, whether it can be restored, its change set, and a link to its audit events.
- **History** shows the change's parent, if it is a rollback or a redo, and the rollbacks and redos
  of the change, with the redo of a rollback listed under that rollback, down to five levels and
  forty rows (a note says when more exist). Each relative links to its own diff.

If the content of a change was removed by retention, or was never stored, the drawer says so
instead of showing a diff.

## Undo and Redo

The footer of the drawer has **Undo this change**. It opens a dialog with the dry run of the undo
before anything is written:

- **What this does**: what changed, the ability that changed it, and one sentence for the kind of
  item.
- **The diff** goes from what is on the site now to what the change recorded before it, so the
  lines that disappear are the ones the undo removes. A kind of item whose current state
  Stonewright cannot read shows a notice instead.
- **Warnings**: a newer change to the same item that the undo also overwrites, and drift (below).

**Cancel** has the first focus, **Escape** closes the dialog and focus goes back to the Undo
button. The dialog fits a 400 px screen, and the confirm button names the action. Without script,
**Undo this change** is a link to the same page with the dialog printed open, and the form posts as
it is.

Pressing the confirm button posts the form to `admin-post.php` with a nonce. Only an administrator
(`manage_options`) can send it. The page then opens the new row and says what happened at the top:

- the change was undone (or redone) and the site loads;
- it was done, but the health check could not run, or the site was already failing and still is;
- the site failed its health check, so the earlier state was put back (both rows stay in the
  history), or the earlier state could not be put back and the site needs checking now;
- nothing was changed, with the reason: the item was edited since, it changed while the dialog was
  open, the item already is as it was before the change, another rollback of the item is running,
  the confirmation is missing or expired, or the change cannot be undone.

The notice links to the **new change**, the rollback row, which has its own diff and sits in the
History of the change, and shows the receipt with a link to the audit event.

A change that was rolled back shows **Redo this change**, which undoes the rollback and puts the
change back in effect. A rollback row shows it too. A redo can be undone again. A change that was
rolled back and not redone cannot be rolled back a second time.

In **production-safe** mode the dialog also asks you to type `ROLL BACK`, and the form carries a
confirmation token bound to this change and to the state you saw, valid for five minutes. A token
that is for another change, or that was issued without the overwrite choice, is refused.

### Drift

If someone, or another tool, changed the item after Stonewright's change, the dialog says it has
**changed since** and the diff shows the edit that the undo would overwrite. The confirm button then
needs a box ticked: **I understand that this overwrites changes made since.** Without it nothing is
written. The overwritten state is kept in the rollback row, so **Redo** brings it back. If the item
changes while the dialog is open, the run is refused and asks you to open the change again.

### Code needs you

For a theme file, a snippet, the Customizer CSS or a sandbox file, an undo or a redo is a change to
code, and the dialog says **This puts code back**. An agent, WP-CLI or the REST route cannot do it:
they get the approval-required answer with the address of this page and stop. Pressing the button
here as an administrator is the approval. The code is written through the same checks as any code
change (the path rules, the PHP syntax check, the read-back), and the site is probed afterwards.

### What can be undone

Posts and their kinds (pages, Elementor documents, Gutenberg content, templates, global styles),
settings and other options, theme switches, menus, widgets, theme files, snippets, the Customizer
CSS, sandbox files, users (fields and roles, never a password), comments, media (fields and
metadata, not the file), WooCommerce products, variations, terms and attributes, site memory,
skills and design directions.

**Not restorable** means the history has no way to put the item back: the content was too large,
the store was full, the change held a secret that was masked, Stonewright keeps no copy of that
kind of content (credential files, secret settings, passwords), or the change is an event with
nothing to restore (a plugin delete, a password change, a PHP snippet that ran, a setting written
from an admin screen). Such a change has no button. In its place the drawer says **Undo is not
available for this change** with the reason; a change that was rolled back and whose rollback
cannot be redone says that instead.

An agent can list, diff and undo the same changes with `stonewright-change-history-list`,
`stonewright-change-diff-get` and `stonewright-change-rollback`, and an operator with
`wp stonewright changes`; see [Rescue](../rescue.md#abilities-and-wp-cli).

An incident that Rescue lists is still rolled back from Rescue, as before. When Changes undoes a
change whose Rescue entry is still open (armed, incident or rollback failed), it uses the Rescue
rollback, so both pages tell the same story. Any other change, a verified one included, is undone
by the change history with its own probe and revert, and Rescue then lists it as rolled back.

## What the page never shows

- A stored image, a blob or its file name. A diff is built from the image through the diff engine
  and shows only the lines and values that differ.
- Values that look like passwords, keys or tokens: they appear as `[redacted]`. Content the change
  history masked when it stored it stays masked.
- A whole hash: the Details tab shows the first 12 characters, and the Undo form carries the first
  32 to bind the run to the state you saw.
- Every value on the page, a diff line, a title, a file path or something typed into a filter, is
  escaped and shown as text.

Sources:
- `plugin/includes/Admin/ChangesPage.php` (the list, the filters and the address)
- `plugin/includes/Admin/ChangeDetail.php` (the drawer)
- `plugin/includes/Admin/ChangeUndo.php` (the Undo and Redo dialog, its post action and the result)
- `plugin/includes/Admin/ChangeLabels.php` (family, status and reason labels)
- `plugin/includes/Security/ChangeRollback.php` (the rollback engine)
- `plugin/includes/Admin/Ui/DiffView.php` (the diff component)
- `plugin/includes/Support/Diff/ChangeDiff.php` (which diff reads which kind of content)
