---
title: HTML widget
source_url: https://elementor.com/help/html-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:html]
related_widgets: [shortcode, text-editor]
---

## Purpose
The HTML widget provides a raw code editor inside Elementor where you can paste arbitrary HTML, JavaScript, or embed codes directly into a page without restrictions. The code is rendered as-is in the frontend, bypassing Elementor's widget abstraction layer — ideal for third-party embeds and snippets that Elementor has no native widget for.

## Use this when
- Embedding third-party scripts or iframes (Typeform, Calendly, HubSpot, custom maps)
- Injecting raw HTML structures not supported by any built-in widget
- Pasting shortcode output that needs surrounding HTML context
- Adding inline JavaScript that must run at a specific DOM position
- Prototyping custom markup before building a proper custom widget

## Settings highlights
- **Content (HTML Code)**: multi-line code editor accepting full HTML/JS/CSS `<script>` tags
- **CSS ID** (Advanced tab): applies an ID attribute to the widget wrapper div
- **CSS Classes** (Advanced tab): space-separated class list on the wrapper
- **Custom CSS** (Advanced tab): scoped CSS targeting `.elementor-widget-html` selector
- No style tab controls — all styling is via inline styles or the Custom CSS panel
- Motion Effects, responsive visibility, and positioning controls available in the Advanced tab
- Entrance animations can be applied like any other widget

## Limits / gotchas
- Unfiltered HTML requires the `unfiltered_html` WordPress capability — Contributors and Authors cannot save raw scripts by default
- Scripts inside the widget execute on page load; deferred execution must be handled in the code itself
- The widget wraps output in a `<div>` — block-level wrapper may break certain inline-only HTML patterns
