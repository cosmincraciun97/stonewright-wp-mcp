---
name: gutenberg-fse-builder
description: >
  Block editor + FSE skill. Inserts/updates blocks, manages theme.json global
  styles, FSE templates, template parts, and synced patterns.
---

# Gutenberg FSE Builder

Covers the full Gutenberg surface: per-post block trees, FSE templates and
template parts, global styles (theme.json), and synced patterns (wp_block CPT).
All write operations that touch post content or theme.json take a snapshot first.

## FSE pre-flight

Check block theme support before touching templates or global styles:

```json
{ "ability": "stonewright/site-capabilities", "args": {} }
```

`integrations.fse` must be true. If false, FSE abilities will return
`fse_unavailable`.

## Block operations

Work flow for adding blocks to an existing page:

1. `stonewright/blocks-parse` - read current block tree
2. `stonewright/blocks-insert` - insert a new block (takes snapshot internally)
3. `stonewright/blocks-serialize` - serialize back to HTML if needed

For spec-driven builds use `stonewright/design-spec-to-gutenberg` which handles
the full parse/mutate/write cycle.

## theme.json / global styles

`stonewright/fse-update-global-styles` writes to the active theme's user-level
theme.json. This is a global change. Always confirm with the user and snapshot
before calling.

`stonewright/fse-get-theme-json` reads the merged theme.json (theme + user).
Use it to inspect current values before writing.

## Templates and template parts

Templates are identified by ID in the format `theme//slug` (e.g. `twentytwentyfour//home`).

```json
{
  "ability": "stonewright/fse-update-template",
  "args": {
    "id": "mytheme//home",
    "type": "wp_template",
    "content": "<!-- wp:group --><div class=\"wp-block-group\"></div><!-- /wp:group -->"
  }
}
```

Template parts use `type: "wp_template_part"`.

## Synced patterns

Patterns created with `stonewright/patterns-create` are stored as `wp_block`
CPT entries. They can be reused across pages via `<!-- wp:block {"ref":ID} -->`.

## Backup rule

`stonewright/blocks-insert`, `stonewright/blocks-update`, and
`stonewright/blocks-remove` call `Backup::snapshot_post` internally.
`stonewright/fse-update-global-styles` and `stonewright/fse-update-template`
do not; call `stonewright/site-backup-page` before these.

## Ability summary

| Ability | Purpose |
|---|---|
| `stonewright/blocks-list-registered` | All registered block types |
| `stonewright/blocks-get-schema` | block.json schema for a type |
| `stonewright/blocks-parse` | Parse post content to block tree |
| `stonewright/blocks-insert` | Insert block at path/position |
| `stonewright/blocks-update` | Update block attrs/innerHTML |
| `stonewright/blocks-remove` | Remove block at path |
| `stonewright/blocks-serialize` | Serialize block tree to HTML |
| `stonewright/blocks-transform-html` | Raw HTML -> block markup |
| `stonewright/design-spec-to-gutenberg` | Spec-driven page build |
| `stonewright/fse-get-theme-json` | Read merged theme.json |
| `stonewright/fse-update-global-styles` | Write user global styles |
| `stonewright/fse-list-templates` | List all templates |
| `stonewright/fse-update-template` | Write template/template-part |
| `stonewright/fse-create-template-part` | Create new template part |
| `stonewright/patterns-list` | List registered + synced patterns |
| `stonewright/patterns-create` | Create synced pattern |

See `references/block-examples.md` for concrete block JSON payloads.
See `references/fse-examples.md` for theme.json and template examples.
