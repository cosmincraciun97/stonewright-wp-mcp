# DesignEvidence and native-first planning

Stonewright accepts a compact, vendor-neutral handoff from Figma, screenshots,
images, live pages, official documentation, or a user brief. AI vision extracts
evidence; deterministic code validates semantics and selects native primitives.
AI output never becomes raw Elementor settings.

## Contract

`stonewright/design-native-plan` accepts `action: validate|plan`, a target
(`elementor` / `elementor-v3` / `gutenberg` / `fse` / `wordpress`), and
DesignEvidence 1.0:

- `sources`: unique source IDs with type, reference, hash/date where available;
- `viewports`: measured width and height (breakpoint frames);
- `global`: reusable widths, colors, `color_tokens`, typography,
  `typography_ramp`, spacing, `spacing_scale`, `figma_token_table`, assets,
  provenance;
- `measured_targets`: per-breakpoint measured values (`viewport_id`,
  `property`, `value_px`, optional `node_id`, `tolerance_px`);
- `nodes`: semantic roles, content, actions, assets, layout intent
  (`type` flex|grid, `align_items`, `justify_content`, `gap`, …), style intent,
  responsive observations, per-node measured targets, provenance, children, and
  customization needs;
- `unresolved`: explicit ambiguities that block planning.

Normalization keeps only the contract fields. Raw Figma documents and unknown
vendor payloads are discarded before hashing or planning. The canonical
evidence hash makes repeated plans deterministic.

For a large page, pass the shallow extraction through
`stonewright/design-section-manifest` first. A page manifest contains an
ordered `sections` array. Each section supplies its own stable `section_id`,
positive bounding box, semantic roles, and source-node provenance; shared page,
frame, source fingerprint, target renderer, confidence, and assets may be
inherited from the page. Explicit `order` values are unique non-negative
integers. When omitted, source order is preserved. `action: decompose` returns
the validated section manifests in that order, including their independent
digests, so an agent can deep-read and implement one section at a time.

Every non-neutral style value needs a provenance row:

```json
{
  "source": "design",
  "source_id": "figma:hero",
  "confidence": 0.99,
  "requires_confirmation": false
}
```

Allowed provenance types are `design`, `live_schema`, `official_docs`, `user`,
`verified_memory`, and `inference`. Inference must set
`requires_confirmation: true` and blocks the native plan until confirmed.

## Semantic write gates

- Buttons, CTAs, and links need a visible label and real URL/page/anchor/email/
  phone/form action. Empty destinations, `#`, and JavaScript URLs fail.
- Navigation needs a WordPress menu or labeled links with valid destinations.
- Forms need fields plus submit and success behavior.
- Images need an attachment/source and alt text or explicit alt policy.
- Unknown roles fail; they are never converted from raw Figma node names.
- Elementor V4 is a separate target and never falls back to V3 structures.

Errors use `stonewright_design_evidence_invalid` and return compact diagnostics
with exact path, code, blocking status, and one repair action.

## Native plan

Each semantic node emits **either** a `native_mapping` (engine-native construct)
**or** a justified `native_gap` (CSS allowed only here). The same evidence can
target `elementor`, `gutenberg`, or `fse` via the `target` / `engine` argument.

For Elementor V3, nodes map to installed native containers, widgets, content
models, or Theme Builder templates (schema hashes attached). Gutenberg/FSE map
to core blocks; FSE also records constrained template surfaces. The planner
emits intent, not settings; settings compile against the live schema during
dry-run/write.

The section-manifest planning surface is deliberately stricter than a widget
name suggestion. Its schema input contains registered candidates with a valid
schema hash, native target, controls, semantic capabilities, and optional
capability-to-control mappings. Stonewright selects only from those candidates.
If none is verified or a required capability is absent, it returns a native
gap with `custom_code_approved: false`; it does not guess `image-carousel`,
`core/gallery`, or another renderer target.

Carousel evidence must state slide count, gap, arrows, and dots for desktop,
tablet, and mobile. Missing optional behavior remains unknown rather than being
filled with defaults. Active arrows require explicit previous/next assets and
labels. The asset union is WordPress media, a renderer icon-library identifier,
a normalized manifest asset, or inline SVG sanitized by a fail-closed DOM
allowlist. Remote or externally referencing SVG is not treated as sanitized.

The native phase order is:

1. global styles;
2. content model;
3. native structure and widgets;
4. responsive settings;
5. dry-run;
6. approval and guarded write;
7. immediate readback;
8. agent Playwright verification against `measured_targets`.

`status: ready_for_native_dry_run` is the only write-ready result. Native
coverage is reported per task. The planner itself is read-only and applies
nothing.

Custom CSS without a recorded `native_gap.reason` fails
`stonewright/design-implementation-contract` (`action: validate`) with
`stonewright_spec_invalid` / `custom_css_without_native_gap`.

## Phase-two customization

The native page must be complete and editable before phase two. Any remaining
visual or behavioral delta appears in `customization_proposal`, ordered as CSS,
CSS plus scoped JS, then versioned custom PHP. A proposal is never applied and
requires explicit approval plus exact files, semantic selectors, diff, impact,
risk, rollback, tests, and a confirmation request. Refusing phase two does not
invalidate or rebuild the native result.

## Minimal flow

1. Read `stonewright-design-direction-brief` once, then the current page, kit,
   media, menus, and live widget schemas.
2. With official Figma MCP or Figma Console MCP, read shallow page metadata and
   reusable tokens once; deep-read only the current top-level section.
3. Validate/decompose the ordered page manifest, then normalize the current
   section into DesignEvidence and omit raw source trees.
4. Call `stonewright-design-native-plan`.
5. Resolve every blocker and repeat until ready.
6. Compile the returned intent through schema-validated native dry-runs.
7. Write through guarded batch operations and verify readback.
8. Compare bounded rendered anchors, including line-height and spacing, then
   offer phase two only for a measured remaining delta.

See [figma-to-elementor-workflow.md](figma-to-elementor-workflow.md) for the
complete low-token extraction, implementation, and onboarding guide.
