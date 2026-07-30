# Elementor V4 engine

Stonewright treats Elementor V4 Atomic as a separate architecture. It never
rewrites an Atomic payload as Elementor V3 and never turns an unknown node into
a placeholder.

## Schema sources

The repository starts with a small verified core and expands it from the active
Elementor runtime. Runtime discovery reads every registered `e-*` layout and
widget, calls its public `get_props_schema()` API, and stores the compact JSON
schema with an exact fingerprint. Add-ons therefore become available without a
Stonewright release; writes remain blocked until their live schema is present.

The bundled structures are based on Elementor's official documentation for
[Atomic elements](https://developers.elementor.com/docs/data-structure/atomic-elements/index.html),
[Atomic widgets](https://developers.elementor.com/docs/data-structure/atomic-widgets/index.html),
[Atomic styles](https://developers.elementor.com/docs/data-structure/atomic-styles/index.html),
and [Atomic global classes](https://developers.elementor.com/docs/data-structure/atomic-global-classes/index.html).
The runtime source was verified at Elementor commit
`07628d6754fa2fae7c8400191f018c9cd23a36bb`.

## Safety contract

- Layout elements use their native `elType`; Atomic widgets use
  `elType=widget` plus `widgetType=e-*`.
- Every element carries `version`, `isInner`, `settings`, `editor_settings`,
  `interactions`, `styles`, and `elements`.
- Unknown nodes, properties, prop types, malformed styles, duplicate responsive
  variants, and unresolved CTA actions are structured errors.
- Classes use Elementor `Global_Classes_Repository`; variables use
  `Variables_Service`. The obsolete guessed kit keys are not used.
- Every mutation snapshots, validates, writes through the verified runtime API,
  and requires readback.
- Production-safe V4 writes stay disabled while the adapter is experimental.

## Surgical node update

`stonewright/elementor-v4-update-node` patches **settings only** on one atomic
node by id (merge or replace). It:

1. Loads the full `_elementor_data` tree via `ElementorData::read` — never the
   lifted `atomic_tree` projection from `elementor-v4-read-atomic-tree`.
2. Rejects pure V3 / empty documents (`v4_architecture_mismatch`) and
   non-atomic targets (`non_atomic_target`).
3. Validates patched keys against the Atomic schema reverse map (or requires a
   `$$type`/`value` envelope for new keys); unknown keys already on the node are
   preserved with a warning.
4. Snapshots with `Backup::snapshot_post`, then writes through
   `ElementorData::write` **without** `skip_integrity`.
5. Supports `dry_run:true` to return the planned settings without writing.

Prefer `dry_run` first. Use class/variable abilities for kit-level styles; do
not use this ability for tree restructure, elType/widgetType remaps, or full
styles-map editing.

## Fixture status

The fixtures in `plugin/tests/fixtures/elementor-v4` are Stonewright-authored
structural fixtures. Core 4.x source shape and mixed-tree behavior are verified.
Elementor 3.29 Atomic opt-in and licensed Pro editor/frontend parity remain
explicitly marked pending until those controlled-site E2E jobs run. No stable
claim is made from a synthetic fixture.

## Page-resident editor and migration

`@stonewright/visual` exposes a dedicated `ElementorV4EditorAdapter` behind the
single workspace gateway. It discovers `atomic_props_schema` from the active
editor, preserves native V4 payloads, requires `confirm_write=true`, executes
history-aware operations, supports batch refs/rollback, and verifies both the
editor model and preview DOM after mutation. It never calls the V3 adapter as a
fallback.

`stonewright/elementor-v4-migrate` is the only explicit V3-to-V4 path. Dry-run
returns a per-element compatibility/loss report. Apply is refused unless every
element has a verified zero-loss mapping; successful apply snapshots the page,
writes once, compares the readback hash, and restores automatically on mismatch.
