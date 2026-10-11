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

Connect only Stonewright to the AI client. Stonewright runs the certified Elementor
abilities itself, with snapshots, readback, audit, and rollback; a call on Elementor's
own MCP server has none of them. See [Elementor MCP with Stonewright](elementor-mcp.md).

### What the module needs

`native_elementor.mcp_module` reports the first three as `requirements` (each met or
missing, with the names of the missing ones in `missing`), the site switch as
`site_exposure_enabled` and the Atomic Editor as `atomic_editor_active`:

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
`elementor.status.native_elementor`, plus one short `native_hint`. The report lists the Elementor version, the
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

1. **Gates.** The permission for the family (`edit_theme_options` for default
   styles, edit access to the post for a composition or a structure read; Elementor's
   own ability checks its capability again when it runs). The tool is available when
   the native Elementor state is `available` and at least one ability is certified;
   otherwise the call fails with `stonewright_native_unavailable`, the state, the
   issue codes (for example `elementor_version_out_of_range`) and the Stonewright
   writers to use instead. The experimental V4 flag is not part of this gate. Then,
   for a real write in `production-safe` mode, a confirmation token bound to the
   arguments (`stonewright/security-issue-confirmation-token`), the same rule the
   other writers follow. Reads and dry-run plans need no token in any mode.
2. **Route.** The ability must be certified for the live Elementor version and its
   contract must allow a native write; otherwise the call fails with
   `stonewright_native_route_refused`, the exact reason or issues, the missing
   feature (for example `elementor_mcp_site_exposure`), and the Stonewright
   writers to use instead. A contract that requires the Atomic Editor fails with
   `stonewright_native_atomic_editor_inactive` while that experiment is off.
   Documents route per subtree and are never converted:
   a V3 document or a V3 subtree of a mixed document routes to the V3 writers, an
   insert at the root of a mixed document and a missing parent are refused, and an
   Atomic subtree or an empty or V4 document routes native.
3. **Exposure.** Every Atomic type in the composition must be registered on the
   site; a missing one fails with `stonewright_atomic_type_unavailable`,
   `missing_feature: atomic_type:<type>`, and the types the site has.
4. **Snapshot and lock.** `Backup::snapshot_post()` of the page (or the active kit;
   a kit snapshot of a default-styles write also takes the default styles through
   Elementor's repository, because they are posts of their own) and the per-post
   write lock, released afterwards.
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
   default-styles write names its kit snapshot as the rollback recipe when that
   snapshot holds the default styles (the change history marks the same row
   restorable); without them it names none, and its automatic rollback happens
   inside the call.

Where the tool is listed: on the essential surface and right after provider
discovery in the `elementor-design` profile when native Elementor is available.
The agent instructions say to prefer it for a V4 (Atomic) document and to fall back
to the Stonewright V4 writers when it refuses; V3 stays on the V3 writers, and
`elementor/*` tools are never called on Elementor's own MCP server.

### Calls made outside the tool

A call to an `elementor/*` write ability that does not come through
`stonewright/elementor-native-execute` (for example through Elementor's own MCP
server) cannot be gated, but it is observed. Stonewright hooks core's
`wp_before_execute_ability` (name, input, ability) and `wp_after_execute_ability`
(name, input, result, ability); core fires the first after the input and permission
checks and the second only when the call succeeded. Before the call, the post the
input names (or the active kit for default styles, classes and variables) is
snapshotted, into a pool of its own (the newest three per post) that never pushes a
real backup out; the snapshot is dropped when the call failed or changed nothing.
The change it looks for is a change of the post's image, which for the active kit
holds the global classes and the default styles as Elementor's repositories return
them, and the global variables. After the call, a change-history row (family `elementor`, status "Not
verified", with the content before so it can be undone) is written when the
document changed, and an audit row marked `observed_native` is always written. The
observer never blocks, refuses or rewrites the call, opens no rescue frame, and a
failure of its own recording is logged only. A page the call created is recorded
from `post_id` in the result. A change staged in an autosave leaves the live
document unchanged, so it has an audit row (outcome `staged_in_autosave`, not
`unchanged`) and no history row.

Statuses are `planned`, `applied`, `unchanged`, `staged_in_autosave`, and `read`.
The structure read returns Elementor's structure and flags a pending autosave,
because that read shows the published document.

### Readback for the Stonewright V4 writers

`stonewright/elementor-v4-update-node` and `stonewright/elementor-v4-render-from-spec`
compare the stored document with the tree they wrote, nested content included,
and restore the snapshot on a mismatch.
`render-from-spec` takes the page write lease before it reads the page (for an
append) and keeps it through the snapshot, the write and the readback; a busy page
answers `stonewright_elementor_write_busy` before anything is stored. The class adapters compare the stored
class, variants and props included, with what was written and put the previous
class back (or delete the new one) on a mismatch; the variable adapter checks the
label, type, and value and, on a mismatch, deletes the new variable or puts the
previous label, type and value back, and says in `rollback_status` whether that was
confirmed. The snapshot the four class and variable writers return covers the
variables (the kit meta `_elementor_global_variables`) and the global classes. From
Elementor 4.3 a class is an `e_global_class` post, so the snapshot of a class write
holds the items, order and labels that Elementor's `Global_Classes_Repository`
returns for the frontend and preview contexts, and a restore writes them back with
`put()` and reads them back; the legacy kit meta key `_elementor_global_classes` is
only the fallback where that repository does not exist. `change-restore` and the
Changes page bring a class or variable change back. Each result carries `readback` or fails with
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
4. Takes the page write lock, reads the page again and refuses with
   `elementor_revision_conflict` when it changed since the plan, then snapshots with
   `Backup::snapshot_post` and writes through `ElementorData::write` **without**
   `skip_integrity`.
5. Supports `dry_run:true` to return the planned settings without writing.
   `dry_run` defaults to **false**: a call without it writes.

Prefer `dry_run` first. Use class/variable abilities for kit-level styles; do
not use this ability for tree restructure, elType/widgetType remaps, or full
styles-map editing.

## Batch mode: reusing a section

`stonewright/elementor-v4-update-node` also takes `operations` instead of `element_id` and `settings`: a list of up to 50 `insert_section` and `update_node` operations applied to the document in memory. One dry run and one apply cover the whole list: one snapshot, one write lock, one write, and the recursive nested readback of the whole document, with the snapshot restored on a mismatch.

- `insert_section` copies a `SectionPortableV1` payload from `stonewright/section-reuse-extract` under `parent_id` (or `parent_ref`, or the document root). Every element gets a fresh id; every local style id becomes `e-<element id>-<suffix>` and every class list follows; a global class or variable that does not exist fails with that exact reference; an Atomic type the site does not have fails with `missing_feature: atomic_type:<type>`. Later operations address the new nodes as `@<op_id>.<placeholder>`.
- `update_node` patches one node with the validation of the single-node mode, a node of an earlier insert included.
- The route follows `NativeRoute::for_document`: the document root of a V4 or empty document and any Atomic container are accepted; a V3 document or V3 parent fails with `v4_architecture_mismatch`; the root of a mixed document is refused (`native_route_refused`). Elementor's native composition takes a composition description, not a raw Atomic tree, so the copy always runs on this writer. The response's `route` gives `stonewright_v4_fallback`, the reason, and the document route.
- After a verified write, the batch and the single-node mode alike, Elementor is asked to drop the cached local styles of the page, so the styles are built again on the next view.
- V4 writes stay blocked in `production-safe`; a dry run is allowed there.
- Text props (`title` of `e-heading`, `paragraph` of `e-paragraph`, `text` of `e-button`) are written with the type the live widget declares in its props schema: `escaped-html` (a plain string) on current Elementor, `html-v3` and `html-v2` (objects) or `html` on others. A write of another type, or of a value that is not the shape of its type, is refused with `invalid_settings_envelope_type` or `invalid_settings_envelope_value`; a copied element that still stores its text in another type than the live one is refused with `stonewright_atomic_text_not_renderable` until `update_node` rewrites it in the same batch, and the readback restores the snapshot rather than report verified for text that would render empty. Only without a live schema (older Elementor, no runtime) does the bundled `html-v3` map apply.
- A repeated `_cssid` of a copy is renamed `name-2`, `name-3` with the `#name` links of the copy, and a repeated `op_id` or a batch that adds more elements than the element cap is refused.
- Custom CSS in a copied style needs the custom-code approval; the empty `custom_css` key every variant carries is not custom CSS.

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
returns a per-element compatibility/loss report. Apply needs `confirm_migration=true`
(and, once a V4 write is allowed in `production-safe` mode, a `confirmation_token`
bound to the post and the other arguments) and is refused unless every element has a
verified zero-loss mapping. It takes the page write lock, refuses a page that changed
since the plan (`elementor_revision_conflict`), snapshots the page, writes once,
compares the readback hash, and restores the snapshot on a failure or mismatch; the
error carries `rollback_status` (`succeeded` or `failed`) from that restore.
