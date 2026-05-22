# QA — Screenshot, Pixel Diff, and Accessibility

This document describes the Stonewright QA pipeline: how screenshots are captured,
how pixel diffs are run, where artifacts are stored, and how Lighthouse and a11y
audits fit into the flow.

## Overview

The QA pipeline consists of two parts:

1. **PHP abilities** (`plugin/includes/Abilities/QA/`) — the MCP-exposed surface.
   They call the companion via `CompanionClient` and interpret the results.
2. **Companion Node server** (`companion/src/`) — does the actual browser work
   (Playwright screenshots, axe-core a11y, Lighthouse, pixel diff).

Communication between PHP and companion is over HTTP to `stonewright_companion_url`
(default `http://localhost:3500`) with bearer-token authentication.

## Screenshot flow

```
Ability: ScreenshotPage
      │
      ├─ permission_callback() → Permissions::edit_posts()
      ├─ CompanionClient::post('/screenshot', { url, artifact_path, request_id, viewport_width, viewport_height })
      │
      │  Companion
      │  ├─ Validates url (must be http/https)
      │  ├─ Validates artifact_path (must contain stonewright-qa/)
      │  ├─ Playwright launches Chromium
      │  ├─ Navigates to url, waits for networkidle
      │  ├─ Captures PNG (full_page: true by default)
      │  └─ Returns { path, width, height }
      │
      └─ PHP ability returns { artifact_id, path, url, width, height, viewport, created_at }
```

**Viewport presets used by `ResponsiveCheck`:**

| Label | Width | Height |
|---|---|---|
| desktop | 1440 | 900 |
| tablet | 768 | 1024 |
| mobile | 375 | 812 |

Screenshots are saved to `<artifact_path>/<request_id>/screenshot-<viewport>.png`.

## Pixel diff flow

```
Ability: DiffScreenshot
      │
      ├─ Requires: reference_artifact_id and actual_artifact_id (artifact_ids from prior /screenshot calls)
      ├─ CompanionClient::post('/diff', { request_id, reference_artifact_id, actual_artifact_id, artifact_path, threshold })
      │
      │  Companion (pixel-diff.ts)
      │  ├─ Loads both PNGs via sharp (raw RGBA buffers)
      │  ├─ Compares pixel-by-pixel with configurable per-channel tolerance
      │  ├─ Highlights mismatched pixels in red in a diff PNG
      │  └─ Returns { passed, diff_ratio, diff_url, needs_reference, threshold, mismatch_regions }
      │
      └─ PHP ability returns { passed, diff_ratio, diff_url, threshold }
```

### Threshold

The `threshold` parameter plays a dual role:

1. **Per-pixel channel tolerance** (`companion/src/pixel-diff.ts`): converted to `maxDelta = threshold * 255`. A pixel is counted as mismatched when any RGBA channel exceeds this delta. So `threshold: 0.1` means a per-channel difference of more than 25.5 (out of 255) marks that pixel as differing.

2. **Pass/fail ratio cutoff** (`companion/src/http-api.ts`): after all pixels are counted, `diff_ratio = mismatched_pixels / total_pixels`. The result is considered **passing** when `diff_ratio <= threshold` (at or below threshold — inclusive).

The default is `0.1` (10% per channel tolerance; pass if at most 10% of pixels differ). Override per call:

```json
{ "reference_artifact_id": "...", "actual_artifact_id": "...", "artifact_path": "...", "threshold": 0.05 }
```

### Ignore regions

The companion's `PixelDiffOptions` supports `ignore_regions` (array of
`{x, y, width, height}` rectangles excluded from comparison). Ignored pixels are
painted grey in the diff image. This field is not yet exposed in `DiffScreenshot`
ability input — pending follow-up. Companion accepts the field if PHP sends it.

## Artifact paths

All companion filesystem writes are validated against the allowed-path regex:

```
^\/[a-z0-9/_.-]+stonewright-qa\/[a-z0-9-]+\/?$
```

Artifacts land at:

```
<wp-content>/uploads/stonewright-qa/<request_id>/
  screenshot-desktop.png
  screenshot-tablet.png
  screenshot-mobile.png
  diff-<label>.png
  axe-report.json
  lighthouse-report.html
```

The `artifact_path` prefix is computed by `QaArtifactStore` in
`plugin/includes/QA/QaArtifactStore.php`.

`QaArtifactStore` is fully implemented. Its `reserve( string $request_id )` static
method creates `WP_CONTENT_DIR/uploads/stonewright-qa/{request_id}/` (mode 0755)
and returns the absolute path. A second static method maps absolute filesystem paths
back to public URLs via `content_url()`.

## Accessibility (axe-core)

```
Ability: AccessibilityCheck
      │
      └─ CompanionClient::post('/axe', { url, artifact_path, request_id, rules? })

  Companion (http-api.ts)
  ├─ Playwright navigates to url
  ├─ Injects axe-core 4.9.1 (vendored at companion/vendor/axe-core-4.9.1.min.js)
  ├─ Runs axe.run() in the page context
  └─ Returns { violations, passes, incomplete }
```

Each `violation` object contains `id`, `impact` (critical/serious/moderate/minor),
`nodes`, and `description`.

## Lighthouse

```
Ability: Lighthouse
      │
      └─ CompanionClient::post('/lighthouse', { url, artifact_path, request_id, categories? })

  Companion (http-api.ts)
  ├─ Runs Lighthouse against url using the Playwright browser context
  ├─ Categories default to: performance, accessibility, best-practices, seo
  └─ Returns { scores: { performance: 0.97, ... }, report_path }
```

The HTML report is saved to `<artifact_path>/<request_id>/lighthouse-report.html`.

## Layout diff

```
Ability: DiffLayout
      │
      └─ CompanionClient::post('/layout', { url, artifact_path, request_id, selector? })

  Companion
  ├─ Captures DOM layout via Playwright evaluate()
  ├─ Returns { sections: [{tag, rect, children}], alignment_diffs: [{element, issue, severity}] }
```

Layout diff compares DOM geometry for structural issues (overflow, overlap,
alignment). It does not require a reference screenshot — it analyses the live DOM.

## Companion security model

1. `artifact_path` is validated against the regex above before any FS write.
2. `url` must match `^https?://` — no `file://`, `data:`, or relative URLs.
3. `request_id` must be a valid UUID v4.
4. All requests (except `/health`) require `Authorization: Bearer <COMPANION_BEARER_TOKEN>`.
5. Request body size is limited to `config.maxBodyBytes` (configured via companion env).

See [`docs/companion-contract.md`](../companion-contract.md) for full endpoint schemas.
