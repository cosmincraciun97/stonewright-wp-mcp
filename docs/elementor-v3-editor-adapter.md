# Elementor V3 editor adapter and write compiler

Stonewright has two strict V3 write paths that share the same rules:

- the page-resident Visual adapter mutates the active editor through Elementor's
  command bus;
- the WordPress ability compiler mutates `_elementor_data` with a snapshot,
  optimistic hash, one write, and immediate readback.

Neither path writes unknown widget or structural controls. A missing runtime
schema is an error.

## Visual nested tools

`ElementorV3EditorAdapter` is exposed only behind
`stonewright-workspace-request`; its nested tools never become top-level MCP
tools:

- discovery: `list_widgets`, `get_widget_schema`;
- read: `get_page_structure`, `get_element`, `get_evidence_ledger`;
- write: `create_element`, `update_settings`, `move_element`,
  `delete_element`;
- history/persistence: `undo`, `redo`, `save`;
- orchestration: inherited `batch_call` with refs and rollback.

The browser runtime uses `$e.run()` commands after checking the live command
registry. It does not click editor UI, patch DOM, or rewrite post meta. The
verified commands are:

```text
document/elements/create
document/elements/settings
document/elements/move
document/elements/delete
document/history/undo
document/history/redo
document/save/default
```

Elementor documents the Commands API and live `$e.commands.getAll()` discovery
at <https://developers.elementor.com/js-api/js-api-commands/>. Runtime argument
shapes were checked against Elementor's GPL source at commit
`07628d6754fa2fae7c8400191f018c9cd23a36bb` (tag description
`4.3.0-latest-1784038745`), including its own `elementor-v3-mcp` package. No
Elementor source was copied into Stonewright.

## Live schema and evidence gate

Before an editor mutation, Stonewright:

1. reads `widgetsCache` from the active editor, including third-party widgets;
2. rejects unknown control names, invalid options/shapes, invalid repeaters,
   inactive conditions, and unsupported responsive suffixes;
3. blocks CTA widgets that do not resolve a URL/action through a live control;
4. computes a deterministic SHA-256 of the live control schema;
5. requires evidence for every planned setting;
6. writes through the command bus;
7. reads the editor model back and compares the requested result.

Each evidence row contains:

```text
control_key, schema_hash, source, confidence,
responsive_scope, requires_confirmation

`create_element` and `update_settings` accept `allowed_breakpoints`. The
production adapter always resolves an authorized scope from that list or the
per-setting evidence, validates it before writing, and verifies that
non-target breakpoint hashes remain unchanged after updates.
```

Only successful mutations enter the page-session evidence ledger.

The WordPress compiler resolves container, section, and column controls from
Elementor's live `elements_manager`. The bundled structural fixture is used
only when Elementor is not booted (tests/offline compilation); it is never a
silent fallback on an active Elementor site. Structural settings pass the same
unknown-key, responsive, shape, schema-hash, and final-tree gates as widgets.

## Batch transaction rules

`batch_call` resolves `$alias` references from create results. On the first
failure it undoes every completed mutation back to the transaction's history
position, then reads the page tree again. `undo`, `redo`, and `save` are not
allowed inside a batch because persistence/history control cannot be rolled
back as a normal element mutation. Call `save` only after a successful batch.

## WordPress write compiler

`stonewright/elementor-v3-batch-mutate` now accepts:

- `expected_tree_hash` for optimistic concurrency;
- `idempotency_key` for 24-hour replay protection;
- `require_evidence=true` and per-operation `settings_evidence`;
- the existing `dry_run`, refs, confirmation, and stop-on-error controls.

A non-dry write follows this sequence:

```text
permission/context/confirmation
  -> idempotency lookup
  -> read + before_hash conflict check
  -> compile all operations in memory
  -> live schema + evidence validation
  -> one backup
  -> one write
  -> readback_hash comparison
  -> restore snapshot on mismatch
  -> idempotency record
```

The response includes `before_hash`, `after_hash`, and `readback_hash`.
Success requires `after_hash === readback_hash`. Replaying an identical key
returns `idempotent_replay=true`; changing input under the same key returns
HTTP-style conflict data and performs no write.

## Compatibility abilities

The generated `stonewright/elementor-add-*` abilities remain available only in
the explicit full/compatibility surface during the two-release warning window.
Their metadata points to `stonewright/elementor-v3-batch-mutate`; the generated
[migration table](migration-elementor-v3-tools.md) lists every exact mapping.
The earliest removal release is alpha.67. New skills and recommendations use
the live schema plus batch compiler.

## Verification coverage

Automated tests cover live third-party widget discovery, compact schemas,
unknown-setting rejection, responsive settings, CTA links, evidence hashes,
idempotent replay/conflict, create refs, transactional rollback, move/delete
readback, undo/redo/save, command serialization, stale-tree conflicts, one
snapshot/write, and post-write tree hashing.
