---
name: blocksy-build-page
description: Use when building Blocksy pages with live block schemas, theme chrome tokens, and the Gutenberg finalizer.
version_constraints: {"blocksy": "required"}
---

# Blocksy Build Page

Use this when the active theme is Blocksy and the task is a Gutenberg page,
not an Elementor canvas. Blocksy is a **theme adapter**. It does not get a
`library: "blocksy"` row on `stonewright-blocks-library-list-blocks` (that
ability only lists GenerateBlocks, Kadence, and Spectra). Page body is still
queued core or third-party blocks. Header and footer chrome stays on
`stonewright-theme-chrome-get` / `stonewright-theme-chrome-update`.

Do not invent a `blocksy/*` block namespace. List what is actually
registered.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "blocksy"`. Stop
   if `active` is false. This pack does not apply on another theme.
2. `stonewright-theme-chrome-get` with `theme: "blocksy"`. Reuse the returned
   `colors`, `typography`, `header`, and `footer` tokens. Do not invent
   Customizer keys. If `writable` is empty, you may not patch chrome.
3. If GenerateBlocks, Kadence Blocks, or Spectra is also active, call
   `stonewright-blocks-library-list-blocks` then
   `stonewright-blocks-library-get-schema` for each block you will insert.
   Otherwise list core types with `stonewright-blocks-list-registered` and
   read `stonewright-blocks-get-schema`.

Introspection on a local plugin site (Blocksy not active) returned
`check-setup.active: false`, `theme-chrome-get.active: false`, empty
`colors` / `typography` / `header` / `footer` / `writable`, and
`stonewright-blocks-list-registered` with namespace `blocksy` count `0`.
No Blocksy block names were verified. The recipes below use verified
`core/*` blocks from that same registry. When Blocksy is actually active,
re-read chrome and re-list namespaces before writing.

## Namespaces (theme vs plugin vs add-on)

Blocksy the theme is not a block library in `list-blocks`. Companion
plugins (Kadence, GenerateBlocks, Spectra) keep their own prefixes.

| Prefix | Role | Verified on the introspection site |
|---|---|---|
| `blocksy/` (if the theme registers one) | Theme-provided blocks, only when listed | Not registered. Namespace search returned `0`. |
| Any other `blocksy-*` prefix | Add-on packs, if they register their own namespace | None registered. Do not invent widgets. |
| `kadence/`, `generateblocks/`, `uagb/` | Companion plugins, listed only when those libraries are active | All three `list-blocks` counts were `0`. |
| `core/` | WordPress core. Default page-body toolkit on this site. | 116 blocks registered. Recipes below use this set. |

When Blocksy is active, call `stonewright-blocks-list-registered` with
`namespace: "blocksy"` again. If it is still empty, build the body with
core (and any live companion library). Do not invent theme widgets to fill
the gap.

## Pending-change pipeline

The page body is serialized by the live block editor, not by PHP. Queue a
spec, keep the Block Editor Queue tab online, then persist hashed HTML.
Theme chrome is a separate write.

1. `stonewright-blocks-parse` the target post so you know `path`, `position`,
   and the current content hash.
2. Queue one section as `{name, attributes, innerBlocks}` with
   `stonewright-blocks-queue-change`. Required: `post_id`, `block_spec`.
   Pass `action` (`insert` / `update` / `replace`), `path`, `position`, and
   `expected_content_hash` when you have them. Do not hand-write block HTML.
3. Call `stonewright-blocks-finalizer-runtime`. You need `online: true`, a
   `finalizer_url` / `url`, `pending_count`, and per-target
   `editor_frame_url`. `keep_open` is true on purpose.
4. If `online` is false, tell the operator to open **Stonewright → Block
   Editor Queue** and leave that tab open. Do not finalize while the
   heartbeat is dead. `stonewright-blocks-finalizer-url` is the same URL if
   you only need the link.
5. Poll `stonewright-blocks-pending-batch` until the queued ids are
   `serialized`. That list never returns the full spec.
6. Persist with `stonewright-blocks-finalize-batch` (`change_ids`, `post_id`).
   Snapshot, production-safe confirmation, audit, and readback happen here.
7. Verify the front end in a **separate** browser tab at desktop, tablet,
   and mobile. If Blocksy chrome changed, re-read
   `stonewright-theme-chrome-get`.

Header and footer chrome stays on `stonewright-theme-chrome-update`
(dry-run first). The page body never goes through a theme-specific write
path.

One section per batch unless two sections are trivial and tightly coupled.

## `likely_partial` and editor-owned ids

Core blocks on the introspection site were not `likely_partial` (they
declare enough attributes). Companion prefixes are:

- `kadence/`, `generateblocks/`, `uagb/` → always `likely_partial: true`
  in `AttributeValidator`. Finalizer path warns (`likely_partial_schema`)
  and keeps unknown keys. Server path rejects them.
- Unregistered names fail both paths with `block_not_registered` /
  `stonewright_block_not_registered`.

If Blocksy later registers thin JS-backed blocks (editor script present
and fewer than three declared attributes), those also count as partial.
Read `likely_partial` from `stonewright-blocks-get-schema` when the running
plugin includes that field.

The Queue iframe lets the editor assign identity during mount-settle
(unique IDs). Do **not** invent identity values in the queued spec. Omit
identity keys unless a live schema readback on an **update** already has
them.

Do not PHP-serialize third-party Blocksy companion blocks. If a visual
`core/*` block reports `is_dynamic: true` because it has a render callback
(`core/heading`, `core/cover`, `core/image`, `core/button` did on the
introspection site), still queue it. That is not `save:null`.

## Theme chrome

Blocksy chrome keys come from live theme mods (via `blocksy_get_theme_mod`
when that helper exists). Stonewright buckets a key by its name:

- `header*` → `header`
- `footer*` → `footer`
- `font*` / `typography*` → `typography`
- `color*` / `palette*` / `hue*` → `colors`
- anything else is ignored by the adapter

On the introspection site no mods were readable because Blocksy was not
active. There are **no verified token names** to copy into this skill.
When the theme is active:

1. Read `stonewright-theme-chrome-get`.
2. Patch only keys listed in `writable`.
3. `stonewright-theme-chrome-update` with `dry_run: true` first.
4. Apply only the planned keys. Re-read.

Do not invent Customizer keys from Blocksy docs. If a key is not in the
live `writable` list, it is not writable through this adapter.

## Composition recipes

These sketches use verified `core/*` attributes from
`stonewright-blocks-get-schema` on the introspection site. Map colors and
type to Blocksy chrome tokens once `theme-chrome-get` returns them. Keep
the same `{name, attributes, innerBlocks}` shape.

`core/column` parent is `core/columns`. `core/button` parent is
`core/buttons`. Do not insert those children at the root.

### 1. Hero

```json
{
  "name": "core/cover",
  "attributes": {
    "align": "full",
    "dimRatio": 50,
    "minHeight": 560,
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
the live attachment. Do not invent media ids. Prefer Blocksy palette slugs
from chrome / `theme.json` over hardcoded hex.

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
          "attributes": { "text": "Get in touch", "url": "/contact", "tagName": "a" },
          "innerBlocks": []
        }
      ]
    }
  ]
}
```

## Failure modes

| Symptom | Code / signal | What you do |
|---|---|---|
| Name not in the live list | `block_not_registered` / `stonewright_block_not_registered` | Re-run `list-registered` / `list-blocks`. Stop inventing names. |
| Unknown chrome key | `stonewright_unknown_chrome_key` | The key is not in live `writable`. Drop it. Do not invent Customizer names. |
| Theme adapter inactive | `stonewright_theme_inactive` / `check-setup.active` false | This pack does not apply. Switch theme or use `gutenberg-fse-builder`. |
| Queue rejected unknown keys on PHP insert | `unknown_block_attributes` | You used the server path for a partial schema. Re-queue on the finalizer. |
| Warning only, queue accepted | `likely_partial_schema` | Expected for companion `kadence/` / `generateblocks/` / `uagb/` names. Leave the keys. |
| Finalize refused, item still `queued` | `finalizer_not_serialized` (409) | Open Block Editor Queue. Wait for serialize. Retry. |
| Post changed under you | `content_conflict` (409) | Re-parse. Queue again with a fresh `expected_content_hash`. |
| Heartbeat dead | `stonewright-blocks-finalizer-runtime` `online: false` | Do not finalize. Do not PHP-serialize the body. Keep the Queue tab open. |

Do not write the page body through theme chrome. Do not write header/footer
chrome through the block queue.

## Verify

1. Queued names exist in the live registry.
2. Pending batch is empty after finalize.
3. Front end, separate tab: desktop, tablet, mobile. Check overflow.
4. If Blocksy chrome changed, re-read `stonewright-theme-chrome-get` and
   confirm only `writable` keys moved.
