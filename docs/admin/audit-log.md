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

See [Updating Stonewright](../updates.md) for persistence guarantees and
[Security](../security.md) for the broader audit contract.
