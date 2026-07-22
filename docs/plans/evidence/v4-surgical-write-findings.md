# Task 9.1 — V4 surgical write investigation findings

**Branch:** `feature/elementor-integrity-gate-p0`
**Date:** 2026-07-22
**Scope:** Investigation only. No production ability code. No `UpdateNode` implementation.

---

## Executive summary

**Yes — surgical V4 node update can proceed safely for Task 9.2**, with a narrow scope.

| Gate / surface | V4-compatible? | Notes |
|---|---|---|
| `DocumentIntegrityGate::assert_write_allowed()` | **Yes (V4→V4)** | Architecture-agnostic: list root, ids, elTypes, size-collapse, widgetType remap. No V3-only assumptions. |
| `SettingsValidator::validate_tree()` | **Yes (structure-only for atomic)** | `e-*` widgets skip V3 control schema. Layout `e-div-block` / `e-flexbox` / `e-grid` get id/elType/children checks only. |
| `ElementorData::write()` | **Yes** | Runs both gates; **do not** use `skip_integrity`. Double-encode + readback remain. |
| `elementor-v3-update-element` / batch-mutate | **No** | V3 abilities reject v4/mixed architecture or fail V3 schema validation on `e-*`. |
| `AtomicSchemaRepository` | **Describe/compile, not tree-write** | Prop catalog + fingerprint; **no** `validate_settings()` / `validate_tree()`. 9.2 must add a small atomic settings check. |

**Critical constraint:** `AtomicTreeInspector::inspect()` returns a **lifted projection** of atomic nodes (non-atomic parents stripped; non-atomic siblings under atomic parents dropped from the projection). **Never write `atomic_tree` back.** Surgical update must load the full document via `ElementorData::read()`, resolve by id with `find_path` / `set`, then `write()`.

**Recommendation:** Implement `stonewright/elementor-v4-update-node` mirroring `ElementorV3/UpdateElement.php` (resolve → atomic-validate → snapshot → `ElementorData::write()` → surface write errors). No integrity bypass. Defer tree restructure, full styles editor, and runtime JSON-schema deep validation.

---

## Q1. What does `AtomicTreeInspector::inspect()` return?

### Return shape

Source: [`plugin/includes/Elementor/V4/AtomicTreeInspector.php`](../../../plugin/includes/Elementor/V4/AtomicTreeInspector.php)

```33:41:plugin/includes/Elementor/V4/AtomicTreeInspector.php
		return [
			'atomic_tree'         => $atomic_tree,
			'atomic_count'        => $stats['atomic'],
			'non_atomic_count'    => $stats['v3'],
			'unknown_atomic'      => $stats['unknown_atomic'],
			'architecture'        => $architecture,
			'schema_fingerprint'  => AtomicSchemaRepository::fingerprint(),
			'implicit_conversion' => false,
		];
```

| Field | Meaning |
|---|---|
| `architecture` | `empty` \| `v3` \| `v4` \| `mixed` from atomic vs non-atomic counts (L24–31) |
| `atomic_tree` | List of atomic nodes only (lifted); not the full `_elementor_data` root |
| `atomic_count` / `non_atomic_count` | Inventory stats |
| `unknown_atomic` | `{ path, atomic_type, action: 'refresh_live_schema' }` when type missing from schema repo (L72–77) |
| `schema_fingerprint` | SHA-256 of `AtomicSchemaRepository::all()` |
| `implicit_conversion` | Always `false` |

### Atomic detection (id scheme / type scheme)

```50:55:plugin/includes/Elementor/V4/AtomicTreeInspector.php
	private static function inspect_node( array $element, array $path, array &$stats ): array {
		$el_type     = (string) ( $element['elType'] ?? '' );
		$widget_type = (string) ( $element['widgetType'] ?? '' );
		$atomic_type = 'widget' === $el_type ? $widget_type : $el_type;
		$is_atomic   = str_starts_with( $atomic_type, 'e-' );
```

- **Atomic layout:** `elType` is `e-div-block` \| `e-flexbox` \| `e-grid` (no `widgetType`).
- **Atomic widget:** `elType=widget` + `widgetType` starts with `e-` (e.g. `e-heading`, `e-paragraph`).
- **Ids:** opaque strings. Fixtures use `a000001`, `atomic1`, `legacy1`. Runtime generation: `ElementorData::generate_id()` → 7-char hex from `md5(uniqid)` (`ElementorData.php` L191–193). Integrity only requires non-empty unique ids.

### Node shape (fixtures + renderer)

**Pure V4 layout fixture** `plugin/tests/fixtures/elementor-v4/atomic-layout-0.4.json` (page envelope; tree under `content`):

```6:15:plugin/tests/fixtures/elementor-v4/atomic-layout-0.4.json
  "content": [{
    "id": "a000001",
    "version": "0.0",
    "elType": "e-div-block",
    "isInner": false,
    "settings": [],
    "editor_settings": [],
    "interactions": [],
    "styles": [],
    "elements": [{
```

**Mixed tree fixture** `plugin/tests/fixtures/elementor-v4/mixed-v3-v4.json` (document-root list as stored in `_elementor_data`):

```1:3:plugin/tests/fixtures/elementor-v4/mixed-v3-v4.json
[
  {"id": "legacy1", "elType": "container", "settings": {}, "elements": [{"id": "atomic1", "version": "0.0", "elType": "widget", "widgetType": "e-heading", "isInner": false, "settings": {}, "editor_settings": [], "interactions": [], "styles": [], "elements": []}]},
  {"id": "atomic2", "version": "0.0", "elType": "e-flexbox", "isInner": false, "settings": [], "editor_settings": [], "interactions": [], "styles": [], "elements": []}
]
```

**Rendered atomic widget settings model** (typed envelopes), from tests:

```22:28:plugin/tests/Unit/Elementor/V4/AtomicRendererTest.php
		$this->assertSame( 'widget', $out['elType'] );
		$this->assertSame( 'e-heading', $out['widgetType'] );
		$this->assertNotEmpty( $out['id'] );
		$this->assertSame( 'html-v3', $out['settings']['title']['$$type'] );
		$this->assertSame( 'Hello', $out['settings']['title']['value']['content']['value'] );
		$this->assertSame( 'string', $out['settings']['tag']['$$type'] );
		$this->assertSame( 'h2', $out['settings']['tag']['value'] );
```

Canonical node fields from `AtomicRenderer::render_node()` (L111–125):

```111:125:plugin/includes/Elementor/V4/AtomicRenderer.php
		$out         = [
			'id'              => $id,
			'version'         => (string) $schema['version'],
			'elType'          => 'layout' === $schema['kind'] ? $atomic_type : 'widget',
			'isInner'         => (bool) ( $node['is_inner'] ?? false ),
			'settings'        => [] === $settings ? [] : $settings,
			'editor_settings' => [] === $editor_settings ? [] : $editor_settings,
			'interactions'    => $interactions,
			'styles'          => $styles,
			'elements'        => $children,
		];
		if ( 'widget' === $schema['kind'] ) {
			$out['widgetType'] = $atomic_type;
		}
```

### Mixed-tree lift behavior (write-back hazard)

```66:80:plugin/includes/Elementor/V4/AtomicTreeInspector.php
		if ( ! $is_atomic ) {
			++$stats['v3'];
			return $atomic_children;
		}

		++$stats['atomic'];
		// ...
		$element['elements'] = $atomic_children;
		return [ $element ];
```

Evidence in tests:

```24:31:plugin/tests/Unit/Elementor/V4/AtomicFoundationTest.php
	public function test_mixed_tree_inventory_keeps_atomic_descendants_without_conversion(): void {
		$tree = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/elementor-v4/mixed-v3-v4.json' ), true );
		$result = AtomicTreeInspector::inspect( $tree );
		$this->assertSame( 'mixed', $result['architecture'] );
		$this->assertSame( 2, $result['atomic_count'] );
		$this->assertSame( 1, $result['non_atomic_count'] );
		$this->assertCount( 2, $result['atomic_tree'] );
```

`legacy1` is counted as non-atomic and **not** present in `atomic_tree`; `atomic1` is lifted to the root of the projection. Confirmed in `ReadAtomicTreeTest` L93–95.

### Ability surface today

`stonewright/elementor-v4-read-atomic-tree` is read-only inventory (`ReadAtomicTree.php` L110). There is **no** V4 surgical write ability under `plugin/includes/Abilities/ElementorV4/` (only Read, Status, Describe, List, Classes, Variables, Migrate, RenderFromSpec, AtomicWidgetDefine).

---

## Q2. Do `validate_tree()` and `assert_write_allowed()` accept V4→V4 writes?

### Write pipeline

```77:114:plugin/includes/Support/ElementorData.php
	public static function write( int $post_id, array $tree, array $options = [] ): bool {
		self::$last_write_error = null;
		$previous               = self::read( $post_id );

		if ( empty( $options['skip_integrity'] ) ) {
			$gate = DocumentIntegrityGate::assert_write_allowed( $tree, $previous, $options );
			// ...
		}

		if ( ! SettingsValidator::validate_tree( $tree ) ) {
			// ...
			return false;
		}
		// encode → assert_meta_payload_not_double_encoded → persist + readback
```

### `DocumentIntegrityGate` — architecture-neutral

Checks (L27–109 of `DocumentIntegrityGate.php`):

1. Incoming is array list (not string / map).
2. Not double-encoded list-of-JSON-string.
3. Every node: non-empty `id`, non-empty `elType`; widgets need `widgetType`; `elements` array if present.
4. Size collapse: if previous > 2048 bytes and incoming < 85% of previous → reject (unless `force_destructive`).
5. widgetType remaps on same id → reject (unless `allow_widget_type_remap`).

**V4→V4 surgical patch** that keeps ids, elTypes, widgetTypes, and document size → **passes**. Explicit test that remap blocks `e-paragraph` → `text-editor` (`DocumentIntegrityGateTest.php` L59–80). Same-type patch allowed (L83–95).

**No V4-specific bypass exists or is required.** Options `skip_integrity` / `force_destructive` / `allow_widget_type_remap` must remain gated and rare.

**Gap:** remap map only tracks `elType === 'widget'` (`widget_type_map` L274–276). Changing a layout node’s `elType` (e.g. `e-flexbox` → `e-grid`) is **not** blocked by the remap check. 9.2 should refuse elType/widgetType changes unless explicitly requested.

### `SettingsValidator::validate_tree()` — atomic structure-only

```173:206:plugin/includes/Elementor/Schema/SettingsValidator.php
			if ( 'widget' === $element_type ) {
				$widget_type = (string) ( $element['widgetType'] ?? '' );
				// ...
				if ( str_starts_with( $widget_type, 'e-' ) ) {
					// Skip V3 schema validation for atomic nodes.
				} elseif ( '' !== $widget_type && 'html' !== $widget_type ) {
					// V3 validate(..., preserve_unknown=true)
				}
			} elseif ( in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
				// V3 container schema with preserve_unknown
			}
```

Implications:

| Node kind | Tree validation |
|---|---|
| `widget` + `widgetType` `e-*` | Structure only (id, unique, elType, widgetType, children array). **Settings values not validated.** |
| `elType` `e-div-block` / `e-flexbox` / `e-grid` | Structure only (not in container/section/column branch). **Settings not validated.** |
| Classic V3 widget/container | Full control schema + text integrity + preserve_unknown |

Explicit test:

```282:290:plugin/tests/Unit/Elementor/Schema/WidgetSchemaRepositoryTest.php
	public function test_final_tree_guard_allows_atomic_widgets_in_mixed_tree(): void {
		// P0: coexisting e-* nodes are structure-only; never force convert to text-editor.
		$valid = SettingsValidator::validate_tree(
			[
				[ 'id' => 'atomic1', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => [], 'elements' => [] ],
			]
		);

		self::assertTrue( $valid );
	}
```

### Why V3 update cannot be reused

`UpdateElement` always runs V3 validators:

```90:102:plugin/includes/Abilities/ElementorV3/UpdateElement.php
				if ( in_array( $element_type, [ 'container', 'section', 'column' ], true ) ) {
					// SettingsValidator::validate_container
				} elseif ( 'widget' === ( $existing['elType'] ?? '' ) ) {
					$validated = SettingsValidator::validate( (string) ( $existing['widgetType'] ?? '' ), $next );
```

`SettingsValidator::validate()` uses `WidgetSchemaRepository` (V3 controls). Atomic widgets will fail schema lookup / unknown keys.
`BatchMutate` additionally hard-blocks v4/mixed architecture (`BatchMutate.php` L186–195) and refuses adding `e-*` widgets (L482–483).

### Integrity-gate options for 9.2

| Option | Verdict |
|---|---|
| A. Use `ElementorData::write()` as-is (no skip) | **Required default** — double-encode, size collapse, remap, readback stay on. |
| B. `skip_integrity` for V4 | **Reject** — loses P0 protections. |
| C. Extend `validate_tree` to deep-validate atomic envelopes | Optional later; not blocking 9.2 if ability-layer validates. |
| D. Ability-layer atomic settings check + write through normal path | **Recommended for 9.2** |

---

## Q3. What does `AtomicSchemaRepository` expose for per-widget setting validation?

File: [`plugin/includes/Elementor/V4/AtomicSchemaRepository.php`](../../../plugin/includes/Elementor/V4/AtomicSchemaRepository.php)

### Public API

| Method | Role |
|---|---|
| `all(): array` | Map `atomic_type => schema` (bundled + runtime discovery + filter) |
| `for_atomic_type( string ): ?array` | Lookup by `e-heading`, `e-flexbox`, … |
| `for_design_type( string ): ?array` | Lookup by DesignSpec type (`Heading`, `Container`, …); injects `atomic_type` |
| `fingerprint(): string` | SHA-256 of full schema map |
| `invalidate(): void` | Clear cache |
| Constants | `ELEMENT_VERSION = '0.0'`, `PAGE_VERSION = '0.4'` |

**There is no** `validate()`, `validate_settings()`, or `validate_tree()`.

### Schema shape

Bundled example (L29–38):

```29:38:plugin/includes/Elementor/V4/AtomicSchemaRepository.php
		$schemas = [
			'e-div-block' => self::layout( 'Div', [] ),
			'e-flexbox'   => self::layout( 'Container', [ 'direction' => 'string', 'gap' => 'size' ] ),
			'e-grid'      => self::layout( 'Grid', [ 'columns' => 'string', 'rows' => 'string', 'gap' => 'size' ] ),
			'e-heading'   => self::widget( 'Heading', [ 'text' => [ 'key' => 'title', 'type' => 'html-v3' ], 'level' => [ 'key' => 'tag', 'type' => 'heading-level' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			'e-paragraph' => self::widget( 'TextEditor', [ 'text' => [ 'key' => 'paragraph', 'type' => 'html-v3' ], 'link' => [ 'key' => 'link', 'type' => 'link' ] ] ),
			// e-image, e-button, e-divider, e-svg ...
		];
```

Each schema:

```json
{
  "kind": "layout|widget",
  "design_types": ["Heading"],
  "version": "0.0",
  "props": {
    "text": { "key": "title", "type": "html-v3" },
    "level": { "key": "tag", "type": "heading-level" }
  },
  "source": "elementor_official_docs|live_runtime"
}
```

- **Design prop name** (`text`) → **persisted settings key** (`title`) + **envelope type** (`html-v3`).
- Layout style props map to CSS-like keys (`flex-direction`, `gap`) with types `style-string` / `style-size` (L143–154).
- Runtime discovery (`discover_runtime`, L91–137): every registered `e-*` with `get_props_schema()` → props as `raw-json` + full `json_schema` from Elementor.

### How abilities use it today

- `DescribeAtomicWidget` — lists design props for a DesignSpec node type (not raw settings keys).
- `AtomicRenderer` — validates **incoming DesignSpec props** against schema, then emits typed settings.
- `Status` — exposes fingerprint.

### Implication for UpdateNode validation

Surgical update operates on **already-persisted settings keys** (`title`, `paragraph`, `classes`, …), not DesignSpec prop names (`text`, `level`).

Recommended 9.2 validator (new helper, not inventing V3 schema):

1. Resolve atomic type from node (`widgetType` if widget else `elType`).
2. `AtomicSchemaRepository::for_atomic_type( $type )` — if null, reject or structure-only + `unknown_atomic` warning (mirror inspector).
3. Build reverse map: settings `key` → type from `props[*].key` / `props[*].type`.
4. For each patched settings key:
   - Allow `classes` (documented special) as `{ $$type: 'classes', value: string[] }`.
   - If key known: require array envelope with `$$type` + `value` (shape from type / raw-json schema when present).
   - If key unknown: **preserve** (parity with P0 preserve_unknown) + warning; do **not** strip.
5. Modes `merge` \| `replace` like V3 UpdateElement; replace must not drop sibling keys the agent did not intend if that collapses size — prefer merge default; replace only with explicit agent intent.

Defer deep value validation against full Elementor JSON Schema for every `raw-json` prop unless runtime schema is present and cheap.

---

## Q4. How does Elementor V4 persist atomic settings?

Sources: `docs/elementor-v4-engine.md`, `AtomicRenderer`, class/variable adapters, fixtures — not guesses.

### Document-level (per node in `_elementor_data`)

From `docs/elementor-v4-engine.md` L25–28 and renderer:

- Layout: native `elType` = `e-*`.
- Widgets: `elType=widget` + `widgetType=e-*`.
- Every element carries: `version`, `isInner`, `settings`, `editor_settings`, `interactions`, `styles`, `elements`.

**Content props** live in `settings` as **typed envelopes**:

```json
"settings": {
  "title": { "$$type": "html-v3", "value": { "content": { "$$type": "string", "value": "Hello" }, "children": [] } },
  "tag": { "$$type": "string", "value": "h2" }
}
```

(`AtomicRenderer::typed_value` L129–166; tests L25–28.)

**Class attachment** is a settings envelope, not free-form CSS on the node:

```84:90:plugin/includes/Elementor/V4/AtomicRenderer.php
		if ( [] !== $class_ids ) {
			// ...
			$settings['classes'] = [ '$$type' => 'classes', 'value' => array_values( array_unique( $class_ids ) ) ];
		}
```

**Local / node styles** sit on the node’s `styles` object (map id → class-like style with variants), separate from content settings. Layout props (`direction`, `gap`) are compiled into a generated local style + class id (`AtomicRenderer` L74–83; test L111–116):

```111:116:plugin/tests/Unit/Elementor/V4/AtomicRendererTest.php
		$style_id = $out['settings']['classes']['value'][0];
		$style_props = $out['styles'][ $style_id ]['variants'][0]['props'];
		$this->assertSame( 'string', $style_props['flex-direction']['$$type'] );
		$this->assertSame( 'row', $style_props['flex-direction']['value'] );
		$this->assertSame( 'size', $style_props['gap']['$$type'] );
```

Style fixture shape: `plugin/tests/fixtures/elementor-v4/atomic-style.json` — `id`, `label`, `type: "class"`, `variants[]` with `meta.breakpoint` / `meta.state` and typed `props`.

### Kit / site-level (not in the page tree)

From `docs/elementor-v4-engine.md` L31–32:

- **Global classes** → Elementor `Global_Classes_Repository` via `AtomicClassRepositoryAdapter` (`apply_changes` + readback). Abilities: create/update/list class.
- **Variables** → `Variables_Service` via `AtomicVariableRepositoryAdapter`. Abilities: create/update/list variable.

Obsolete guessed kit keys are explicitly not used.

### Safety / write policy

- Mutations snapshot + validate + runtime API + readback (`docs/elementor-v4-engine.md` L33–35).
- Production-safe V4 writes blocked while experimental (`V4FeatureGate::check( true )` L12–14).
- Fixtures are structural; editor/frontend parity still pending controlled-site E2E (`manifest.json` claim; docs L37–43).

---

## Recommended minimal scope for Task 9.2

### Implement

1. **Ability** `stonewright/elementor-v4-update-node`
   File: `plugin/includes/Abilities/ElementorV4/UpdateNode.php`
   Mirror structure of [`UpdateElement.php`](../../../plugin/includes/Abilities/ElementorV3/UpdateElement.php):
   - Inputs: `post_id`, `element_id`, `settings` (object), `mode` enum `merge|replace` (default `merge`), optional `dry_run` (recommended true-default or explicit for safety).
   - Permission: `V4FeatureGate::check( !$dry_run )` + `Permissions::edit_post( $post_id )`. **Never** `__return_true`.
   - `UpdateElement` does **not** use confirmation tokens; single-node settings patch need not invent a token unless mode is production-safe (already blocked by V4FeatureGate writes).
   - Execute path:
     1. `ElementorData::read( $post_id )`
     2. Architecture: if pure `v3` → `v4_architecture_mismatch` (mirror V3 batch gate); if target node is not atomic (`e-` type) → structured error.
     3. `find_path` → resolve element; refuse if missing (include repair: read-atomic-tree).
     4. Forbid changing `id`, `elType`, `widgetType`, `version` via this ability.
     5. Merge/replace **settings only** (optional later: shallow `styles` patch — defer if unclear).
     6. Atomic settings validation (new small helper using schema reverse map).
     7. `Backup::snapshot_post( $post_id )`
     8. `ElementorData::write( $post_id, $new_tree )` **without** `skip_integrity`
     9. On failure: `ElementorData::write_error_for_ability()`
     10. Return `{ post_id, snapshot_id, element_id, dry_run?, warnings? }`

2. **Register** ability in the existing ElementorV4 registration path.

3. **Tests** `plugin/tests/Unit/Elementor/V4UpdateNodeTest.php` (plan names this file):
   - dry-run no write
   - write: snapshot + settings merge + readback
   - unknown id
   - V3-only document → mismatch
   - non-atomic target id in mixed tree → reject
   - remap/elType change attempt rejected
   - permission / feature gate
   - optionally: V4 tree survives integrity+validate_tree (integration via write)

4. **Remediation / truth matrix / docs** in same PR: ability-truth-matrix regen, changelogs, short note in `docs/elementor-v4-engine.md`, fix `v3_architecture_mismatch` repair text to name `elementor-v4-update-node` once it exists.

### Defer

| Item | Why |
|---|---|
| Add/remove/move nodes, reparent | Needs batch model + size-collapse strategy |
| Full styles/variant editor on node | Use class abilities; local `styles` map is easy to corrupt |
| DesignSpec prop → settings compile path in UpdateNode | Renderer already does that; surgical path should patch stored shape |
| Deep runtime JSON-schema validation for every raw-json prop | Expensive; start with envelope + known keys |
| Promote V4 writes in production-safe | Explicit product decision after E2E |
| Extend integrity gate for layout elType remaps | Nice; ability can refuse first |
| Write-back of inspector `atomic_tree` | Unsafe; never |

### Integrity gate options (decision)

**Ship 9.2 with Option A:** normal `ElementorData::write()`, ability-layer atomic validation, no skip.
Option C (deeper `validate_tree` atomic checks) can follow once tests prove envelope rules.

---

## Risk list

| Risk | Severity | Mitigation in 9.2 |
|---|---|---|
| Writing inspector projection instead of full tree | Critical | Only mutate via `read` + `find_path` + `set` |
| Agents pass DesignSpec props (`text`) instead of stored keys (`title`) | High | Document keys; accept optional reverse-map aliases only if tests lock them |
| Invalid `$$type` envelopes pass `validate_tree` | High | Ability-layer shape check before write |
| Layout elType swap not blocked by integrity remap | Medium | Ability refuses elType/widgetType mutation |
| Size collapse on aggressive `replace` emptying settings | Medium | Default `merge`; size gate remains as backstop |
| Mixed document: patching atomic leaves legacy siblings intact | Low (desired) | Full-tree write preserves non-targets |
| Unknown runtime atomic types (no schema) | Medium | Structure-only + warning or require `for_atomic_type` non-null for write |
| Production-safe still blocked | Product | Keep V4FeatureGate; document |
| Double-encode via php-execute workarounds | Existing | Keep ProtectedElementorWriteGuard; point agents to UpdateNode |
| Fixtures ≠ live editor parity | Known | Unit tests structural; E2E still pending per manifest |
| Concurrent editor edits / CSS cache | Medium | Existing cache clear in `ElementorData::persist_encoded`; snapshot for restore |

---

## Evidence index (files read)

| Path | Relevance |
|---|---|
| `plugin/includes/Elementor/V4/AtomicTreeInspector.php` | inspect return + lift semantics |
| `plugin/includes/Elementor/V4/AtomicSchemaRepository.php` | schema API + prop model |
| `plugin/includes/Elementor/V4/AtomicRenderer.php` | persisted node/settings/styles shape |
| `plugin/includes/Elementor/V4/AtomicStyleValidator.php` | global class variant rules |
| `plugin/includes/Elementor/V4/AtomicClassRepositoryAdapter.php` | kit-level class persistence |
| `plugin/includes/Elementor/V4/V4FeatureGate.php` | experimental write gate |
| `plugin/includes/Elementor/Integrity/DocumentIntegrityGate.php` | write integrity |
| `plugin/includes/Elementor/Schema/SettingsValidator.php` | tree guard + e-* skip |
| `plugin/includes/Support/ElementorData.php` | write pipeline |
| `plugin/includes/Abilities/ElementorV3/UpdateElement.php` | structure to mirror |
| `plugin/includes/Abilities/ElementorV3/BatchMutate.php` | v4 architecture block |
| `plugin/includes/Abilities/ElementorV4/*` | existing V4 surface (no UpdateNode) |
| `plugin/tests/fixtures/elementor-v4/*` | structural fixtures + manifest |
| `plugin/tests/Unit/Elementor/V4/AtomicFoundationTest.php` | inspect + schema tests |
| `plugin/tests/Unit/Elementor/V4/AtomicRendererTest.php` | envelope persistence |
| `plugin/tests/Unit/Elementor/Schema/WidgetSchemaRepositoryTest.php` | validate_tree allows e-* |
| `plugin/tests/Unit/Elementor/DocumentIntegrityGateTest.php` | remap / size / double-encode |
| `plugin/tests/Unit/ElementorV4/ReadAtomicTreeTest.php` | ability projection |
| `docs/elementor-v4-engine.md` | official persistence contract |
| `docs/plans/stonewright-implementation-success-plan-2026-07-22.md` | Task 9.1/9.2 definition |

---

## Go / no-go for 9.2

**GO** — implement surgical settings update for existing atomic nodes through the standard integrity-gated write path.

**No-go for this PR:** tree surgery, integrity bypass, production-safe V4 promotion, or treating `atomic_tree` as a writable document.
