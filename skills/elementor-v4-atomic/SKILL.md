---
name: elementor-v4-atomic
description: >
  Stonewright experimental Elementor V4 atomic renderer. Gated behind the
  stonewright_elementor_v4_atomic option. Use V3 on production.
version_constraints: {"elementor": ">=3.31"}
---

# Elementor V4 Atomic

Renders Stonewright Design Specs into Elementor V4 atomic element structures.
This renderer is experimental and ships disabled. Enable it only on staging or
development environments after confirming the V4 renderer class is present in
the build.

## Gate check

Before doing anything, verify the feature is enabled:

```json
{ "ability": "stonewright/site-capabilities", "args": {} }
```

Check `feature_flags.elementor_v4_atomic`. If false or absent, stop. Do not
attempt to enable the flag from this skill; ask the user to toggle it in
wp-options (`stonewright_elementor_v4_atomic = 1`).

Also check `integrations.elementor_v4`. It reports Elementor >= 4.0.0; the
Atomic Widgets module gate itself accepts Elementor 3.31+ builds that ship it.

## Native Elementor status

Elementor can register its own `elementor/*` abilities through its MCP module.
Stonewright reports that state and never calls those abilities from this skill.
Read it from `native_elementor` in `stonewright/site-capabilities`, or from the
one-word `elementor.status.native_elementor` state in `stonewright-task-start`.

| `state` | Meaning |
|---|---|
| `not_installed` | Elementor is not active. |
| `module_unavailable` | This Elementor build has no MCP module. |
| `requirements_missing` | The module needs the WordPress Abilities API, the WordPress MCP Adapter, or Elementor's MCP Composer; `mcp_module.missing` names the absent one. |
| `exposure_disabled` | The requirements are met but the MCP switch in Elementor's settings is off, so no abilities are registered. |
| `no_abilities_registered` | The switch is on and no `elementor/*` ability is registered. |
| `available_uncertified` | Abilities are registered and none matches a Stonewright contract. |
| `available` | At least one registered ability matches its contract. |

`native_elementor.certification` gives each contracted ability one of three
results. `certified` means the live schemas, class, owner, annotations, and
Elementor version all match the shipped contract. `rejected` lists the exact
mismatches in `issues`. `unsupported` carries a machine-readable reason.

| Ability | Result | Native write |
|---|---|---|
| `elementor/manage-default-styles` | certifiable | allowed: site-wide default styles per HTML tag. |
| `elementor/build-composition` | certifiable | allowed: composes an element tree; on a published page it lands in an autosave. |
| `elementor/get-page-structure` | certifiable, read-only | read only; it shows the published document, not a pending autosave. |
| `elementor/manage-classes` | certifiable | refused, `upstream_global_clear_cache`: clears generated CSS site-wide. |
| `elementor/manage-global-variable` | certifiable | refused, `upstream_global_clear_cache`. |
| `elementor/manage-elements` | unsupported | `upstream_global_clear_cache`, `staged_in_autosave`. |

An edit to a published page through `build-composition` is saved into an
autosave and is not live until the page is published. Report `staged_in_autosave`,
never "applied", and never publish unless the user explicitly asks.

## Native first, fallback second

When `native_elementor.certified` lists the ability, write through
`stonewright/elementor-native-execute`: pass `ability` and its certified `input`,
look at the plan (`dry_run` is true by default), then repeat with `dry_run: false`.
It snapshots, locks, executes, reads back the whole nested result, rolls back on a
mismatch, regenerates post-scoped CSS only through
`stonewright/elementor-css-regenerate`, and returns a change set. A dropped child
or an unexpected change is an error, not a success.

- Default styles and element composition route native when certified.
- Global classes and variables never route native: use
  `stonewright/elementor-v4-create-class`, `update-class`, `create-variable`, and
  `update-variable`, which are the Fallback writers for those.
- A V3 document or a V3 subtree of a mixed document stays on the Stonewright V3
  writers; the route names them. A mixed document is routed per subtree and is
  never converted. Do not insert at the root of a mixed document.
- If a route is refused or an Atomic type is missing
  (`stonewright_atomic_type_unavailable`), read the reason and the missing
  feature; use the Fallback writers (`elementor-v4-render-from-spec`,
  `elementor-v4-update-node`, `design-spec-to-elementor-v4`) only when the route
  names them and the V4 flag is on. They stay experimental and blocked in
  `production-safe`.

If the user also has Elementor's own MCP server connected, do not repeat one
change through both. Use Stonewright when you want snapshots, readback, audit,
and rollback; Elementor's MCP alone is fine for a quick draft.
## Dry-run first

`design-spec-to-elementor-v4` defaults to `dry_run: true`. Always call it in
dry-run mode first and inspect the `rendered` output before any page write.

```json
{
  "ability": "stonewright/design-spec-to-elementor-v4",
  "args": {
    "spec": { ...validated spec... },
    "dry_run": true
  }
}
```

Returns `{ "rendered": [...atomic_elements...], "dry_run": true }`.

## Write boundary

The Stonewright V4 renderer does not yet have a complete typed page or
interactions writer. Stop after dry-run, or use the native composition path above
where it is certified. Never pass rendered Atomic data through WP-CLI, raw REST,
PHP meta writes, V3 abilities, or `update-node` as a substitute. Motion apply is
unsupported unless `stonewright-design-motion-capabilities` proves the official
Document Mutator, Interactions Applier, plain-value resolver, and matching live
schema and a dedicated interactions patch tool is visible.

Class and variable list abilities are safe for discovery. Create/update remain
experimental and do not establish style-system parity until dry-run, native CSS
conversion, impact inventory, separate fingerprints, active-kit snapshot,
readback, editor reopen, and frontend CSS parity are all proven.

## Atomic element concepts

- Layout elements use their native `elType` (for example `e-flexbox`); widgets
  use `elType=widget` plus `widgetType=e-*`. Every element carries `id`,
  `settings`, `styles`, and `elements` (children); every prop is a typed
  `{ "$$type", "value" }` envelope.
- Variables: read and written through Elementor's `Variables_Service`.
- Classes: applied through the typed `settings.classes` prop
  (`{ "$$type": "classes", "value": [ids] }`).
- Breakpoints and states: responsive and state overrides live in the element's
  `styles` map, as entries in each style's `variants` list keyed by
  `meta.breakpoint` (e.g. `desktop`, `tablet`, `mobile`) and `meta.state`.
  `settings.__globals__` is a V3 concept and is not used for Atomic
  breakpoints.

## Ability summary

| Ability | Purpose |
|---|---|
| `stonewright/design-spec-to-elementor-v4` | Render spec to V4 atomic JSON |
| `stonewright/design-validate-spec` | Validate spec before render |
| `stonewright/design-build-spec` | Assemble spec |
| `stonewright/site-capabilities` | Check gate + integrations |
| `stonewright/site-backup-page` | Snapshot before an authorized typed write |
| `stonewright/design-motion-capabilities` | Read live V4 interaction schema and write readiness |
| `stonewright/elementor-v4-list-classes` | Read Atomic global classes |
| `stonewright/elementor-v4-list-variables` | Read Atomic variables |
| `stonewright/design-choose-renderer` | Confirm V4 is the chosen renderer |

## When the renderer or writer is missing

`renderer_missing` means the V4 renderer is absent. A missing write adapter is
not permission to fall back silently. Ask before intentionally choosing V3;
otherwise report V4 write as unsupported.

## Do not use on production

V4 atomic is unstable. Never render to a live production page without explicit
user confirmation and a backup snapshot. Present the confirmation token:

```
"Confirm:
  post_id: <id>
  snapshot_id: <id>
  action: write_elementor_v4_atomic
  WARNING: experimental renderer
Reply YES to proceed."
```

See `references/v4-payload-examples.md` for atomic element JSON structures.
