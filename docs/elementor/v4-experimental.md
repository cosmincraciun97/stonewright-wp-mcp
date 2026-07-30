# Elementor V4 — Experimental Atomic Widget Abilities

> **Status: experimental.** These abilities are feature-flagged and disabled in
> `production-safe` mode. Do not use in production until this page loses the
> experimental notice.

## What is Elementor V4 atomic?

Elementor V4 introduces an "atomic" widget model built around:

- **Style classes** (`e_atomic_classes` kit meta): named CSS classes with selectors and properties.
- **Design variables** (`e_atomic_variables` kit meta): typed tokens (color, font, size, string) referenced in style declarations.
- **Atomic tree** (`_elementor_data` nodes with `atomic_type` markers): V4-style widget nodes that carry explicit style references rather than inline settings.

Stonewright exposes read and write abilities for each of these three surfaces,
plus a `Status` probe and a `RenderFromSpec` ability that converts a DesignSpec
into an atomic tree.

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

| Ability slug | Class | R/W | Description |
|---|---|---|---|
| `stonewright/elementor-v4-status` | `ElementorV4\Status` | Read | Returns V4 availability, atomic flag state, build string, and detected capabilities. Always readable. |
| `stonewright/elementor-v4-list-variables` | `ElementorV4\ListVariables` | Read | Returns all design variables from the active kit. |
| `stonewright/elementor-v4-create-variable` | `ElementorV4\CreateVariable` | Write | Adds a new design variable; snapshots the kit before writing. |
| `stonewright/elementor-v4-update-variable` | `ElementorV4\UpdateVariable` | Write | Patches an existing design variable; snapshots the kit before writing. |
| `stonewright/elementor-v4-list-classes` | `ElementorV4\ListClasses` | Read | Returns all CSS classes from the active kit. |
| `stonewright/elementor-v4-create-class` | `ElementorV4\CreateClass` | Write | Adds a new CSS class; snapshots the kit before writing. |
| `stonewright/elementor-v4-update-class` | `ElementorV4\UpdateClass` | Write | Patches an existing CSS class; snapshots the kit before writing. |
| `stonewright/elementor-v4-read-atomic-tree` | `ElementorV4\ReadAtomicTree` | Read | Returns the atomic-aware subset of `_elementor_data` for a post. |
| `stonewright/elementor-v4-render-from-spec` | `ElementorV4\RenderFromSpec` | Write | Converts a DesignSpec into a V4 atomic tree and persists it. Requires Backup + Validator + token gate. |

## Write envelope for V4 abilities

V4 write abilities follow the same AGENTS.md security rules as V3:

1. `permission_callback` checks `Permissions::edit_theme_options()` (plus the feature flag).
2. `Backup::snapshot_post()` is called before any kit or post mutation.
3. `Validator::validate()` is called before any spec-to-render path (`RenderFromSpec`).
4. `ConfirmationToken` is required in `production-safe` mode for `RenderFromSpec`.

## Current limitations

- V4 atomic tree rendering (`RenderFromSpec`) is a structural scaffold.
  `ElementorV4SpecRenderer` currently emits one `e-flexbox` container per top-level
  spec section. Child blocks are **not yet rendered** — all child nodes produce an
  `unsupported_node` diagnostic instead. In other words:

  - **Currently supported atomic operations:** top-level section → `e-flexbox`
    container (with `classes`, `variables`, and `label` settings).
  - **Not yet supported:** any child block type (headings, text, images, buttons,
    columns, or any other widget). The Elementor V4 atomic widget API had not
    stabilised at the time of implementation; full child-block mapping is deferred to
    a future Stonewright release.

- Style class and variable writes are tested for CRUD correctness (kit meta
  round-trip) but not yet for Elementor runtime compatibility (i.e., whether
  Elementor's editor panel reflects the new classes/variables correctly).

- `ReadAtomicTree` filters `_elementor_data` nodes by the presence of `atomic_type`
  metadata. The filter logic is best-effort — Elementor's internal atomicity
  criteria may diverge from this implementation in future Elementor releases.

- V4 abilities are **blocked in `production-safe` mode** for all write operations.

## Enabling for development

```bash
# 1. Activate Elementor 3.21+ (V4 classes must be present)
wp plugin activate elementor

# 2. Enable the Stonewright V4 flag
wp option update stonewright_elementor_v4_atomic 1

# 3. Verify via the status ability
# (via MCP client or curl to the Stonewright REST endpoint)
```

## Tests

Primary test files:

- `plugin/tests/Integration/ElementorWriterTest.php` — feature-flag gate, CRUD
  round-trip for variables and classes (kit meta in/out), backup assertion before
  each write, `RenderFromSpec` validation rejection path.
- `plugin/tests/Unit/RendererValidationTest.php` — `ElementorV4SpecRenderer`
  validation: rejects invalid specs, passes valid specs through both
  `GutenbergSpecRenderer` and `ElementorV4SpecRenderer`.

Coverage is shallow at the renderer level — the `e-flexbox` placeholder path is
exercised but child-block rendering has no tests because it is not yet implemented.
Expansion is planned for a future Stonewright release.
