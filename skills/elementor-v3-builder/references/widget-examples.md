# Elementor V3 write examples

New writes use the live schema and one batch. Per-widget `elementor-add-*`
abilities are deprecated.

## 1. Read structure and schemas

```json
{ "ability": "stonewright/elementor-v3-get-page-structure", "args": { "post_id": 42 } }
```

```json
{ "ability": "stonewright/elementor-schema", "args": { "mode": "summary", "widget_type": "heading" } }
```

Repeat schema lookup for `text-editor`, `image`, and `button`. Keep each
returned `schema_hash`; never invent a setting.

## 2. Dry-run a native section batch

```json
{
  "ability": "stonewright/elementor-v3-batch-mutate",
  "args": {
    "post_id": 42,
    "dry_run": true,
    "require_evidence": true,
    "expected_tree_hash": "<hash from page structure/readback>",
    "operations": [
      {
        "action": "add_container",
        "op_id": "hero",
        "parent_id": "<existing-parent-id>",
        "settings": {},
        "settings_evidence": {}
      },
      {
        "action": "add_widget",
        "op_id": "title",
        "parent_ref": "hero",
        "widget_type": "heading",
        "settings": { "title": "Build faster.", "header_size": "h1" },
        "settings_evidence": {
          "title": {
            "schema_hash": "<heading-schema-hash>",
            "source": "figma:node/hero-title",
            "confidence": 0.99,
            "responsive_scope": "desktop",
            "requires_confirmation": false
          },
          "header_size": {
            "schema_hash": "<heading-schema-hash>",
            "source": "design-semantic:h1",
            "confidence": 1,
            "responsive_scope": "all",
            "requires_confirmation": false
          }
        }
      },
      {
        "action": "add_widget",
        "op_id": "cta",
        "parent_ref": "hero",
        "widget_type": "button",
        "settings": {
          "text": "Get started",
          "link": { "url": "/contact", "is_external": false }
        },
        "settings_evidence": {
          "text": {
            "schema_hash": "<button-schema-hash>",
            "source": "figma:node/hero-cta-label",
            "confidence": 1,
            "responsive_scope": "all",
            "requires_confirmation": false
          },
          "link": {
            "schema_hash": "<button-schema-hash>",
            "source": "user-requirement:/contact",
            "confidence": 1,
            "responsive_scope": "all",
            "requires_confirmation": false
          }
        }
      }
    ]
  }
}
```

Buttons and CTA widgets without `link` or another live action control are an
error, not a static decoration.

## 3. Apply once

Reuse the validated operations with `dry_run: false` and add a unique
`idempotency_key`. Keep the same `expected_tree_hash` only if the page did not
change after dry-run.

Send the exact validated operations array from the dry-run again, changing only
`dry_run` to `false` and adding a unique `idempotency_key` such as
`home-hero-v1-20260714`. Keep the same `expected_tree_hash` only if the page
did not change after dry-run.

Success requires `after_hash === readback_hash`. Repeating the identical
request returns `idempotent_replay: true` without creating duplicates. Reusing
the key with changed input is rejected.

## 4. Visual editor path

When Stonewright Visual is connected, call nested page tools through the single
`stonewright-workspace-request` gateway:

- `get_page_structure`, `list_widgets`, `get_widget_schema`;
- `create_element`, `update_settings`, `move_element`, `delete_element`;
- `batch_call` for refs and rollback;
- `undo`, `redo`, then `save` outside a batch.

Every editor mutation performs immediate model readback. `save` verifies the
editor is no longer dirty.
