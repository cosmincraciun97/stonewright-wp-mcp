# Elementor transaction envelope

Stonewright applies multi-step Elementor V3 mutations through a **transaction envelope** so agents get snapshot, readback, and optional rollback without hand-rolling recovery.

## Ability

- WordPress ability: `stonewright/elementor-v3-transaction-run`
- MCP tool: `stonewright-elementor-v3-transaction-run`

Related batch path: `stonewright/elementor-v3-batch-mutate` (grouped ops without the full envelope).

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
5. **Rollback** — restore snapshot when the run fails mid-flight (when rollback is enabled for the failure class).

Do not claim absolute transactional ACID guarantees across WP-CLI, object cache, and Elementor CSS regeneration. The envelope makes agent edits **more recoverable**, not a database transaction.

## Agent workflow

1. `stonewright-task-start`
2. `stonewright-elementor-page-digest` (or structure get) on the target post
3. Prefer `stonewright-design-native-plan` + DesignSpec when building from evidence
4. `stonewright-elementor-v3-transaction-run` (or batch-mutate for smaller edits)
5. Re-read digest / frontend; restore from audit/snapshot if needed

## Native policy note

DesignSpec and native plan gates reject unresolved semantics and unproven style choices. See [design-evidence-native-planner.md](design-evidence-native-planner.md) and [design-spec.md](design-spec.md). Validators run before render; invalid specs return `stonewright_spec_invalid`.

## Connection verify

Before trusting a long mutation chain:

- **wp-admin:** Stonewright → Setup → **Verify connection** (authenticated MCP loopback: initialize → tools/list → task-start).
- **CLI:** `npx @stonewright/companion doctor` (Node version, credentials, REST index/namespaces, REST auth, MCP initialize). Never prints Application Passwords.

Public contracts (additions-only compatibility):

- `docs/contracts/public-api-v1.json` — plugin abilities
- `docs/contracts/direct-tools-v1.json` — Direct tools

Regenerate after ability changes: `cd plugin && composer contracts:generate && composer contracts:compat`.
