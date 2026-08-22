---
name: acf-build-fields
description: Use when building or editing ACF field groups, custom fields, repeaters, flexible content, options pages, or ACF values on posts.
version_constraints: {"acf": "required"}
---

# ACF Build Fields

Use this when Advanced Custom Fields is active and the task is field groups,
field schema, values on posts, options pages, repeaters, or flexible content.
For ACPT, Meta Box, ASE, or Pods, load `content-model-integrations` instead.

There is no `stonewright-acf-options-page` ability. Do not invent one.

## First call

Call `stonewright-task-start` with surface `acf` (or `wordpress`) and the
task intent. Keep `stonewright_context_token`. Then:

1. `stonewright-site-plugins-list` — confirm ACF is active.
2. `stonewright-acf-field-group-list` — compact group DTOs (`key`, `title`,
   `active`, `location_summary`).
3. `stonewright-wp-cli-status` then `stonewright-wp-cli-discover` with
   `responseMode: "summary"` and `commandFilter: ["acf","cpt","post","option"]`.
   Use only command paths that discover returns.

If list returns `stonewright_plugin_missing`, stop. ACF is not loaded.

## Typed surface (this is the whole set)

| MCP tool | Ability | Job |
|---|---|---|
| `stonewright-acf-field-group-list` | `stonewright/acf-field-group-list` | List groups |
| `stonewright-acf-field-group-get` | `stonewright/acf-field-group-get` | One group + compact fields |
| `stonewright-acf-field-group-save` | `stonewright/acf-field-group-save` | Create/update group schema |
| `stonewright-acf-values-get` | `stonewright/acf-values-get` | All values on a post |
| `stonewright-acf-value-update` | `stonewright/acf-value-update` | One selector on a post |

Related, not ACF-named: `stonewright-cpt-list`, `stonewright-cpt-register`,
`stonewright-taxonomy-register`, `stonewright-content-model-loop-grid-flow`
(experimental Loop Grid path). Production-safe schema writes need
`stonewright-security-issue-confirmation-token`.

## Field-group model

`stonewright-acf-field-group-save` requires `group` with `group.key`. It
calls `acf_import_field_group` when that function exists. Shape the payload
like ACF JSON, not like guessed `post_meta` keys.

Minimum group:

```json
{
  "key": "group_example_team",
  "title": "Team member",
  "active": true,
  "location": [[{ "param": "post_type", "operator": "==", "value": "page" }]],
  "fields": [
    {
      "key": "field_example_role",
      "label": "Role",
      "name": "role",
      "type": "text",
      "required": 0
    }
  ]
}
```

Rules:

- Stable `group_…` / `field_…` keys. Reuse keys on update; do not mint a
  second group for the same job.
- `name` is the value selector. `key` is the schema identity. Nested writes
  prefer field keys when names collide.
- Location rules are arrays of arrays (OR of AND groups). Options screens
  use `param: "options_page"` and the options-page slug ACF already has.
- `stonewright-acf-field-group-get` returns compact field DTOs only:
  `key`, `name`, `label`, `type`, `required`. It **drops** `sub_fields`,
  `layouts`, `min`, `max`, and location. Do not treat that compact get as
  the full schema. For nested types, inspect with `stonewright-php-execute`
  (`acf_get_field_group` + `acf_get_fields`) or re-import the JSON you
  saved.

## Discover → read → write

1. List groups. Pick the `key`.
2. Get the compact DTO. If the type is repeater, group, flexible content,
   or clone, read the full field array in PHP before writing values.
3. For posts: `stonewright-acf-values-get` with `post_id` (integer ≥ 1).
   Optional `format_value` (default true) is `get_fields( $post_id, … )`.
4. Plan the write. Schema goes through `stonewright-acf-field-group-save`
   (`confirmation_token` in production-safe). Values go through
   `stonewright-acf-value-update` (`post_id`, `selector`, `value`). That
   path snapshots the post first.
5. Read back with `stonewright-acf-values-get` or `get_field` via
   `stonewright-php-execute`. Meta-key peeking (`role_0_name`) is not
   completion.

Do not write `_elementor_data` or raw `update_post_meta` for ACF fields
when these typed tools exist.

## Options pages

No typed options-page register/list/update ability.

- Register or inspect with `stonewright-php-execute` calling
  `acf_add_options_page` / `acf_get_options_pages` (official ACF API).
- Location: `options_page` + the slug ACF assigned. Confirm the slug
  before attaching a group.
- **Typed value tools cannot target options.** `acf-values-get` and
  `acf-value-update` require integer `post_id` ≥ 1. They reject `'option'`.
  Read/write options values with `stonewright-php-execute`:
  `get_fields('option')`, `update_field( $selector, $value, 'option' )`.
  Then read back.

## Repeater and flexible content

- Repeaters: `update_field` wants the full rows array, not a single
  `fieldname_0_sub` meta key. Each row is `[ 'sub_name' => value, … ]`.
- Flexible content: every row needs `acf_fc_layout` set to the layout
  `name`. Missing layout keys store garbage or drop the row.
- Formatted `get_fields` (default) returns nested arrays. Unformatted
  (`format_value: false`) returns mixed storage. Pick one and stay
  consistent for the readback.
- Relationship / post object / taxonomy: pass IDs (or ID arrays), not
  titles. Read the field type before writing.
- Clone fields follow the cloned group's names. Confirm with a full
  `acf_get_fields` dump, not the compact get.

## ACF 6.8 notes

6.8 adds site flags and CLI that Stonewright does not wrap as typed
schema writers:

- `wp acf json status|sync|import|export` — only if
  `stonewright-wp-cli-discover` lists those paths. Run them with
  `stonewright-wp-cli-run` argv tokens. If the group is missing, the
  site does not have that command. Do not invent `wp acf field` writers.
- ACF can expose WordPress Abilities when `enable_acf_ai` is on. That
  is ACF's surface, not Stonewright's. Prefer `stonewright-acf-*`.
- JSON-LD / Schema.org field mapping (`enable_schema`) is ACF output,
  not `stonewright-seo-meta-*`. There is no typed Stonewright writer
  for ACF schema property maps.
- Native ACF post types / taxonomies stay in ACF. `stonewright-cpt-register`
  / `stonewright-taxonomy-register` persist CPT UI-style options. Do not
  mix the two stores for the same slug.

Useful docs:
- https://www.advancedcustomfields.com/resources/register-fields-via-php/
- https://www.advancedcustomfields.com/resources/get_field/
- https://www.advancedcustomfields.com/resources/update_field/
- https://www.advancedcustomfields.com/resources/acf_add_options_page/
- https://www.advancedcustomfields.com/resources/repeater/
- https://www.advancedcustomfields.com/resources/flexible-content/
- https://www.advancedcustomfields.com/blog/acf-6-8-release-ai-ready-discoverable-content/

## Loop Grid

For repeated Elementor cards backed by a CPT + ACF, prefer
`stonewright-content-model-loop-grid-flow` over a pile of lower-level
calls. It is experimental. Still list/get the field group after it runs.

## php-execute / WP-CLI

`stonewright-php-execute` is for official ACF API snippets the typed
surface cannot take (options pages, full nested schema, `'option'`
values). Do not replace `acf-field-group-save` with a homemade importer
and do not write ACF tables through `$wpdb`.

Never `wp eval`, `wp eval-file`, `wp shell`, `wp package`, `--exec`, or
`--require`. Tokenized `stonewright-wp-cli-run` only. Long JSON sync:
`stonewright-wp-cli-job-start` then `stonewright-wp-cli-job-status`.
