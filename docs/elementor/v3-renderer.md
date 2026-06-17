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
      ├─ 1. permission_callback()   ← Permissions::edit_post() + Permissions::manage_options()
      │
      ├─ 2. Backup::snapshot_post() ← creates a wp_post revision; ABORTS on failure
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
      ├─ 6. Cache clear: Plugin::$instance->files_manager->clear_cache()
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

Called before any mutation. Creates a `wp_post` revision keyed by a UUID snapshot ID.
If the backup fails (e.g. `wp_insert_post` returns a `WP_Error`), the write is
**aborted** and the ability returns a `stonewright_backup_failed` error. The post is
never mutated without a successful snapshot.

### Step 3 — Validator::validate()

Validates the raw spec array against the DesignSpec JSON Schema
(`plugin/schemas/design-spec.schema.json`). Returns the normalized spec on success,
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
`unsupported_node_pro_required` code.

### Step 5 — Post meta writes

Three post-meta keys are written:

| Meta key | Value |
|---|---|
| `_elementor_data` | JSON-encoded element array (`wp_slash()` applied) |
| `_elementor_edit_mode` | `'builder'` |
| `_elementor_version` | Current `ELEMENTOR_VERSION` constant or `'3.0.0'` fallback |

### Step 6 — Cache clear

`\Elementor\Plugin::$instance->files_manager->clear_cache()` is called after the meta
writes so Elementor regenerates its CSS files on the next page load. The call is
wrapped in a try/catch — failure is silently ignored because the cache layer is
optional (non-fatal, and absent in test environments).

### Step 7 — Audit log

`AuditLog::record()` appends an entry to `stonewright_audit_log` with the post ID
and a SHA-1 prefix of the spec for traceability.

## Confirmation token (production-safe mode)

`BuildPageFromSpec` uses the `ConfirmationGuard` trait. When
`stonewright_mode = production-safe`, the ability requires a valid
`confirmation_token` before reaching Step 2. See
[`docs/security-guarantees.md`](../security-guarantees.md) for the token flow.

## Diagnostics response shape

```json
{
  "post_id": 42,
  "snapshot_id": "abc123",
  "diagnostics": [
    {
      "code": "unsupported_node_pro_required",
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
- Backup abort when `Backup::snapshot_post()` returns empty.
- Validator rejection with `stonewright_spec_invalid`.
- Snapshot and write round-trip for a valid spec.
- Per-widget render snapshots (one per supported type).
- Diagnostic output for unsupported and Pro-gated nodes.
