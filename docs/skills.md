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
