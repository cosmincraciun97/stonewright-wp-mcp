# AI Abilities

The AI Abilities page lists the MCP tools currently exposed by Stonewright, lets
you disable individual ones, and shows a live count per category. Essential
tools mode is enabled by default, so the first view is the compact fast-path
surface; turn essential tools mode off from Configuration when you need to
inspect or expose every registered ability.

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
| `gutenberg` finalizer | `blocks-queue-change`, `blocks-finalize-batch`, `blocks-finalizer-runtime` |
| `patterns` | pattern library |
| `fse` | full-site editing templates |
| `elementor` | element CRUD, page structures |
| `theme-builder` | Elementor templates and display conditions |
| `content-model` | CPT/ACF-backed Loop Grid workflow |
| `blocks` | GenerateBlocks / Kadence / Spectra library introspection |
| `design` | spec validate, renderer selection |
| `wp-cli` | status, discovery, command run |
| `memory` | memory CRUD |
| `system` | abilities list, instructions get |
| `system` discover-execute | `discover-abilities`, `get-ability-info`, `execute-ability` |
| `skills` | skill list, read, and save |
| `runtime` | direct WordPress PHP snippets (`full` profile only) |
| `themes` chrome | `theme-chrome-get`, `theme-chrome-update` |
| `sandbox` | sandbox file lifecycle |

---

## Enabling and disabling abilities

### Per-ability toggle

Each row in a category table has a switch: a checkbox with the switch role, named
after the ability it controls so a screen reader announces which ability it turns on
or off. Moving it saves at once, without reloading the page: the script calls
`POST /wp-json/stonewright/v1/admin/abilities/toggle` and confirms the change in a
toast that offers **Undo**. If the server refuses or cannot be reached, the switch
goes back and a notice that stays on the page says why. If the route is blocked (a
response that is not JSON), the script hands the change to the form handler below.

The route and the form handler `admin-post.php?action=stonewright_toggle_ability` are
two ways into the same code (`AbilityToggles::set_enabled()`) and keep the same
gates:

1. The `manage_options` capability.
2. The nonce of the form (`stonewright_toggle_ability`). The route also needs the
   REST nonce that WordPress asks of a signed-in request.
3. The `stonewright_disabled_abilities` option (an array of ability names): the
   name is added or removed and the option is saved with
   `update_option( 'stonewright_disabled_abilities', $updated, false )`.

Neither writes an audit row. The option is a plain PHP array of string ability
names. You can inspect it in wp-options:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'stonewright_disabled_abilities';
```

### Bulk actions

The toolbar holds the search field, a count of what is shown and what is on, and the
bulk controls: **Select visible**, a bulk action (enable or disable the selected
abilities, or a whole category), a category and **Apply**. With script, Apply calls
`POST /wp-json/stonewright/v1/admin/abilities/bulk` and says how many abilities
changed in a toast with **Undo**. Apply with nothing chosen says what is missing next
to the control and moves focus to it; nothing is sent. Each category also has
**Enable all** and **Disable all** buttons, shown when script runs.

Without script the bulk form posts to `admin-post.php?action=stonewright_bulk_abilities`
(nonce `stonewright_bulk_abilities`), and the page redirects back with
`?stonewright_toggled=` set to `bulk-enabled`, `bulk-disabled`, `bulk-no-action`,
`bulk-no-selection` or `bulk-no-category` (and `stonewright_changed=` with the count).
The result, or what is missing, is printed as a notice that stays until you leave
the page. Single switches need script.

### Parameters

The input parameters of an ability are not part of the page. Opening a row's
**Parameters** loads them once from
`GET /wp-json/stonewright/v1/admin/abilities/parameters?name=<ability>` (name, type,
required, description). A failed request says so and offers **Try again**.

### Master toggle interaction

When `stonewright_enabled` is `false`, a warning banner replaces normal
interaction at the top of the page:

> **Master toggle is OFF** — these abilities are registered but the MCP server
> rejects calls. Enable from the Configuration page.

Individual toggles still work (you can pre-configure the disabled list) but
no AI calls will go through until the master toggle is turned back on.

The `AbilityRegistry::enabled_abilities()` method returns the currently public
set after essential tools mode and per-ability disables are applied. The MCP
layer still applies the master toggle check at request time.

---

## Filtering and search

### Categories

Abilities are grouped by provider, then by category. Every category starts closed;
its heading carries a count in words (for example "3 of 5 on"). While a search
is active, the categories that match open and the others are hidden; clearing the
search puts them back.

### Search input

The field filters rows in the browser, with no page reload. It matches the ability
name, label, MCP tool name, category and kind. The matching text is highlighted, and
the toolbar says "12 of 397 abilities". When nothing matches, an empty state names
the search and offers **Clear search**. Press `/` to focus the field and Escape to
clear it.

### Read-only mode

If the current user lacks `manage_options`, the toggle checkboxes are replaced
with plain text labels. No form is rendered. This applies when a lower-privilege
user can view the page but not change settings (e.g. an Editor role with a
custom cap grant).
