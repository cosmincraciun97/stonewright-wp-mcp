# Skill Packs

Stonewright ships skill packs in `skills/`. Persistent **plugin** site skills can
also be created or edited in the WordPress admin and are loaded through
MCP tool `stonewright-task-start` (or compatibility `stonewright-context-bootstrap`)
at the start of each task.

## Direct mode (companion-local) skills

Pluginless installs store skills and memory on the companion host under
`~/.stonewright/skills/<scope>/` and `~/.stonewright/memory/<scope>.jsonl`.

| Tool | Role |
|---|---|
| `stonewright-task-start` | Returns matched skill refs + memory highlights (no bodies) |
| `stonewright-skill-list` | Compact index |
| `stonewright-skill-get` | Load one body on demand |
| `stonewright-skill-save` / `delete` | Create/update/delete local playbooks |
| `stonewright-memory-list` / `learning-record` | List and record corrections |

These are per-machine, not shared across operators like the plugin Admin UI skills.

### Built-in Direct skills

The companion package ships `companion/skills-builtin/` and seeds them into
`~/.stonewright/skills/_builtin/` on Direct startup (**copy-if-missing**):

| Skill | Purpose |
|---|---|
| `elementor-direct-editing` | Local WP-CLI Elementor data edit protocol |
| `gutenberg-authoring` | Compose + validate block content |
| `no-hallucination-protocol` | Read before write; fix errors; never invent schemas |

User edits to a seeded skill file are **never overwritten** on upgrade. Deleting
a builtin file restores it on the next seed.

Each skill has a master active toggle and two exposure flags:

- **Auto-match** adds the skill description to the compact routing index used
  during context bootstrap. Keep these descriptions short to reduce token use.
- **Prompt/command** keeps the skill available for explicit user or client
  selection without forcing it into automatic matching.

| Skill | Directory | Description |
|---|---|---|
| `design-to-wordpress` | `skills/design-to-wordpress/` | Build pages from design references, images, briefs, or manual specs |
| `content-model-integrations` | `skills/content-model-integrations/` | Work with ACF, ACPT, Meta Box, ASE, Pods, custom fields, CPTs, taxonomies, and option pages |
| `elementor-v3-builder` | `skills/elementor-v3-builder/` | Build and edit Elementor V3 pages |
| `elementor-v4-atomic` | `skills/elementor-v4-atomic/` | Experimental Elementor V4 atomic workflow |
| `gutenberg-fse-builder` | `skills/gutenberg-fse-builder/` | Build Gutenberg/FSE output from a Design Spec |
| `woocommerce-catalog` | `skills/woocommerce-catalog/` | Manage WooCommerce catalog work: products, variations, SKUs, attributes, terms, and shipping classes |
| `wp-plugin-dev` | `skills/wp-plugin-dev/` | Build WordPress plugins, blocks, widgets, and abilities |
| `stonewright-review` | `skills/stonewright-review/` | Review generated page structure against the Design Spec and site state |
| `visual-direction` | `skills/visual-direction/` | Decide and prove visual direction: capture, reviewed kit sync, first-section checkpoint, rendered evidence |

`visual-direction` is loaded for new or changed visual direction — a rebrand, a
new palette or type scale, a different spacing rhythm. It does not replace a
renderer skill: `elementor-v3-builder` still owns every write to an Elementor
tree, and cross-references the pack instead of duplicating its rules. Work that
stays inside an existing direction (copy edits, adding a widget in the
established style, repairing a control) does not load it.

A skill directory may declare `topic` and `version_constraints` in its front
matter. `version_constraints` is a single-line JSON object of component to
version expression, for example `{"elementor": ">=3.16"}`; the seeder forwards
both to the skill record, and a pack that declares neither leaves whatever the
site already recorded for that slug untouched.

## Skill lifecycle in wp-admin

**Stonewright → Skills** has four views: Catalog, Editor, Import, and Trash.

**Catalog.** Every skill states where it came from — `built-in` (ships with
Stonewright), `local` (created on this site), or the id of the plugin that
registered it — plus its state, revision, and how many times it has been
verified. Search filters the list in place. Inspect opens a drawer with the
body, lint findings, trust findings, and history. Export downloads normalized
Markdown with provenance and a content hash.

**Editor.** Creating and editing a skill is a nonce-checked form that works
with JavaScript disabled. It is the only write path on the page that does not
go through the REST routes.

**Import.** Import is two steps. The file is inspected first — UTF-8 Markdown,
1 MiB ceiling, front matter with `name` and `description` required — and the
review lists lint errors and trust findings before anything is stored. The
confirmation binds the content hash, so a file cannot change between review and
persistence. An imported skill lands **disabled, as a draft**, and is re-checked
on the server regardless of what the file claims about itself. An import never
overwrites an existing skill.

**Trash and restore.** Trashing disables a skill everywhere an agent could read
it and offers an undo. Trashed skills never match `stonewright-task-start`.
Restore returns the skill as a disabled draft, so somebody has to enable it
deliberately. Built-in skills can be disabled but not removed.

**Permanent deletion** is a separate, irreversible action in the Trash view. It
opens a review drawer listing exactly what is about to be destroyed, and in
`production-safe` mode it also requires a confirmation token issued by
`stonewright-security-issue-confirmation-token`.

No action on the page uses a native browser dialog. Titles, descriptions, and
imported Markdown reach the DOM as text, never as markup.

## External skill sources

Another plugin can publish skills through the `stonewright_skill_sources`
filter. Source enumeration is read-only: Stonewright does not execute source
code and does not fetch URLs.

Resolution order is built-in, then this site's database, then registered
external sources. Built-in ids are reserved and external sources must use
source-qualified ids, so a source cannot silently shadow a built-in or a local
skill. Anything that tried to is reported as a visible conflict in the catalog
instead of quietly winning.

## Conventions

- Call `stonewright-task-start` before planning or writing.
- If a returned skill matches the task, read and follow it.
- Put large or rarely needed playbooks in prompt/command mode instead of
  auto-match mode.
- Call `stonewright-learning-record` when the user corrects a repeatable
  mistake so future sessions inherit the lesson.
- For Elementor, use native widgets and call the widget intent and
  implementation-guide abilities before writing.
- Use WP-CLI discovery/status before relying on installed plugin commands.
- For custom field or catalog work, call `stonewright-workflow-preflight` and
  follow returned specialization guidance before writing.
