# Gutenberg Visual Adapter

Stonewright Visual exposes Gutenberg through nested page tools under the single
`stonewright-workspace-request` MCP tool. It does not add Gutenberg tools to the
top-level MCP surface.

The adapter reads the active `core/block-editor` and `core/editor` data stores.
It discovers every registered core or third-party block from the live WordPress
block registry, rejects unknown attributes, and performs insert, update, move,
delete, undo, redo, save, and serialization through native editor APIs.

All writes require `confirm_write=true`, use an idempotency key, and perform
immediate editor-store readback. `batch_call` resolves references between calls
and rolls the editor history back when a call fails. Saving must clear the
editor dirty flag before the operation is reported as successful.

The adapter is intentionally separate from the PHP Gutenberg renderer. The PHP
abilities remain the server-side path for content automation; Visual owns only
the already-open editor session, so persistence is never performed twice.

For server-side content batches, use `stonewright/blocks-batch-mutate`. Dynamic
blocks (`save: null` / `render_callback`) still serialize in PHP. Static and
third-party blocks are queued as `{name, attributes, innerBlocks}` for the hidden
`stonewright-block-finalizer` admin page, which runs the live editor `save()` and
posts hashed HTML back. Persist stays in `stonewright/blocks-finalize-batch`
(snapshot, confirmation in production-safe, audit, readback). List/status tools
never return the full spec. See [Permanent remediation contracts](permanent-remediation-contracts.md).
