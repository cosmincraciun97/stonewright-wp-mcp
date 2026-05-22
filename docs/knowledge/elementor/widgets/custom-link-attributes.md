---
title: Custom Link Attributes
source_url: https://elementor.com/help/custom-link-attributes/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:custom-link-attributes]
related_widgets: []
---

## Purpose
Custom link attributes allow you to add custom HTML attributes to links in Elementor widgets, enabling advanced functionality like tracking, analytics integration, and custom data attributes without requiring code modifications.

## Use this when
- You need to add data attributes for JavaScript tracking or interactions
- Integrating third-party analytics or marketing tools that require specific HTML attributes
- Building accessible links with ARIA labels or custom accessibility attributes
- Creating links with custom CSS classes for styling or JavaScript targeting
- Adding rel attributes for SEO purposes (nofollow, sponsored, etc.)

## Settings highlights
- Access custom attributes through widget link settings
- Add multiple custom attributes to a single link element
- Define attribute names and corresponding values independently
- Apply attributes across different link types (buttons, text links, images)
- Support for dynamic values through Elementor's dynamic content features
- Preview custom attributes in the editor before publishing

## Limits / gotchas
- Some HTML attributes may be restricted for security reasons depending on WordPress configuration
- Custom attributes don't automatically validate; invalid syntax could break link functionality
- Attributes are applied at the individual widget level, not globally across all links
- Changes to custom attributes require republishing the page to take effect
