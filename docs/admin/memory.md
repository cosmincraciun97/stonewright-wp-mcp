# Memory & Instructions

The Memory & Instructions page manages two distinct features: a block of
free-text instructions that is injected into every connected agent session,
and a typed key/value store that persists information across conversations.

Sources:
- `plugin/includes/Admin/MemoryInstructionsPage.php`
- `plugin/includes/Memory/Memory.php`

---

## Custom Instructions

### What they do

The text in the Custom Instructions textarea is prepended to the MCP server
description returned in the `tools/list` response. Every MCP client receives
this text when it first connects, before the user types anything. Use it for
persistent context: site purpose, preferred writing tone, content guidelines,
or anything else the AI should know by default.

The instructions are also included in the output of the
`stonewright/abilities-list` MCP ability so agents can read them
programmatically.

### Enabling and disabling

The toggle saves to `stonewright_custom_instructions_enabled` (boolean). When
`false`, the text is stored but not injected — turn it off temporarily without
losing the content.

### Limits

Maximum length is 4000 characters. The sanitizer in
`MemoryInstructionsPage::register_settings()` truncates silently to that
limit on save. Newlines are preserved; HTML is not stripped, but the content
is injected as plain text into the MCP description rather than rendered as
markup.

---

## Memory entries

### Data model

Each memory entry is a row in the `wp_stonewright_memory` table with these
columns:

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment PK |
| `scope` | `VARCHAR(64)` | Namespace (default: `"default"`) |
| `type` | `VARCHAR(32)` | One of the five types below |
| `name` | `VARCHAR(190)` | Human-readable label |
| `memory_key` | `VARCHAR(190)` | Unique within a scope |
| `value_json` | `LONGTEXT` | JSON-encoded value |
| `confidence` | `DECIMAL(5,4)` | AI-provided confidence score, 0–1 |
| `created_at` | `DATETIME` | Auto-set on insert |
| `updated_at` | `DATETIME` | Auto-updated on change |

There is a `UNIQUE KEY` on `(scope, memory_key)`, so updating an existing
key in the same scope replaces the record rather than creating a duplicate.

### Types

| Type | Intended use |
|---|---|
| `user` | Facts about the site owner or their preferences |
| `feedback` | AI-generated notes on what worked or didn't |
| `project` | Project-specific context (goals, constraints, tech stack) |
| `reference` | External references (URLs, IDs, codes) |
| `generic` | Anything that doesn't fit a specific category |

### Scope

All entries are site-scoped, not user-scoped. The `scope` field is a
developer-supplied namespace string (`"default"` when not specified). It
allows the same `memory_key` to exist in multiple logical namespaces without
collision. There is no per-WordPress-user isolation.

### How entries get created

The AI explicitly calls `stonewright/memory-write` when it decides information
is worth retaining. Nothing is auto-saved based on conversation content.
Admins can also create entries manually via the Add new form on this page, or
via the REST API (`POST /stonewright/v1/memory`).

---

## Page UI

### Tabs

The page shows five tabs: **All**, **User**, **Feedback**, **Project**,
**Reference**. Clicking a tab appends `?type={type}` to the URL; the page
re-renders with `Memory::list_by_type()` filtered results. The **All** tab
calls `Memory::list_all()`.

Each tab label shows a count in parentheses, e.g. **Project (3)**, drawn
from a full-table scan on page load.

### Adding an entry

Click **Add new** to reveal the hidden form. Fill in Name, Scope (defaults
to `"default"`), Key, Type (select), and Value. The Value field accepts any
text or JSON. Submitting posts to
`admin-post.php?action=stonewright_memory_create` which calls
`Memory::put_typed()`.

### Editing an entry

Editing inline is not yet in the current wave. To change a value, delete the
existing entry and re-add it, or use the REST endpoint
`POST /stonewright/v1/memory` with the same scope/key (an upsert will overwrite
the existing row) or `POST /stonewright/v1/memory` passing `id` for a targeted
update.

### Deleting an entry

Click **Delete** in the Actions column. A `confirm()` dialog prevents
accidental removal. The handler calls `Memory::delete_by_id()`.

### Master memory toggle

The **Enable memory abilities** checkbox saves to `stonewright_memory_enabled`.
When `false`, the four memory MCP abilities (`stonewright/memory-list`,
`stonewright/memory-read`, `stonewright/memory-write`,
`stonewright/memory-delete`) are excluded from the `tools/list` response.
Existing entries in the database are unaffected.
