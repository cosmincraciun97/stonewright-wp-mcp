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
`plugin/data/elementor-native-contracts/`. Discovery and certification are read
only and never run an Elementor ability. A certified ability whose contract
allows a native write is executed only by `stonewright/elementor-native-execute`
(see Native execution below); the Stonewright V4 writers above stay as the
fallback.

Elementor's MCP server and Stonewright do not compete: both can be connected to
one client. Use Stonewright when you want snapshots, readback, audit, and
rollback; Elementor's MCP alone is fine for a quick draft.

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

| Ability | Result | Native write | Verified Elementor versions |
|---|---|---|---|
| `elementor/manage-default-styles` | certifiable | allowed | 4.3.x (verified 4.3.0 to 4.3.4) |
| `elementor/build-composition` | certifiable | allowed | 4.3.x (verified 4.3.0 to 4.3.4) |
| `elementor/get-page-structure` | certifiable, read-only | read only | 4.3.x (two schema variants) |
| `elementor/manage-classes` | certifiable | refused: `upstream_global_clear_cache` | 4.3.x |
| `elementor/manage-global-variable` | certifiable | refused: `upstream_global_clear_cache` | 4.3.x |
| `elementor/manage-elements` | unsupported | `upstream_global_clear_cache`, `staged_in_autosave` | none |

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

Every contract pins the Elementor version (`version_policy: required`): the
version must be readable and inside the contract's range. A range ending in
`.*` covers a whole minor line, so a patch release inside it certifies when every
fingerprint matches exactly; a new minor line is unsupported until its schemas are
verified and the contract is extended. A contract may mark the description text
as ignored when only the schemas matter. A contract that embeds a certified input
schema is ignored unless the schema hashes to the contract's fingerprint.

A contract also records `routing`: its family (`kit_defaults`,
`tree_composition`, `structure_read`, or `global_kit`) and whether a native write
is `allowed`, `refused` (with a reason), or `read_only`. A contract that lists the
`global_css_cache_clear` side effect can never allow a native write.

### Known side effects

- `manage-classes` and `manage-global-variable` clear the generated CSS cache for
  the whole site after a change, and `manage-elements` does too. Stonewright's CSS
  rules allow only post-scoped regeneration through
  `stonewright/elementor-css-regenerate`, and Elementor offers no way to run these
  abilities without the site-wide clear, so their native writes are refused with
  `upstream_global_clear_cache`. Stonewright's own class and variable writers
  remain the supported path.
- `manage-elements` and `build-composition` save an edit to a published or
  private document into the current user's autosave. The ability still reports
  success, but the change is not live until the document is published. Such a
  result is reported as `staged_in_autosave`, never as applied, and publishing
  needs explicit user intent. A later composition builds on the pending autosave,
  not on the live document.
- `get-page-structure` reads the published document, so it does not show a
  change that is staged in an autosave.
- `manage-classes` requires the `elementor_global_classes_update_class`
  capability, and `manage-default-styles` and `manage-global-variable` require
  `manage_options`.

## Native execution

`stonewright/elementor-native-execute` runs a certified Elementor ability
in-process through `wp_get_ability()->execute()`. The ability enum offers only
`elementor/manage-default-styles`, `elementor/build-composition`, and
`elementor/get-page-structure`, and `input` carries each ability's certified input
schema (one `anyOf` branch each, within the router's schema limits) so an agent
does not guess fields. Writes plan first: `dry_run` defaults to `true`.

A write runs in this order and stops at the first failure:

1. **Route.** The ability must be certified for the live Elementor version and its
   contract must allow a native write; otherwise the call fails with
   `stonewright_native_route_refused`, the exact reason or issues, the missing
   feature (for example `elementor_mcp_site_exposure`), and the Stonewright
   writers to use instead. Documents route per subtree and are never converted:
   a V3 document or a V3 subtree of a mixed document routes to the V3 writers, an
   insert at the root of a mixed document and a missing parent are refused, and an
   Atomic subtree or an empty or V4 document routes native.
2. **Gates.** The experimental V4 flag, the Atomic Editor requirement, the
   production-safe block, the permission for the family (kit options, or the post),
   and the production-safe confirmation token. Native writes stay blocked in
   `production-safe` mode like the other V4 writers.
3. **Exposure.** Every Atomic type in the composition must be registered on the
   site; a missing one fails with `stonewright_atomic_type_unavailable`,
   `missing_feature: atomic_type:<type>`, and the types the site has.
4. **Snapshot and lock.** `Backup::snapshot_post()` of the page (or the active kit)
   and the per-post write lock, released afterwards.
5. **Execute** the Elementor ability.
6. **Independent readback.** Default styles are read back from Elementor's
   repository; a composition is read back from `_elementor_data` (or from the
   autosave for a staged edit) and compared recursively with the structure the
   ability reports: every node, its type, its position under its parent, and its
   order. A dropped child, a retyped or moved node, an unplanned change to another
   element, or a live document that changed during a staged write is an error, never
   a success.
7. **Rollback** on any failure or mismatch: the snapshot restores the page, the
   previous autosave is put back (or removed), and the previous default styles are
   rewritten. The result reports `rollback_status`.
8. **CSS.** For an applied page edit, post-scoped CSS goes only through
   `stonewright/elementor-css-regenerate`; a failure is reported with the next step
   and does not undo the write. Default styles use Elementor's scoped style cache and
   need no page CSS. A staged edit changes no live CSS.
9. **ChangeSetV1 and audit.** The result carries `change_set`: planned and applied
   changes, before and after hashes, the verification outcome, and `repair_of` and
   `supersedes` lineage. A staged edit is `queued` with nothing applied. A
   default-styles write reports no rollback recipe, because a kit snapshot does not
   cover the default style posts; its automatic rollback happens inside the call.

Statuses are `planned`, `applied`, `unchanged`, `staged_in_autosave`, and `read`.
The structure read returns Elementor's structure and flags a pending autosave,
because that read shows the published document.

### Readback for the Stonewright V4 writers

`stonewright/elementor-v4-update-node` and `stonewright/elementor-v4-render-from-spec`
compare the stored document with the tree they wrote, nested content included,
and restore the snapshot on a mismatch. The class adapters compare the stored
class, variants and props included, with what was written and put the previous
class back (or delete the new one) on a mismatch; the variable adapter checks the
label, type, and value. Each result carries `readback` or fails with
`stonewright_atomic_readback_mismatch` and its `problems`.

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
