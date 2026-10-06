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
- the redacted structured payload behind **View payload**.

Use **Copy payload** when attaching evidence to a private support report. Review
it first even though Stonewright redacts known credential and code fields.

## Rows, views, and counters

- Failed, blocked, and retryable rows always carry a readable message. When the
  caller supplied none, it names the outcome and the error code.
- Successful rows carry no error code, repair hint, or incident link, and the
  page shows no error cause or repair hint for successful rows stored earlier.
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

## Layout contract

Large screens use one fixed-layout table. At narrower admin widths each row
becomes a labeled card, and the details cell spans the full card. Payloads wrap
and scroll inside their own container; they must never widen the WordPress admin
page.

Sandbox no longer embeds a second audit table. Historical
`stonewright-sandbox&tab=audit` links point to this page so filters, pagination,
incident guidance, and payload behavior have one implementation.

## Retention and updates

The log is append-only during normal operation. Plugin updates and schema
migrations preserve existing audit rows. A genuinely fresh installation starts
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
resolution time. The daily retention run performs that sweep when an operator
has configured scheduled retention, and a recurrence after a quiet week reopens
the incident even before the sweep ran. Write, verification, and rollback
incidents close only through a verified repair.

See [Updating Stonewright](../updates.md) for persistence guarantees and
[Security](../security.md) for the broader audit contract. The complete
contract is [Permanent remediation contracts](../permanent-remediation-contracts.md).
