---
name: spectra-build-page
description: Use when building pages with Spectra (uagb/*) blocks, live schemas, and the Gutenberg finalizer.
version_constraints: {"spectra": "required"}
---

# Spectra Build Page

Use this when Spectra is active and the task is a Gutenberg page. For the
Spectra One block theme itself, follow `gutenberg-fse-builder` instead of
duplicating Full Site Editing steps here. If both the theme and the `uagb/*`
plugin are active, keep FSE chrome on `gutenberg-fse-builder` and plugin
blocks here.

Do not invent `uagb/*` names. The only legal names are those
`stonewright-blocks-library-list-blocks` returns on the target.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "spectra"`. Stop
   if `active` is false. Do not queue guessed Spectra names.
2. `stonewright-blocks-library-list-blocks` with `library: "spectra"` and
   keep only `uagb/*` names. That prefix is what the ability filters. Count
   `0` means Spectra is not registering blocks here.
3. `stonewright-blocks-library-get-schema` for each block you will insert.
   Use registered attributes only.

If the active theme is Blocksy, Kadence, or GeneratePress, read tokens with
`stonewright-theme-chrome-get`. Otherwise use `stonewright-fse-get-theme-json`.
On the introspection site none of those theme adapters were active.

Introspection on a local plugin site (Spectra absent) returned
`active: false`, `version: ""`, `status: "unavailable"`, and `list-blocks`
count `0`. No `uagb/*` name was registered. The recipes below use verified
`core/*` blocks from that same registry. When Spectra is actually active,
replace `core/group` and `core/columns` with live `uagb/*` containers from
the list — after reading each schema.

## Namespaces (plugin vs add-on)

`list-blocks` only returns `uagb/`. Additional Spectra-related prefixes
appear only if another pack registered them. Do not invent those names.

| Prefix | Role | Verified on the introspection site |
|---|---|---|
| `uagb/` | Spectra plugin. This is what `list-blocks` returns. | Not registered. Count `0`. `check-setup.active` is false. |
| Any other Spectra-related prefix | Add-on packs, if they register their own namespace | None registered. Do not invent widgets. |
| `core/` | WordPress core. Use until Spectra containers are live. | 116 blocks registered. Recipes below use this set. |

Re-run list-blocks on the target before every build. Spectra One is a
**theme**. It does not register `uagb/*`. Do not treat a Spectra One site
as "Spectra plugin is active" unless `check-setup` says so.

## Pending-change pipeline

Spectra is serialized by the live block editor, not by PHP. Queue a spec,
keep the Block Editor Queue tab online, then persist hashed HTML.

1. `stonewright-blocks-parse` the target post so you know `path`, `position`,
   and the current content hash.
2. Queue one section as `{name, attributes, innerBlocks}` with
   `stonewright-blocks-queue-change`. Required: `post_id`, `block_spec`.
   Pass `action` (`insert` / `update` / `replace`), `path`, `position`, and
   `expected_content_hash` when you have them. Do not bypass the finalizer
   with raw markup.
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
   and mobile. Do not resize the editor or the Queue tab to fake a
   breakpoint.

One section per batch unless two sections are trivial and tightly coupled.
Never parallelize Spectra writes.

## `likely_partial` and editor-owned ids

Spectra server `block.json` is treated as partial. In
`AttributeValidator`, any `uagb/` name is `likely_partial: true`.

What that means:

- On the **finalizer** path (`stonewright-blocks-queue-change`), unknown
  keys become a `likely_partial_schema` warning. They are not stripped and
  they are not a hard reject. The live editor owns those keys.
- On the **server** path (`blocks-insert` / PHP serialize), the same unknown
  keys are `unknown_block_attributes`. Do not send Spectra through PHP.
- Unregistered names fail both paths with `block_not_registered` /
  `stonewright_block_not_registered`. Re-list. Do not guess.

The Queue iframe lets the editor assign identity during mount-settle
(unique IDs). Do **not** invent identity values in the queued spec. Omit
identity keys unless a live schema readback on an **update** already has
them. After serialize, saved markup should contain whatever the editor
assigned. If it does not, the round-trip failed — do not persist by hand.

`stonewright-blocks-library-get-schema` exposes `likely_partial` once the
running plugin includes that field. If the field is missing, still treat
every `uagb/` name as partial and stay on the finalizer.

## Theme chrome

Spectra the plugin has no `stonewright-theme-chrome-get` adapter. Chrome
comes from the **active theme**:

- Blocksy / Kadence / GeneratePress: `stonewright-theme-chrome-get` with
  that `theme` id. Patch only `writable` keys. Dry-run first.
- Spectra One or any block theme: `stonewright-fse-get-theme-json` and
  `gutenberg-fse-builder`. Do not invent a parallel Spectra One playbook.
- On the introspection site Blocksy, Kadence, and GeneratePress all
  returned `active: false` with empty buckets. There are no verified chrome
  tokens to copy.

Page body still stays on the finalizer.

## Composition recipes

These sketches use verified `core/*` attributes from
`stonewright-blocks-get-schema` on the introspection site. They are the
fallback while `uagb/` is empty. When Spectra is live, swap the outer
`core/group` / `core/columns` for registered `uagb/*` containers after a
schema read. Keep the same `{name, attributes, innerBlocks}` shape.

`core/column` parent is `core/columns`. `core/button` parent is
`core/buttons`. Do not insert those children at the root.

On this WordPress, `core/heading`, `core/cover`, `core/image`, and
`core/button` reported `is_dynamic: true` because they have render
callbacks. That is **not** `save:null`. Still queue them.

### 1. Hero

```json
{
  "name": "core/cover",
  "attributes": {
    "align": "full",
    "dimRatio": 50,
    "minHeight": 500,
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
          "attributes": { "text": "Talk to us", "url": "/contact", "tagName": "a" },
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
| Name not in the live list | `block_not_registered` / `stonewright_block_not_registered` | Re-run `list-blocks`. Stop inventing names. |
| Queue rejected unknown keys on PHP insert | `unknown_block_attributes` | You used the server path. Re-queue on the finalizer. |
| Warning only, queue accepted | `likely_partial_schema` | Expected for `uagb/`. Leave the keys. Let the editor save. |
| Finalize refused, item still `queued` | `finalizer_not_serialized` (409) | Queue tab is offline or the iframe never serialized. Open Block Editor Queue. Wait. Retry. |
| Post changed under you | `content_conflict` (409) | Re-parse. Queue again with a fresh `expected_content_hash`. |
| Heartbeat dead | `stonewright-blocks-finalizer-runtime` `online: false` | Do not finalize. Do not PHP-serialize Spectra. Ask the operator to keep the Queue tab open. |
| Hash mismatch | `finalizer_hash_mismatch` | Discard that serialized blob. Re-queue. Never persist unverified HTML. |
| `check-setup.active` false | `status: unavailable` | This pack does not apply. Use `gutenberg-fse-builder` with core blocks, or install Spectra. |

Do not work around a dead queue with `php-execute`, raw REST, or hand-written
markup. Do not strip unknown Spectra keys to silence a warning. Do not
create a `spectra-one` skill pack.

## Verify

1. Every queued `uagb/*` name is in the live Spectra list — or, while the
   list is empty, every queued name is in `stonewright-blocks-list-registered`.
2. Pending batch is empty after finalize.
3. Front end, separate tab: desktop, tablet, mobile. Check overflow.
4. If the theme adapter was used, re-read chrome or `theme.json` and confirm
   only live keys moved.
