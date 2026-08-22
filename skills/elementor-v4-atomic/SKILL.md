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

Also check `integrations.elementor_v4` is true (requires Elementor >= 4.0.0).

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

V4 does not yet have a complete typed page or interactions writer. Stop after
dry-run. Never pass rendered Atomic data through WP-CLI, raw REST, PHP meta
writes, V3 abilities, or `update-node` as a substitute. Motion apply is
unsupported unless `stonewright-design-motion-capabilities` proves the official
Document Mutator, Interactions Applier, plain-value resolver, and matching live
schema and a dedicated interactions patch tool is visible.

Class and variable list abilities are safe for discovery. Create/update remain
experimental and do not establish style-system parity until dry-run, native CSS
conversion, impact inventory, separate fingerprints, active-kit snapshot,
readback, editor reopen, and frontend CSS parity are all proven.

## Atomic element concepts

- Atomic elements have `type`, `id`, `settings`, and `elements` (children).
- Variables: referenced as `var(--e-global-color-primary)` in settings values.
- Classes: applied via the `classes` array on an element.
- Breakpoints: responsive overrides live in `settings.__globals__` keyed by
  breakpoint handle (e.g. `tablet`, `mobile`).

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
