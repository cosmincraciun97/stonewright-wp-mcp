---
title: Add HTML tags to my section & column
source_url: https://elementor.com/help/how-to-add-html-tags-to-my-section-column/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v3, editor:v4]
related_widgets: []
---

## Purpose
Add semantic HTML tags to sections and columns in Elementor to improve site structure, accessibility, and SEO. This feature allows you to specify which HTML tag wraps your layout elements instead of using default `<div>` tags.

## Use this when
- You need proper semantic HTML structure for accessibility compliance
- Building sections that should be `<article>`, `<aside>`, `<nav>`, or `<header>` elements
- Improving SEO by using correct heading hierarchy and structural tags
- Creating layouts that require specific HTML semantics for screen readers
- Differentiating content sections semantically rather than visually

## Settings highlights
- **HTML Tag dropdown** – Select from semantic options like `<div>`, `<section>`, `<article>`, `<aside>`, `<header>`, `<footer>`, `<nav>`, `<main>`
- **Section-level configuration** – Apply custom tags at the section container level
- **Column-level configuration** – Set HTML tags for individual columns within sections
- **Accessibility impact** – Properly tagged elements improve screen reader interpretation
- **SEO benefits** – Semantic markup helps search engines understand content hierarchy
- **Advanced tab location** – Found in the Advanced settings panel for each element
- **No visual changes** – Tag modifications only affect HTML output, not page appearance

## Limits / gotchas
- Tag selection is limited to semantic options; custom HTML tags cannot be entered
- Changing tags doesn't automatically restructure nested content relationships
- Improper tag nesting (e.g., `<header>` inside `<article>`) is user's responsibility
