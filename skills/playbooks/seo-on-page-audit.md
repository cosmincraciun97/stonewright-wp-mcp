---
name: SEO on-page audit
description: Audit a page for title, headings, alt text, internal links, and schema readiness using Stonewright tools.
enable_agentic: true
enable_prompt: true
---

# SEO on-page audit

## Steps
1. Task start with page URL or ID.
2. Load page content + structure (builder tools or `stonewright/content-get-page`).
3. Check: one H1, logical H2/H3, meta title/description if available, image alts (`stonewright/media-list` / `media-set-alt`), internal links.
4. Report findings as a prioritized checklist; fix only when the user asks.
5. Prefer `stonewright/media-set-alt` for missing alts after stock imports.

## Rules
- Read-only by default; writes need explicit user approval.
