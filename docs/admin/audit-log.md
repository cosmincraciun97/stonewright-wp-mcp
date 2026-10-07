# Audit Log

The Audit Log is Stonewright's single append-only view of redacted Plugin
mutations, protected REST writes, authentication incidents, verification, and
rollback status.

## What the page shows

- ability or protected route;
- WordPress user when available;
- result status;
- affected resource, verification, and rollback state;
- UTC timestamp;
- a readable incident cause for blocked, authentication, and error rows;
- incident state (`observing`, `open`, `resolved`, `suppressed`), occurrence and
  reopen counts, expected verifier, and remediation code;
- a **Change set** line with a short identifier, a copy button, and the lineage of a change set that has relatives (see below);
- the redacted structured payload behind **View payload**.

Use **Copy payload** when attaching evidence to a private support report. Review
it first even though Stonewright redacts known credential and code fields.

## Rows, views, and counters

- Failed, blocked, and retryable rows always carry a readable message. When the
  caller supplied none, it names the outcome and the error code.
- Successful rows carry no error code, repair hint, or incident link, and the
  page shows no error cause, repair hint, or incident link for successful rows
  stored earlier.
- An ability call is recorded as one row. Defining, registering, and creating a
  custom Elementor widget, and defining an Elementor atomic widget, add no
  second row of their own, including when the source guard rejects the widget.
  A skill saved through the `skills-save` ability adds the skill library's
  details (action, slug, revision, content hash) to the call's row, while saves
  from REST and the admin screen keep their own row. Queueing a block change
  writes one row for the call; sweeping stale entries out of the queue is a
  separate `gutenberg.queue_prune` event.
- OAuth token, revocation, and authorization rows name a client only once the
  site knows that client, so an identifier a caller made up does not create
  rows of its own.
- Read-only abilities are recorded as `READ` rows. Lock, busy, and conflict
  errors are recognized from the reported error code, never from a word in the
  ability name, so abilities whose names start with `blocks-` are not mistaken
  for lock errors.
- Free-text redaction masks a value written after a credential word ("the token
  is ...") but keeps ordinary prose readable, so "The refresh token is no longer
  valid." stays as written.
- The view links (**All**, **Errors**, **Retryable**, **Blocked / Safety**,
  **Auth**, **Resolved**) show the number of rows each one lists. **Errors**,
  **Retryable**, **Blocked / Safety**, and **Auth** list problems only, so a
  successful sign-in appears under **All** but not under **Auth**.
- Incident totals count every incident, not only the most recent page.
- The **Recurring errors** panel lists patterns that failed more than once. A
  pattern leaves the panel after 30 days without an occurrence.

## Change sets and repair lineage

Every row that belongs to a change set shows a **Change set** line in its
details: a short identifier (the full identifier is its tooltip), a **Copy**
button that copies the full identifier, and **Show rows**, which filters the log
to that change set. While the log is filtered to a change set, a chip under the
filters names it, and its **Remove change set filter** link takes off only that
filter; the chip stays when no row matches.

A write that repairs a failed change passes `repair_of` with that change's
`change_set_id`. When a change set has relatives (a repair of it, a change it
repairs, a change it supersedes, or a parent that left the log), its line also
has a **Lineage** button. The button opens a drawer titled with the change set.
The drawer holds one panel per chain of change sets:

- a summary: how many change sets failed, were verified, or are not verified,
  the UTC time span, and the site mode;
- a nested list in which a repair sits under the change it repairs, oldest
  first. For example: a change that verified when written, a failed
  verification of it, the repair, and the repair verified;
- one list item per change set, readable as text: a state badge
  (**Verification failed**, **Failed**, **Verified**, or **Not verified**), the
  operation, the time, the duration, the ability code, the short identifier,
  the change set it repairs or supersedes, the state of the incident it opened,
  and a **Show rows** link. The change set of the row you opened the drawer
  from is marked **This change set**;
- a branch with more than five repairs folds into a `details` element, and the
  panel draws at most 50 change sets before **Show N more** lists the rest.
  The list indents three levels; deeper change sets keep the third level's
  indent and say which level they are, such as "… Level 5";
- a note when the chain is longer than 100 change sets, and the text
  "not in this log" for a repair whose parent was pruned.

The drawer is a modal dialog. Tab moves from node to node, Escape or **Close**
closes it, and focus returns to the **Lineage** button that opened it. At 782 px
and below it fills the screen. The panels are rendered by the server; the script
that opens the drawer makes no request, and without it the **Show rows** links
still work. The page looks up the change sets of its rows with the indexed
`change_set_id` and `repair_of` columns, at most three hops, and prints at most
25 panels.

The page renderer reaches the drawer through two hooks only:
`stonewright_audit_log_toolbar` runs under the filter form with the filters, the
rows of the page, and the incident state of each incident, and
`stonewright_audit_log_change_set_cell` runs once for each row that names a
change set. Without a callback on them the page shows no **Change set** line.

## Layout contract

Large screens use one fixed-layout table. At narrower admin widths each row
becomes a labeled card, and the details cell spans the full card. Payloads wrap
and scroll inside their own container; they must never widen the WordPress admin
page. The lineage drawer wraps long identifiers and reads at a 400 px viewport
without horizontal scrolling. Its styles are in
`assets/admin/pages/audit-lineage.css` and use design tokens only.

Sandbox no longer embeds a second audit table. Historical
`stonewright-sandbox&tab=audit` links point to this page so filters, pagination,
incident guidance, and payload behavior have one implementation.

## Retention and updates

The log is append-only during normal operation. Plugin updates and schema
migrations preserve existing audit rows; the update that adds `repair_of` adds
the column and its index in place. A genuinely fresh installation starts
with zero rows; the first real operation may add one.

Administrative cleanup uses only
`stonewright/security-runtime-data-purge`. Review its count-only dry run, then
apply the exact returned state and plan hashes with the explicit destructive
acknowledgement. `production-safe` also requires a confirmation token. The
purge never deletes rows created above the reviewed numeric watermarks and
retains one redacted cleanup receipt when audit history is selected.

Retryable OAuth/provider bursts are sampled: the first event, threshold
crossings, and the first event after a quiet period remain visible, while the
intermediate volume is retained as a count. `AUTH`/`PERMISSION`/`SAFETY` rows
are protocol or operator outcomes, not recurring agent-repair debt. Replays that
revoke a grant, authorization-code replays, explicit revocations, and duplicate
refresh deliveries are never sampled: each writes its own row, marked as a
security event and free of credential values.

An incident covers one cause: the same error code from the same ability family
on the same kind of resource, whatever the record, path, or change set. A
resolved incident reopens when its cause recurs, and each reopening is counted.
Incidents that do not involve writes, verification, or rollback close after
seven days without a new occurrence, with the end of that quiet period as their
resolution time. A daily run performs that sweep and stays scheduled whatever
the retention setting; rows and incidents are deleted only when a retention
window is configured. A recurrence after a quiet week reopens the incident
even before the sweep ran. Write, verification, and rollback incidents close
only through a verified repair.

See [Updating Stonewright](../updates.md) for persistence guarantees and
[Security](../security.md) for the broader audit contract. The complete
contract is [Permanent remediation contracts](../permanent-remediation-contracts.md).
