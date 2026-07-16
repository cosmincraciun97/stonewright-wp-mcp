---
{
  "name": "Elementor direct editing",
  "description": "Edit Elementor pages in Direct mode via local WP-CLI without inventing widget schemas.",
  "triggers": ["elementor", "widget", "section", "container"],
  "enabled": true,
  "updated_at": "2026-07-16T00:00:00.000Z"
}
---

# Elementor direct editing (pluginless)

1. Call `stonewright-elementor-status` first. If `can_edit_data` is false, tell the user the honest options (install the Stonewright plugin, or work on a local site with WP-CLI). Do **not** improvise REST hacks for `_elementor_data`.
2. Call `stonewright-elementor-data-get` for the target post. Find the target element and, when adding widgets, **copy the structure of an existing sibling** — never invent `widgetType` or settings keys.
3. If a widget/setting schema is unknown, research official Elementor developer docs on the web before writing. If still unsure, ask the user.
4. Call `stonewright-elementor-data-update` (automatic backup under `~/.stonewright/backups/`), then open the page URL and verify. CSS flush is best-effort; if `css_flushed` is false, open the Elementor editor once or clear cache.
5. On the same error twice → stop, call `stonewright-learning-record`, and change approach.
