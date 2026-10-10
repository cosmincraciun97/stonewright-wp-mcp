# Changes

The Changes page (**Stonewright → Activity → Changes**) lists the changes Stonewright recorded in
its change history, newest first, and shows what each one changed as a diff. It is marked **EXP**
like the other pages that are still changing. Rescue stays the page for a change that stopped the
site from loading and for safe mode; its header links to Changes (**View changes**) and each change
it lists that has a history record links to that change's diff (**View diff**).

The page needs the `manage_options` capability. It only reads: it has no form that saves, no
button that changes the site and no background request. The change history itself, what it stores
and for how long, is described in [Rescue](../rescue.md#change-history-ledger).

## The list

Each row shows when the change happened (site time, with the UTC time in the tooltip), the kind of
thing that changed (Post, Elementor, Theme file, Option and so on) and its name, the ability that
made the change and the client it came through, the user, the result and the short summary the
writer gave.

- The name of a post links to its edit screen while the post exists. Anything else (an option, a
  file, a menu) shows its name as text.
- The result is a word and an icon: **Verified**, **Rolled back**, **Incident**, **Rollback
  failed**, **Failed** or **Not verified**. A rollback or a redo row also carries its kind.
- A change that cannot be undone says why: **Not restorable: the content was too large to store**,
  or that it held a secret that was masked, or that Stonewright keeps no copy of that kind of
  content.
- The list shows 25 changes a page. **Newer changes** and **Older changes** keep the filters.

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

A value that is not understood is ignored, and an unknown user or ability matches nothing; it
never means "any".

## The diff

**View diff** opens one drawer for that change. The address names the change
(`...&change=<change id>`), so the drawer works without script, can be bookmarked and is
rendered on the server for that one change only: opening the list never reads or prints the
content of any change.

The drawer has three views, as tabs that are links:

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

  A callout above the diff says when it was cut to keep the page small, and when values were
  masked. A change that cannot be restored says so here too.
- **Details** lists the change id, kind, family, resource, ability, user, client, status, the times
  it was recorded and settled, the size and the first characters of the hash of the content before
  and after, whether it can be restored, its change set, and a link to its audit events.
- **History** shows the change's parent, if it is a rollback or a redo, and the rollbacks and redos
  of the change. Each relative links to its own diff.

If the content of a change was removed by retention, or was never stored, the drawer says so
instead of showing a diff.

**Undo this change** is shown disabled with the reason "Undo arrives with the rollback engine".

## What the page never shows

- A stored image, a blob or its file name. A diff is built from the image through the diff engine
  and shows only the lines and values that differ.
- Values that look like passwords, keys or tokens: they appear as `[redacted]`. Content the change
  history masked when it stored it stays masked.
- Every value on the page, a diff line, a title, a file path or something typed into a filter, is
  escaped and shown as text.

Sources:
- `plugin/includes/Admin/ChangesPage.php` (the list, the filters and the address)
- `plugin/includes/Admin/ChangeDetail.php` (the drawer)
- `plugin/includes/Admin/Ui/DiffView.php` (the diff component)
- `plugin/includes/Support/Diff/ChangeDiff.php` (which diff reads which kind of content)
