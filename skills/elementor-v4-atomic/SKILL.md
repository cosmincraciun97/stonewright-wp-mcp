---
name: elementor-v4-atomic
description: >
  Experimental Elementor V4 atomic renderer. Gated behind the
  stonewright_elementor_v4_atomic option. Use V3 on production.
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

## Write flow (when dry-run output is approved)

V4 does not yet have a dedicated `build-page-from-spec` ability. Use
`stonewright/site-backup-page` before any manual write, then pass the
`rendered` output through the companion Playwright layer or a custom
WP-CLI command. Do not write Elementor V4 data directly via the V3 abilities.

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
| `stonewright/site-backup-page` | Snapshot before any write |
| `stonewright/design-choose-renderer` | Confirm V4 is the chosen renderer |

## When the renderer is missing

`renderer_missing` error means the `ElementorV4SpecRenderer` class is not in
this build. The V4 renderer is conditionally bundled. Fall back to
`design-spec-to-elementor-v3`.

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
