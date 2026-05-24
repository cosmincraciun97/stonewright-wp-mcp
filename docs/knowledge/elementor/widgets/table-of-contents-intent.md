---
title: Table of Contents Widget
source_url: https://elementor.com/widgets/table-of-contents-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:table-of-contents]
related_widgets: [menu-anchor]
---

## Purpose

Generates a dynamic table of contents for WordPress posts and pages by automatically creating navigation links from heading tags (H1–H6) found in the page content. Improves readability, accessibility, and SEO by helping readers scan long-form content quickly and enabling search engines to capture structured data for rich snippets. No separate plugin required — the widget reads existing headings in real time.

## Use this when

- Creating long-form articles or guides where readers need quick jump-navigation to sections
- Building FAQ pages that benefit from structured, scannable heading-based navigation
- Reducing reliance on separate table-of-contents plugins on content-heavy sites
- Targeting SEO improvements through heading-based structured anchor links
- Designing mobile experiences requiring collapsible navigation panels for long content

## Settings highlights

- **Heading tags**: Choose which H-tag levels (H2–H6) are included in the TOC
- **Minimum headings to show**: Hide the widget automatically if the page has fewer headings than the threshold
- **Title**: Label shown above the list (e.g. "Table of Contents")
- **Word wrap**: Controls whether heading text truncates or wraps in the list
- **Collapse on mobile**: Toggle the list into a collapsible panel on small screens
- **Marker**: Bullet, number, or none before each TOC entry
- **Indentation**: Visual nesting depth for sub-headings (H3 indented under H2)
- **List item spacing**: Gap between TOC entries
- **Typography**: Font controls for the TOC title and list items independently
- **Sticky scroll behavior**: Pin the TOC in view as the user scrolls (via Sticky advanced option)

## Limits / gotchas

- Requires proper heading hierarchy in the page content; pages without structured H-tags produce an empty or single-item TOC
- Reads headings from the current page only — cannot aggregate headings across multiple posts
- Custom post types must use standard Elementor heading widgets or native WP content headings; headings injected by third-party shortcodes may not be detected
- Mobile collapsible state defaults to expanded; test the collapsed default if UX requires it hidden on load
