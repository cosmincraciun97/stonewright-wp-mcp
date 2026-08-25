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

## Native policy note

DesignSpec and native plan gates reject unresolved semantics and unproven style choices. See [design-evidence-native-planner.md](design-evidence-native-planner.md) and [design-spec.md](design-spec.md). Validators run before render; invalid specs return `stonewright_spec_invalid`.

## Connection verify

Before trusting a long mutation chain:

- **wp-admin:** Stonewright → Setup → **Verify connection** (authenticated MCP loopback: initialize → tools/list → task-start). Stonewright → **Troubleshoot** runs the same class of probes from **Run diagnostics** without reloading the page.
- **CLI:** versioned `stonewright doctor` companion command (Node version, credentials, REST index/namespaces, REST auth, MCP initialize). Never prints Application Passwords.

Public contracts (additions-only compatibility):

- `docs/contracts/public-api-v1.json` — plugin abilities
- `docs/contracts/direct-tools-v1.json` — Direct tools

Regenerate after ability changes: `cd plugin && composer contracts:generate && composer contracts:compat`.
