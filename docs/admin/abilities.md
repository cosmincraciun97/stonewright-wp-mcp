# AI Abilities

The AI Abilities page lists every MCP tool registered by Stonewright, lets
you disable individual ones, and shows a live count per category.

Source: `plugin/includes/Admin/AbilitiesPage.php`

---

## What an ability is

An "ability" is a one-to-one mapping to an MCP tool. Each ability has:

- **Name** — the MCP tool identifier (e.g. `stonewright/memory-write`)
- **Label** — a human-readable display name
- **Category** — groups related abilities together
- **Description** — one-line summary shown in the table

When the MCP server responds to a `tools/list` call it returns only the
abilities that are currently enabled and not blocked by the master toggle.

### Categories

| Category | Example abilities |
|---|---|
| `security` | confirmation token, audit log |
| `site` | settings read/write |
| `content` | post and page CRUD |
| `media` | upload, read metadata |
| `gutenberg` | block operations |
| `patterns` | pattern library |
| `fse` | full-site editing templates |
| `elementor` | element CRUD, design import |
| `design` | Figma ingestion, spec validate |
| `qa` | Playwright screenshot, pixel diff |
| `memory` | memory CRUD |
| `system` | abilities list, instructions get |
| `sandbox` | sandbox file lifecycle |

---

## Enabling and disabling abilities

### Per-ability toggle

Each row in the table has a checkbox. Unchecking it and submitting posts to
`admin-post.php?action=stonewright_toggle_ability`. The handler:

1. Validates the nonce (`stonewright_toggle_ability`).
2. Reads the `stonewright_disabled_abilities` option (an array of ability
   names).
3. Adds or removes the ability name from the array.
4. Calls `update_option( 'stonewright_disabled_abilities', $updated, false )`.

The option is a plain PHP array of string ability names. Serialized by
WordPress automatically. You can inspect it in wp-options:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'stonewright_disabled_abilities';
```

The page redirects back with `?stonewright_toggled=enabled` or `=disabled`
and shows a dismissible success notice.

### Master toggle interaction

When `stonewright_enabled` is `false`, a warning banner replaces normal
interaction at the top of the page:

> **Master toggle is OFF** — these abilities are registered but the MCP server
> rejects calls. Enable from the Configuration page.

Individual toggles still work (you can pre-configure the disabled list) but
no AI calls will go through until the master toggle is turned back on.

The `AbilityRegistry::enabled_abilities()` method always returns the full
registered set so the UI can display it; the MCP layer applies the master
toggle check at request time.

---

## Filtering and search

### Category chips

A row of `<a class="button">` chips above the table filters by category. The
active chip has the `button-primary` class. Clicking "All" clears the filter.
The URL parameter is `?page=stonewright-abilities&cat={category}`.

Category chips display a count of abilities in that category, e.g. **Memory (4)**.

### Search input

A free-text search box filters rows client-side (no page reload). It matches
against the ability name, label, and category simultaneously:

```js
var match = name.indexOf(query) !== -1
         || label.indexOf(query) !== -1
         || category.indexOf(query) !== -1;
```

### Read-only mode

If the current user lacks `manage_options`, the toggle checkboxes are replaced
with plain text labels. No form is rendered. This applies when a lower-privilege
user can view the page but not change settings (e.g. an Editor role with a
custom cap grant).
