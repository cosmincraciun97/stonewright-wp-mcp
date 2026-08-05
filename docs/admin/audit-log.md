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
are protocol or operator outcomes, not recurring agent-repair debt. A resolved
incident reopens only when the same resource/path or change-set failure returns.

See [Updating Stonewright](../updates.md) for persistence guarantees and
[Security](../security.md) for the broader audit contract. The complete
contract is [Permanent remediation contracts](../permanent-remediation-contracts.md).
