---
name: elementor-site-clone
description: Use when cloning, duplicating, migrating, or rebuilding an Elementor page or section into a new draft with typed Stonewright tools.
version_constraints: {"elementor": "required"}
---

# Elementor site clone

Clone an existing Elementor page into a new draft using **typed Stonewright tools only**. Never improvise with `php-execute` writes or HTML widgets.

## When to use

User asks to clone, duplicate, migrate, or rebuild an Elementor page/section structure on the same site or another draft.

## Hard rules

- HTML widgets are **disabled by default** at site level. Never plan HTML/raw-html widgets. `allow_html_widget=true` is ignored while the site option is off.
- No blind CSS copy, no absolute positioning dumps, no skipping snapshots/backups.
- Do not write Elementor trees via `stonewright/php-execute`.

## Engine

If the user wants Gutenberg/blocks/FSE instead of Elementor, stop this clone skill and use `stonewright-blueprint-apply` or Gutenberg typed tools with `engine=gutenberg`. When the user said Elementor, pass `engine=elementor` on any blueprint apply; if Elementor is missing the call fails loudly — report it, do not switch engines silently.

## Procedure

1. `stonewright-task-start` with `surface=elementor`, `intent=write`.
2. If `stonewright-elementor-page-digest` is missing from the tool list: call `stonewright-tool-profile` with `extras=["stonewright/elementor-page-digest"]` (or profile that includes it), then **re-list tools**; `extras` needs an administrator, so if the call is refused tell the user which tool is missing. Never work around missing tools with php-execute or by asking for the `full` profile.
3. `stonewright-elementor-page-digest` on the **source** post_id → compact structure (sections, widgets, key settings).
4. Map each source widget to a **native** V3 type. A DesignSpec uses its own block names (`heading`, `paragraph`, `image`, `button`, `spacer`, `separator`, `list`, `video`, ...); a widget the spec has no block for goes into a `stonewright-elementor-build-tree` payload by widget type, with its keys read from `stonewright-elementor-schema` first:

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

5. Build a DesignSpec (or build-tree payload) and write it to a **new draft** page, dry run first:
   - `stonewright-elementor-v3-build-page-from-spec`, or
   - `stonewright-elementor-build-tree`.
   In `production-safe` mode the write (not the dry run) needs a confirmation token issued for the exact call.
6. Media: `stonewright-media-upload-batch` for remote images (sideload). No hotlinks in production.
7. Run `stonewright-elementor-css-regenerate` and `stonewright-elementor-post-write-verify` on the draft, digest the **result** page and list structural diffs (widget type order, missing nodes).
8. Fix every error in `diagnostics` (build-page-from-spec) or the path-exact errors (build-tree) before reporting done. A draft made with `stonewright-blueprint-apply` also returns a `qa` block: fix its issues until the score is >= 90.
9. Report: draft URL, post_id, diffs, diagnostics, qa score when there is one.

## After apply

> After apply, read the qa block when the call returns one; fix every issue until score >= 90 before reporting done.
