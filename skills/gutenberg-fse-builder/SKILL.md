---
name: gutenberg-fse-builder
description: >
  Stonewright Gutenberg and FSE builder. Inserts/updates blocks, manages
  theme.json global styles, FSE templates, template parts, and synced patterns.
---

# Gutenberg FSE Builder

Covers the full Gutenberg surface: per-post block trees, FSE templates and
template parts, global styles (theme.json), and synced patterns (wp_block CPT).
All write operations that touch post content or theme.json take a snapshot first.

Static and third-party block writes go through the browser finalizer. The
server serialize path is only for true `save:null` dynamic blocks.

## Block Theme Production Workflow

Use this workflow when the user asks for a Gutenberg-only page, a block theme,
or Full Site Editing work:

For **Spectra One** and other block themes, stay on this pack. Do not create a
parallel Spectra One playbook. Discover the theme with `stonewright/site-theme`
and `stonewright/site-capabilities`, then use theme.json, templates, template
parts, and patterns below. If Spectra (the `uagb/*` plugin) is also active,
load `spectra-build-page` for plugin blocks and keep FSE chrome here.

1. Discover active theme state with `stonewright/site-theme`,
   `stonewright/site-capabilities`, `stonewright/fse-get-theme-json`, and
   `stonewright/blocks-list-registered`.
2. Read current templates, template parts, patterns, and relevant post content
   before writing.
3. Plan design tokens first in `theme.json`: color palette, typography,
   spacing scale, layout widths, custom CSS properties, and per-block styles.
4. Prefer core blocks, block supports, patterns, template parts, and global
   styles before adding custom blocks or CSS.
5. Use `stonewright/design-spec-to-gutenberg` for page-like first passes and
   FSE abilities for templates/template parts/global styles.
   For visual pages from Figma, images, prompts, or design systems, implement
   one section per pass by default, or two sections only when simple and tightly
   coupled. Verify desktop, tablet, and mobile screenshots plus overflow after
   each batch, then auto-continue to the next batch when checks pass.
6. Keep client editing intuitive: expose reusable patterns, name template parts
   clearly, avoid fragile custom markup, and keep styles visible in the Site
   Editor where possible.
7. For theme export or handoff, use the WordPress Create Block Theme workflow
   after Stonewright has produced valid templates, template parts, and
   `theme.json`.
8. Verify front end and editor output with an external browser MCP at desktop,
   tablet, and mobile sizes.

AI block/theme builders are useful as rapid prototypes and prompt references,
but Stonewright uses a prototype-to-production workflow: valid `theme.json`,
valid block markup, readable template files, direct PHP runtime snippets only
when they are the shorter correct path, and browser verification before
signoff.

Companion page-body packs (load when `check-setup` is active):
`blocksy-build-page`, `kadence-build-page`, `generateblocks-build-page`,
`spectra-build-page`. This pack still owns FSE chrome for those sites.

## FSE pre-flight

Check block theme support before touching templates or global styles:

```json
{ "ability": "stonewright/site-capabilities", "args": {} }
```

`integrations.fse` must be true. If false, FSE abilities will return
`fse_unavailable`. Classic themes can still receive Gutenberg post bodies
through the finalizer; they cannot take template / global-style writes.

## Which write path

Default: queue `{name, attributes, innerBlocks}` and persist through the
Block Editor Queue. That is the path for static core blocks and every
third-party namespace.

Server path (`stonewright-blocks-insert` / `stonewright-blocks-update` /
`stonewright-blocks-serialize`): only when the registered type is
`save:null` — PHP renders the saved markup. On the introspection site these
were `is_dynamic: true` **and** are actually server-rendered:

| Name | Use |
|---|---|
| `core/query` | Query Loop. Server. |
| `core/latest-posts` | Latest posts. Server. |
| `core/shortcode` | Shortcode embed. Server. |
| `core/template-part` | FSE part. Server / FSE write. |
| `core/navigation` | Navigation. Server. |
| `core/site-title` | Site title. Server. |
| `core/site-logo` | Site logo. Server. |
| `core/post-content` | Post content slot. Server. |
| `core/post-title` | Post title slot. Server. |

`is_dynamic: true` is **not** the same as `save:null`. On the same site
`core/heading`, `core/cover`, `core/image`, `core/button`, and `core/list`
reported `is_dynamic: true` because they have render callbacks. Still queue
those. PHP serialize will drop editor-owned HTML.

`stonewright-blocks-insert` already routes `BlockQueue::requires_finalizer`
blocks into the queue. Prefer calling `stonewright-blocks-queue-change`
yourself for visual sections so the spec is explicit.

## Pending-change pipeline

1. `stonewright-task-start` with surface `gutenberg`.
2. `stonewright-blocks-parse` the target. Keep `path`, `position`, and the
   content hash.
3. `stonewright-blocks-list-registered` (and
   `stonewright-blocks-library-list-blocks` when a companion library is
   active). Then `stonewright-blocks-get-schema` /
   `stonewright-blocks-library-get-schema` for every name you will write.
4. Queue one section with `stonewright-blocks-queue-change`
   (`post_id`, `block_spec`, `action`, `path`, `position`,
   `expected_content_hash`). Spec shape: `{name, attributes, innerBlocks}`.
5. `stonewright-blocks-finalizer-runtime`. Require `online: true`. If it is
   false, tell the operator to open **Stonewright → Block Editor Queue** and
   leave it open. `keep_open` is true on purpose.
   `stonewright-blocks-finalizer-url` is the same link.
6. Poll `stonewright-blocks-pending-batch` until ids are `serialized`. The
   list is compact — it never returns the full spec.
7. Persist with `stonewright-blocks-finalize-batch`. Snapshot, production-safe
   confirmation, audit, readback.
8. Verify in a **separate** frontend tab at desktop, tablet, and mobile.

Do not hand-write block HTML. Do not PHP-serialize third-party blocks.

## `likely_partial` and editor-owned ids

`AttributeValidator` marks a schema `likely_partial` when:

- the name starts with `kadence/`, `generateblocks/`, or `uagb/`, or
- the registered type has an editor script and fewer than three declared
  attributes.

On the **finalizer** path, unknown keys become a `likely_partial_schema`
warning. They are not stripped. The live editor owns them, including unique
IDs assigned during mount-settle. Omit identity keys on insert unless a live
readback on **update** already has them.

On the **server** path, the same unknown keys are
`unknown_block_attributes`. That is why `save:null` is the only PHP write.

Unregistered names fail both paths: `block_not_registered` /
`stonewright_block_not_registered`.

`stonewright-blocks-get-schema` returns `likely_partial` once the running
plugin includes that field. Companion `list-blocks` counts on the
introspection site were all `0` (Kadence, GenerateBlocks, Spectra absent).
Treat those prefixes as partial the moment they appear.

## Verified registry (introspection site)

Namespaces actually registered:

| Namespace | Count |
|---|---|
| `core` | 116 |
| `stonewright` | 2 |
| `yoast` | 2 |
| `yoast-seo` | 5 |
| `kadence/` `generateblocks/` `uagb/` `blocksy/` | 0 |

`core/group` attributes verified: `tagName`, `templateLock`, `lock`,
`metadata`, `align`, `className`, `style`, `backgroundColor`, `textColor`,
`gradient`, `fontSize`, `fontFamily`, `borderColor`, `layout`, `ariaLabel`,
`anchor`. `is_dynamic: false`.

`core/columns`: `verticalAlignment`, `isStackedOnMobile` (default true),
`templateLock`, plus shared color/layout keys. `core/column` parent is
`core/columns`; `width` is a string. `core/button` parent is `core/buttons`.

Do not use `textAlign` as a top-level attribute. Heading alignment is
`align` (`left` / `center` / `right` / `wide` / `full` / `""`).

## Theme chrome vs theme.json

Block theme: `stonewright-fse-get-theme-json` is the design contract. Write
user global styles with `stonewright-fse-update-global-styles` after a
snapshot and an explicit confirm.

Classic theme with a chrome adapter (Blocksy, Kadence Theme, GeneratePress):
`stonewright-theme-chrome-get`. On the introspection site all three returned
`active: false` and empty `writable`. There are no verified chrome tokens to
copy. When an adapter is active, patch only `writable` keys, dry-run first.

## Composition recipes

Verified `{name, attributes, innerBlocks}` sketches. Queue these. Do not
PHP-insert them.

### 1. Hero

```json
{
  "name": "core/cover",
  "attributes": {
    "align": "full",
    "dimRatio": 60,
    "minHeight": 520,
    "minHeightUnit": "px",
    "contentPosition": "center center",
    "isDark": true,
    "overlayColor": "contrast"
  },
  "innerBlocks": [
    {
      "name": "core/heading",
      "attributes": { "level": 1, "content": "Headline", "textColor": "base", "align": "center" },
      "innerBlocks": []
    },
    {
      "name": "core/paragraph",
      "attributes": { "content": "One sentence of support copy.", "align": "center", "textColor": "base" },
      "innerBlocks": []
    },
    {
      "name": "core/buttons",
      "attributes": { "layout": { "type": "flex", "justifyContent": "center" } },
      "innerBlocks": [
        {
          "name": "core/button",
          "attributes": { "text": "Primary action", "url": "/contact", "tagName": "a" },
          "innerBlocks": []
        }
      ]
    }
  ]
}
```

Upload media first if the hero needs a photo. Then set `url` and `id` from
the live attachment. Do not invent media ids.

### 2. Feature grid

```json
{
  "name": "core/group",
  "attributes": {
    "align": "wide",
    "tagName": "section",
    "layout": { "type": "constrained" }
  },
  "innerBlocks": [
    {
      "name": "core/heading",
      "attributes": { "level": 2, "content": "What you get", "align": "center" },
      "innerBlocks": []
    },
    {
      "name": "core/columns",
      "attributes": { "isStackedOnMobile": true, "align": "wide" },
      "innerBlocks": [
        {
          "name": "core/column",
          "attributes": { "width": "33.33%" },
          "innerBlocks": [
            { "name": "core/heading", "attributes": { "level": 3, "content": "One" }, "innerBlocks": [] },
            { "name": "core/paragraph", "attributes": { "content": "Verified column copy." }, "innerBlocks": [] }
          ]
        },
        {
          "name": "core/column",
          "attributes": { "width": "33.33%" },
          "innerBlocks": [
            { "name": "core/heading", "attributes": { "level": 3, "content": "Two" }, "innerBlocks": [] },
            { "name": "core/paragraph", "attributes": { "content": "Verified column copy." }, "innerBlocks": [] }
          ]
        },
        {
          "name": "core/column",
          "attributes": { "width": "33.33%" },
          "innerBlocks": [
            { "name": "core/heading", "attributes": { "level": 3, "content": "Three" }, "innerBlocks": [] },
            { "name": "core/paragraph", "attributes": { "content": "Verified column copy." }, "innerBlocks": [] }
          ]
        }
      ]
    }
  ]
}
```

### 3. CTA

```json
{
  "name": "core/group",
  "attributes": {
    "align": "full",
    "tagName": "section",
    "backgroundColor": "contrast",
    "textColor": "base",
    "layout": { "type": "constrained" }
  },
  "innerBlocks": [
    {
      "name": "core/heading",
      "attributes": { "level": 2, "content": "Ready when you are", "align": "center" },
      "innerBlocks": []
    },
    {
      "name": "core/paragraph",
      "attributes": { "content": "Close with one action.", "align": "center" },
      "innerBlocks": []
    },
    {
      "name": "core/buttons",
      "attributes": { "layout": { "type": "flex", "justifyContent": "center" } },
      "innerBlocks": [
        {
          "name": "core/button",
          "attributes": { "text": "Get started", "url": "/contact", "tagName": "a" },
          "innerBlocks": []
        }
      ]
    }
  ]
}
```

## theme.json / global styles

`stonewright/fse-update-global-styles` writes to the active theme's user-level
theme.json. This is a global change. Always confirm with the user and snapshot
before calling.

`stonewright/fse-get-theme-json` reads the merged theme.json (theme + user).
Use it to inspect current values before writing.

Use `theme.json` as the main design contract for block themes. Keep repeated
colors, font sizes, spacing, layout widths, and block-level styles there so
clients can keep editing through the Site Editor instead of editing custom CSS.

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

Template parts use `type: "wp_template_part"`. Prefer
`stonewright/fse-create-template-part` for new parts, then
`stonewright/fse-write-template-part` for updates. Snapshot with
`stonewright-site-backup-page` first — these abilities do not snapshot
internally.

A `core/template-part` block in a page body is `save:null`. Insert it on the
server path (`slug`, `theme`, `area` from the live schema). Do not queue it
as if it were a static group.

## Synced patterns

Patterns created with `stonewright/patterns-create` are stored as `wp_block`
CPT entries. They can be reused across pages via `<!-- wp:block {"ref":ID} -->`.

For client-friendly pages, package repeated page sections as synced or unsynced
patterns where that matches the task. Use block supports for spacing, color,
typography, layout, borders, and dimensions instead of hardcoded wrappers when
the registered block supports them. Pattern **bodies** that contain static
blocks still go through the finalizer when you write the `wp_block` post.

## Backup rule

`stonewright/blocks-insert`, `stonewright/blocks-update`, and
`stonewright/blocks-remove` call `Backup::snapshot_post` internally when they
persist immediately. `stonewright-blocks-finalize-batch` snapshots before
persist. `stonewright/fse-update-global-styles` and
`stonewright/fse-update-template` do not; call MCP tool
`stonewright-site-backup-page` before these.

## Failure modes

| Symptom | Code / signal | What you do |
|---|---|---|
| Name not registered | `block_not_registered` | Re-list. Do not invent names. |
| PHP insert of a partial schema | `unknown_block_attributes` | Re-queue on the finalizer. |
| Partial schema, queue accepted | `likely_partial_schema` | Leave the keys. Let the editor save. |
| Item still `queued` | `finalizer_not_serialized` (409) | Open Block Editor Queue. Wait. Retry. |
| Post changed under you | `content_conflict` (409) | Re-parse. Fresh hash. Re-queue. |
| Heartbeat dead | `online: false` | Do not finalize. Do not PHP-serialize static blocks. |
| Hash mismatch | `finalizer_hash_mismatch` | Discard the blob. Re-queue. |
| Not a block theme | `fse_unavailable` | Skip template / global-style writes. Page body can still use the finalizer. |

## Ability summary

| Ability | Purpose |
|---|---|
| `stonewright/blocks-list-registered` | All registered block types |
| `stonewright/blocks-get-schema` | block.json schema for a type (`likely_partial` when present) |
| `stonewright/blocks-parse` | Parse the post to a block tree |
| `stonewright-blocks-queue-change` | Queue `{name, attributes, innerBlocks}` for the finalizer |
| `stonewright-blocks-finalizer-runtime` | `online`, pending count, Queue URL, editor frame URLs |
| `stonewright-blocks-pending-batch` | Compact queued ids (no full spec) |
| `stonewright-blocks-finalize-batch` | Persist hashed HTML after snapshot |
| `stonewright/blocks-insert` | Immediate insert for `save:null` only; otherwise queues |
| `stonewright/blocks-update` | Update attrs for `save:null`; otherwise queues |
| `stonewright/blocks-remove` | Remove block at path |
| `stonewright/blocks-serialize` | Server serialize — `save:null` only |
| `stonewright/blocks-transform-html` | Raw HTML -> block markup |
| `stonewright/design-spec-to-gutenberg` | Spec-driven page build (still honor the finalizer) |
| `stonewright/fse-get-theme-json` | Read merged theme.json |
| `stonewright/fse-update-global-styles` | Write user global styles |
| `stonewright/fse-list-templates` | List all templates |
| `stonewright/fse-update-template` | Write template/template-part |
| `stonewright/fse-create-template-part` | Create new template part |
| `stonewright/patterns-list` | List registered + synced patterns |
| `stonewright/patterns-create` | Create synced pattern |

See `references/block-examples.md` for concrete block JSON payloads.
Those examples that call `blocks-insert` for `core/group` are stale — queue
static trees instead. See `references/fse-examples.md` for theme.json and
template examples.
