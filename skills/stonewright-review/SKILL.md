---
name: stonewright-review
description: >
  Review mode: validates a generated page against the Stonewright Design Spec,
  surfaces spec drift, and proposes a ranked refinement plan.
---

# Stonewright Review

Compares a live WordPress page to the Stonewright Design Spec it was generated
from. Surfaces structural drift (missing sections, wrong token values, layout
deviations) and accessibility issues. Produces a ranked refinement plan with
specific ability calls to close each gap.

## When to run

Run this skill after `design-to-wordpress` produces a page, or when a page
has been edited manually and you need to verify it still matches the spec.

## Inputs required

- `post_id`: the WordPress post to review.
- `spec`: the original Stonewright Design Spec (or its validated/normalized form).

## Review steps

```
1. stonewright/design-validate-spec      validate the reference spec is still well-formed
2. Read live page state:
     - elementor_v3: stonewright/elementor-v3-get-page-structure
     - gutenberg:    stonewright/blocks-parse
     - fse template: stonewright/fse-list-templates + stonewright/fse-get-theme-json
3. Compare spec sections to live elements -> find missing, extra, or drifted sections
4. Compare spec tokens to kit colors / global styles -> find token drift
5. Compare spec assets to media library -> find unresolved or swapped assets
6. Run accessibility surface check (heading hierarchy, alt attributes)
7. Rank findings -> present refinement plan
8. Issue confirmation token if any write fixes are proposed
9. Apply approved fixes via appropriate builder ability
```

## Spec drift categories

- **Missing section**: a section in the spec has no corresponding element on
  the live page.
- **Extra element**: the live page has an element with no spec counterpart
  (may be intentional; flag but do not remove without confirmation).
- **Token drift**: a color, font, or spacing value on the live page does not
  match the spec token.
- **Asset mismatch**: an image on the live page differs from the spec's asset
  reference (different attachment ID or URL).
- **Layout deviation**: container direction, column widths, or padding values
  differ from spec.

## Refinement plan format

```
Review findings for post 42 (spec version 1.0.0):

SPEC DRIFT
1. [missing-section] "testimonials" section not found on live page
   Spec: sections[2].type = "testimonials"
   Fix: run design-to-wordpress with sections[2] only, append mode

2. [token-drift] Hero background color
   Spec: tokens.colors.primary = "#0057FF"
   Live: #0044CC (kit color "primary")
   Fix: stonewright/elementor-v3-update-kit-colors -> primary: "#0057FF"

3. [asset-mismatch] Hero image
   Spec: sections[0].background.image = "https://source.com/hero.jpg"
   Live: attachment id 77 (hero-v1.jpg)
   Fix: confirm expected asset, then stonewright/media-upload if needed

ACCESSIBILITY
4. [missing-alt] attachment id 77 has empty alt text
   Fix: stonewright/media-set-alt -> "..."

No critical layout deviations found.

Confirm fixes 1-4? Reply YES or list item numbers.
```

## Confirmation token

For any write fix, issue a token:

```
"Confirm:
  post_id: 42
  snapshot_id: <id from pre-review backup>
  fixes: [1, 2, 3, 4]
Reply YES to apply."
```

Take a backup before applying fixes:
`stonewright/site-backup-page` (Gutenberg/FSE) or
`stonewright/elementor-v3-backup-page` (Elementor).

## Ability summary

| Ability | Purpose |
|---|---|
| `stonewright/design-validate-spec` | Validate reference spec |
| `stonewright/elementor-v3-get-page-structure` | Read live Elementor tree |
| `stonewright/blocks-parse` | Read live Gutenberg blocks |
| `stonewright/fse-get-theme-json` | Read global styles |
| `stonewright/fse-list-templates` | List FSE templates |
| `stonewright/elementor-v3-update-kit-colors` | Fix color token drift |
| `stonewright/elementor-v3-update-element` | Fix element-level drift |
| `stonewright/blocks-update` | Fix Gutenberg block drift |
| `stonewright/fse-update-global-styles` | Fix global style drift |
| `stonewright/media-set-alt` | Fix missing alt text |
| `stonewright/media-get` | Inspect media attachment |
| `stonewright/site-backup-page` | Snapshot before fixes |

See `references/drift-examples.md` for annotated drift detection examples.
