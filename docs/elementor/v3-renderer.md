# Elementor V3 Renderer — Architecture

This document describes the Stonewright Design Spec → Elementor V3 write pipeline,
its control steps, and how cache clearing works.

## Overview

The V3 renderer converts a validated [Stonewright Design Spec](../design-spec.md)
into an Elementor-compatible `_elementor_data` JSON array and persists it to a
WordPress post. The pipeline is orchestrated by `ElementorWriter::write()`.

## Pipeline diagram

```
Agent / Ability
      │
      ▼
  Ability::execute()
      │
      ├─ 1. permission_callback()   ← Permissions::edit_post() (current_user_can edit_post)
      │
      ├─ 2. Backup::snapshot_post() ← post-meta snapshot (+ revision); ABORTS on failure
      │
      ├─ 3. Validator::validate()   ← JSON Schema check; returns WP_Error on invalid spec
      │
      ├─ 4. Renderer::render()      ← routes spec nodes to per-widget handler classes
      │      │
      │      ├─ Section   (layout shell)
      │      ├─ Column    (inner layout)
      │      ├─ Container (flexbox wrapper)
      │      ├─ Heading / Paragraph
      │      ├─ Image, Video, Button, Spacer, Divider
      │      ├─ Icon, IconBox, ImageBox
      │      ├─ Testimonial, Tabs, Accordion, Toggle
      │      ├─ SocialIcons, ProgressBar, Counter
      │      ├─ TextEditor (list fallback, embed)
      │      └─ Form / Slides → ProGate → diagnostic (Pro required)
      │
      ├─ 5. ElementorWriter writes:
      │      ├─ _elementor_data   (JSON-encoded element array)
      │      ├─ _elementor_edit_mode = 'builder'
      │      └─ _elementor_version = ELEMENTOR_VERSION constant
      │
      ├─ 6. Post-only HTML/object cache invalidation (CSS untouched)
      │
      └─ 7. AuditLog::record()
```

## Source files

| File | Role |
|---|---|
| `plugin/includes/Elementor/ElementorWriter.php` | Orchestrator — owns Steps 1–7 |
| `plugin/includes/Elementor/Renderer.php` | Dispatch switch — routes `type` to handler |
| `plugin/includes/Elementor/Renderer/*.php` | Per-widget handler classes |
| `plugin/includes/Abilities/ElementorV3/BuildPageFromSpec.php` | Ability that calls `ElementorWriter::write()` |
| `plugin/includes/DesignSpec/Validator.php` | JSON Schema validator (Step 3) |
| `plugin/includes/Security/Backup.php` | Snapshot helper (Step 2) |

## Step details

### Step 2 — Backup::snapshot_post()

Runs inside the transaction runner, after Steps 3 and 4 and just before the tree is
written. Saves a post-meta snapshot keyed by a UUID snapshot ID, plus a WordPress
revision when the post type supports revisions. If the snapshot fails, the write is
**aborted** and the ability returns `stonewright_transaction_snapshot_failed`. A post
that does not exist returns `stonewright_backup_failed` before validation. The post is
never mutated without a successful snapshot.

### Step 3 — Validator::validate()

Validates the raw spec array against the DesignSpec JSON Schema
(`plugin/schemas/stonewright.schema.json`). Returns the normalized spec on success,
or a `WP_Error` with code `stonewright_spec_invalid` on failure. The renderer never
receives an invalid spec.

### Step 4 — Renderer::render()

Routes each `spec.sections[].blocks[]` node by its `type` field to the appropriate
handler class. Handler classes live in `plugin/includes/Elementor/Renderer/`.

All element IDs are deterministic: `substr( sha1( canonical_key_path ), 0, 7 )`.
The same spec always produces the same element IDs, making diff-based updates
possible.

For unsupported types the renderer appends a diagnostic object and continues rendering
the rest of the spec. Pro-gated types (`form`, `slides`) produce a distinct
`elementor_pro_required` code.

### Step 5 — Post meta writes

Three post-meta keys are written:

| Meta key | Value |
|---|---|
| `_elementor_data` | JSON-encoded element array (`wp_slash()` applied) |
| `_elementor_edit_mode` | `'builder'` |
| `_elementor_version` | Current `ELEMENTOR_VERSION` constant or `'3.0.0'` fallback |

### Step 6 — Cache and CSS closure

The write removes only Elementor's target-post HTML cache key and cleans the
WordPress post cache. It preserves `_elementor_css` and never calls Elementor's
site-wide files-manager clear. After the typed write, call
`stonewright-elementor-css-regenerate` when generated CSS must be rebuilt, then
`stonewright-elementor-post-write-verify`. The regenerator updates only the
resolved target through Elementor's official `update()` API inside a
bounded asset transaction, moves the stylesheet version (`?ver=`) forward, and purges that post in the page cache plugins it finds (`cache_purge` in the answer). The verifier is observation-only and never
regenerates CSS. Never pass `regenerate_css`.

### Step 7 — Audit log

`AuditLog::record()` appends an entry to `stonewright_audit_log` with the post ID
and a SHA-1 prefix of the spec for traceability.

## Confirmation token (production-safe mode)

`BuildPageFromSpec` uses the `ConfirmationGuard` trait. When
`stonewright_mode = production-safe`, every call that is not a dry run requires a
valid `confirmation_token` bound to the call's arguments before it reaches Step 2,
whatever its `mode` (`replace`, `append` or `replace_section`). A call with
`dry_run: true` writes nothing and needs no token. See
[`docs/security-guarantees.md`](../security-guarantees.md) for the token flow.

## Diagnostics response shape

```json
{
  "post_id": 42,
  "snapshot_id": "abc123",
  "diagnostics": [
    {
      "code": "elementor_pro_required",
      "type": "form",
      "path": "s0.b2",
      "renderer": "elementor_v3",
      "message": "The Elementor Form widget requires Elementor Pro."
    }
  ]
}
```

## Tests

Primary test file: `plugin/tests/Integration/ElementorWriterTest.php`

The integration test suite covers:
- Abort with `stonewright_backup_failed` when the post does not exist.
- Validator rejection with `stonewright_spec_invalid`.
- Snapshot and write round-trip for a valid spec.
- Per-widget render snapshots (one per supported type).
- Diagnostic output for unsupported and Pro-gated nodes.
