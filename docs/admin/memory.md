# Memory & Instructions

The Memory & Instructions page manages two distinct features: a block of
free-text instructions that is injected into every connected agent session,
and a typed key/value store that persists information across conversations.

Sources:
- `plugin/includes/Admin/MemoryInstructionsPage.php`
- `plugin/includes/Memory/Memory.php`

A fresh installation has zero memory rows. Updates preserve existing rows.
Memory writes reject high-confidence credential material; use private client
configuration for site URLs, usernames, Application Passwords, tokens, and
other authentication values.

---

## Custom Instructions

### What they do

The text in the Custom Instructions textarea is prepended to the MCP server
description returned in the `tools/list` response. Every MCP client receives
this text when it first connects, before the user types anything. Use it for
persistent context: site purpose, preferred writing tone, content guidelines,
or anything else the AI should know by default.

The instructions are also included in the output of the
`stonewright-system-abilities-list` MCP tool so agents can read them
programmatically.

The textarea has a visible "Custom instructions" label, and the guidance and the
4000-character limit printed under it are linked to it as its description.
The two switches (**Enable memory abilities**, **Enable custom instructions**)
and the textarea share one **Settings** form: WordPress saves every option of a
settings group on each post and clears the ones the post did not carry, so one
form that posts all three keeps saving one from clearing another.

The connect-time server instructions carry the custom instructions only (their
first 1200 characters). Enabled Context page text (`Stonewright → Knowledge → Context`)
is not part of them: it reaches agents in `stonewright-task-start` and
`stonewright-context-bootstrap`, in front of the custom instructions. Compact
`stonewright-task-start` includes the first 400 characters of that combined text
in `context.custom_instructions.text`; `responseMode=full` and
`stonewright-context-bootstrap` carry the first 1200 characters of the Context
text and the combined text up to 2400 characters. See [Context](context.md).
Pluginless Direct mode never sees wp-admin Context or custom instructions.

### Enabling and disabling

The toggle saves to `stonewright_custom_instructions_enabled` (boolean). When
`false`, the text is stored but not injected — turn it off temporarily without
losing the content.

### Limits

Maximum length is 4000 characters. The sanitizer in
`MemoryInstructionsPage::register_settings()` truncates silently to that
limit on save. Newlines are preserved; HTML is not stripped, but the content
is injected as plain text into the MCP description rather than rendered as
markup. The Context page text is different: it is stored as plain text with
tags removed and no HTML entities (see [Context](context.md)).

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
| `created_at` | `DATETIME` | Set in UTC when the entry is created |
| `updated_at` | `DATETIME` | Set in UTC on each write; reading never changes it |

There is a `UNIQUE KEY` on `(scope, memory_key)`. An ability that saves an
existing key replaces the record rather than creating a duplicate. The **Add
entry** form never replaces: it refuses a scope and key that are already in use
and says which entry holds them.

Times are stored in UTC. The page shows **Updated** in the site's time zone
in a `time` element whose `datetime` and `title` carry the UTC instant.
Recording that task start retrieved an entry sets only `last_retrieved_at`,
shown as **Last retrieved** (UTC) in the entry's facts; it does not change
`updated_at`.

### Types

| Type | Intended use |
|---|---|
| `user` | Facts about the site owner or their preferences |
| `feedback` | Notes on what worked or did not |
| `project` | Project-specific context (goals, constraints, tech stack) |
| `reference` | External references (URLs, IDs, codes) |
| `generic` | Anything that doesn't fit a specific category |

### Scope

All entries are site-scoped, not user-scoped. The `scope` field is a
developer-supplied namespace string (`"default"` when not specified). It
allows the same `memory_key` to exist in multiple logical namespaces without
collision. There is no per-WordPress-user isolation.

### How entries get created

The AI explicitly calls `stonewright-memory-save` when it decides information
is worth retaining. Nothing is auto-saved based on conversation content.
Admins can also create entries manually via the Add new form on this page, or
via the REST API (`POST /stonewright/v1/memory`).

Repeated errors can add a proposed lesson: when the same error repeats ten
times, Stonewright writes a Reference entry in scope `audit` with status
`draft`. See [Proposed lessons](#proposed-lessons).

---

## Page UI

The page is built from the shared admin UI layer. From top to bottom: the
messages for the last action, the store note and guidance, the **Memory
entries** card (add form, lifecycle filters, table), **Learned rules** when
there are any, **Settings**, **Import and export**, and **Receipt lookup and
feedback migration**. The one primary action in the page header is **Add entry**.

### Messages

Every action ends in a message on this page, printed where the page begins and
never removed by a timer: entry created, saved, deleted, lesson approved,
draft discarded, learned rule disabled, settings saved, legacy feedback
classified, and the refusals (see below). Confirmations are status messages;
refusals and failures are alerts. The redirect carries a short `memory_notice`
code and the id of the entry it concerns; the page looks the code up and never
prints request text.

### Lifecycle filters

**All**, **User**, **Project**, **Verified repairs**, **Unresolved incidents**,
**Incident lifecycle**, **Audit feedback** and **Reference** are links that add
`?type={view}`; each carries its count. **Incident lifecycle** and **Unresolved
incidents** list the incident store, with its own state filters (**Open**,
**Observing**, **Resolved**, **Suppressed**).

### The table

One row per entry: name and key, type (a tag), scope, status (**Active**,
**Draft**, **Stale** or **Rejected**, with the lifecycle state when there is
one), updated time, and the row's actions, each named after its entry. The
table stacks into one card per row at 782px and below. It lists the newest 200
entries of a view and says so when there are more. An empty store and an empty
view each have their own message.

### Adding an entry

**Add entry** opens the **Add a memory entry** section (it is also opened by
`?add=1`). Name and Scope, Key, Type and Value (JSON or text). The form posts to
`admin-post.php?action=stonewright_memory_create`, which calls
`Memory::put_typed()` only when the scope and key are free. If the pair is in
use, nothing is written: the page says "No entry was created: that scope and
key are already in use", names the entry that holds the pair and links to its
edit view. Text that looks like a password, key or token is refused with its
own message.

### Editing an entry

**Edit** opens the entry (`?edit={id}`): its facts (backend, origin, activation,
lifecycle, last retrieved, updated) and the form. Saving posts to
`admin-post.php?action=stonewright_memory_update`, validates the nonce and
updates the row by id. Moving an entry onto a scope and key that another entry
holds is refused in the same way as adding. The REST endpoint
`POST /stonewright/v1/memory` can also update by scope/key or by passing `id`.

### Deleting an entry

**Delete this entry**, at the foot of the edit view, opens a short explanation
and a **Delete entry** button (a destructive action is never primary, and
needs that second step with or without JavaScript). The handler calls
`Memory::delete_by_id()`.

### Proposed lessons

A draft lesson shows **Approve** and **Discard** in its Actions cell.
**Approve** makes the entry active and records who approved it and when (UTC) in
the entry's value; **Discard** rejects it. Only draft entries can be approved or
discarded (anything else answers "not a draft" and changes nothing), and both
actions need `manage_options` and a nonce. An approved lesson is offered to
agents like any other active Reference entry; a proposed lesson that is not
active and approved is not offered. A one-time repair returned proposed lessons
that were active without a recorded approval to draft.

### Master memory toggle

The **Enable memory abilities** switch saves to `stonewright_memory_enabled`.
When `false`, the memory MCP tools (`stonewright-memory-list`,
`stonewright-memory-get`, `stonewright-memory-save`,
`stonewright-learning-record`, `stonewright-memory-delete`) are excluded from
the `tools/list` response.
Existing entries in the database are unaffected.
