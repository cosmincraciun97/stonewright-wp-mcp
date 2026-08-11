# Permanent remediation contracts

This document describes the durable contracts behind the audit, OAuth, write,
and design surfaces. It is intentionally site-independent: runtime rows keep
hashes and bounded evidence, never customer content, credentials, or local
paths.

## Audit events and incidents

Every audited outcome is normalized to schema `2.0` before persistence. The
taxonomy separates `AUTH`, `PERMISSION`, `SAFETY`, `VALIDATION`, `TRANSIENT`,
`WRITE`, `VERIFY`, `ROLLBACK`, `EXTERNAL`, and `INCIDENT` categories from the
outcomes `SUCCESS`, `BLOCKED`, `RETRYABLE`, and `FAILED`.

An event carries one root error code, a public message, a bounded resource
identity, a normalized path, cause and strategy fingerprints, transaction and
change-set identifiers, retry information, and an allowlisted redacted detail
map. Context-token identity is hashed. Authorization values, request bodies,
recipient addresses, page HTML, and filesystem paths are not audit payloads.

Recurring incidents have an explicit lifecycle: `observing`, `open`,
`resolved`, or `suppressed`. Ordinary failures open after two matching
occurrences; retryable failures use three; critical rollback failures open
immediately. Permission and safety blocks do not become agent-repair
incidents. A resolver closes an incident only after a correlated success with
the same transaction resource/path or an exact change-set correlation. A new
matching failure reopens a resolved incident. Legacy rows are classified and
migrated idempotently into the same contract.

`stonewright/security-runtime-data-purge` is the only supported runtime-history
reset. Its dry run returns counts, hashes, and numeric watermarks without row
bodies. Apply requires the exact reviewed state and plan hashes, an explicit
acknowledgement, and a confirmation token in `production-safe` mode. Database
deletes stop at the reviewed watermarks, so concurrently created rows survive
and force a visible partial-failure result. A successful audit purge retains
one redacted cleanup receipt.

## OAuth failure and rate-limit contract

The companion persists OAuth tokens in an atomic temporary-file replacement
with mode `0600`. Refresh calls are single-flight per process. Every successful
refresh must return a new, nonempty refresh token; omission or replay of the
previous token clears local token state and requires reauthorization. The new
rotated token replaces the old value. `invalid_grant`, `invalid_client`, and
`unauthorized_client` delete local token state and stop retrying; the caller
receives a reauthorization-required result.

Authorization, token exchange, refresh, and bearer validation carry or verify
the exact canonical MCP resource. Protected Resource Metadata advertises only
the minimal `mcp` resource scope. Refresh tokens share a one-way grant-family
identifier: reuse of a revoked token revokes the complete family and every
access token issued from it.

Transient HTTP responses and network failures use bounded exponential backoff,
`Retry-After` when present, jitter, and a circuit breaker. OAuth responses use
`Cache-Control: no-store`, a bounded `Retry-After`, and a correlation ID.
Server-side throttling is atomic when the rate-limit table is available and
falls back only for test doubles or pre-schema startup. It keys endpoint and
registration counters by client identity plus an IPv4 `/24` or IPv6 `/64`
network bucket. Forwarded client IPs are trusted only when the immediate peer
is in the explicit trusted-proxy allowlist.

Deterministic matrix coverage (discovery paths, PKCE S256-only, resource
binding, `WWW-Authenticate`, JSON `invalid_grant` reasons, companion terminal
reauth, and refresh rotation/replay) lives in:

- `plugin/tests/Unit/OAuth/OAuthMatrixContractTest.php`
- `companion/tests/oauth-matrix.test.ts`

OAuth audit rows preserve retryable and server-side failures on the short
diagnostic cadence. Terminal client-side failures such as an expired or
revoked grant are coalesced for 24 hours by endpoint, client, status, error,
and reason, with sparse aggregate receipts at counts 1, 25, 100, and 500.
Admin rendering resolves registered OAuth client names in one batched lookup;
pre-login events therefore show a client label instead of an unknown user and
do not add one database query per row.

## Elementor and Gutenberg writes

One write receipt is the transaction handoff. It contains the transaction and
change-set IDs, architecture, targets, lock fingerprint and age, snapshot,
before/planned/after/readback hashes, verification state, rollback state,
root failure path, retry guidance, and bounded recovery instructions.

Elementor V3 batch mutation owns one post lock, one snapshot, one persistence
write, one readback, and one rollback decision. The write path may preserve
unknown untouched runtime settings, but a newly touched unknown key is an
error. Responsive scope is enforced, repeater `_id`/`custom_id` identities
must be unique, and legacy violations are warnings only when they are outside
the patch. `stonewright/elementor-v3-legacy-debt-report` exposes a bounded
read-only debt report with risk and approval requirements; it never normalizes
the source document. V3/V4 architecture is routed before mutation; mixed roots
are not rewritten or silently converted.

`stonewright/blocks-batch-mutate` applies insert, update, move, and remove
operations in memory, then snapshots, writes, reads back, and restores once on
failure. Existing block attributes and child structure are preserved unless
the operation explicitly changes them. Remove remains confirmation-gated in
`production-safe` mode.

## Design evidence and diagnostics

`SectionManifest` is a vendor-neutral, deterministic handoff for section
bounds, layout, spacing, typography, color, assets, responsive intent, roles,
provenance, confidence, and unresolved evidence. A page manifest contains real
validated section manifests, preserves explicit numeric order (or source order
when omitted), rejects duplicate section IDs and explicit order values, and
decomposes into those normalized sections rather than a synthetic parent row.
Visual-source sections require stable node provenance.

`CarouselIntent` requires measured desktop, tablet, and mobile slide, gap,
arrow, and dot evidence. Optional behavior stays `null` when it was not
observed; the validator does not invent carousel defaults. An arrow asset is
required only when at least one viewport enables arrows. Each active previous
and next arrow uses exactly one WordPress media ID, renderer icon-library
reference, normalized manifest asset, or sanitized inline SVG, plus an
accessibility label. Inline SVG is parsed with a DOM element/attribute
allowlist; scripts, event handlers, CSS imports, external references, entities,
and unsupported nodes fail closed. A content hash is marked verified only when
Stonewright hashed the sanitized inline bytes.

Section-manifest renderer planning accepts only registered candidates carrying
a valid schema hash. The native target comes from that candidate, never from a
hardcoded widget or block guess. Required semantic capabilities must be
declared by the candidate, map to controls present in its live schema, or match
an exact live control. A gap never means custom code was approved. Visual
comparison validates expected geometry, caps viewports, anchors, and findings,
and reports box, typography, line-height, spacing, color, missing-measurement,
and extra-element evidence with deterministic tolerances and per-anchor scores.
Third-party risk maps record ownership, unknown controls, destructive actions,
safe patch keys, and preservation hashes.

Form delivery diagnostics inspect actions, structural mail settings, provider
hooks, Newsman presence, and failure counts without sending mail or returning
recipients/body content. Capability preflight reports the current user,
object-level `edit_post`, roles, mapped capabilities, permission filters, and
an evidence-based remediation hint; it never bypasses a denial.

## Verification

The repository verifies these contracts with focused PHPUnit and Vitest suites,
static analysis, coding standards, dependency/security audits, generated
ability-matrix refresh, documentation freshness, public-hygiene scanning, and
package smoke checks. Browser rendering remains a separate acceptance gate for
visual Elementor work; a metadata readback is not visual proof.

CSV audit exports neutralize spreadsheet-formula prefixes after redaction.
Exports remain bounded to allowlisted fields and fail closed if secret-like
content survives sanitization.
