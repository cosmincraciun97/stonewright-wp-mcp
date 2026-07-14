# DesignEvidence and native-first planning

Stonewright accepts a compact, vendor-neutral handoff from Figma, screenshots,
images, live pages, official documentation, or a user brief. AI vision extracts
evidence; deterministic code validates semantics and selects native primitives.
AI output never becomes raw Elementor settings.

## Contract

`stonewright/design-native-plan` accepts `action: validate|plan`, a target, and
DesignEvidence 1.0:

- `sources`: unique source IDs with type, reference, hash/date where available;
- `viewports`: measured width and height;
- `global`: reusable widths, colors, typography, spacing, assets, provenance;
- `nodes`: semantic roles, content, actions, assets, layout/style intent,
  responsive observations, provenance, children, and customization needs;
- `unresolved`: explicit ambiguities that block planning.

Normalization keeps only the contract fields. Raw Figma documents and unknown
vendor payloads are discarded before hashing or planning. The canonical
evidence hash makes repeated plans deterministic.

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

For Elementor V3, each semantic node maps to an installed native container,
widget, content model, or Theme Builder template. Widget and container results
carry their live schema hash. The planner emits intent, not settings; settings
are compiled only against the live schema during dry-run/write.

The native phase order is:

1. global styles;
2. content model;
3. native structure and widgets;
4. responsive settings;
5. dry-run;
6. approval and guarded write;
7. immediate readback.

`status: ready_for_native_dry_run` is the only write-ready result. Native
coverage is reported per task. The planner itself is read-only and applies
nothing.

## Phase-two customization

The native page must be complete and editable before phase two. Any remaining
visual or behavioral delta appears in `customization_proposal`, ordered as CSS,
CSS plus scoped JS, then versioned custom PHP. A proposal is never applied and
requires explicit approval plus exact files, semantic selectors, diff, impact,
risk, rollback, tests, and a confirmation request. Refusing phase two does not
invalidate or rebuild the native result.

## Minimal flow

1. Read the current page, kit, media, menus, and live widget schemas.
2. Normalize design inputs into DesignEvidence; omit raw source trees.
3. Call `stonewright-design-native-plan`.
4. Resolve every blocker and repeat until ready.
5. Compile the returned intent through schema-validated native dry-runs.
6. Write through guarded batch operations and verify readback.
7. Offer phase two only for a measured remaining delta.
