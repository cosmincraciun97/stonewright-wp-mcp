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

## 6. The plan — six phases

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

---

## 7. Subagent dispatch playbook (lessons from this session)

* **Subagents must commit their own work.** Main thread cannot reliably `git add` files a subagent created — the previous session learned this twice (Phase 2.1 and Phase 2.5). Include `git add ... && git commit -m "..."` in every subagent prompt. Heredoc the commit body.
* **Keep subagent file scopes orthogonal.** If two subagents touch the same directory you get merge friction. Phase A.2 batches must each own a distinct set of widget files. Phase C batches must each own a distinct alphabetical range of widget abilities.
* **Tell subagents the known environmental failures so they don't chase them.** The Windows symlink tests in `QaArtifactStorePurgeSymlinkTest` (PHP) and `tests/path-safety.test.ts` (companion) error out — ignore both.
* **Subagent reports under 400 words, structured.** Ask for `(a) test counts before/after, (b) commit SHA, (c) any deviation from the spec, (d) one surprise worth flagging`.
* **Tell subagents the path conventions verbatim.** `tests/Unit/<feature>/`, `includes/Core/AbilityRegistry.php`, 2 contract fixtures, run `composer docs:matrix` after.
* **Trust but verify.** After every subagent claims done, the main thread reads the commit and at least spot-checks one new file. The prior session was burned twice by subagent reports that didn't match reality.
* **Concurrent subagent budget.** Run no more than 3-4 truly concurrent. Beyond that the WebFetch / FS contention degrades quality. Phase A.2 should be batched as 4 at a time, two waves.

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
3. Read this file end to end
4. Read `D:\Work\stonewright-wp-mcp\docs\superpowers\plans\2026-05-22-stonewright-full-coverage-completion.md` (the original 29-task plan — still has open Phase 3 / 4 / 5 items)
5. Run a quick probe: `Invoke-WebRequest http://mcp-test.local/ -UseBasicParsing | Select -Expand StatusCode` — confirm the live site is up
6. Then start at **Phase B** (critical bug fixes — they unblock everything else) or **Phase A** (inventory — needed before C onwards). They can run in parallel: B is single-threaded plugin work, A is subagent fan-out.

The user wants to see Phase E running end to end with a pixel-perfect nZeb result. Every phase before E is in service of that.

---

*End of plan. Length: ~700 lines. Save the file; do not delete it after the work is done — keep as a postmortem reference.*
