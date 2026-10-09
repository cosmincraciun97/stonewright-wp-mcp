# Abilities Reference

> Category counts are generated from `docs/ability-truth-matrix.md` (**394** abilities).
Stonewright registers WordPress abilities under the `stonewright/` prefix. MCP
clients call the same names with slashes converted to hyphens: ability
`stonewright/task-start` is MCP tool `stonewright-task-start`.
The source of truth is `Stonewright\WpMcp\Core\AbilityRegistry`; run
`cd plugin && composer docs:matrix` to regenerate the complete ability truth
matrix after changing the registry.

## Current Categories

| Category | Count | Scope |
|---|---:|---|
| Section reuse | 2 | Find sections the site already has and extract one as a portable payload. The copy itself is an insert operation of the V3, V4 and block batch writers. |
| Security | 7 | Confirmation tokens, audit reconcile, runtime purge, incident repair, one-time links, and rescue status and rollback. |
| Site | 17 | WordPress diagnostics, snapshots, health, plugins, theme, shortcodes, and front-page settings. |
| Content | 8 | Create, update, duplicate, bulk upsert, and read posts/pages. |
| Media | 8 | Upload, batch upload, inspect, optimize, list, annotate, and import stock media. |
| Gutenberg | 13 | Parse, render, serialize, insert, update, remove, query-loop, apply, and transact dynamic blocks. |
| Finalizer abilities | 6 | Queue, runtime, pending batch, finalize, cancel, and finalizer URL for static or third-party blocks. |
| Patterns | 5 | List, create, update, delete, and categorize synced patterns. |
| Full Site Editing | 12 | theme.json, templates, template parts, global styles, navigation, and child-theme handoff. |
| Elementor V3 | 35 | Structure editing, transactions, document health, performance audit, legacy-debt report, CSS regenerate, observation-only post-write verification, specs, kit globals, preflight, and batch mutation. |
| Elementor native bridge | 2 | Report Elementor's own MCP module and its certified abilities, and run a certified ability inside Stonewright's snapshot, write lock, readback and change set. |
| Elementor V4 (Experimental) | 14 | Atomic nodes, variables, classes, and experimental V4 rendering. |
| Elementor Widget Builder | 4 | Custom Elementor widget project helpers. |
| Elementor Widgets | 94 | Deprecated generated per-widget compatibility builders. |
| Design | 33 | DesignSpec, native planning, directions, manifests, comparison, kit sync, intent routing, and rendered quality evidence. |
| Runtime | 1 | Direct PHP snippets inside the loaded WordPress runtime (full profile only). |
| WP-CLI | 6 | Companion-backed status, command discovery, tokenized command execution, batch execution, and background jobs. |
| Memory | 6 | Persistent memory, generalization, corrections, and learned records. |
| System | 11 | Task start, native rules, profiles, preflight, instructions, ability list, and knowledge transfer. |
| System discover-execute | 3 | Compact catalog, bounded schema, and gated execute without exposing the full MCP tool list. |
| Sandbox | 8 | Admin-only generated code/artifact lifecycle. |
| Diagnostics | 3 | OAuth header, form delivery, and object capability diagnostics. |
| Content Model | 4 | CPT/ACF Loop Grid flow, CPT register/list, and taxonomy registration. |
| Blocks library introspection | 3 | GenerateBlocks, Kadence Blocks, or Spectra setup, registered names, and block.json schema. |
| Blueprints | 3 | Blueprint listing, inspection, and guarded application. |
| Brand Kits | 2 | Reusable brand-kit reads and writes. |
| Skills | 3 | Agent skill listing, reads, and saves. |
| Knowledge | 7 | Elementor knowledge search, guidance, inspection, import, and refresh. The Elementor articles live in a private folder under uploads that `elementor-knowledge-refresh` fills; the readers return nothing until the first refresh. |
| Expertise | 4 | Expertise pack discovery and reads. |
| Theme Builder | 6 | Elementor Theme Builder templates, conditions, and apply-template orchestration. |
| Comments | 5 | Comment list, update, moderation, and deletion. |
| Users | 6 | User and application-password administration. |
| Widgets | 4 | WordPress widget-area reads and writes. |
| Settings | 2 | Site settings reads and guarded updates. |
| Themes | 6 | Theme discovery, activation, Customizer CSS, file reads, and scoped writes. |
| Theme chrome | 2 | Blocksy, Kadence Theme, or GeneratePress global color, typography, header, and footer. |
| Custom Code | 1 | Typed dry-run then human-approval apply for WPCode / Code Snippets / equivalent providers. |
| Plugins Manage | 3 | Plugin install, activate, and deactivate operations. |
| Revisions | 3 | Revision listing, inspection, and restore. |
| Search | 2 | WordPress search and OpenSearch discovery. |
| WooCommerce | 17 | Product, variation, term, catalog-audit, order, and sales operations. |
| ACF | 5 | Field groups and post field-value reads/writes. |
| SEO | 3 | Multi-plugin SEO status and metadata reads/writes. |
| Menu | 5 | Menu creation, item management, locations, and deletion. |

## Section reuse

| Name | Kind | What it does |
|---|---|---|
| `stonewright/section-reuse-find` | Read | Lists sections the current user can read and edit, grouped by the roles a new page needs (hero, features, testimonials, pricing, FAQ, CTA, gallery, contact, other). Each candidate carries its source, locator, role guess, layout summary, a layout-only similarity from 0 to 1, a short outline, and reuse warnings. At most the 200 most recent sources are scanned and `scan.truncated` says when there were more. |
| `stonewright/section-reuse-extract` | Read | Returns one section as a portable payload in its own builder format, with Elementor element ids and V4 local style ids replaced by placeholders and Gutenberg anchors listed, plus every reference the section carries (each with whether it exists here) and its layout summary. Changes nothing. |
| `insert_section` of `stonewright/elementor-v3-batch-mutate` | Write | Inserts a V3 payload under fresh ids in the same dry run and apply as `update_element` adaptations that address the new elements as `@op_id.placeholder`. |
| `insert_section` and `update_node` of `stonewright/elementor-v4-update-node` (`operations`) | Write | The same for a V4 payload, with local style ids remapped. Experimental; blocked in `production-safe`. |
| `insert_section` of `stonewright/blocks-batch-mutate` | Write | The same for a Gutenberg payload; later `update` operations address its blocks with `section_ref` and `relative_path`. |

The option `stonewright_section_reuse` is `ask` (default) or `off`, edited in **Stonewright > Setup > Settings**. While it is `off` the two read abilities are left out of the tool lists and the profiles, `find` answers only `{ "enabled": false, "instruction": ... }`, and every other reuse call fails with `stonewright_section_reuse_off`. Agents see the value as `agent_preferences.section_reuse` in `stonewright-task-start` and in the connect-time instructions, and for fifteen minutes after a change as a `notices` line on every response. In `production-safe` mode an Elementor V3 `insert_section` needs the confirmation token that every `elementor-v3-batch-mutate` write needs unless it is a dry run, and a Gutenberg `insert_section` needs one only when the same batch removes a block. Elementor copies carry checks of their own: CSS classes must be in `stonewright_approved_css_classes`, placeholder and unregistered widgets are refused, a repeated id attribute is renamed with an `anchors_renamed` warning, a repeated `op_id` is refused, and one batch may add at most the element cap (2000) of one write; `section-reuse-extract` warns about the first four before the copy. V4 text adaptations use the text type the live widget declares (`escaped-html` on current Elementor, not `html-v3`). See [Section reuse](architecture.md#section-reuse).

All ability responses support optional `stonewright_fields` projection while
retaining top-level fields required by their declared output schema. The
`stonewright/rules-get` ability serves the native registry with digest/filter
semantics, `stonewright/memory-generalize` de-identifies memory in bounded
batches, and `stonewright/elementor-v3-get-page-structure` accepts `knownHash`
for unchanged short-circuit reads.

## Context Requirement

Agents must call MCP tool `stonewright-task-start` at the start of every task. It
returns the same write token plus active mode, auth guidance, compact Elementor
capabilities, plugin specialization guidance, task-aware recommended tools,
hyphenated MCP tool names, compact `tool_profile` groups, next-best tool
recommendations, compact call examples, and the same visual-build gate in one
low-token response. Use the inlined `fast_path.tool_profile` before making a
separate profile or broad discovery call.

`stonewright-context-bootstrap` and `stonewright-workflow-preflight` remain
compatibility tools. `stonewright-context-bootstrap` is also the tool-list
sentinel: if it is missing, the Stonewright MCP server did not load.

Use `stonewright-tool-profile` when the MCP client has a strict tool limit or
the task needs to switch or verify a low-token execution profile. It returns
compact profiles such as `low-tools`, `elementor-design`, `content-model`,
`gutenberg`, `wp-cli`, and the opt-in `discover-execute` and read-only
`inspect` with the hyphenated MCP
tool names agents should keep using before broad discovery. It also returns `tool_groups`,
`next_best_tools`, and `discovery_policy` so agents can pick the next Elementor,
content/media, Gutenberg/FSE, WP-CLI, or site-admin tool without reading the
full ability matrix. Use `low-tools` for Antigravity, Gemini API, or other
strict tool-cap clients before switching to a specialist profile.

For pixel-matching tasks, `visual_build_gate` is a blocking signoff checklist.
Agents must prepare a reference token table, existing media audit, and section
implementation plan before the first write. Before completion they must provide
desktop, tablet, and mobile screenshot deltas plus logged-out public viewport
checks.

Reference screenshots are the layout authority. Design-tool structure is useful
for tokens, text, styles, assets, and node hints, but agents should not copy a
broken layer tree into WordPress when the visible design needs a cleaner native
structure. For long designs, agents should capture multiple section reference
screenshots and compare section-by-section before final full-page signoff.

Write abilities need the task context token that `stonewright-task-start` returns, as `stonewright_context_token` in their input. A name does not exempt a write: the markers `-get`, `-list`, `-read`, `-describe`, `-discover`, `-explain`, `-search`, `-validate`, `-status`, `-preview`, `-parse` and `-serialize` exempt an ability only when it is not recorded as a write, so `elementor-add-icon-list`, `elementor-add-price-list`, `elementor-add-read-more` and `elementor-add-search` need the token like any other write. A short list of abilities (task start, ping, site reads, skill and memory reads, the Elementor knowledge readers and a few planners) never needs it.

`stonewright/skills-list` can filter skills by exposure mode: `all`, `agentic`
for automatic matching, or `prompt` for explicit prompt/command entries.

Authenticated admins can also execute registered Stonewright abilities through
the Stonewright REST runner when a client cannot call the MCP ability transport
directly:

```http
POST /wp-json/stonewright/v1/abilities/run
Content-Type: application/json

{
  "name": "stonewright/ping",
  "input": {}
}
```

The runner uses the same registry, permission callbacks, master toggle,
disabled-ability checks, UTF-8 sanitization, context-token gate, audit flow, and
ability handlers as the MCP surface. It is not a shell workaround for agents
when the MCP tool list did not load. Agents
must not inspect private AI-client config files, create `query-mcp.js` or
`run-ability.js`, create helper JSON argument files such as
`bootstrap-args.json`, `cli_command.json`, or `get_structure.json`, launch the
companion through `query-local-stonewright.js`, create action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, inspect plugin/companion
source to reverse-engineer tool schemas, or hand-roll JSON-RPC to reach this
runner when `stonewright-context-bootstrap` is missing.

## Tool annotations and exposure

Every ability registers four hints in `meta.annotations`. The MCP adapter maps
them to the tool annotations a client reads from `tools/list`, so a client can
tell a read from a write, an addition from an overwrite, and a local tool from
one that reaches the web before it calls the tool:

| Ability meta | MCP annotation | Meaning |
|---|---|---|
| `readonly` | `readOnlyHint` | The ability changes nothing. |
| `destructive` | `destructiveHint` | It can overwrite or delete what exists; `false` means it only adds. |
| `idempotent` | `idempotentHint` | Repeating the call with the same arguments has no further effect. |
| `openWorldHint` | `openWorldHint` | It can reach hosts outside the site: web requests, downloads, third-party services. |

The hints come from the code of the ability and from its name:

- An ability that cannot change state is read-only, not destructive, and
  idempotent (`Read` in the matrix).
- Any other ability is not read-only. It is not destructive when the last verb
  of its name only adds (`create`, `add`, `insert`, `upload`, `duplicate`,
  `backup`, `queue`); otherwise it is destructive, including when the name holds
  no known verb and when the verb can replace an earlier entry (`record`,
  `capture`, `register`, `define`, `activate`). It is idempotent when its name
  says `delete`, `remove`, or `deactivate`.
- An ability whose code makes HTTP requests, downloads, oEmbed lookups, or
  sideloads is open-world (`External` is `Yes` in the matrix).

`docs/ability-truth-matrix.md` lists the result for each ability in its **Hints**
column. An ability whose nature differs overrides a hint in its `meta()`:

```php
public function meta(): array {
    return [ 'annotations' => [ 'readonly' => false, 'idempotent' => false ] ];
}
```

Abilities that store a context token or mint the token that authorizes a
destructive call (`task-start`, `context-bootstrap`, `workflow-preflight`,
`security-issue-confirmation-token`) are not read-only; `execute-ability` can do
what the ability it runs does; `php-execute` and the WP-CLI runners can reach
any host. `plugin-activate` only adds to the active list, so it is not
destructive and is idempotent; `elementor-create-custom-widget` writes the
widget file under its slug without looking for an earlier one, so it is
destructive; `design-checkpoint-record` only signs an approval token, so it is
not destructive.

After you change an ability, run `cd plugin && composer docs:matrix`. It rewrites
the matrix and `plugin/data/ability-traits.php`, the facts the plugin reads when
it registers abilities; an ability missing from that file registers the
conservative hints (not read-only, destructive, not idempotent, open-world). Run
`composer contracts:generate` to record the hints in
`docs/contracts/public-api-v1.json`, which `composer contracts:compat` compares.

The hints describe an ability to a client. They grant nothing and remove no gate:
permission, mode, confirmation, backup, validation, and audit checks run on every
call. WordPress's REST run endpoint
(`/wp-json/wp-abilities/v1/abilities/<name>/run`) chooses the HTTP method from
the hints: GET for a read-only ability, DELETE for a destructive and idempotent
one, POST for the others. `POST /wp-json/stonewright/v1/abilities/run` is not
affected.

Exposure. Each ability also registers `meta.public`, the single exposure flag of
WordPress 7.1 (it seeds `show_in_rest`), and the per-channel flags that older
cores and the MCP adapter read: `meta.mcp.public` and `meta.show_in_rest`. All
three are `true`. An ability opts out of every channel with `'public' => false`
in its `meta()`, or out of one channel with `show_in_rest` or `mcp.public`.
`GET /wp-json/stonewright/v1/abilities` returns each input schema prepared with
`wp_prepare_json_schema_for_client()` on WordPress 7.1 and later, and unchanged
on older cores.

## Discover-execute

`discover-execute` is an opt-in MCP profile. Auto routing never selects it.
Activate it with `stonewright-tool-profile` when the client cannot hold the
full catalog but still needs one typed ability.

The three protocol tools are:

| Ability | MCP tool | Purpose |
|---|---|---|
| `stonewright/discover-abilities` | `stonewright-discover-abilities` | Compact catalog (name, MCP tool, label, description, category, enabled). No schemas. |
| `stonewright/get-ability-info` | `stonewright-get-ability-info` | Bounded input/output schema plus permission and mode notes for one ability. |
| `stonewright/execute-ability` | `stonewright-execute-ability` | Runs that named ability through the same permission, confirmation, backup, audit, and context gates as a direct MCP call. |

`execute-ability` cannot invoke itself. Disabled abilities stay disabled.
`stonewright/php-execute` is not on this profile; it remains on `full`.

## Inspect

`inspect` is an opt-in, read-only MCP profile. Auto routing never selects it.
Activate it with `stonewright-tool-profile` when the task is to look, not to
change: the startup set plus discovery, read, and verify tools.

| Group | Tools |
|---|---|
| Discovery | `site-info`, `site-capabilities`, `site-plugins-list`, `site-theme`, `content-inventory`, `elementor-v3-capabilities-summary`, `elementor-v4-status`, `design-direction-brief` |
| Read | `content-get-page`, `elementor-v3-get-page-structure`, `elementor-v4-read-atomic-tree`, `elementor-v3-get-kit-globals`, `elementor-schema`, `blocks-get-schema`, `fse-get-theme-json`, `theme-file-read`, `media-list`, `menu-list`, `settings-get` |
| Verify | `elementor-post-write-verify` (observation only), `elementor-document-health`, `design-visual-compare`, `site-health`, `capability-preflight` |

The profile has no write tool, no snapshot or confirmation-token tool, no
blueprint or Design Direction write, and neither `php-execute` nor
`execute-ability`. Activating it adds these tools to the session. It does not
change the surface the operator saved: a `bootstrap` surface stays `bootstrap`,
and a surface that already lists write tools keeps listing them. When a change
is needed, call `stonewright-tool-profile` with the profile that owns the write
(`elementor-design`, `gutenberg`, `content-model`, or `site-admin`).

## Runtime

Use `stonewright/php-execute` (`stonewright-php-execute`) for short PHP snippets
inside the loaded WordPress runtime. It is on the **full** tool profile only —
bootstrap and essential do not expose it. It has access to WordPress functions,
loaded plugins, `$wpdb`, and normal PHP runtime APIs. Prefer typed Stonewright
abilities for common workflows, and use PHP execute when direct plugin API or
database inspection is the shorter correct path. Runtime `$wpdb` and protected
meta writes are blocked; see [Security](security.md#php-execute-runtime-guards).

When a snippet that ran uses a common pattern, the response adds a short
`routing_hint` that names the typed tool for it. `prefer` maps the pattern to
MCP tool names, and `note` says the call was not blocked.

| Pattern | Snippet signal | Typed tools named |
|---|---|---|
| `post_meta` | `update_post_meta` or `add_post_meta` (or the metadata API with the `post` type) with a literal public key | `stonewright-content-update-post`, `stonewright-content-bulk-upsert-posts` |
| `options` | `get_option`, `update_option`, or `add_option` with a literal name in the settings allowlist | `stonewright-settings-get`, `stonewright-settings-update` |
| `elementor_data` | `_elementor_data` | `stonewright-elementor-v3-get-page-structure`, `stonewright-elementor-v3-batch-mutate` |
| `menus` | `wp_get_nav_menus`, `wp_create_nav_menu`, `wp_update_nav_menu_item`, `wp_delete_nav_menu`, or `set_theme_mod( 'nav_menu_locations' )` | the matching `stonewright-menu-*` tool |

The hint never blocks `php-execute`, never repeats the snippet, omits a tool the
operator disabled, and names at most four patterns with three tools each.
`stonewright-task-start` returns the same kind of hint as
`fast_path.routing_hint` for the patterns the task mentions.

## WP-CLI

The WP-CLI tools are:

| Ability | Purpose |
|---|---|
| `stonewright/wp-cli-status` (`stonewright-wp-cli-status`) | Checks that WP-CLI is available through the companion and returns `wp cli info --format=json`. |
| `stonewright/wp-cli-discover` (`stonewright-wp-cli-discover`) | Returns compact `wp cli cmd-dump` command paths by default; use `responseMode=full` only when the raw command tree is required. |
| `stonewright/wp-cli-run` (`stonewright-wp-cli-run`) | Runs a tokenized WP-CLI command through the companion. It supports writes; use `stonewright/php-execute` for PHP snippets instead of WP-CLI eval or shell entry points. |
| `stonewright/wp-cli-batch-run` (`stonewright-wp-cli-batch-run`) | Runs repeated tokenized WP-CLI commands in one request for faster content, meta, term, media, option, and plugin-command work. |
| `stonewright/wp-cli-job-start` (`stonewright-wp-cli-job-start`) | Starts a tokenized WP-CLI command or batch in the companion background queue for long operations. |
| `stonewright/wp-cli-job-status` (`stonewright-wp-cli-job-status`) | Polls a WP-CLI background job and returns the compact result when complete. |

In the Node companion MCP, the same MCP names `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, `stonewright-wp-cli-run`,
`stonewright-wp-cli-batch-run`, `stonewright-wp-cli-job-start`, and
`stonewright-wp-cli-job-status` are direct companion aliases. They do not
require the WordPress-side HTTP bridge explicitly enabled with
`STONEWRIGHT_HTTP_ENABLE=1` plus `PORT`. Use batch run for
repeated commands or Unicode-heavy values so agents do not need large inline
shell scripts. Use background jobs for long WP-CLI work that should not block
one MCP request.
The companion also exposes `stonewright-wp-cli-install`, which downloads the
official `wp-cli.phar` into the Stonewright cache for users who do not have
`wp` on `PATH` or a LocalWP-provided phar.
Agents should not recover by running `wp cli info`, `wp plugin activate`,
`wp option update`, or other `wp` commands in a normal shell.

Agents should prefer native Stonewright abilities for structured writes. Use
WP-CLI when it is faster, better documented by the installed plugin, or useful
for debugging and operational tasks.

## Design And Elementor Contracts

- `stonewright/widget-intent-resolve` accepts `forbid_html_widget`. When true,
  any resolution to an Elementor HTML/raw-html widget returns
  `stonewright_html_widget_forbidden`; callers must choose native Elementor
  widgets and containers instead.
- `stonewright/elementor-v3-status` and `stonewright/elementor-v4-status`
  report Elementor version, Pro availability, active widget types, unsupported
  required native widgets, and V4 atomic support state.
- `stonewright/elementor-v3-get-kit-globals` returns a compact active-kit color
  and typography snapshot so agents can compare Figma tokens before updating
  Elementor kit globals or writing section specs.
- `stonewright/elementor-v3-get-page-structure` returns a compact Elementor
  outline by default; use `responseMode=full` only when the raw element tree is
  required.
- `stonewright/elementor-schema` lists/searches live widgets and returns compact
  widget controls with `mode=summary`; use `mode=control` for one complete
  control or paginated `mode=full` only when required.
- `stonewright/blocks-list-registered` and `stonewright/blocks-get-schema`
  include third-party block inserter metadata such as keywords, examples,
  supports, attributes, and variations when WordPress exposes them.
- `stonewright/design-direction-list` and `stonewright/design-direction-get`
  read stored design directions; `get` returns revision history only when
  `include_versions` is true. Both are reads and need no token.
- `stonewright/design-direction-brief` returns the active direction's ready
  state, tokens, guidance, waivers, hash, and compact translated Elementor
  rules. Density maps to responsive section padding and a default container
  gap; variance maps to layout rhythm; motion blocks, limits, or allows
  entrance/motion effects. Declared spacing tokens override dial defaults.
  Reuse this response across section batches instead of transferring the full
  contract repeatedly.
- `stonewright/design-direction-save` validates the contract allowlist-only and
  rejects unknown fields instead of stripping them. It creates a new revision
  only when the contract hash changed, and returns the hash before and after.
- `stonewright/design-direction-capture` turns compact Elementor kit evidence,
  as returned by `stonewright/elementor-v3-get-kit-globals`, into a draft
  contract with provenance for every mapped token. It previews by default and
  stores only when `save` is true; a stored capture is always a draft, is never
  marked ready, and never becomes the active direction. Values the kit did not
  report stay absent instead of being guessed, contradictory values keep the
  first and report a conflict, and unusable or unsupported evidence is reported
  in `unmapped` rather than silently dropped.
- `stonewright/design-direction-activate` and
  `stonewright/design-direction-restore` change live design intent. Both
  require the task context token, and in production-safe mode a confirmation
  token issued for that exact direction id — and, for restore, that exact
  revision. Both read their effect back and return
  `stonewright_direction_verification_failed` when storage disagrees.
- `stonewright/design-direction-sync-plan` is the dry run for Elementor kit
  globals. It writes nothing and returns the exact operations a sync would
  perform, plus `warnings` for what the kit has no global for and `blocked` for
  values it cannot store. Its `base_hash` describes the live kit and is the
  concurrency guard for the apply half.
- `stonewright/design-direction-sync-apply` writes a sync-ready direction into
  the kit. It requires the dry run's `base_hash` and refuses as
  `stonewright_direction_sync_stale` when the kit moved since then, refuses any
  plan with blocked values rather than coercing them, snapshots the kit before
  mutating it, merges only the planned entry properties so unknown kit settings
  survive, and re-reads the kit before the receipt claims success. Sync covers
  kit colors and typography; spacing, radii, elevation, motion, and component
  styles are reported as warnings instead of being forced into unrelated kit
  fields.
