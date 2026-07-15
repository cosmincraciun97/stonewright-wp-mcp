# Elementor site clone

Clone an existing Elementor page into a new draft using **typed Stonewright tools only**. Never improvise with `php-execute` writes or HTML widgets.

## When to use

User asks to clone, duplicate, migrate, or rebuild an Elementor page/section structure on the same site or another draft.

## Hard rules

- HTML widgets are **disabled by default** at site level. Never plan HTML/raw-html widgets. `allow_html_widget=true` is ignored while the site option is off.
- No blind CSS copy, no absolute positioning dumps, no skipping snapshots/backups.
- Do not write Elementor trees via `stonewright/php-execute`.

## Procedure

1. `stonewright-task-start` with `surface=elementor`, `intent=write`.
2. If `stonewright-elementor-page-digest` is missing from the tool list: call `stonewright-tool-profile` with `extras=["stonewright/elementor-page-digest"]` (or profile that includes it), then **re-list tools**. Never work around missing tools with php-execute.
3. `stonewright-elementor-page-digest` on the **source** post_id → compact structure (sections, widgets, key settings).
4. Map each source widget to a **native** V3 type supported by the Elementor renderer:

| Source (common) | Target |
|---|---|
| heading / title | heading |
| text-editor / text | paragraph |
| button | button |
| image | image |
| icon-box | icon-box |
| image-box | image-box |
| testimonial | testimonial |
| tabs | tabs |
| accordion | accordion |
| counter | counter |
| form | form |
| image-carousel | slides |
| video | video |
| gallery | gallery |
| divider | separator |
| spacer | spacer |
| nav-menu | nav-menu |
| google_maps / html map | closest native embed/map if available; else image + link — **never html** |

5. Build a DesignSpec (or build-tree payload) and write with:
   - `stonewright-elementor-v3-build-page-from-spec`, or
   - `stonewright-elementor-build-tree` on a **new draft** page.
6. Media: `stonewright-media-upload-batch` for remote images (sideload). No hotlinks in production.
7. Digest the **result** page; list structural diffs (widget type order, missing nodes).
8. Read the response **`qa`** block. Fix issues until score ≥ 90 before reporting done.
9. Report: draft URL, post_id, diffs, qa score.

## After apply

> After apply, read the qa block; fix every issue until score >= 90 before reporting done.
