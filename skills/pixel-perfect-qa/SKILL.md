---
name: pixel-perfect-qa
description: >
  QA loop: screenshot the live page, diff against Figma reference, run
  accessibility and Lighthouse checks, propose a fix plan, apply with
  confirmation token.
---

# Pixel-Perfect QA

Drives a screenshot-diff-fix loop against a Figma reference export or a local
reference image. Surfaces layout drift, accessibility issues, and performance
flags. Proposes a ranked fix plan and applies fixes only after a confirmation
token is issued and acknowledged.

## Loop structure

```
1. companion/screenshot  (Playwright via companion layer)
2. companion/pixel-diff  (compare screenshot vs. reference)
3. stonewright/site-health  OR  companion/lighthouse
4. companion/axe-check   (accessibility)
5. Rank issues -> present fix plan to user
6. Issue confirmation token
7. Apply fixes via appropriate builder ability
8. Re-screenshot -> verify
```

## Screenshot

The companion layer provides the screenshot capability. Call it with the
page URL. The response is a path or base64 PNG.

From the plugin side, `stonewright/site-info` provides the home URL for
constructing the page URL:

```json
{ "ability": "stonewright/site-info", "args": {} }
```

## Pixel diff

Pass the live screenshot and the Figma reference export to the companion
`pixel-diff` function. Threshold: 0.05 (5% pixel difference tolerance by
default). The diff highlights changed regions with bounding boxes.

## Accessibility check

Use the companion axe-core integration. Map axe violation IDs to WCAG
criteria. Surface level A and AA violations only (ignore AAA in the fix plan
unless asked).

## Fix plan format

Present the fix plan as a numbered list ranked by severity. For each item:
- Issue type (layout drift / color contrast / missing alt / font mismatch / ...)
- Affected element (CSS selector or block path)
- Proposed change (specific ability call with args)
- Severity (critical / major / minor)

Example:

```
Fix plan for post 42 (snapshot_id: snap_1716300000_42):

1. [critical] Color contrast on .hero-cta button fails WCAG AA (ratio 2.1:1)
   Fix: stonewright/elementor-v3-update-element
   element_id: "b3c4d5e6"
   settings.button_text_color -> "#FFFFFF" (ratio becomes 7.2:1)

2. [major] Heading font is Inter 600, reference shows Inter 700
   Fix: stonewright/elementor-v3-update-element
   element_id: "a1b2c3d4"
   settings.typography_font_weight -> "700"

3. [minor] Hero section bottom padding is 40px, reference is 60px
   Fix: stonewright/elementor-v3-update-element
   element_id: "a1b2c3d4"
   settings.padding.bottom -> "60"

Confirm all 3 fixes? Reply YES to apply, or list item numbers to skip.
```

## Confirmation token

Issue a confirmation token before applying any fix. The token must include:
- `post_id`
- `snapshot_id` (from the most recent backup)
- Item numbers to be applied

Do not apply fixes until the user replies with YES or a subset of item numbers.

## After applying fixes

Re-run the screenshot and diff. If the diff score drops below threshold,
report success. If drift persists on specific regions, loop again (max 3
iterations before asking the user to review manually).

## Ability summary

| Ability | Purpose |
|---|---|
| `stonewright/site-info` | Get home URL + site metadata |
| `stonewright/site-health` | WP site health check |
| `stonewright/site-backup-page` | Explicit snapshot before fix |
| `stonewright/elementor-v3-get-page-structure` | Read element tree |
| `stonewright/elementor-v3-update-element` | Apply element-level fix |
| `stonewright/elementor-v3-update-kit-colors` | Fix global color |
| `stonewright/blocks-update` | Apply Gutenberg block fix |
| `stonewright/fse-update-global-styles` | Fix global style drift |
| `stonewright/media-set-alt` | Fix missing alt text |

See `references/diff-report-format.md` for the expected diff report shape.
