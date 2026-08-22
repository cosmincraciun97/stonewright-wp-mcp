---
name: forms-inventory
description: Use when inventorying contact forms, discovering form shortcodes or blocks, or embedding an existing form. Read and embed only.
version_constraints: {"any_of": "wpforms|contact-form-7|gravity-forms|fluent-forms|ninja-forms|formidable-forms"}
---

# Forms Inventory

Use this when a form plugin is active and the task is **list what exists**,
**find the embed tag or block**, or **place that embed on a page**.

Stonewright has no typed form-builder writes and must not fake them through php-execute.
No create-form, update-fields, notifications, confirmations,
entries, or routing through `$wpdb`, REST guesses, or `wp eval`.

## First call

Call `stonewright-task-start` with surface `wordpress` and intent `read`
(embed jobs still start as read, then a Gutenberg/Elementor write of the
embed only). Then:

1. `stonewright-site-plugins-list` — which form plugin is actually active.
2. `stonewright-wp-cli-status` then `stonewright-wp-cli-discover` with
   `responseMode: "summary"` and `commandFilter` such as
   `["wpforms","cf7","gravityform","fluentform","ninja","formidable","form"]`.
   Keep only paths discover returns. Many form CLIs are list-only; treat
   unknown write commands as out of scope.
3. `stonewright-site-shortcodes-discover` with `search` like `"form"` or
   `"contact"`. Optional `include_callbacks: true`. This lists registered
   tags without executing handlers.

## What you may read

Inventory is plugin posts / official list APIs, **read-only**:

- `stonewright-php-execute` may call documented list APIs (examples:
  `WPCF7_ContactForm::find()`, Gravity `GFAPI::get_forms()` when that
  class exists). Return id, title, and embed hint. Do not insert, update,
  or delete rows.
- `stonewright-wp-cli-run` only for discover-listed **list** commands.
- Gutenberg: `stonewright-blocks-list-registered` then
  `stonewright-blocks-get-schema` for a form block **name that listed**.
  Do not invent `wpforms/form-selector` or similar from memory.
- Elementor form **widgets** on a post: `stonewright-form-delivery-diagnostic`
  with `post_id` (optional `widget_id`). Read-only. It inspects Elementor
  `form` widgets (mail action shape, hashed recipient domain, SMTP plugin
  presence). It does not send mail and it does not cover CF7/WPForms
  builders.

If the list API is missing, say so. Do not scrape `wp_posts` with handmade
SQL for a "close enough" form table.

## Embed only (shortcode or block)

You may place an **existing** form on a page. You may not edit the form.

### Shortcode embed

1. Confirm the tag with `stonewright-site-shortcodes-discover`. Common
   tags (verify; do not assume): `contact-form-7`, `wpforms`,
   `gravityform`, `fluentform`, `ninja_form`, `formidable`. Attributes
   (`id`, `title`) come from the inventory, not from a guessed number.
2. Parse the target with `stonewright-blocks-parse`.
3. Queue `core/shortcode` (or the live form block name if registered)
   as `{name, attributes, innerBlocks}` via
   `stonewright-blocks-queue-change`. For `core/shortcode` the attribute
   is `text` holding the shortcode string the plugin documents.
4. `stonewright-blocks-finalizer-runtime` — need the Block Editor Queue
   tab online. Then `stonewright-blocks-pending-batch` until serialized.
5. Persist with `stonewright-blocks-finalize-batch`.

Do not `stonewright-blocks-insert` a static third-party form block and
call it done. Queue → finalize.

### Block embed

If `stonewright-blocks-list-registered` returns a plugin form block,
`stonewright-blocks-get-schema` for that exact name, queue only keys the
schema returns, then the same finalizer path.

### Elementor canvas

There is no typed "insert CF7 widget" ability. If the live Elementor
widget list includes a form or shortcode widget, use the Elementor v3
typed write path from `elementor-v3-builder` (schema → batch-mutate),
not php-execute. `stonewright-form-delivery-diagnostic` is diagnosis
after an Elementor form already exists.

## Forbidden

- Creating or cloning forms.
- Adding/removing fields, choices, pages, or layout in the form plugin.
- Editing notifications, confirmations, webhooks, or payment feeds.
- Marking entries read, spam, or deleted.
- `stonewright-php-execute` that calls `GFAPI::add_form`,
  `wpforms()->form->add`, `$wpdb->insert` on form tables, or equivalent.
- Inventing `stonewright-forms-save` / `stonewright-cf7-update`.

If the operator wants a new form or field changes, stop and tell them
to build it in the plugin UI (or supply an existing form id). Then you
can embed.

## Delivery vs builder

Mail not arriving on an **Elementor** form: `stonewright-form-delivery-diagnostic`.
Mail not arriving on CF7 / WPForms / Gravity: inventory + the plugin's
own screens. There is no typed SMTP writer. Do not dump credentials.
