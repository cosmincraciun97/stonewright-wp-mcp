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
| `blocksy-build-page` | `skills/blocksy-build-page/` | Build Blocksy pages from live block schemas and theme chrome |
| `kadence-build-page` | `skills/kadence-build-page/` | Build Kadence Blocks pages from live `kadence/*` schemas |
| `generateblocks-build-page` | `skills/generateblocks-build-page/` | Build GenerateBlocks pages from live `generateblocks/*` schemas |
| `spectra-build-page` | `skills/spectra-build-page/` | Build Spectra (`uagb/*`) pages from live block schemas |
| `woocommerce-catalog` | `skills/woocommerce-catalog/` | Manage WooCommerce catalog work: products, variations, SKUs, attributes, terms, and shipping classes |
| `wp-plugin-dev` | `skills/wp-plugin-dev/` | Build WordPress plugins, blocks, widgets, and abilities |
| `stonewright-review` | `skills/stonewright-review/` | Review generated page structure against the Design Spec and site state |
| `visual-direction` | `skills/visual-direction/` | Decide and prove visual direction: capture, reviewed kit sync, first-section checkpoint, rendered evidence |
| `how-to-write-skills` | `skills/how-to-write-skills/` | Write, review, import, and test site skills: trigger descriptions, version constraints, exposure flags, and the import review |
| `stonewright-rescue` | `skills/stonewright-rescue/` | Recover from a change that left the site failing: read the rescue status, plan and run the rollback, and re-check after a fix by hand |

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

A skill may also declare `requires_provider`, a single provider id. The only
accepted value is `elementor-native`: Elementor's own MCP abilities are
registered on the site (the `native_elementor` state is `available` or
`available_uncertified`). The value is lowercase, one id only; an unknown id,
a list, an empty value, or a repeated key makes the document invalid.
`requires_provider` compiles into the visibility constraint
`"provider:elementor-native": "required"` that is merged into
`version_constraints`, so the stored record, the export, and the runtime check
use that constraint. While the provider is absent the skill is hidden from
agents and prompts exactly like a skill whose required plugin is missing, and
`stonewright-skills-get` names `provider:elementor-native` as the missing
requirement. It is shown again as soon as the provider is present. A provider
requirement never blocks saving, enabling, or exporting a skill. The constraint
accepts only the expression `required`; skill lint reports an unknown provider
id or any other expression as `invalid_provider_requirement:<component>`, which
blocks activation.

## Skill lifecycle in wp-admin

**Stonewright → Knowledge → Skills** has four views: Catalog, Editor, Import, and Trash.

**Catalog.** Every skill states where it came from — `built-in` (ships with
Stonewright), `local` (created on this site), or the id of the plugin that
registered it — plus its state, revision, and how many times it has been
verified. Search filters the list in place. Inspect opens a drawer with the
body, lint findings, trust findings, and history. Export downloads normalized
Markdown with provenance and a content hash.

Packaged industry landing-page playbooks (`Landing page — Agency`, SaaS, Law
firm, Healthcare, Nonprofit, Real estate, Restaurant) are no longer shipped.
Already-seeded playbook rows for those slugs are retired on seed. Presence-gated
Blocksy, Kadence, GenerateBlocks, and Spectra build-page skills remain.

**Editor.** Creating and editing a skill is a nonce-checked form that works
with JavaScript disabled. It is the only write path on the page that does not
go through the REST routes.

**Import.** Import is two steps. The file is inspected first — UTF-8 Markdown,
1 MiB ceiling, front matter with `name` and `description` required — and the
review lists lint errors and trust findings before anything is stored. The
review also carries a receipt that the server issues for the reviewing user and
that stays valid for 30 minutes; an import without it is refused. The
confirmation binds the file name and the content hash, so neither the file nor
its slug can change between review and persistence. A file that tells an agent
to override the plugin's rules or safety gates, to disable confirmation tokens,
or to send credentials elsewhere is refused; other trust findings are warnings.
An imported skill lands **disabled, as a draft**, and is re-checked on the
server regardless of what the file claims about itself. An import never
overwrites an existing skill: a slug that already exists, including a reserved
built-in slug, answers HTTP 409. The skills of an imported knowledge bundle are
added the same way, as disabled drafts that never replace a skill; the import
result lists the skipped ones, and the Memory page reports how many skills were
added and skipped.

**Trash and restore.** Trashing disables a skill everywhere an agent could read
it and offers an undo. Trashed skills never match `stonewright-task-start`.
Restore returns the skill as a disabled draft, so somebody has to enable it
deliberately. Built-in skills can be disabled but not removed.
`DELETE /stonewright/v1/skills/{id}` moves a skill to the trash in the same way,
and a built-in skill answers 403.

**Permanent deletion** is a separate, irreversible action in the Trash view. It
opens a review drawer listing exactly what is about to be destroyed, and in
`production-safe` mode it also requires a confirmation token issued by
`stonewright-security-issue-confirmation-token` for ability
`stonewright/skills-destroy` with args `{"id": <skill id>}`.

No action on the page uses a native browser dialog. Titles, descriptions, and
imported Markdown reach the DOM as text, never as markup.

## External skill sources

Another plugin can publish skills through the `stonewright_skill_sources`
filter. The filter receives an empty list and returns sources shaped as
`['source_id' => 'plugin-slug', 'skills' => [ $skill, ... ]]`, where each skill
has `slug`, `title`, `description`, and `content`, and may add `topic` and
`version_constraints`. Published skills appear in the catalog only. Source
enumeration is read-only: Stonewright does not execute source code and does not
fetch URLs.

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
