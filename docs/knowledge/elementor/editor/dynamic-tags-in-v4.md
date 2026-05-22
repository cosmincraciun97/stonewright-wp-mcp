---
title: Dynamic tags in V4
source_url: https://elementor.com/help/dynamic-tags-in-v4/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Dynamic tags in Elementor V4 bind element content to live WordPress data — post titles, featured images, custom field values, user info, site identity, etc. — enabling Template Builder templates to render context-aware content for any post, page, or archive without duplicating templates. V4 updates the dynamic tag API to work with atomic element props rather than V3 widget control fields.

## Use this when

- Building single post templates where the Heading element should show the post title dynamically
- Displaying author name, date, or category in archive and single templates
- Binding an Image element's `src` prop to the post featured image
- Pulling ACF, Metabox, or Pods custom field values into any text-capable element prop
- Creating reusable Loop templates where each iteration shows a different post's data

## Settings highlights

- **Tag picker** — available on any prop that accepts dynamic data (text, image URL, link URL); accessed via the lightning bolt icon
- **V4 prop binding** — tags bind to atomic props (`text`, `src`, `href`) rather than legacy control IDs
- **Categories** — Post, Site, Archive, Author, User, Media, WooCommerce, ACF, custom
- **Fallback** — each dynamic tag supports a static fallback value shown when dynamic data is empty
- **Tag parameters** — many tags expose config (e.g. post meta key name, image size, date format)
- **Nested tags** — link URL props can combine a dynamic URL tag with a text label dynamic tag
- **Loop context** — inside Loop templates, tags automatically resolve to the current loop item's data

## Limits / gotchas

- V4 dynamic tag prop binding API changed from V3; custom third-party dynamic tag plugins must update to V4 tag registration hooks
- ACF, JetEngine, and Pods require their Elementor integration plugins for their custom fields to appear in the tag picker
- Some tags require Elementor Pro (post meta, author meta, WooCommerce tags)
- Dynamic tags in V4 do not support JavaScript expressions; logic must be handled via custom PHP dynamic tag registration
