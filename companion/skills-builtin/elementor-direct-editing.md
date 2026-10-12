---
{
  "name": "Elementor direct editing",
  "description": "Edit Elementor pages in Direct mode via local WP-CLI without inventing widget schemas.",
  "triggers": ["elementor", "widget", "section", "container"],
  "enabled": true,
  "updated_at": "2026-10-12T00:00:00.000Z"
}
---

# Elementor direct editing (pluginless)

1. Call `stonewright-task-start` for the site, then `stonewright-elementor-status`. If the Stonewright plugin is active on the site, Direct refuses writes and points to Plugin mode (reads still work): stop and tell the user. If `can_edit_data` is false, tell the user the honest options (install the Stonewright plugin, or work on a local site with WP-CLI). Do **not** improvise REST hacks for `_elementor_data`.
2. Call `stonewright-elementor-data-get` for the target post. Find the target element and, when adding widgets, **copy the structure of an existing sibling** — never invent `widgetType` or settings keys.
3. If a widget/setting schema is unknown, research official Elementor developer docs on the web before writing. If still unsure, ask the user.
4. Call `stonewright-elementor-data-update` with `expected_hash` from the read. It saves the full tree first in the local Direct state folder (`~/.stonewright`, `backups` subfolder; list the copies and put one back with `stonewright-direct-backup-list` and `stonewright-direct-backup-restore`), writes, reads the tree back and reports the verification. On a remote site the first call is refused with a `confirmation_id`; repeat the same call, unchanged, with it (bound to that tool, site and arguments; one use, ten minutes; a plain `confirm:true` counts only on a local host). Direct refuses custom code (CSS, script, HTML widget, iframe, inline event handlers) in the tree, and does not rewrite a document with Atomic (V4) elements (that needs Plugin mode); report the refusal and do not look for a way around it. Then open the page URL and verify. Direct mode invalidates only the post HTML/object cache, preserves CSS metadata, and never runs a CSS flush; use Plugin mode for guarded target-post CSS closure.
5. On the same error twice → stop, call `stonewright-learning-record`, and change approach.
