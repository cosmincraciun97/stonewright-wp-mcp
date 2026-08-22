---
name: kadence-build-page
description: Use when building pages with Kadence Blocks, live kadence/* schemas, and Kadence Theme chrome when that theme is active.
version_constraints: {"kadence-blocks": "required"}
---

# Kadence Build Page

Use this when Kadence Blocks is active and the task is a Gutenberg page, not
an Elementor canvas. Load `gutenberg-fse-builder` for Site Editor chrome,
`theme.json`, and templates. This pack owns Kadence plugin blocks and, when
the Kadence theme is active, theme chrome.

Do not invent `kadence/*` names from memory, marketing, or another site. The
only legal names are those `stonewright-blocks-library-list-blocks` returns
on the target.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "kadence"`. Stop if
   `active` is false. Tell the operator the plugin is missing. Do not queue
   guessed Kadence names.
2. `stonewright-blocks-library-list-blocks` with `library: "kadence"`. Keep
   only `kadence/*` names. That prefix is the plugin namespace this ability
   filters. Count `0` means the plugin is not registering blocks here.
3. `stonewright-blocks-library-get-schema` for every name you will insert.
   Queue only keys that schema returns. Never invent controls.
4. If the active theme is Kadence, call `stonewright-theme-chrome-get` with
   `theme: "kadence"`. Reuse live `colors`, `typography`, `header`, and
   `footer` tokens. If `active` is false, skip chrome writes.

Introspection on a local plugin site (Kadence Blocks absent) returned
`active: false`, `version: ""`, `status: "unavailable"`,
and `list-blocks` count `0`. No `kadence/*` name was registered. The recipes
below therefore use verified `core/*` blocks from that same registry. When
Kadence is actually active, replace `core/group` and `core/columns` with live
`kadence/*` containers from the list — after reading each schema. Do not keep
the core fallback once a library container exists for that job.

## Namespaces (plugin vs add-on)

`stonewright-blocks-library-list-blocks` only returns the `kadence/` prefix.
Additional `kadence-*` prefixes appear only if some other pack registered
them. Do not invent those names.

| Prefix | Role | Verified on the introspection site |
|---|---|---|
| `kadence/` | Kadence Blocks plugin. This is what `list-blocks` returns. | Not registered. Count `0`. `check-setup.active` is false. |
| Any other `kadence-*` prefix | Add-on packs, if they register their own namespace | None registered. Do not invent widgets from docs. |
| `core/` | WordPress core. Use until Kadence containers are live. | 116 blocks registered. Recipes below use this set. |

Re-run list-blocks on the target before every build. A table from another
session is stale the moment plugins change.

## Pending-change pipeline

Kadence (and every other static / third-party block) is serialized by the
live block editor, not by PHP. Queue a spec, keep the Block Editor Queue
tab online, then persist hashed HTML.

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
   Editor Queue** and leave that tab open. The page copy is: keep it open
   while an agent session runs. Do not finalize while the heartbeat is dead.
   `stonewright-blocks-finalizer-url` is the same URL if you only need the
   link.
5. Poll `stonewright-blocks-pending-batch` until the queued ids are
   `serialized`. That list is compact on purpose — it never returns the full
   spec.
6. Persist with `stonewright-blocks-finalize-batch` (`change_ids`, `post_id`).
   That path snapshots, confirms in production-safe, audits, and readbacks.
7. Verify the front end in a **separate** browser tab at desktop, tablet,
   and mobile. Do not resize the editor or the Queue tab to fake a
   breakpoint.

One section per batch unless two sections are trivial and tightly coupled.
Never parallelize Kadence writes.

## `likely_partial` and editor-owned ids

Kadence server `block.json` is treated as partial. In
`AttributeValidator`, any `kadence/` name is `likely_partial: true`.

What that means:

- On the **finalizer** path (`stonewright-blocks-queue-change`), unknown
  keys become a `likely_partial_schema` warning. They are **not** stripped
  and they are **not** a hard reject. The live editor owns those keys.
- On the **server** path (`blocks-insert` / PHP serialize), the same unknown
  keys are `unknown_block_attributes`. Do not send Kadence through PHP.
- Unregistered names fail both paths with `block_not_registered` /
  `stonewright_block_not_registered`. Re-list. Do not guess.

The editor assigns identity during mount-settle in the Queue iframe
(unique IDs such as `uniqueID` when that library uses them). Do **not**
invent identity values in the queued spec. Omit identity keys unless a live
schema readback on an **update** already has them. After serialize, the
saved markup should contain whatever the editor assigned. If it does not,
the round-trip failed — do not persist by hand.

`stonewright-blocks-library-get-schema` exposes `likely_partial` once the
running plugin includes that field. If the field is missing, still treat
every `kadence/` name as partial and stay on the finalizer.

## Theme chrome

Kadence **theme** chrome is a different adapter from Kadence **Blocks**.

- `stonewright-theme-chrome-get` with `theme: "kadence"` returns `active`,
  `version`, `colors`, `typography`, `header`, `footer`, and `writable`.
- On the introspection site the Kadence theme was not active:
  `active: false`, empty buckets, empty `writable`. There are no verified
  chrome tokens to copy. Do not invent Customizer keys.
- When the theme is active, patch only keys listed in `writable`. Call
  `stonewright-theme-chrome-update` with `dry_run: true` first. Apply only
  after the plan lists those exact keys.
- Header and footer chrome never goes through the block queue. Page body
  never goes through theme chrome.

## Composition recipes

These sketches use verified `core/*` attributes from
`stonewright-blocks-get-schema` on the introspection site. They are the
fallback while `kadence/` is empty. When Kadence is live, swap the outer
`core/group` / `core/columns` for registered `kadence/*` containers after a
schema read. Keep the same `{name, attributes, innerBlocks}` shape.

`core/column` parent is `core/columns`. `core/button` parent is
`core/buttons`. Do not insert those children at the root.

On this WordPress, `core/heading`, `core/cover`, `core/image`, and
`core/button` reported `is_dynamic: true` because they have render
callbacks. That is **not** `save:null`. Still queue them. Do not PHP-serialize
a visual section.

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
          "attributes": { "text": "Book a call", "url": "/contact", "tagName": "a" },
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
| Warning only, queue accepted | `likely_partial_schema` | Expected for `kadence/`. Leave the keys. Let the editor save. |
| Finalize refused, item still `queued` | `finalizer_not_serialized` (409) | Queue tab is offline or the iframe never serialized. Open Block Editor Queue. Wait. Retry. |
| Post changed under you | `content_conflict` (409) | Re-parse. Queue again with a fresh `expected_content_hash`. |
| Heartbeat dead | `stonewright-blocks-finalizer-runtime` `online: false` | Do not finalize. Do not PHP-serialize Kadence. Ask the operator to keep the Queue tab open. |
| Hash mismatch | `finalizer_hash_mismatch` | Discard that serialized blob. Re-queue. Never persist unverified HTML. |
| `check-setup.active` false | `status: unavailable` | This pack does not apply. Use `gutenberg-fse-builder` with core blocks, or install Kadence Blocks. |

Do not work around a dead queue with `php-execute`, raw REST, or hand-written
markup. Do not strip unknown Kadence keys to silence a warning.

## Verify

1. Every queued name is in the live Kadence list — or, while the list is
   empty, in `stonewright-blocks-list-registered`.
2. Pending batch is empty after finalize.
3. Front end, separate tab: desktop, tablet, mobile. Check overflow.
4. If you patched Kadence theme chrome, re-read `stonewright-theme-chrome-get`
   and confirm only `writable` keys moved.
