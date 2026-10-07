# Elementor V4 — Experimental Atomic Widget Abilities

> **Status: experimental.** These abilities are feature-flagged and disabled in
> `production-safe` mode. Do not use in production until this page loses the
> experimental notice.

## What is Elementor V4 atomic?

Elementor V4 introduces an "atomic" model built around:

- **Atomic elements and widgets**: layout elements use their native `elType`
  (for example `e-flexbox`); widgets use `elType=widget` plus a `widgetType`
  beginning with `e-` (for example `e-heading`). Every prop is a typed
  `{ "$$type", "value" }` envelope.
- **Global classes**: read and written through Elementor's
  `Global_Classes_Repository` runtime API.
- **Variables**: read and written through Elementor's `Variables_Service`.
  Stonewright never writes guessed kit meta keys.
- **Styles**: responsive and state overrides live in each style's `variants`
  list, keyed by `meta.breakpoint` and `meta.state`.

Stonewright exposes discovery, read, surgical update, class, variable,
migration, and DesignSpec render abilities for these surfaces. The engine
contract is described in [Elementor V4 engine](../elementor-v4-engine.md).

## Feature flag

All write abilities check the `stonewright_elementor_v4_atomic` WordPress option
at runtime. If the option is falsy (the default), write calls return:

```json
{
  "code": "feature_disabled",
  "message": "Elementor V4 atomic features are disabled."
}
```

The `Status` ability is intentionally **not** gated — clients can always discover
whether V4 is available before attempting a write.

Enable the flag (development only):

```bash
wp option update stonewright_elementor_v4_atomic 1
```

## Abilities

The authoritative gate columns live in the generated
[ability truth matrix](../ability-truth-matrix.md#elementor-v4-experimental).

| Ability slug | R/W | Status | Description |
|---|---|---|---|
| `stonewright/elementor-v4-status` | Read | experimental | V4 availability, atomic flag state, build string, and detected capabilities. Always readable. |
| `stonewright/elementor-v4-list-atomic-node-types` | Read | stable | DesignSpec node types the certified Atomic schema repository can render. |
| `stonewright/elementor-v4-describe-atomic-widget` | Read | stable | Live props schema for one Atomic widget. |
| `stonewright/elementor-v4-read-atomic-tree` | Read | experimental | Compact outline (or full tree) of the Atomic elements in a post. |
| `stonewright/elementor-v4-update-node` | Write | experimental | Fallback. Settings-only patch of one Atomic node by id, validated against its certified schema. |
| `stonewright/elementor-v4-list-variables` | Read | experimental | Variables through `Variables_Service`. |
| `stonewright/elementor-v4-create-variable` | Write | experimental | Fallback. Creates a variable through `Variables_Service` and verifies readback. |
| `stonewright/elementor-v4-update-variable` | Write | experimental | Fallback. Updates a variable through `Variables_Service`. |
| `stonewright/elementor-v4-list-classes` | Read | experimental | Global classes through `Global_Classes_Repository`. |
| `stonewright/elementor-v4-create-class` | Write | experimental | Fallback. Creates a global class. |
| `stonewright/elementor-v4-update-class` | Write | experimental | Fallback. Updates a global class. |
| `stonewright/elementor-v4-migrate` | Write | experimental | Explicit V3-to-V4 migration with a per-element loss report; never implicit. |
| `stonewright/elementor-v4-render-from-spec` | Write | experimental | Fallback. Renders a validated DesignSpec into an Atomic tree; `dry_run` defaults to true. |
| `stonewright/elementor-v4-atomic-widget-define` | Read | sandboxed | Sandboxed Atomic widget definition. |
| `stonewright/elementor-native-execute` | Write | experimental | Runs a certified Elementor ability (default styles, element composition, structure read) inside the snapshot, lock, readback, rollback, and audit closure; `dry_run` defaults to true. |

## Write envelope for V4 abilities

V4 write abilities follow the same AGENTS.md security rules as V3, and every V4
write reads the stored result back and compares nested content: a dropped child is
an error and the snapshot is restored.

1. `permission_callback` checks the feature flag, then `Permissions::edit_theme_options()`
   for class and variable writes or `Permissions::edit_post()` for post writes.
2. `Backup::snapshot_post()` is called before any kit or post mutation.
3. `Validator::validate()` is called before any spec-to-render path (`RenderFromSpec`).
4. `ConfirmationToken` is required in `production-safe` mode for `RenderFromSpec`.

## Current limitations

- `RenderFromSpec` and `design-spec-to-elementor-v4` render sections and the
  DesignSpec block types `heading`, `paragraph`, `image`, `button`,
  `separator`, `icon`, `row`, and `column` through `AtomicRenderer`. A block
  type without a trusted, certified Atomic schema is a structured error; a
  partial tree is never returned as success.
- Atomic types discovered at runtime from third-party plugins are inventory
  only. They stay read-only until Stonewright certifies their provider,
  version, provenance, and contract.
- Licensed Elementor Pro editor and frontend parity is not yet proven by
  controlled-site E2E runs.
- Elementor's own registered abilities (`elementor/*`) are discovered,
  fingerprinted, and certified against shipped contracts by the provider router.
  Only certified abilities whose contract allows a native write run, through
  `stonewright/elementor-native-execute`: default styles and element composition.
  Writes that clear generated CSS site-wide (global classes, global variables)
  are refused with `upstream_global_clear_cache`; the Fallback abilities above stay
  the supported path for them. See
  [Native execution](../elementor-v4-engine.md#native-execution) for the closure,
  the routing, and `staged_in_autosave`.
- V4 abilities are **blocked in `production-safe` mode** for all write
  operations.

## Using Elementor's own MCP server alongside

Stonewright does not disable, replace, or compete with Elementor's MCP server,
and both can be connected to the same client. Use Stonewright when you want
snapshots, readback, audit, and rollback. Elementor's MCP alone is fine for a
quick draft that you will review in the editor. Do not send one change through
both servers: Stonewright's backup and readback only cover what it writes. When
Stonewright runs an Elementor ability itself, through
`stonewright/elementor-native-execute`, the closure covers it.

## Enabling for development

```bash
# 1. Activate an Elementor build that ships the Atomic Widgets module (3.31+)
wp plugin activate elementor

# 2. Enable the Stonewright V4 flag
wp option update stonewright_elementor_v4_atomic 1

# 3. Verify via the status ability from your MCP client
```

## Tests

Primary test files:

- `plugin/tests/Integration/ElementorWriterTest.php`: feature-flag gate,
  class and variable round-trips, backup assertion before each write, and the
  `RenderFromSpec` validation rejection path.
- `plugin/tests/Unit/ElementorV4/RenderFromSpecTest.php`: a failed backup
  snapshot aborts before any Elementor write.
- `plugin/tests/Unit/Elementor/V4/AtomicRendererTest.php`: typed-envelope
  rendering for each supported block type, nested children, and structured
  errors for unknown or uncertified types.
- `plugin/tests/Unit/RendererValidationTest.php`: invalid specs are rejected
  by both `GutenbergSpecRenderer` and `ElementorV4SpecRenderer`.
