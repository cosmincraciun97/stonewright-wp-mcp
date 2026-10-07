# Elementor V4 engine

Stonewright treats Elementor V4 Atomic as a separate architecture. It never
rewrites an Atomic payload as Elementor V3 and never turns an unknown node into
a placeholder.

## Schema sources

The repository starts with a small verified core and expands it from the active
Elementor runtime. Runtime discovery reads every registered `e-*` layout and
widget, calls its public `get_props_schema()` API, and stores the compact JSON
schema with an exact fingerprint. Discovery is inventory, not write authority:
third-party schemas remain read-only unless a future Stonewright release ships
their exact provider, version, provenance, and contract in its immutable
authority. Every renderer, validator, and mutator reads the same write-safe
repository, which currently admits only bundled or verified-official schemas
that remain byte-for-contract identical after filters run.

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
- An Atomic type without a trusted, certified schema is rejected before dry-run
  validation or mutation; a typed envelope cannot bypass provider trust.
- Classes use Elementor `Global_Classes_Repository`; variables use
  `Variables_Service`. The obsolete guessed kit keys are not used.
- Every mutation snapshots, validates, writes through the verified runtime API,
  and requires readback.
- Production-safe V4 writes stay disabled while the adapter is experimental.

## Native Elementor abilities

When Elementor's MCP module is active it registers abilities named
`elementor/*`. Stonewright reads them, fingerprints their schemas, and checks
each one against a contract file under
`plugin/data/elementor-native-contracts/`. This is evidence only: Stonewright
does not execute an Elementor ability, and `routable_write` stays `false`.

Stonewright's own V4 writers above remain the Atomic writers. Elementor's MCP
server and Stonewright do not compete: both can be connected to one client. Use
Stonewright when you want snapshots, readback, audit, and rollback; Elementor's
MCP alone is fine for a quick draft.

### What the module needs

`native_elementor.mcp_module` reports each requirement as met or missing:

- the WordPress Abilities API (`wp_register_ability`, part of WordPress core);
- the WordPress MCP Adapter (`WP\MCP\Core\McpAdapter`);
- Elementor's MCP Composer (`Elementor\MCP\Composer\Mcp\Registry`);
- the site switch for Elementor's MCP (the `elementor_mcp_enabled` option, off
  by default): with it off, Elementor registers no abilities;
- the Atomic Editor experiment, which gates execution of most abilities.

Elementor 4.3 ships the adapter and the Composer as bundled packages, so no
separate plugin is required. A standalone copy of either also satisfies the
requirement. `mcp_adapter.provider_id` names the plugin whose copy was loaded.

### Report and state

`native_elementor` appears in `stonewright/site-capabilities`,
`stonewright/elementor-v3-status`, and `stonewright/elementor-provider-discovery`.
`stonewright-task-start` carries only its `state` as
`elementor.status.native_elementor`. The report lists the Elementor version, the
module requirements, the registered `elementor/*` abilities, ownership
(`official`, `third-party`, or `mixed`), one schema fingerprint per ability, and
a certification entry per contract.

`state` is one of `not_installed`, `module_unavailable`,
`requirements_missing`, `exposure_disabled`, `no_abilities_registered`,
`available_uncertified`, or `available`.

### Contracts and certification

Each contract file names one ability and records its provider, runtime class,
annotations, runtime constants, the required capability, known side effects, and
the closure steps a caller must provide. A certifiable contract also lists exact
input, output, and description fingerprints for each verified range of Elementor
versions, and a few named probes that explain which part of a schema changed.

| Ability | Result | Verified Elementor versions |
|---|---|---|
| `elementor/manage-default-styles` | certifiable, write | 4.3.0 to 4.3.4 |
| `elementor/manage-classes` | certifiable, write | 4.3.0 to 4.3.4 |
| `elementor/manage-global-variable` | certifiable, write | 4.3.0 to 4.3.4 |
| `elementor/get-page-structure` | certifiable, read-only | 4.3.0 to 4.3.3 and 4.3.4 (two schema variants) |
| `elementor/manage-elements` | unsupported | `upstream_global_clear_cache`, `staged_in_autosave` |
| `elementor/build-composition` | unsupported | `staged_in_autosave` |

Certification fails closed. An ability is `rejected` when any of these differ
from its contract, and the exact reasons are listed in `issues`:
`runtime_identity_mismatch`, `official_owner_mismatch`, `ownership_unverified`,
`annotations_mismatch`, `runtime_constants_mismatch`, `input_schema_mismatch`,
`output_schema_mismatch`, `ability_semantics_mismatch`,
`elementor_version_out_of_range`, `elementor_version_unverified`, or a named
probe such as `action_contract_mismatch`. An ability with no contract file is
`unsupported` with `not_available_for_certification`; an ability that is not
registered is `unsupported` with `upstream_ability_not_registered`. A contract
file that fails validation is ignored and listed in `contract_errors`.

A contract's version policy is `required` (the Elementor version must be known
and inside a verified range) or `when_observed` (a version that cannot be read is
accepted, one that can be read must be inside the range). A release outside the
verified range stays unsupported until its schemas are verified and the contract
is extended.

### Known side effects

- `manage-classes` and `manage-global-variable` clear the generated CSS cache for
  the whole site after a change. Stonewright's CSS rules allow only post-scoped
  regeneration, so these abilities carry the `css_containment` closure
  requirement.
- `manage-elements` also clears that cache, and `manage-elements` and
  `build-composition` save an edit to a published document into the current
  user's autosave. The ability still reports success, but the change is not live
  until the document is published. Such a result must be reported as
  `staged_in_autosave`, never as applied, and publishing needs explicit user
  intent.
- `get-page-structure` reads the published document, so it does not show a
  change that is staged in an autosave.
- `manage-classes` requires the `elementor_global_classes_update_class`
  capability, and `manage-default-styles` and `manage-global-variable` require
  `manage_options`.

## Surgical node update

`stonewright/elementor-v4-update-node` patches **settings only** on one atomic
node by id (merge or replace). It:

1. Loads the full `_elementor_data` tree via `ElementorData::read` — never the
   lifted `atomic_tree` projection from `elementor-v4-read-atomic-tree`.
2. Rejects pure V3 / empty documents (`v4_architecture_mismatch`) and
   non-atomic targets (`non_atomic_target`).
3. Requires a trusted, certified Atomic schema, then validates patched keys
   against its reverse map. Unknown keys already on a certified node are
   preserved with a warning; new keys still require a `$$type`/`value`
   envelope.
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
