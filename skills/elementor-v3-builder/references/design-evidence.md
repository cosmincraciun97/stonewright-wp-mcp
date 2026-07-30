# DesignEvidence and native-first planning

Load this reference only for visual implementation or when phase-two custom
code is being considered.

## Compact handoff

Keep one source row per design input and one provenance row per non-neutral
style leaf. Raw Figma documents, image bytes, and guessed Elementor settings do
not belong in the payload.

```json
{
  "sources": [
    {
      "id": "figma:hero",
      "type": "figma",
      "ref": "node:1:2",
      "captured_at": "2026-07-14T12:00:00Z"
    }
  ],
  "viewports": [
    { "id": "desktop", "width": 1440, "height": 900 }
  ],
  "global": {},
  "nodes": [
    {
      "id": "hero",
      "role": "container",
      "layout": { "direction": "column" },
      "style": { "gap": 24 },
      "provenance": {
        "gap": {
          "source": "design",
          "source_id": "figma:hero",
          "confidence": 0.99,
          "requires_confirmation": false
        }
      },
      "children": [
        {
          "id": "hero-cta",
          "role": "button",
          "content": { "label": "Start now" },
          "action": { "url": "https://example.com/start" }
        }
      ]
    }
  ],
  "unresolved": []
}
```

Call:

```json
{
  "action": "plan",
  "target": "elementor-v3",
  "evidence": { "...": "the normalized payload above" }
}
```

Proceed only when `status` is `ready_for_native_dry_run`. Resolve every blocker
instead of inventing a destination, control, asset, or V4 fallback.

## Semantic checks

- Button/CTA/link: visible label plus URL, page, anchor, email, phone, or form
  action. Empty destinations and `#` are invalid.
- Navigation: a real WordPress menu or labeled destination list.
- Form: real fields, validation intent, submit action, and success behavior.
- Image: attachment/source plus alt text or an explicit decorative policy.
- Repeated cards: choose static containers only for small fixed sets; use a
  content model and Loop Grid when editors must manage repeated data.
- Header/footer: use Theme Builder templates and display conditions.

## Native and custom phases

Complete and verify the native phase first. The planner's
`customization_proposal` is informational and never auto-applied. If the user
asks for the remaining delta, return the exact files, semantic selectors, diff,
impact, risk, rollback, test plan, and confirmation request. Prefer CSS, then
CSS plus narrowly scoped JS, then versioned custom PHP. Refusal of phase two
must leave a complete, editable native page.
