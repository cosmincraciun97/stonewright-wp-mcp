---
title: How to make clickable phone links or mailto links in Elementor widgets
source_url: https://elementor.com/help/clickable-phone-links/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:clickable-phone-links]
related_widgets: [button, heading, icon-box, text-editor]
---

## Purpose
Create clickable phone (`tel:`) and email (`mailto:`) links in Elementor widgets so visitors can initiate calls or compose emails directly from a web page. These protocol-based links trigger the device's native phone or email app, making contact CTAs immediately actionable — especially on mobile.

## Use this when
- Building contact sections where phone numbers and emails should be one-tap-callable
- Designing mobile-first pages where `tel:` links are the primary CTA
- Adding `mailto:` CTAs to any widget that accepts a URL (Button, Heading, Icon Box)
- Implementing dynamic phone/email values via Dynamic Tags on single-post templates

## Settings highlights
- Link field: enter `tel:+15550123456` (include country code, no spaces)
- Email link field: enter `mailto:hello@example.com`
- Subject line suffix: `mailto:hello@example.com?subject=Hello`
- **Allowed URI Protocols** (Elementor > Settings > Advanced): must include `tel` and `mailto` if blocked
- Applicable widgets: Button, Heading, Icon Box, Image, Social Icons link field
- Dynamic Tags `Post Custom Field` can populate `tel:` values on ACF/CPT phone fields
- Custom link attributes for tracking (`data-event`, `onclick`)

## Limits / gotchas
- `tel:` links do nothing on desktop browsers without a softphone app installed
- Elementor sanitizes link inputs — if `tel:` is stripped, explicitly add it to the Allowed URI Protocols list in settings
- `mailto:` behaviour varies by OS/browser configuration; some users have no default mail client set
