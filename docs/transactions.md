# Elementor transaction envelope

Stonewright applies multi-step Elementor V3 mutations through a **transaction envelope** so agents get snapshot, readback, and optional rollback without hand-rolling recovery.

## Ability

- WordPress ability: `stonewright/elementor-v3-transaction-run`
- MCP tool: `stonewright-elementor-v3-transaction-run`

Related batch path: `stonewright/elementor-v3-batch-mutate` (grouped ops without the full envelope).

Native repeated-content path:
`stonewright/elementor-wire-loop` plans or transactionally adds one Loop Grid
or Loop Carousel. It validates the live Pro widget schema and query, stages a
new loop-item template only when requested, writes the page once, verifies
readback, and rolls back both resources on failure.

## Envelope contract (summary)

| Field | Role |
|---|---|
| `post_id` | Target Elementor document |
| `operations` | Ordered mutation ops (same family as batch-mutate) |
| `precondition_hash` / structure hash | Optional: refuse to write if live data diverged |
| `dry_run` | Validate + plan without committing |
| `confirmation_token` | Required for destructive runs when `stonewright_mode=production-safe` |

Runtime behavior (plugin):

1. **Permission** — `Permissions::edit_post( $post_id )`.
2. **Snapshot** — `Backup::snapshot_post` before mutating Elementor data.
3. **Apply operations** — via the Elementor transaction runner.
4. **Readback** — structural hash / element count after write.
5. **Post cache** — invalidate only the target document's Elementor HTML cache
   and WordPress object cache after verified readback; preserve CSS metadata.
6. **Rollback** — restore the document snapshot when the run fails mid-flight.
   CSS closure is a separate guarded target-post transaction.

Do not claim absolute transactional ACID guarantees across WP-CLI, object cache, and Elementor CSS regeneration. The envelope makes agent edits **more recoverable**, not a database transaction.

## Agent workflow

1. `stonewright-task-start`
2. `stonewright-elementor-document-health` to measure architecture, serialized
   size, invalid settings, and heavy `e-paragraph` usage without returning
   content
3. `stonewright-elementor-page-digest` (or structure get) on the target post
4. Prefer `stonewright-design-native-plan` + DesignSpec when building from evidence
5. `stonewright-elementor-v3-transaction-run` (or batch-mutate for smaller edits)
6. Call `stonewright-elementor-css-regenerate` when generated CSS must be rebuilt.
   It regenerates only the resolved post or loop CSS inside a guarded asset
   transaction and returns hashed health evidence. Never pass `regenerate_css`.
7. Call `stonewright-elementor-post-write-verify` with the touched element IDs
   or bounded content markers. It renders without a CSS pass and returns
   assertion results without returning page HTML.
8. Measure and capture the logged-out frontend at desktop, tablet, and mobile.
   For boxed containers inspect both the outer container and `.e-con-inner`.
9. Re-read health + digest; restore from audit/snapshot if verification fails.

## Change set (ChangeSetV1)

Every write that returns a receipt also returns one `change_set` object with the
same shape, so an agent reads one contract whatever it wrote. The change set is a
projection of the receipt the write already produces (`write_receipt`, or the
hashes, backup reference, and verification keys of a file or option write); it is
not a second receipt and nothing persists it separately. A failed write returns
it inside the error data, next to `write_receipt`; a client that receives only
the error message or the REST error data gets its `change_set_id`. The change set
belongs to Plugin abilities: Direct mode keeps its own receipts. The published
schema is [contracts/change-set-v1.schema.json](contracts/change-set-v1.schema.json).

| Field | Meaning |
|---|---|
| `schema` | Always `ChangeSetV1`. |
| `change_set_id` | Identifier of the change set. A caller-supplied `change_set_id` is kept; otherwise the write derives `cs-` plus 24 hex characters from what it changes. It is the `change_set_id` of the write's receipt and audit rows. |
| `planned` | The changes the write intended, each `{kind, ref, action, index?, note?}`. A request that already matches the current state is listed here and nowhere else. |
| `applied` | Planned changes the independent readback confirmed. |
| `missing` | Planned changes the readback did not confirm, or that were not written. A refused, blocked, or failed write applies nothing and misses everything it planned. |
| `unexpected` | Changes the readback shows that the plan did not ask for. Elementor V3 and V4 writes compare the live document with the pre-write snapshot element by element. Gutenberg verifies the post content as one document and reports none. |
| `before_hash`, `after_hash` | SHA-256 of the target before the write and as read back after it. For a dry run `after_hash` is the hash the write would produce; it is empty when the write did not run or the value is not known. |
| `verification` | `{status, evidence}`. `status` is `verified`, `failed`, or `unverified` (see below). `evidence` is a bounded map; it always holds `counts`, the exact sizes of the four lists. |
| `rollback_available` | `true` when a recipe can undo the change set now. |
| `rollback_recipe_ref` | `{kind, ref, target?}` naming the recipe (`post_snapshot`, `theme_backup`, `option_snapshot`, `provider_snapshot`), or `null` exactly when `rollback_available` is `false`. |
| `repair_of` | `change_set_id` of the failed change this write repairs, or `null`. |
| `supersedes` | `change_set_id` of an earlier change this write replaces, or `null`. |
| `approval_reason` | Code naming the approval the write ran under: `custom_code_grant`, `confirmation_token` (production-safe mode), or `mode_policy` (the site mode needed no extra approval). `null` when no write ran. |

Each list holds at most 50 entries; `verification.evidence.counts` keeps the exact
sizes and `verification.evidence.truncated` names a list that was cut.

### Verification status

- `verified`: the write ran and its independent readback matched the plan. For
  `stonewright-elementor-post-write-verify`, the rendered page shows every checked
  element and marker.
- `failed`: the call failed. The write was refused after validation, was not
  persisted, was rolled back, or its readback or render contradicted the plan.
  `evidence.root_error_code` and `evidence.rollback_status` say which.
- `unverified`: there is no verdict yet. This covers a dry run, a change queued for
  the browser finalizer, a request that changes nothing, a write that a gate
  stopped (approval, confirmation token, permission), and a success that reports
  no verification. `evidence.outcome` names the case: `dry_run`, `queued`,
  `unchanged`, `not_applied`, or `pending`.

### Which writes return it

| Ability | Planned change: `kind` / `ref` / `action` | `rollback_recipe_ref.kind` |
|---|---|---|
| `stonewright/elementor-v3-batch-mutate` | `element` / element id / the operation action | `post_snapshot` |
| `stonewright/elementor-v4-update-node` | `element` / element id / `update_settings` | `post_snapshot` |
| `stonewright/blocks-batch-mutate` | `block` / block path, `root` for the top level / the operation action | `post_snapshot` |
| `stonewright/theme-file-patch` | `file` / theme-relative path / the patch mode | `theme_backup` |
| `stonewright/theme-backup-restore` | `file` / theme-relative path / `restore` | none |
| `stonewright/theme-custom-css` | `custom_code` / Customizer CSS path / `update` | `post_snapshot` |
| `stonewright/custom-code-provider` (`dry-run`, `apply`, `rollback`) | `custom_code` / `provider:target` / `apply` or `rollback` | `provider_snapshot` (apply only) |
| `stonewright/theme-chrome-update` | `theme_option` / `bucket.key` / `set` | `option_snapshot` |
| `stonewright/elementor-post-write-verify` | `element` or `content` / checked element id or marker hash / `render` | taken from the given change set |

A custom-code provider that delegates to a typed ability (theme files, Customizer
CSS) returns that ability's change set unchanged.

### Repairing a failed change

1. Keep `change_set_id` from the write's response. A failed write reports it in
   its error (the message and the REST error data carry it, the PHP error data
   holds the whole change set); `stonewright-elementor-post-write-verify` returns
   the whole change set with a failed verification when it is given the write's
   `change_set`.
2. Fix the cause and write again with `repair_of` set to that `change_set_id`.
   Every ability in the table above accepts `repair_of` and `supersedes` as input.
   `supersedes` is for a change that replaces an earlier one that did not fail; it
   is recorded in the lineage and never resolves an incident.
3. When the repair verifies, the incident that the repaired change opened moves to
   `resolved`. The repair's audit row is the resolution event; the incident keeps
   the repair's `change_set_id`, `repair_of`, and `after_hash` as its receipt. A
   repair that does not verify, a dry run, a request that changes nothing, a
   repair of a different resource, and an incident whose rollback failed leave the
   incident open.

An identical retry of a failed change produces the same `change_set_id`; passing it
as `repair_of` records the retry as a repair of itself and closes the incident the
same way.

### Audit trail

Each audit row of a write carries `change_set_id`; a repair's rows also carry
`repair_of` (an indexed column) and `parent_event_id`, the newest audit event of the
change it repairs. Successful rows carry no `incident_id`, including the row that
resolves an incident. The Audit Log page shows each row's change set with a copy
button and, for a change set that has relatives, a **Lineage** drawer: a nested list
in which a repair sits under the change it repairs, for example `A`, failed
verification, repair `B`, verified. Each node links to the rows of its change set.

### Extending the shape

Version 1 is additions-only. Required fields keep their names, types, and meaning.
A new optional field is declared in two places: an entry in
`Stonewright\WpMcp\Security\ChangeSet::EXTENSIONS` (the field's JSON schema fragment
and whether it may be `null`) and the same property, marked `"x-extension": true`
and never listed in `required`, in the published schema. A write supplies the value
under `extensions` when it calls `ChangeSet::build()`; the field is omitted when it
does not apply, and a name that is not declared is dropped. Consumers ignore
properties they do not know.

## Native policy note

DesignSpec and native plan gates reject unresolved semantics and unproven style choices. See [design-evidence-native-planner.md](design-evidence-native-planner.md) and [design-spec.md](design-spec.md). Validators run before render; invalid specs return `stonewright_spec_invalid`.

## Connection verify

Before trusting a long mutation chain:

- **wp-admin:** Stonewright → Setup → **Verify connection** (authenticated MCP loopback: initialize → tools/list → task-start). Stonewright → **Troubleshoot** runs the same class of probes from **Run diagnostics** without reloading the page.
- **CLI:** versioned `stonewright doctor` companion command (Node version, credentials, REST index/namespaces, REST auth, MCP initialize). Never prints Application Passwords.

Public contracts (additions-only compatibility):

- `docs/contracts/public-api-v1.json` — plugin abilities
- `docs/contracts/direct-tools-v1.json` — Direct tools
- `docs/contracts/change-set-v1.schema.json` — the `change_set` every write returns

Regenerate after ability changes: `cd plugin && composer contracts:generate && composer contracts:compat`.
