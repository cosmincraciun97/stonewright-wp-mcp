---
title: Icon library
source_url: https://elementor.com/help/icon-library/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:icon-library]
related_widgets: [icon, icon-box, icon-list, social-icons]
---

## Purpose
The Icon Library is Elementor's centralized repository of icon sets available to all icon-type widgets (Icon, Icon Box, Icon List, Social Icons, Button). It ships with Font Awesome 5 by default and can be extended with custom icon font uploads or SVG sets, giving designers a consistent icon management interface across the editor.

## Use this when
- Choosing icons for any widget that exposes an icon picker control
- Uploading a custom icon font (e.g. branded icon set as `.zip` with font files + `config.json`)
- Managing which icon sets are active to reduce frontend asset load
- Replacing a default icon set with a curated subset for a specific project

## Settings highlights
- **Icon Picker UI**: search-by-name, filter by library/category
- **Font Awesome 5 Free**: bundled; Solid, Regular, and Brands variants
- **Custom Icon Set upload**: Elementor Dashboard > Custom Icons; requires `.zip` with font files + `config.json`
- **SVG Upload**: inline SVG icons via Media Library (requires SVG support enabled)
- **Icon set toggle**: enable/disable individual libraries to reduce enqueued font files
- **Search field**: live-filter icons by keyword within the picker modal
- **Icon Size / Color**: set per-widget in the widget's own style tab

## Limits / gotchas
- Custom icon sets must follow Elementor's `config.json` schema exactly; malformed configs cause the set not to appear
- SVG uploads require the "Enable SVG Support" toggle in Elementor Settings > Advanced
- Loading multiple large icon fonts simultaneously increases page weight — disable unused sets
- Font Awesome 6 is not bundled by default; upgrade or upload manually if FA6 icons are needed
