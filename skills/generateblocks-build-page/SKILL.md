---
name: generateblocks-build-page
description: Use when building pages with GenerateBlocks, live generateblocks/* schemas, and GeneratePress chrome when that theme is active.
version_constraints: {"generateblocks": "required"}
---

# GenerateBlocks Build Page

Use this when GenerateBlocks is active and the task is a Gutenberg page.
Load `gutenberg-fse-builder` for Site Editor chrome, `theme.json`, and
templates. This pack owns `generateblocks/*` plugin blocks and, when
GeneratePress is active, theme chrome.

Do not invent `generateblocks/*` names. The only legal names are those
`stonewright-blocks-library-list-blocks` returns on the target.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "generateblocks"`.
   Stop if `active` is false. Do not queue guessed GenerateBlocks names.
2. `stonewright-blocks-library-list-blocks` with `library: "generateblocks"`.
   That ability filters the `generateblocks/` prefix. Count `0` means the
   plugin is not registering blocks here.
3. `stonewright-blocks-library-get-schema` for every `generateblocks/*` name
   you will insert. Keep the live attribute set. Do not add undocumented keys.
4. If GeneratePress is active, call `stonewright-theme-chrome-get` with
   `theme: "generatepress"` and map spacing, color, and type from those
   tokens. If `active` is false, skip chrome writes.

Introspection on a local plugin site (GenerateBlocks absent) returned
`active: false`, `version: ""`, `status: "unavailable"`, and `list-blocks`
count `0`. No `generateblocks/*` name was registered. The recipes below use
verified `core/*` blocks from that same registry. When GenerateBlocks is
actually active, prefer live `generateblocks/*` containers and grid over
`core/group` and `core/columns` — after reading each schema.

## Namespaces (plugin vs add-on)

`list-blocks` only returns `generateblocks/`. Additional `generateblocks-*`
prefixes appear only if another pack registered them. Do not invent those
names, and do not copy widget lists from marketing.

| Prefix | Role | Verified on the introspection site |
|---|---|---|
| `generateblocks/` | GenerateBlocks plugin. This is what `list-blocks` returns. | Not registered. Count `0`. `check-setup.active` is false. |
| Any other `generateblocks-*` prefix | Add-on packs, if they register their own namespace | None registered. Do not invent widgets. |
| `core/` | WordPress core. Use until GenerateBlocks containers are live. | 116 blocks registered. Recipes below use this set. |

Re-run list-blocks on the target before every build.

## Pending-change pipeline

GenerateBlocks is serialized by the live block editor, not by PHP. Queue a
spec, keep the Block Editor Queue tab online, then persist hashed HTML.

1. `stonewright-blocks-parse` the target post so you know `path`, `position`,
   and the current content hash.
2. Queue one section as `{name, attributes, innerBlocks}` with
   `stonewright-blocks-queue-change`. Required: `post_id`, `block_spec`.
   Pass `action` (`insert` / `update` / `replace`), `path`, `position`, and
   `expected_content_hash` when you have them. Do not serialize markup by
   hand.
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
Never parallelize GenerateBlocks writes.

## `likely_partial` and editor-owned ids

GenerateBlocks server `block.json` is treated as partial. In
`AttributeValidator`, any `generateblocks/` name is `likely_partial: true`.

What that means:

- On the **finalizer** path (`stonewright-blocks-queue-change`), unknown
  keys become a `likely_partial_schema` warning. They are not stripped and
  they are not a hard reject. The live editor owns those keys.
- On the **server** path (`blocks-insert` / PHP serialize), the same unknown
  keys are `unknown_block_attributes`. Do not send GenerateBlocks through
  PHP.
- Unregistered names fail both paths with `block_not_registered` /
  `stonewright_block_not_registered`. Re-list. Do not guess.

The Queue iframe lets the editor assign identity during mount-settle
(unique IDs). Do **not** invent identity values in the queued spec. Omit
identity keys unless a live schema readback on an **update** already has
them. After serialize, saved markup should contain whatever the editor
assigned. If it does not, the round-trip failed — do not persist by hand.

`stonewright-blocks-library-get-schema` exposes `likely_partial` once the
running plugin includes that field. If the field is missing, still treat
every `generateblocks/` name as partial and stay on the finalizer.

## Theme chrome

GeneratePress chrome is a different adapter from GenerateBlocks.

- `stonewright-theme-chrome-get` with `theme: "generatepress"` returns
  `active`, `version`, `colors`, `typography`, `header`, `footer`, and
  `writable`. GeneratePress stores settings in the `generate_settings`
  option when that theme is active.
- On the introspection site GeneratePress was not active: `active: false`,
  empty buckets, empty `writable`. There are no verified chrome tokens to
  copy. Do not invent Customizer keys.
- When the theme is active, patch only keys listed in `writable`. Call
  `stonewright-theme-chrome-update` with `dry_run: true` first. Apply only
  after the plan lists those exact keys.
- Header and footer chrome never goes through the block queue. Page body
  never goes through theme chrome.

## Composition recipes

These sketches use verified `core/*` attributes from
`stonewright-blocks-get-schema` on the introspection site. They are the
fallback while `generateblocks/` is empty. When GenerateBlocks is live, swap
the outer `core/group` / `core/columns` for registered `generateblocks/*`
containers after a schema read. Keep the same `{name, attributes, innerBlocks}`
shape.

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
    "dimRatio": 55,
    "minHeight": 480,
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

Prefer a live GenerateBlocks grid here once `list-blocks` includes one.
Until then, `core/columns` is the verified layout.

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

## Failure modes

| Symptom | Code / signal | What you do |
|---|---|---|
| Name not in the live list | `block_not_registered` / `stonewright_block_not_registered` | Re-run `list-blocks`. Stop inventing names. |
| Queue rejected unknown keys on PHP insert | `unknown_block_attributes` | You used the server path. Re-queue on the finalizer. |
| Warning only, queue accepted | `likely_partial_schema` | Expected for `generateblocks/`. Leave the keys. Let the editor save. |
| Finalize refused, item still `queued` | `finalizer_not_serialized` (409) | Queue tab is offline or the iframe never serialized. Open Block Editor Queue. Wait. Retry. |
| Post changed under you | `content_conflict` (409) | Re-parse. Queue again with a fresh `expected_content_hash`. |
| Heartbeat dead | `stonewright-blocks-finalizer-runtime` `online: false` | Do not finalize. Do not PHP-serialize GenerateBlocks. Ask the operator to keep the Queue tab open. |
| Hash mismatch | `finalizer_hash_mismatch` | Discard that serialized blob. Re-queue. Never persist unverified HTML. |
| `check-setup.active` false | `status: unavailable` | This pack does not apply. Use `gutenberg-fse-builder` with core blocks, or install GenerateBlocks. |

Do not work around a dead queue with `php-execute`, raw REST, or hand-written
markup. Do not strip unknown GenerateBlocks keys to silence a warning.

## Verify

1. Every queued name is in the live GenerateBlocks list — or, while the list
   is empty, in `stonewright-blocks-list-registered`.
2. Pending batch is empty after finalize.
3. Front end, separate tab: desktop, tablet, mobile. Check overflow.
4. If you patched GeneratePress chrome, re-read
   `stonewright-theme-chrome-get` and confirm only `writable` keys moved.
