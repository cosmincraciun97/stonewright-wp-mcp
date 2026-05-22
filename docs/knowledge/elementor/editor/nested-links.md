---
title: Nested links
source_url: https://elementor.com/help/nested-links/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Nested links in Elementor V4 solve the HTML constraint that `<a>` elements cannot be nested inside other `<a>` elements. V4 introduces a mechanism to place a clickable child element (such as a button) inside a linked container without producing invalid HTML, using the `pointer-events` technique or dedicated nested-link prop handling to ensure both links remain functional and valid.

## Use this when

- Building a clickable card container (entire card links to article) that also contains a "Read More" button with its own separate link
- Creating a product card where the image links to the product and the "Add to Cart" button links to checkout
- Placing social icon links inside a linked hero image container
- Building navigation items that have both a parent link and expandable sub-link triggers

## Settings highlights

- **Parent link** — set on a Flexbox, Div Block, or container-level element via the Link prop
- **Child link** — set on a nested Button element, Image element, or text link within that container
- **Nested link handling** — V4 renders child links with modified DOM structure to prevent invalid nesting
- **pointer-events CSS** — parent container link is overridden by child element links via `position` layering
- **Accessibility** — ensure each link has a descriptive `aria-label` since visually identical areas may have different destinations
- **Dynamic tags** — both parent and child link props can use dynamic tags for template-driven URLs

## Limits / gotchas

- HTML spec disallows `<a>` inside `<a>`; Elementor V4 uses CSS/JS workarounds — test across browsers and screen readers
- Screen reader behavior with nested links can be unpredictable; always test with VoiceOver/NVDA
- In V3, nested links required manual `pointer-events: none` custom CSS on parent and `pointer-events: auto` on child; V4 automates this but older themes may conflict
- Nested links inside Loop templates may require extra testing as dynamic URL binding interacts with the nesting mechanism
