# Stonewright Elementor Mastery — Hand-off Plan

**Purpose:** Stonewright MCP must surpass novamira / msrbuilds / claudeus while learning from each. Full Elementor + Pro Elements + WooCommerce widget catalog (≈80+ widgets), smart intent detection, pixel-perfect Figma fidelity.

**Origin:** Hand-off from session `fe5c7a7a-dd4e-41c4-903c-e8f7cd17722e` after the live nZeb Expo build came out "looks like shit" because the renderer was missing countdown / nav-menu / icon-list widgets, StyleMapper skipped group-control toggle activation, and assets were exported as whole-section composites instead of per-sub-node.

> **Read this entire file before any tool call.** It is the single source of truth for what the user wants and what the previous session learned.

---

## 1. The user's goal (verbatim intent)

> "vreau ca stonewrite mcp sa fie mai smecher decat novamira si celelalte mcps, dar ne putem inspira de la ei ca sa mergem in directia buna si sa fim mai smecheri si acurate, mai functionali."
>
> "intra pe acel link [elementor.com/widgets] cu subagenti multipli si analizeaza fiecare widget in parte ca fiecare widget in parte are setarile lui, si hai sa le integram pe toate absolut toate in mcp, si sa imbunatatim sistemul de detectie, ca sa stie cand sa aplice acele widgeturi, sa ia decizii inteligente si smart."

**Translated, what to deliver:**

1. Integrate **every** Elementor / Pro Elements / WooCommerce widget as a first-class ability with its real setting keys.
2. Build a **smart detection layer** so the model picks the right widget for the design intent (countdown pattern → `Countdown` widget, nav row → `Nav Menu` widget, not text-button hacks).
3. **Surpass** the competitor MCPs — see § 2 — by combining each one's strengths.
4. Deliver the nZeb Expo live build (header / footer / hero / menu, desktop + mobile, Figma file `zfoLm0i7YDmVCowIsHlBDH`, nodes `97:2895` desktop + `97:8867` mobile) as the proof.

---

## 1bis. The user's goal — second-round update (verbatim, this session)

The user expanded the brief after seeing the comparative analysis. Direct quotes:

> "https://elementor.com/blog/custom-wordpress-widget/ analizeaza asta, usecase ul asta ar trebui sa il aplicam si noi pentru ca si noi avem posibilitatea sa cream widgeturi custom, si vreau sa pot crea widgeturi custom uptodate si orice widget e posibil in momentul asta sa faci pentru elementor pro sau free."

> "si vreau sa analizeze toate aceste widgeturi corect si sa le implementam in mcp, toate tips urile, ai ul sa tina cont de ele, sa fie trainuit ca sa stie ce, cum si cand sa aplice si sa creeze si ce e posibil de facut doar cu elementor si fara custom code etc sa stie tot ce se poate face ca sa ia decizii corecte tot timpul la implementare si sa customize cu tot ce e posibil in elementor https://elementor.com/help/build-with-the-editor/widgets/"

> "si vreau sa fie trainuit si pentru editor elementor normal dar si pentru V4 sa stie tot ce se poate face ca sa ia decizii corecte tot timpul la implementare si sa customize cu tot ce e posibil in elementor. https://elementor.com/help/build-with-the-editor/getting-started-editor/"

> "SI PENTRU DESIGN YOUR THEME. https://elementor.com/help/design-your-theme/"

> "DECI ESTE OBLIGATORIU CA FIECARE ARTICOL/TUTORIAL, FIECARE LINK DE AICI SA FIE CA SURSA DE TRAINING, SI SA SPAMEZE MULTI AGENTI IN PARALEL CU SONNET CA MODEL."

> "VREAU CA ACEST MCP SA STIE TOATE ACESTE LUCRURI, ABSOLUT TOATE, SI CAND NU STIE SA INTRE PE TOATE LINKURILE TRIMISE DE MINE SI SA ISI ACTUALIZEZE INFORMATIILE CA SA IA CELE MAI BUNE DECIZII DE IMPLEMENTARE"

**What this expansion adds (in English):**

5. **Knowledge harvest is mandatory, not optional.** Every article and tutorial under the canonical Elementor source URLs (listed in § 1ter) must be scraped, summarised, and stored as training material the LLM consumes when planning. Many parallel **Sonnet** subagents (the user named the model — use Sonnet for the doc harvest specifically).
6. **Custom widget creation is a first-class capability.** Stonewright already has Sandbox + WidgetDefine + WidgetRegister abilities (Phase 5 of the original plan). They must be wired so the LLM can build any widget that doesn't ship with Elementor / Pro / WC out of the box, following the Elementor blog's official custom-widget recipe (`extends \Elementor\Widget_Base`, `register_controls()`, `render()`, `content_template()`, `get_script_depends()`).
7. **Self-update loop.** When the LLM hits a question the knowledge base can't answer (a new Elementor release, a non-indexed help page, a community-built widget), Stonewright must expose a `knowledge-refresh` ability that fetches the relevant URL, processes it through the same harvest pipeline, and writes it into the knowledge base — so the next request has the answer cached.
8. **Editor V3 and V4 both.** The LLM must know enough about each editor mode (V3 sections-and-columns + V3 flexbox containers + V4 atomic widgets) to choose correctly when generating a page.
9. **Theme building.** Theme Builder, Site Settings, Global Styles, Display Conditions, Site Parts — full coverage of the "Design Your Theme" knowledge area.

---

## 1ter. The required Elementor knowledge sources (MANDATORY scrape targets)

These are the canonical hub URLs the user specified. Every linked article underneath each hub must be harvested.

| # | Hub URL | Topic | Subagent batch hint |
|---|---|---|---|
| 1 | https://elementor.com/blog/custom-wordpress-widget/ | Official custom-widget walkthrough — feeds Phase G | Single agent — short article, parse code samples carefully |
| 2 | https://elementor.com/widgets/ | Marketing index of every widget (free + Pro + Theme + WC) | Single agent — link harvester only; emit per-widget URLs for further fan-out |
| 3 | https://elementor.com/help/build-with-the-editor/widgets/ | Per-widget help articles — "what does it do / how to use / settings reference" | Fan-out: ~80 parallel batches via Sonnet |
| 4 | https://elementor.com/help/build-with-the-editor/getting-started-editor/ | Editor fundamentals (V3 + V4): canvas, navigator, kit, responsive controls, history, finder | Single agent + fan-out for sub-articles |
| 5 | https://elementor.com/help/design-your-theme/ | Theme Builder, global colors/fonts, site settings, parts, display conditions | Fan-out per section |
| 6 | https://developers.elementor.com/docs/ | Developer documentation — widget API, control types, hooks (cross-reference) | Single agent or batched, low priority but recommended |
| 7 | https://elementor.com/help/ | Root help index — catches anything 3–5 didn't cover (changelogs, FAQs, account / billing skip) | Single agent — emit just the link list, filter to topic-relevant |

**Filter rule:** ignore account / billing / sales / pricing / "buy now" pages. Only ingest editor / widget / theme / developer / changelog / release-notes content.

---

## 2. Competitive intel (already done in prior session — DO NOT re-fetch)

| MCP | Architecture | Widget catalog | "Smoothness" reason |
|---|---|---|---|
| **deus-h/claudeus-wp-mcp** | TypeScript MCP server, 145 tools, one per WP REST endpoint. No Elementor abstraction at all. | N/A — passthrough; LLM writes `_elementor_data` JSON itself | Wide, flat, REST-shaped, well-described — LLM never fights a custom ontology |
| **use-novamira/novamira** | PHP plugin **inside WordPress**. Generic abilities: `execute-php`, `read-file`, `write-file`, `edit-file`, `run-wp-cli`. Pro adds per-builder specializations that manipulate Elementor runtime classes directly. | Full catalog, inherited from runtime (no manual wrapping) | Per-property validation errors so LLM auto-corrects in one try; description-gated Markdown skill playbooks; lives inside WP so no REST friction |
| **msrbuilds/elementor-mcp** | TypeScript MCP server, 51 widget-specific tools (27 free + 22 Pro + 5 WC) + universal `add-widget` escape hatch. | Complete; raw Elementor settings pass-through | One narrow tool per widget surfaces the correct setting keys as input schema; permissive validator just checks key names exist; `defined('ELEMENTOR_PRO_VERSION')` gates Pro tools at boot |

**Stonewright's design synthesis (what we choose):**

* From **msrbuilds**: one ability per widget with raw Elementor setting keys as the input schema. Plus a universal escape-hatch ability for whatever isn't covered.
* From **novamira**: live inside WordPress (already are — Stonewright is a PHP plugin), instantiate Elementor's own widget classes for validation, return structured per-property errors so the LLM corrects itself.
* From **claudeus**: keep the "one site param, multi-site native" multi-tenant shape we already have via `wp-sites.json`.
* **Beyond all of them**: a Figma-aware intent layer that detects design patterns (countdown digit groups, nav rows, social rows) and picks the right widget before the LLM has to ask.

---

## 3. Where the repo is today

* Branch: `feat/full-coverage`
* PR: #1 (https://github.com/cosmincraciun97/stonewright-wp-mcp/pull/1)
* Last commit before the bad live build (`e918b2d`): `fix(theme-builder): use ProElements condition format + rescue theme support`
* Commits since baseline (run `git log --oneline main..HEAD` for current count):
  * Phase 0 — baseline + plan
  * Phase 1.1-1.5 — Responsive helper + V3 renderer wiring + WidgetDefine compiler responsive + ReferenceArtifacts + VerifyAgainstReference
  * Phase 1.6-1.7 — Theme Builder CRUD (CreateTemplate / SetConditions / List / Get / Delete)
  * Phase 2.1-2.6 — V4 atomic (AtomicWidgetMap / AtomicRenderer / AtomicCompiler / AtomicWidgetDefine / introspection), FigmaToSpec adapter, PromptToSpec vision
  * Several fix commits: companion log to stderr, ElementorData::write no-op handling, abilities-api hook name, nested-block rendering, Section layout/gap, StyleMapper style mapping, Menu CRUD, ProElements integration

**Plugin metrics at hand-off:**

* Registered abilities: 123
* Plugin tests: 2192 passing (1 known Windows symlink env error)
* Companion tests: 148 passing (1 known symlink env error)
* Categories: security, site, content, media, gutenberg, patterns, fse, elementor, design, qa, memory, system, sandbox, elementor-widget, theme-builder, menu

**Critical Stonewright bugs found in the live build but NOT yet fixed:**

* **StyleMapper does not activate Elementor group-control toggles.** Elementor requires `background_background: 'classic'` *before* `background_color` takes effect, `typography_typography: 'custom'` before `typography_font_size`, `border_border: 'solid'` before `border_width`, `_box_shadow_box_shadow: 'yes'` before box-shadow keys. Without the toggle the value is silently dropped. This is THE bug behind "colors don't apply / typography is plain". See `plugin/includes/Elementor/Renderer/StyleMapper.php`.
* **No `countdown`, `nav-menu`, `icon-list` widgets** in the V3 Renderer dispatch (`plugin/includes/Elementor/Renderer.php`). Spec block.type enum is missing these. The live build used heading-as-countdown-digits and button-as-nav-item hacks. The user took screenshots of the resulting Elementor editor structure and called it (correctly) garbage.
* **Heading widget ignores top-level `node.url`**; only reads `node['link']['url']`. Spec schema has `url` at the top of every block so this is inconsistent.
* **Live build script created NO WP menus.** Menu CRUD abilities exist but the build hard-coded `button` widgets per nav item instead of calling `stonewright/menu-create` and pointing a `nav-menu` widget at it.
* **Figma assets were downloaded at the wrong level.** `97:2898` (whole hero composite) was exported as a single PNG which already contains title + countdown + buttons baked in, so the page showed everything twice. The stage-only sub-node was never identified.

---

## 4. Environment quick-reference (already true on this machine)

* Repo: `D:\Work\stonewright-wp-mcp`
* Local WP install: `D:\Work\LOCAL-MCP-TEST-WP\app\public` (LocalWP, site name "MCP-Test", domain `mcp-test.local`, Hello Elementor theme, ProElements 4.0.4.2, Elementor + Stonewright plugins active)
* Plugin lives in WP via junction: `wp-content\plugins\stonewright` → `D:\Work\stonewright-wp-mcp\plugin`
* App password (admin user): `z92d xBLZ HhZy BX4Z qwH3 uWv3`
* WP REST entry point for abilities: `http://mcp-test.local/wp-json/wp-abilities/v1/abilities/<name>/run` body `{"input":{...}}`
* WP_SITES_PATH for claudeus-wp-mcp: `C:\Users\cossm\.claude\wp-sites.json` (alias `mcp-test`)
* PHP 8.4 + Composer: `C:\Users\cossm\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe` — must prepend to PATH for PowerShell sessions
* Node 25 / npm 11 on system PATH
* After editing any PHP file in the plugin: `Stop-Process -Name php-cgi -Force` then `Invoke-WebRequest http://mcp-test.local/ -UseBasicParsing` to recycle FPM workers and clear opcache
* Playwright chromium installed at `%LOCALAPPDATA%\ms-playwright\chromium_headless_shell-1223\chrome-headless-shell-win64\chrome-headless-shell.exe` (use `--use-gl=swiftshader --in-process-gpu --no-sandbox` to avoid GPU errors)
* Pro Elements widget source: `D:\Work\LOCAL-MCP-TEST-WP\app\public\wp-content\plugins\pro-elements\modules\*\widgets\*.php`
* Elementor core widget source: `D:\Work\LOCAL-MCP-TEST-WP\app\public\wp-content\plugins\elementor\includes\widgets\*.php`
* **Two known test failures both environmental, both safe to ignore**: `QaArtifactStorePurgeSymlinkTest::test_purge_does_not_follow_symlink_outside_artifacts_root` (PHP, plugin suite) and `tests/path-safety.test.ts > assertInsideArtifacts > rejects a symlink that points outside the root` (TS, companion suite). Both EPERM on `symlink()` because Windows Developer Mode is off. Don't waste time on them.

---

## 5. Conventions established this branch — non-negotiable

| Convention | What | Why |
|---|---|---|
| Test path | `plugin/tests/Unit/<feature>/<Class>Test.php` — NOT `tests/Unit/Abilities/<feature>/` | Established by Phase 1.1-1.7 commits |
| Ability registry | `plugin/includes/Core/AbilityRegistry.php` `::list()` and `::categories()` | Plugin.php is the wrong file |
| Contract fixtures | Every new ability needs `tests/fixtures/abilities/<slug>.json` (positive) + `<slug>.error.json` (permission-error or invalid-input) | Else ContractTest fails |
| Slug rule | Ability name `stonewright/foo-bar-baz` → fixture file `foo-bar-baz.json` (strip `stonewright/`, replace `/` and `.` with `-`) | See `tests/Integration/ContractTest::fixture_slug()` |
| Truth matrix | After adding or renaming any ability run `composer docs:matrix` to regenerate `docs/ability-truth-matrix.md` | `AbilityTruthMatrixTest` enforces |
| Group control toggle | When emitting any group setting (background / typography / border / box-shadow) emit the activator first | Elementor silently drops sub-keys otherwise |
| Commit messages | HEREDOC for multi-line body, end with `Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>` | Consistency |
| Never push without explicit ask | Commits are fine; pushes wait | User control |

---

## 6. The plan — eight phases

### Phase 0 — Knowledge harvest (the foundation — many parallel Sonnet agents)

The user's second-round mandate. Every official Elementor URL in § 1ter becomes harvested markdown stored under `docs/knowledge/elementor/`. The harvested files become both (a) input data for later phases (Phase A.4 manifest enrichment, Phase D.1 description copy) and (b) a runtime knowledge base the LLM can search through dedicated abilities.

**0.1 — Storage layout** (single agent, fast):

```
docs/knowledge/elementor/
  README.md                       <- index of every harvested file with one-line summary
  widgets/<slug>.md               <- one per widget (free / pro / wc / theme)
  editor/<slug>.md                <- one per editor concept (canvas, navigator, kit, history, finder, responsive, theme-style)
  theme/<slug>.md                 <- one per Theme Builder topic (header / footer / single / archive / loop / popup / site-settings / display-conditions / global-styles / kit-import-export)
  developer/<slug>.md             <- developer docs (widget API, control types, hooks, atomic widgets, V4 schema, prop transformers)
  custom-widget/recipe.md         <- the blog/custom-wordpress-widget article fully extracted
  meta/sources.json               <- map of harvested-file -> canonical Elementor URL + fetch timestamp + content hash (used by Phase H refresh loop)
```

Each `.md` file has frontmatter:
```
---
title: <article title>
source_url: https://elementor.com/help/...
fetched_at: 2026-05-22T...
content_hash: sha256-...
applies_to: [widget:<slug>, editor:v3, editor:v4, theme-builder, ...]
related_widgets: [heading, button, ...]
---
```

Body: tight extraction — one-paragraph summary, then bulleted "Use this when" cases, then a "Settings highlights" subsection (linking to the technical manifest from Phase A.4), then a "Limits / gotchas" subsection. Aim for 200-400 words per file — dense, not verbose. No marketing fluff.

**0.2 — Link harvester** (1 agent, Sonnet):

Crawl the 7 hub URLs from § 1ter, emit `docs/knowledge/elementor/_links.json` listing every child article URL grouped by hub. Skip non-topic (billing / account / sales / pricing / changelog-irrelevant). Output ≤500 URLs total expected.

**0.3 — Parallel ingestion** (Sonnet, batched 8 at a time, ~10 articles per batch):

Dispatch Sonnet subagents in waves. Each subagent:

1. Gets a batch of ~10 URLs.
2. For each URL: WebFetch with a tight prompt ("Extract title, 1-paragraph purpose, 3-5 use-when cases, key settings or features mentioned, related widget slugs, any code snippets. Output as the markdown body per the schema above. No marketing copy.").
3. Writes one `.md` file per URL into the correct subdirectory.
4. Appends to `meta/sources.json`.
5. Commits the batch as `docs(knowledge): ingest <hub> batch <n>/<total> via Sonnet`.

**Critical playbook for the Sonnet harvest agents:**

* They MUST be invoked with `model: "sonnet"` — the user explicitly asked for Sonnet, and the harvest workload is exactly Sonnet's sweet spot (high volume of moderate-complexity summaries).
* They MUST commit their own batches — main thread cannot reliably stage and squash 80+ parallel writes.
* They MUST stay in their batch scope — never touch a file outside `docs/knowledge/elementor/`.
* They MUST report only file counts + commit SHAs + URLs that 404'd or were blocked — no content paraphrase back to the main thread.

**0.4 — Knowledge-base abilities** (single agent, after 0.3):

* `stonewright/elementor-knowledge-search` — input `{ query: string, area?: 'widget'|'editor'|'theme'|'developer'|'custom-widget' }`, output top 5 matching `.md` files with their summaries. Backed by a simple SQLite FTS index over the harvested files (write the indexer as a one-shot PHP script, or do an in-memory grep if FTS feels heavy for v1).
* `stonewright/elementor-describe-widget` — input `{ slug: string }`, output the merged record: manifest entry + harvested help-article body + marketing intent.
* `stonewright/elementor-explain-editor` — input `{ topic: string }` (e.g. `responsive controls`, `flex containers`, `theme styles`), output the matching `editor/` or `theme/` doc.

These three abilities are how the runtime LLM consumes the knowledge base.

**Acceptance gate for Phase 0:**

* ≥90% of the URLs in `_links.json` ingested (≤10% allowed to 404/timeout per harvest)
* `composer test` still green
* A new contract test `tests/Integration/KnowledgeBaseContractTest` asserts: every widget that exists in the registered ability list has a matching `widgets/<slug>.md` file (or a `tombstone.md` if the widget intentionally has no help article)

### Phase A — Inventory & extraction (mostly parallel)

**A.1 Widget inventory (single agent, fast)**

Scan both widget directories on disk, output `docs/superpowers/data/widget-inventory.json`:

```
{
  "elementor_version": "x.y.z",
  "pro_version": "4.0.4.2",
  "widgets": [
    { "slug": "heading", "source": "free", "file": "...elementor/includes/widgets/heading.php",
      "name": "Heading", "categories": ["basic"], "keywords": [...], "icon": "eicon-heading" },
    { "slug": "countdown", "source": "pro", "file": "...pro-elements/modules/countdown/widgets/countdown.php",
      "name": "Countdown", "categories": ["pro-elements"], ... },
    ...
  ]
}
```

For each PHP file extract `get_name()`, `get_title()`, `get_categories()`, `get_keywords()`, `get_icon()`. Mark `source` based on whether the file is under `elementor/` or `pro-elements/`. Include WooCommerce widgets if `class_exists('WooCommerce')` resolves on the live site.

**A.2 Per-widget setting extraction (8 parallel agents, batched)**

Split the inventory into 8 equal batches by widget count. Each agent:

* For each widget file in its batch, walk `register_controls()` recursively (the method opens sections via `start_controls_section`, adds controls via `add_control`, group controls via `add_group_control`, repeater controls via `Repeater`).
* Emit per-widget JSON: `{ slug, sections: [{ id, label, tab, conditions, controls: [...] }], group_controls: [{ name, prefix, label }], repeaters: [{ key, fields: [...] }] }`.
* Each control entry: `{ key, type, label, default, options?, responsive?: bool, conditions?, dynamic?: bool }`.
* Store under `docs/superpowers/data/widget-controls/<slug>.json`.

Subagent prompts must include: the exact `register_controls()` parsing approach (parse the AST or do regex extraction of `$this->add_control( 'key', [...] )` blocks; AST is more reliable for nested conditional blocks but regex is faster — pick AST via `nikic/php-parser` since it's already a Composer dep on the plugin side).

**A.3 Marketing intent fetch (1 parallel agent, separate from A.2)**

For each widget in the inventory, fetch `https://elementor.com/widgets/<slug>/` and extract:

* One-line description ("Use this widget to ...")
* Primary use cases (3-5 bullets)
* Related widgets (sometimes linked at bottom)

Store as `docs/superpowers/data/widget-intent/<slug>.md`. Skip 404s silently — not every widget has a marketing page.

**A.4 Synthesis (single agent, after A.1-A.3)**

Merge into `plugin/includes/Elementor/WidgetRegistry/manifest.json`:

```
{
  "<slug>": {
    "source": "free|pro|wc",
    "widget_type": "<elementor get_name>",
    "title": "...",
    "icon": "eicon-...",
    "categories": [...],
    "intent": "Use when you need a horizontal navigation menu linked to a WP menu",
    "use_cases": [...],
    "settings": { "<key>": { "type": "...", "default": ..., "responsive": true|false, "group": "typography|background|border|null" } },
    "group_activators": { "background": "background_background:'classic'", "typography": "typography_typography:'custom'", ... },
    "required_for_render": ["title"]  // minimum keys to produce visible output
  }
}
```

This manifest is the canonical source for everything downstream.

### Phase B — Critical bug fixes (single thread, small commits)

Do these *before* any new renderer work. Each is a small, surgical fix with its own commit.

**B.1 — StyleMapper group-toggle activation.**

In `plugin/includes/Elementor/Renderer/StyleMapper.php`, when emitting a setting that belongs to a group, emit the activator key first. Map (from Elementor source):

| Activator key | Activator value | Sub-keys it enables |
|---|---|---|
| `background_background` | `'classic'` | `background_color`, `background_image`, `background_position`, `background_size` |
| `_background_background` | `'classic'` | `_background_color`, `_background_image` (wrapper variant) |
| `typography_typography` | `'custom'` | `typography_font_family`, `typography_font_size`, `typography_font_weight`, `typography_line_height`, `typography_letter_spacing`, `typography_text_transform`, `typography_text_decoration`, `typography_font_style` |
| `border_border` | `'solid'` (or the user-specified style: `dashed`, `dotted`, `double`, `groove`) | `border_width`, `border_color` |
| `_box_shadow_box_shadow` | `'yes'` | `_box_shadow_box_shadow_type` keys |
| Anything with `_<group>_<group>` suffix pattern | enabling value | sub-keys |

Rule of thumb: if you see a setting key whose prefix matches `<x>_<x>` exactly, that's the activator. Detect programmatically from the manifest's `group` field on each setting.

Add a unit test `StyleMapperGroupToggleTest` that asserts: setting `background_color` also emits `background_background: 'classic'`; setting `typography_font_size` also emits `typography_typography: 'custom'`; etc.

**B.2 — Heading widget honors top-level `node.url`.**

`plugin/includes/Elementor/Renderer/Heading.php`. Accept both `node['url']` and `node['link']['url']`; emit `settings.link = { url, is_external: false, nofollow: false }`.

**B.3 — `Renderer/Countdown.php`** emitting `widgetType: 'countdown'`. Real settings from `pro-elements/modules/countdown/widgets/countdown.php`:

```
countdown_type: 'due_date' | 'evergreen'
due_date: 'YYYY-MM-DD HH:MM' (Elementor's format)
show_days: 'yes' | 'no'
show_hours: 'yes' | 'no'
show_minutes: 'yes' | 'no'
show_seconds: 'yes' | 'no'
label_days, label_hours, label_minutes, label_seconds: string
show_labels: 'yes' | 'no'
expire_actions: 'message' | 'redirect'  (plus follow-up keys)
+ all the typography / color group settings on `digits_*` and `label_*` prefixes
```

DesignSpec extension: add `countdown` to `block.type` enum in `plugin/schemas/stonewright.schema.json`.

**B.4 — `Renderer/NavMenu.php`** emitting `widgetType: 'nav-menu'` (Pro). Settings:

```
menu: <term_id as string>   (the wp_create_nav_menu return value)
layout: 'horizontal' | 'vertical' | 'dropdown'
align_items: 'start' | 'center' | 'end' | 'stretch'
pointer: 'none' | 'underline' | 'overline' | 'double-line' | 'framed' | 'background' | 'text'
+ typography_*, color_menu_item, color_menu_item_hover, color_menu_item_active, etc.
```

Fallback when Pro is not active: render as an inline `icon-list` with `link_click: 'inline'` to mimic horizontal nav. Detect via `defined('ELEMENTOR_PRO_VERSION')`.

**B.5 — `Renderer/IconList.php`** emitting `widgetType: 'icon-list'` (free). Settings include a repeater:

```
icon_list: [
  { text: '...', link: { url: '...', is_external: false, nofollow: false },
    selected_icon: { value: 'fas fa-check', library: 'fa-solid' } },
  ...
]
view: 'traditional' | 'inline'
icon_align: 'left' | 'right'
link_click: 'full_width' | 'inline'
+ typography + color settings
```

DesignSpec: add `icon-list` to `block.type` enum.

**B.6 — Universal escape hatch ability.**

`stonewright/elementor-v3-add-raw-widget`. Category `elementor`. Input: `{ post_id, widget_type: string, settings: object, parent_id?: string, position?: int }`. Output: `{ element_id, post_id }`. Bypasses DesignSpec entirely — the LLM writes the literal Elementor JSON it wants. This matches msrbuilds' `add-widget` escape hatch and is the last-resort guarantee that Stonewright can't ever block an Elementor capability.

### Phase C — Per-widget abilities (parallel, after A.4 manifest is ready)

For every widget in `manifest.json`:

* Generate `plugin/includes/Abilities/ElementorWidgets/<PascalCase>.php` ability class.
  * `name()`: `stonewright/elementor-add-<slug>`
  * `category()`: `elementor-widget`
  * `description()`: combine the marketing intent + a "USE THIS WHEN" sentence
  * `input_schema()`: `{ post_id, parent_id?, position?, settings: { ...flat list of every key from manifest, with type, description from manifest, default, enum where applicable } }`
  * `permission_callback()`: `Permissions::edit_post( $args['post_id'] )`
  * `execute()`: reads the manifest entry, emits the literal Elementor element JSON, calls `ElementorData::insert()` at the requested path
* Generate 2 contract fixtures per ability (positive + permission error)
* Register all in AbilityRegistry under category `elementor-widget`

**Subagent dispatch:** split the widget list into 8 batches of ~10 each. Each subagent generates ~30 files (10 ability classes + 20 fixtures). Critical: subagents must commit their own batch (the previous session learned this the hard way — main thread cannot reliably stage subagent output).

After all batches land: `composer docs:matrix` and one final commit `chore(docs): regenerate truth matrix after widget catalog`.

### Phase D — Smart-detection layer

**D.1 Description-as-detection (no code).**

Every ability's `description()` includes a "USE THIS WHEN" stanza with 3-4 concrete signature examples. The model reads tool descriptions during planning, so verbose, example-rich descriptions are the primary detection mechanism. Pattern:

```
description: |
  Renders an Elementor Countdown widget. USE THIS WHEN you see:
    • A row of large numeric digits separated by colons (e.g. "63 : 11 : 18")
    • Labels like "DAYS / HOURS / MIN / SEC" or i18n equivalents (Zile/Ore/Min, Tage/Stunden, Jours/Heures)
    • An event-start countdown, an offer-expiry timer, or an evergreen "ends in" timer
  DO NOT simulate a countdown with separate Heading widgets — the live timer requires this widget.
```

**D.2 FigmaToSpec pattern recognizer.**

Extend `plugin/includes/Design/FigmaToSpec.php` with a `WidgetIntentResolver`. Run BEFORE the per-node walk. Pattern signatures:

| Signature in Figma | Emit |
|---|---|
| ≥3 child frames each containing a numeric TEXT (1-2 chars), arranged horizontally, with `:` separator text nodes between | `countdown` block |
| Horizontal row of ≥3 TEXT nodes each with `hyperlink` style or fill of brand color + same typography | `nav-menu` block (+ requires upstream `menu-create`) |
| Vertical stack of TEXT nodes with bullet/dot/check icon prefix | `icon-list` block |
| Row of ≥3 small circular frames each containing a single VECTOR (social glyph) | `social-icons` block |
| Frame named "Footer*" + 3-4 sub-columns of repeated text rows | flag as `footer` template type; sub-columns → `icon-list` blocks |
| Frame named "Header*" + horizontal layout + repeated text rows | flag as `header` template type; rows → `nav-menu` blocks |
| FRAME with single TEXT + bg fill + reasonable padding ratio | `button` |

**D.3 Structured validation errors.**

Stonewright's `Validator` should accept the literal Elementor settings dict and return `WP_Error` with `data.violations = [{ path: 'settings.typography_font_size', code: 'group_toggle_missing', expected: 'typography_typography:custom', got: <missing> }]`. The error shape matches Novamira's "fix yourself in one try" pattern.

**D.4 WidgetIntent resolver tool.**

New ability `stonewright/widget-intent-resolve`. Input: `{ intent: 'nav' | 'countdown' | 'social-row' | 'logo+nav' | 'hero-cta-pair' | ..., context?: object }`. Output: `{ widget: <slug>, settings_template: {...}, required_steps: ['create_menu_first', 'upload_logo_first', ...] }`. Lets the LLM call a single tool and get the right widget choice + pre-filled settings template.

### Phase E — nZeb Expo live build (redo, end to end)

Now the build looks like a real designer's output instead of a stack of heading hacks.

1. **Find the right Figma sub-nodes**:
   * Desktop hero stage photo — child of `97:2898`, the FRAME named "stage" or whatever the metadata reveals. The previous session never went that deep; do it now.
   * Logo — `97:3739` (top header) and `97:8869`+children (mobile)
   * Social icons — sub-nodes of the footer's social row
   * Export each at its own node level, never the parent composite
2. **Create the real menus** (call `stonewright/menu-create` then `menu-add-item` per item):
   * `top-menu`: Ediții, Despre Nzeb Expo, Media, Noutăți, Echipă
   * `secondary-menu`: Program, Speakeri, Expozanți, Parteneri
   * `footer-col-1`: Despre nZEB Expo, Misiune, Pentru cine este, nZEB Expo în cifre, Media Kit & Presă, Ediții
   * `footer-col-2`: Program, Speakeri, Parteneri, Informații rapide, Hartă eveniment, Bilet gratuit, Devino expozant
3. **Header spec** uses one `image` widget (logo), two `nav-menu` widgets (top + secondary), one `button` widget for "Devino expozant" outline, one `button` widget for "Obține bilet gratuit" yellow. NO more 9 buttons pretending to be a menu.
4. **Hero spec** uses one `heading` (title), one `paragraph` ("EVENIMENTUL ÎNCEPE ÎN:"), one `countdown` widget with `due_date: '2026-06-11 09:00'` + `show_days/hours/minutes: 'yes'` + `label_days: 'ZILE'` etc., two `button` widgets (outline + yellow), and one `image` widget (stage photo only).
5. **Footer spec** uses three `icon-list` widgets (one per column) with each item carrying its `text` + `link.url`, one `social-icons` widget (real one — not styled buttons), and one bottom row of `paragraph` widgets with embedded links via `node.url`.
6. **Run** `stonewright/qa-verify-against-reference` with the Figma screenshots as reference. Iterate until diff < 3% per viewport.

### Phase F — Documentation + release

* CHANGELOG entries for: bug fixes (StyleMapper, Heading link), new widget category (count), smart-detection layer, escape-hatch ability
* `docs/architecture/widget-catalog.md` — how the manifest works, how to add a new widget
* `docs/architecture/group-controls.md` — the activator rule (so the next contributor doesn't repeat the bug)
* Push to `feat/full-coverage`, update PR #1 description with new metrics + the nZeb pixel diff result
* (Optional) cherry-pick the StyleMapper fix to a hotfix branch if it needs to land independently before the big catalog merge

### Phase G — Custom widget creation (live-build from spec, current Elementor API)

The Elementor blog walkthrough at https://elementor.com/blog/custom-wordpress-widget/ is the official, up-to-date recipe. Stonewright already has the building blocks — `Sandbox*` abilities (write PHP files under a sandboxed dir + StaticGuard scan), `WidgetDefine` (compile DSL to PHP source), `WidgetRegister` (activate the compiled widget). Phase G wires them into a single coherent "create me a custom widget" surface.

**G.1 — Sync the recipe with the harvested article** (`docs/knowledge/elementor/custom-widget/recipe.md`).

The article says: extend `\Elementor\Widget_Base`, override `get_name() / get_title() / get_icon() / get_categories() / get_keywords()`, implement `register_controls()`, `render()`, `content_template()`, `get_script_depends()`, `get_style_depends()`. Confirm the existing `WidgetDefine` compiler emits all of these — if it doesn't, extend it.

**G.2 — High-level ability** `stonewright/elementor-create-custom-widget`. Input shape:

```
{
  slug: 'my-pricing-card',         // becomes the widget_type at runtime
  title: 'Pricing Card',
  icon: 'eicon-price-list',
  categories: ['general'],
  keywords: ['pricing', 'card', 'plan'],
  props: [
    { name: 'plan_name', type: 'string', default: 'Pro' },
    { name: 'price',     type: 'string', default: '$29' },
    { name: 'features',  type: 'repeater', fields: [
        { name: 'text', type: 'string' },
        { name: 'included', type: 'switcher' }
    ]},
    { name: 'cta_text',  type: 'string', default: 'Buy' },
    { name: 'cta_link',  type: 'url' }
  ],
  template: '<div class="card"><h3>{{ plan_name }}</h3><p class="price">{{ price }}</p>...</div>',
  styles: '.card { padding: 24px; ... }',     // optional — bundled CSS
  script_url: null,                            // optional external JS
  activate: true                               // call WidgetRegister after compile
}
```

Output: `{ class_name, php_source, sandbox_path, registered: bool, widget_type }`. Pipeline:

1. Compile via `WidgetDefine` → PHP source string
2. Run through `StaticAnalysis\StaticGuard` (existing — rejects eval / file_put_contents / exec etc.)
3. Write to `wp-content/stonewright-sandbox/widget-<slug>.php` via `SandboxWrite`
4. Activate via `SandboxActivate` (creates an mu-plugin loader)
5. Call `WidgetRegister` so Elementor sees it on next request
6. (Optional) `composer docs:matrix` and update `widget-inventory.json` to mark the slug as `source: custom`

**G.3 — Smart custom-widget detection.** When the FigmaToSpec resolver in Phase D meets a node pattern that doesn't match any built-in widget heuristic (e.g. a unique card layout with non-standard props), it emits a fallback hint: `{ type: 'custom_widget_candidate', signature_hash, suggested_props }`. A new sibling ability `stonewright/widget-intent-promote-to-custom` takes that candidate, generates a `create-custom-widget` input, and (with the user's confirmation token) runs G.2.

**G.4 — Templates library.** Bundle a small set of pre-baked custom-widget templates (`docs/knowledge/elementor/custom-widget/templates/`) for the most common gaps Elementor doesn't have natively (a "pricing-card", a "feature-tile", an "icon-stat-counter-with-suffix"). The LLM can copy + tweak instead of writing from scratch.

### Phase H — Self-update loop ("don't know? fetch it")

User requirement: when the MCP doesn't know something, it should crawl the relevant Elementor link and learn.

**H.1 — `stonewright/elementor-knowledge-refresh` ability.** Input: `{ url: string, hub?: 'widgets'|'editor'|'theme'|'developer'|'custom-widget' }`. Behavior:

1. WebFetch the URL with the same Phase 0.3 extraction prompt.
2. Compute content hash; if it differs from the last fetch (recorded in `meta/sources.json`) write the new markdown into the right subdirectory.
3. If the URL is new (not in `_links.json`), append it.
4. Rebuild the FTS index (Phase 0.4) so subsequent `knowledge-search` calls see the new content.
5. Return `{ slug, file_path, content_changed: bool, updated_at }`.

**H.2 — Auto-refresh policy.** Add an option `stonewright_knowledge_max_age_days` (default 30). When `elementor-describe-widget` or `elementor-explain-editor` is called and the matching `.md`'s `fetched_at` is older than the threshold, emit a warning in the response payload (`stale: true, age_days: N`) but still return the cached content. The LLM can then opt to call `knowledge-refresh` if it cares.

**H.3 — Diff awareness.** When `knowledge-refresh` updates a file with a new content hash, append a one-line entry to `docs/knowledge/elementor/_change_log.md` so the next maintainer can review what Elementor changed. Bonus: feed the change log into a periodic `stonewright/elementor-changelog-summary` ability that returns "what changed in the docs in the last N days".

**H.4 — Bulk refresh CLI script.** `plugin/bin/refresh-knowledge.php` — iterates `meta/sources.json`, calls H.1 for each entry older than the threshold, in batches of 8 with backoff. Cron-able via WP-CLI.

**Acceptance gates for G + H:**

* G.2 round-trip test: create a custom widget via the ability, verify it appears in `wp_get_widget_types()` and renders with a sample settings payload.
* H.1 unit test: stub WebFetch, assert markdown is rewritten only when hash changes, assert FTS rebuild is triggered.
* End-to-end: invoke `knowledge-search` for a term that doesn't exist in the harvested base → confirm a documented escalation path ("try `knowledge-refresh` against `<best-guess-url>`") shows in the empty-result envelope.

---

## 7. Subagent dispatch playbook (lessons from this session)

* **Model choice.** The user explicitly requested **Sonnet** for the documentation harvest (Phase 0). Pass `model: "sonnet"` on every Agent call for Phase 0 + Phase A.3 (marketing copy fetch). For code-implementing agents (Phase A.2 setting extraction, Phase C ability generation, Phase B fixes) the default Sonnet is also the right choice — Phase B may use Opus for the StyleMapper rewrite if precision matters. The point: do not silently downgrade Sonnet to Haiku for the harvest; the user named the model and named it for a reason (cost-effective high-volume summarisation).
* **Subagents must commit their own work.** Main thread cannot reliably `git add` files a subagent created — the previous session learned this twice (Phase 2.1 and Phase 2.5). Include `git add ... && git commit -m "..."` in every subagent prompt. Heredoc the commit body.
* **Keep subagent file scopes orthogonal.** If two subagents touch the same directory you get merge friction. Phase 0.3 batches must each own a distinct set of URLs writing to distinct filenames. Phase A.2 batches must each own a distinct set of widget files. Phase C batches must each own a distinct alphabetical range of widget abilities.
* **Tell subagents the known environmental failures so they don't chase them.** The Windows symlink tests in `QaArtifactStorePurgeSymlinkTest` (PHP) and `tests/path-safety.test.ts` (companion) error out — ignore both.
* **Subagent reports under 400 words, structured.** Ask for `(a) item counts (files written, URLs ingested, tests added), (b) commit SHA(s), (c) any deviation from the spec, (d) one surprise worth flagging`.
* **Tell subagents the path conventions verbatim.** `tests/Unit/<feature>/`, `includes/Core/AbilityRegistry.php`, 2 contract fixtures, run `composer docs:matrix` after.
* **Trust but verify.** After every subagent claims done, the main thread reads the commit and at least spot-checks one new file. The prior session was burned twice by subagent reports that didn't match reality.
* **Concurrent subagent budget.**
  * Code-modifying (PHP / TS): 3-4 truly concurrent. Beyond that, FS contention + AbilityRegistry merge conflicts degrade quality.
  * Doc-harvesting (Sonnet, write-only into `docs/knowledge/elementor/`): up to **8 truly concurrent**, and queue more waves behind them. The work is read-mostly (WebFetch + write to a unique file path), so the conflict surface is tiny. The user expects the harvest to fan out wide and finish fast.
* **No "fire and forget" subagents on the critical path.** If the work blocks a later phase (e.g. Phase 0 blocks Phase A.4 manifest enrichment), wait on the notification, read the result, then proceed. Background only for genuinely independent work.
* **Sonnet harvest prompt template** (copy-paste):

  > You are extracting Elementor documentation into a structured knowledge base for an MCP server. **Use the WebFetch tool** to fetch each URL in your batch (passed in the prompt). For each URL, write **one markdown file** under `D:\Work\stonewright-wp-mcp\docs\knowledge\elementor\<subdir>\<slug>.md` with this frontmatter:
  >
  > ```yaml
  > ---
  > title: <article title>
  > source_url: <the URL>
  > fetched_at: <ISO 8601 timestamp>
  > content_hash: <sha256 of body>
  > applies_to: [widget:<slug>|editor:v3|editor:v4|theme-builder|...]
  > related_widgets: [list of widget slugs mentioned]
  > ---
  > ```
  >
  > Body sections in order: `## Purpose` (1 paragraph, what the feature is for), `## Use this when` (3-5 bullets), `## Settings highlights` (key control keys + 1-line explanations), `## Limits / gotchas` (anything that surprises a new user). 200-400 words, dense, no marketing fluff. If the page is marketing/billing/sales-only, write a stub with `tombstone: true` in frontmatter and skip the body.
  >
  > After all URLs processed, append entries to `docs/knowledge/elementor/meta/sources.json`, then git add + git commit your batch with message `docs(knowledge): ingest <hub> batch <N>/<total> via Sonnet`. Report file count + commit SHA + any 404 URLs. Under 200 words.

## 8. Quality gates (cannot claim done without these)

* `composer test` on `plugin/` — green except the known 1-error Windows symlink
* `npm test` on `companion/` — green except the known 1-fail Windows symlink
* `composer docs:matrix` produces zero diff after the run (matrix matches registry)
* Live homepage at `http://mcp-test.local/` HTML inspection: every expected widgetType appears in the rendered DOM (search for `widgetType="countdown"`, `widgetType="nav-menu"`, `widgetType="icon-list"`)
* `stonewright/qa-verify-against-reference` returns `passed: true` for desktop + mobile reference screenshots
* Visual screenshot review handed to the user; wait for explicit OK; never preempt with "this looks good" — the previous session said "hero is great" when it manifestly wasn't and the user (correctly) blew up

## 9. What NEVER to do again (failure log from this session)

1. **Never claim "great" / "works" without pixel diff.** The previous session said "hero is great" when the hero had Figma-export text baked in next to the live DOM text — visibly broken. User was right to call it out.
2. **Never download a parent Figma composite as an image.** It will contain text that the page is also rendering, producing duplicates. Always find the most-specific sub-node.
3. **Never simulate a widget with another widget.** Countdown ≠ three Heading widgets. Nav menu ≠ four Button widgets. Icon list ≠ stacked Paragraph widgets.
4. **Never write color/typography without the group toggle activator.** It silently disappears. Document it in the manifest as `group_activators` so it's a structural property of the renderer, not something you can forget.
5. **Never let a build script create nav items as buttons because the menu CRUD "feels like extra work".** The user noticed. They will notice every time.
6. **Never skip the qa-verify-against-reference step.** It exists from Phase 1.5 specifically to catch the kind of "looks shit" outcome that the previous session shipped without checking.

## 10. Resume checklist for a new session

When you re-enter this work, do these in order before *any* code:

1. `git status && git log --oneline -10` — confirm where the branch is
2. `cd plugin && composer test 2>&1 | Select-Object -Last 6` — confirm baseline (one known error allowed)
3. Read this file end to end (you are doing it now)
4. Read `D:\Work\stonewright-wp-mcp\docs\superpowers\plans\2026-05-22-stonewright-full-coverage-completion.md` (the original 29-task plan — still has open Phase 3 / 4 / 5 items)
5. Run a quick probe: `Invoke-WebRequest http://mcp-test.local/ -UseBasicParsing | Select -Expand StatusCode` — confirm the live site is up
6. Start phases in this order:
   * **Phase 0** (knowledge harvest, Sonnet fan-out) AND **Phase B** (critical bug fixes, single thread) in parallel. They don't overlap.
   * Then **Phase A** (widget inventory + extraction).
   * Then **Phase C** (per-widget abilities, depends on A's manifest).
   * Then **Phase D** (smart detection, depends on Phase 0 knowledge base + Phase A manifest).
   * Then **Phase E** (nZeb live build redo — the user-facing proof).
   * Phases F / G / H follow E.

The user wants to see Phase E running end to end with a pixel-perfect nZeb result, on top of a Stonewright that genuinely knows the whole Elementor surface (Phases 0 + A + C + D) and can build custom widgets on demand (Phase G) and self-update its knowledge (Phase H). Every phase before E is in service of that proof.

---

## 11. What to put in the first message of the new session (the user's own words)

The user explicitly asked: "*completeaza din nou planul si ce sa spun agentului in noua sesiune cu exact cuvintele mele, sau aproape de ce am zis, ca sa inteleaga ce sa faca.*"

The block below is the user's request reframed for hand-off. Copy it verbatim into the first message of the new session, *after* asking the agent to read `D:\Work\stonewright-wp-mcp\docs\superpowers\plans\2026-05-22-elementor-mastery-plan.md`. The agent will then have both the user's intent in the user's voice and the structured plan to execute against.

> **Read** `D:\Work\stonewright-wp-mcp\docs\superpowers\plans\2026-05-22-elementor-mastery-plan.md` **end-to-end before any tool call.** Then act on the brief below.
>
> ---
>
> Vreau ca Stonewright MCP să fie mai șmecher decât novamira, msrbuilds/elementor-mcp și claudeus-wp-mcp. Ne putem inspira de la ei (analiza e deja făcută în plan, secțiunea § 2 — nu o refaci), dar arhitectural să ajungem peste ei.
>
> **Concret, vreau ca acest MCP să știe absolut tot despre Elementor (free + Pro + WooCommerce + V3 + V4 + Theme Builder + Site Settings + custom widget creation):**
>
> 1. **Knowledge harvest obligatoriu.** Intră pe TOATE link-urile de mai jos cu subagenți paraleli pe model `sonnet`. Fiecare articol/tutorial devine un fișier markdown în `docs/knowledge/elementor/` cu schema din plan § 0.1. Fără excepție.
>    * https://elementor.com/blog/custom-wordpress-widget/ — recipe-ul oficial pentru widget custom; aplică-l ca în Phase G
>    * https://elementor.com/widgets/ — indexul de widgeturi (free + Pro + Theme + WC)
>    * https://elementor.com/help/build-with-the-editor/widgets/ — articol-per-widget cu tips, what/how/when; Stonewright trebuie să știe toate tips-urile astea
>    * https://elementor.com/help/build-with-the-editor/getting-started-editor/ — editorul Elementor V3 ȘI V4, ce e posibil, ce nu, când să folosești fiecare
>    * https://elementor.com/help/design-your-theme/ — Theme Builder complet (header / footer / single / archive / loop / popup / site settings / global styles / display conditions)
>    * Plus root-ul https://elementor.com/help/ pentru orice link copil pe care îl mai prinde
>    * Plus https://developers.elementor.com/docs/ pentru API-uri developer
>
> 2. **Custom widget creation.** Stonewright are deja Sandbox + WidgetDefine + WidgetRegister abilities. Wire-le în `stonewright/elementor-create-custom-widget` cum e în plan § G.2. Vreau să pot crea widgeturi custom up-to-date, orice widget posibil cu Elementor free sau Pro, prin Stonewright.
>
> 3. **Catalogul complet de widgeturi.** Toate widgeturile native (Elementor + Pro + WooCommerce) ca abilități individuale, fiecare cu setting-urile reale ca input schema. Plus un escape hatch universal `stonewright/elementor-v3-add-raw-widget` pentru orice n-am acoperit.
>
> 4. **Smart detection.** AI-ul trebuie să știe ce, cum și când să aplice fiecare widget. Bagi în ability descriptions "USE THIS WHEN" cu cazuri concrete (3-4 signature-uri vizuale). Plus FigmaToSpec heuristică să detecteze pattern-urile (countdown digit pairs → widget countdown, nav row → widget nav-menu, social row → social-icons). Plus structured per-property validation errors ca LLM să se corecteze în 1 try (pattern Novamira).
>
> 5. **Self-update.** Când Stonewright nu știe ceva, expune `stonewright/elementor-knowledge-refresh` cu URL ca input — fetchează, parsează, scrie în knowledge base, rebuild index. Așa MCP-ul învață singur fără să trebuiască să-l reantrenez manual.
>
> 6. **Editor V3 + V4.** Antrenat pentru ambele moduri. AI-ul trebuie să știe când să folosească fiecare.
>
> 7. **Design Your Theme.** Theme Builder, Global Styles, Site Settings, Display Conditions — tot.
>
> **CUM îl rulezi:**
> * Spamează mulți agenți Sonnet în paralel — knowledge harvest e perfect pentru fan-out (până la 8 concurenți, în valuri).
> * Subagentii trebuie să-și commit-eze singuri batch-urile (lecție din sesiunea trecută — main thread nu poate stage corect 80+ scrieri paralele).
> * Pentru work-ul de cod (Phase B fix-uri, Phase A extraction, Phase C ability generation): max 3-4 concurenți, scope-uri ortogonale.
> * Folosește template-ul de prompt din plan § 7 pentru agenții Sonnet de harvest.
>
> **REGULI dure (din eșecul sesiunii trecute, nu mai accept asta):**
> * Niciodată "merge" / "great" / "looks good" fără să rulezi `stonewright/qa-verify-against-reference` și să-mi arăți rezultatul pixel-diff.
> * Niciodată nu simulezi un widget cu altul. Countdown ≠ 3× Heading. Nav menu ≠ 4× Button. Footer link ≠ Paragraph fără link.
> * Niciodată nu descarci compozite Figma — găsești sub-nodul exact pentru fiecare asset.
> * StyleMapper-ul TREBUIE să activeze group toggles (`background_background:'classic'` înainte de `background_color`, `typography_typography:'custom'` înainte de `typography_font_size`, etc.) — fără asta culorile și typography dispar silent.
> * Subagentii — verifică-le commit-urile, nu doar raportele. Trust but verify.
>
> Pornește de la Phase 0 (knowledge harvest Sonnet paralel) și Phase B (bug fixes critice) în paralel. Apoi Phase A, C, D, E. Apoi G, H. Vezi planul § 6 pentru tot graful de dependențe.

---

*End of plan. Length: ~900 lines. Save the file; do not delete it after the work is done — keep as a postmortem reference. When phases land, append a "Phase X — done at commit `<sha>`" note at the bottom of this file rather than rewriting the plan body.*

---

## 12. Phase landing log (append-only, oldest at top)

* (none yet — first entry will be the new session)
