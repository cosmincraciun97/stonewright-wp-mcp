---
name: Stock image fill
description: Search and import Openverse stock images with license attribution into the media library for a page build.
enable_agentic: true
enable_prompt: true
---

# Stock image fill

## Steps
1. Search existing library with `stonewright/media-list` first.
2. `stonewright/stock-image-search` with a precise query and provider `openverse` (default).
3. Review license/creator fields; pick best matches.
4. `stonewright/stock-image-import` with the chosen result id/url; confirm caption has attribution.
5. Set alt via `stonewright/media-set-alt` for context on the target page.
6. Unsplash/Pexels only when site options provide API keys — otherwise stay on Openverse.

## Rules
- Always keep license attribution in caption/meta.
- Prefer fewer high-quality images over bulk spam imports.
