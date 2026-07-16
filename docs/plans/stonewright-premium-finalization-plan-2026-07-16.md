# Stonewright Premium Finalization & Polish Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every gap left open by PR #8 (`feat/premium-enhancement-2026-07`): fix admin UI defects, add tooltips, make the prompt library a real tab, make profile switching actually work end-to-end, make blueprint/brand-kit output genuinely premium and native on Elementor + Gutenberg + FSE with user-selectable engine, ship the Figma→native pixel-perfect workflow, and cover it all with interaction-level Playwright tests.

**Architecture:** All work continues on branch `feat/premium-enhancement-2026-07` (worktree `.worktrees/feat-connection-center-e2e`) or follow-up branches off it. Phases 0–2 are fully specified (complete code). Phases 3–5 are larger: each REQUIRES a per-phase design pass with superpowers:writing-plans before code, using the acceptance criteria and file anchors given here. TDD everywhere: failing test → minimal code → green → commit.

**Tech Stack:** PHP 8.1+ (plugin, PHPUnit/PHPStan/PHPCS), TypeScript/Node (companion, vitest/eslint), Playwright (`e2e/` package + Playwright MCP for interactive diagnosis), WordPress abilities API + MCP adapter.

**OAuth is explicitly OUT OF SCOPE.** It stays a roadmap-only item (see Phase 7.3). Do not implement any OAuth flow in this plan.

---

## Verified audit findings (evidence — do not re-audit, build on this)

All file:line references are against branch `feat/premium-enhancement-2026-07`.

| # | Finding | Evidence |
|---|---------|----------|
| F1 | Notice drawer has only 16px top margin under sticky header; `--sw-shell-offset` computed in JS (`plugin/assets/admin/shell.js:25-38`) but never consumed by CSS | `plugin/assets/admin/shell.css:735-740` |
| F2 | `.sw-btn--primary` declares `color: var(--sw-on-brand)` (#fff) but renders wrong live → something overrides it. Suspects: wp-admin core button styles; legacy `plugin/assets/css/stonewright-admin.css` enqueued unconditionally in `plugin/includes/Admin/AdminBootstrap.php:86-91` | `plugin/assets/admin/shell.css:839-849` |
| F3 | Zero tooltip infrastructure in admin (no tooltip/aria-describedby/popover code anywhere); only 3 bare `title` attrs | grep across `plugin/includes/Admin`, `plugin/assets` |
| F4 | "Apply to draft" is clipboard-copy only (copies an AI prompt); no AJAX/REST. Real apply happens only when an agent calls `stonewright/blueprint-apply` | `plugin/includes/Admin/Pages/BlueprintsPage.php:87-93,250-271`; `plugin/assets/admin/shell.js:217-246` |
| F5 | `.stonewright-domain-lock-status` rendered by `ConfigurationPage::render_domain_lock_status()` has NO CSS rule anywhere; orphan `.sw-domain-lock` rules exist in legacy CSS but never match | `plugin/includes/Admin/ConfigurationPage.php:1195-1211`; `plugin/assets/css/stonewright-admin.css:361-378` |
| F6 | Prompt library is a disclosure link inside Setup page, not a tab; 12 prompts; tests are shape-only | `plugin/includes/Admin/ConfigurationPage.php:580-590`; `plugin/data/prompts/catalog.json`; `plugin/tests/Unit/Support/PromptCatalogTest.php` |
| F7 | Method picker cards (stdio / Streamable HTTP) have one-line blurbs, no tooltips/guidance on which to choose | `plugin/includes/Admin/ConfigurationPage.php:745-764` |
| F8 | Profile switching is non-functional end-to-end: (a) plugin MCP transport never declares `listChanged` capability (`plugin/vendor/wordpress/mcp-adapter/includes/Handlers/Initialize/InitializeHandler.php:50` → `'tools' => new stdClass()`) and never sends `notifications/tools/list_changed`; (b) companion computes its tool list ONCE per process from env (`companion/src/wordpress-mcp.ts:526-618,675-690`) — its `sendToolListChanged()` (`:589-608`) points at an unchanged static list; (c) `stonewright-task-start` silently triggers profile activate and STRIPS `tools_changed`/`re_list_instruction` from its response (`plugin/includes/Abilities/System/WorkflowPreflight.php:447-456`, output schema `:103-124` has no such fields); (d) admin `stonewright_mcp_surface` select has zero live effect and no restart warning (`plugin/includes/Admin/ConfigurationPage.php:330-344`) | as cited |
| F9 | AbilityRegistry gates tools/list per request by re-reading the option (`plugin/includes/Core/AbilityRegistry.php:921-982`) — so for HTTP transport the tool list IS fresh on re-list; the missing piece is the notification + client re-list, not the list itself | as cited |
| F10 | Elementor renderer drops `separator` nodes: switch has `case 'divider':` only (`plugin/includes/Elementor/Renderer.php:133-134`); ALL 12 bundled blueprints use `"type": "separator"`; Elementor is the default engine (`auto` prefers it) → every blueprint silently loses separators today | as cited |
| F11 | Blueprint "v2" metadata is 100% synthesized defaults: no bundled JSON has `version`, `align_items`, `justify_content`, `gap`, `content_facts`, `engine_compat`, `responsive_rules`; `BlueprintStore::normalize()` fabricates the envelope (`plugin/includes/Blueprints/BlueprintStore.php:185-251`); `DesignSpec\Migrator::v1_to_v2()` is production dead code (called only from its own test); specs stay `1.0.0` so `Validator::is_v2()` never routes to v2 schema | as cited |
| F12 | Engine selection is real but incomplete: `engine` enum `['auto','gutenberg','elementor']` — NO `fse` branch anywhere; FSE classes under `plugin/includes/Abilities/FSE/*` are disconnected from blueprint-apply | `plugin/includes/Abilities/Blueprints/ApplyBlueprint.php:49-54`; `plugin/includes/Blueprints/BlueprintApplier.php:91-108` |
| F13 | Elementor auto-centering (`Renderer.php:235-260`) never fires for bundled blueprints (none set `fullWidth`/width hints); Gutenberg path centers unconditionally via `core/group` `layout: constrained` (`plugin/includes/Renderers/GutenbergSpecRenderer.php:124-134`) | as cited |
| F14 | Transaction/rollback infra exists but unwired: `ElementorTransactionRunner`/`TransactionEnvelope` and `FseTransactionQueue`/`EditorSnapshot` are standalone abilities never called from `BlueprintApplier::apply()` or `BrandKit::apply()` | commits a5078da, fbdb2e3 |
| F15 | `BrandKit::apply()` options/theme-mod path has NO restore point (`plugin/includes/DesignTokens/BrandKit.php:95-137`); only `apply_elementor_kit()` snapshots, and only when an active kit post exists. No diff preview exists anywhere | as cited |
| F16 | Zero end-to-end render-output tests: no test wires a bundled blueprint through `BlueprintApplier` → renderer and asserts on resulting Elementor JSON / Gutenberg blocks. The separator bug survived because of this | `plugin/tests/Unit/Blueprints/*`, `plugin/tests/Unit/ElementorRendererTest.php` |
| F17 | Figma is deliberately EXTERNAL: external Figma MCP extracts, plugin normalizes via DesignEvidence 1.0 / `figma_token_table` / `visual_build_gate`; removal-guard test `plugin/tests/Unit/Removal/FigmaQaSurfaceTest.php` bans figma/playwright/screenshot modules inside plugin abilities + companion src. Any Figma feature MUST build on this pipeline, not an embedded client | `plugin/includes/Design/Evidence/Validator.php:14`; `plugin/includes/Context/ContextBuilder.php:328-435` |
| F18 | e2e suite is smoke-only: `e2e/tests/admin-ui.spec.ts` = login, 8 pages × 10 projects, overflow + console-error checks, screenshots. NO interaction tests | as cited |

---

## Playwright verification protocol (used by multiple phases)

Every UI-affecting task ends with this check (via the `e2e/` package or Playwright MCP against the local WP site):

1. Viewports: 1440×900, 1024×768, 782×1024, 390×844, 320×568 — each in light AND dark scheme.
2. `document.documentElement.scrollWidth - document.documentElement.clientWidth <= 0` (no horizontal overflow; existing specs allow 2px tolerance — keep that).
3. Zero console errors (reuse the noise filter already in `e2e/tests/admin-ui.spec.ts`).
4. Accessibility snapshot: interactive elements have accessible names; tooltips are reachable by keyboard (focus shows them, Escape hides them).
5. Screenshot per viewport for the changed page, attached to the PR.

---

## Phase 0 — Deterministic UI fixes (complete code, no design pass needed)

### Task 0.1: Notice drawer spacing under the sticky header

**Files:**
- Modify: `plugin/assets/admin/shell.css:735-740`
- Test: `e2e/tests/admin-ui.spec.ts` (extend)

- [ ] **Step 1: Reproduce.** Playwright: log into wp-admin, open any Stonewright page with a queued notice (activate a second plugin that emits one, or temporarily `add_action('admin_notices', ...)` in a mu-plugin on the dev site). Measure:

```js
const header = page.locator('.sw-shell__header');
const drawer = page.locator('.sw-notice-drawer');
const gap = (await drawer.boundingBox()).y - ((await header.boundingBox()).y + (await header.boundingBox()).height);
```

Expected today: gap ≈ 16px or less (visually cramped per user screenshot).

- [ ] **Step 2: Fix CSS.** In `plugin/assets/admin/shell.css`, change the drawer margin and give it breathing room from the sticky header:

```css
.sw-notice-drawer {
	max-width: var(--sw-shell-content-max);
	margin: var(--sw-space-6) auto 0;
	padding: 0 var(--sw-space-5);
	border: none;
}
```

- [ ] **Step 3: Consume the dead variable or delete it.** `shell.js:25-38` computes `--sw-shell-offset` that no CSS consumes. Either use it (`scroll-padding-top: var(--sw-shell-offset)` on `html.sw-has-shell` if anchor jumps hide under the header) or delete the JS computation + the `:86` declaration. Do not leave dead code.

- [ ] **Step 4: e2e regression test.** Add to `e2e/tests/admin-ui.spec.ts`: with a notice present, assert `gap >= 24`.

- [ ] **Step 5: Run Playwright protocol (§ above) on Setup page. Commit** `fix(admin): give notice drawer clear spacing below sticky header`.

### Task 0.2: "Apply to draft" button colors

**Files:**
- Modify: `plugin/assets/admin/shell.css` (button rules ~`:839`), possibly `plugin/includes/Admin/AdminBootstrap.php:86-91`
- Test: `e2e/tests/admin-ui.spec.ts` (extend)

- [ ] **Step 1: Diagnose, don't guess.** Playwright on Design Library page:

```js
const btn = page.locator('.sw-btn--primary').first();
await btn.evaluate(el => {
  const cs = getComputedStyle(el);
  return { color: cs.color, background: cs.backgroundColor };
});
```

Then in DevTools-style inspection (`page.evaluate` over `document.styleSheets` or manual), identify WHICH rule wins over `.sw-btn--primary { color: var(--sw-on-brand); }`. Two known suspects (F2): wp-admin core `.button`/link styles, and the legacy `plugin/assets/css/stonewright-admin.css` enqueued unconditionally by `AdminBootstrap.php:86-91`.

- [ ] **Step 2: Fix at the source.** Preferred fixes in order: (a) stop enqueueing the legacy stylesheet on shell pages (scope the enqueue in `AdminBootstrap.php` to non-shell screens, or delete rules that collide with `.sw-btn`); (b) raise specificity by prefixing the button system with the shell scope:

```css
.sw-shell .sw-btn--primary,
.sw-shell .sw-btn--primary:visited {
	background: var(--sw-brand-fill);
	color: var(--sw-on-brand);
}
```

Do NOT reach for `!important` unless the diagnosis proves a core wp-admin `!important` rule is the winner.

- [ ] **Step 3: e2e assertion.** On Blueprints page: computed `color` of `.sw-btn--primary` resolves to `rgb(255, 255, 255)` in light AND dark scheme.

- [ ] **Step 4: Playwright protocol on Blueprints page. Commit** `fix(admin): apply-to-draft primary button renders white text`.

### Task 0.3: Domain lock block — spacing + centering

**Files:**
- Modify: `plugin/assets/admin/shell.css` (append), `plugin/assets/css/stonewright-admin.css:361-378` (delete orphan rules)

- [ ] **Step 1: Add real CSS for the class the markup actually uses** (F5):

```css
/* Connect tab — domain lock status */
.sw-shell .stonewright-domain-lock-status {
	margin: var(--sw-space-6) auto 0;
	max-width: 560px;
	text-align: center;
	padding: var(--sw-space-4);
	background: var(--sw-surface-raised);
	border: 1px solid var(--sw-border);
	border-radius: var(--sw-radius-sm);
	color: var(--sw-text-secondary);
	font-size: var(--sw-text-sm);
}
```

- [ ] **Step 2: Delete the orphan `.sw-domain-lock` rules** in `stonewright-admin.css:361-378` (they match nothing).

- [ ] **Step 3: Playwright protocol on Connect tab (check bottom of page at 320px too). Commit** `fix(admin): style and center domain lock status on Connect tab`.

### Task 0.4: Elementor renderer drops `separator` nodes (bug, data-loss)

**Files:**
- Modify: `plugin/includes/Elementor/Renderer.php:133-134`
- Test: `plugin/tests/Unit/ElementorRendererTest.php`

- [ ] **Step 1: Failing test.** In `ElementorRendererTest.php`:

```php
public function test_separator_node_renders_as_divider_widget(): void {
	$spec = $this->minimal_spec_with_block( [ 'type' => 'separator' ] );
	$result = ( new Renderer() )->render( $spec );
	$widgets = $this->collect_widget_types( $result['elements'] );
	$this->assertContains( 'divider', $widgets );
	$this->assertSame( [], $result['diagnostics']['unsupported_nodes'] ?? [] );
}
```

(Reuse the test class's existing spec/collection helpers — the file already has 1010 lines of similar tests to copy shape from.)

- [ ] **Step 2: Run it, confirm FAIL** (`cd plugin && composer test -- --filter test_separator_node_renders_as_divider_widget`). Expected: separator lands in unsupported diagnostics, no divider widget.

- [ ] **Step 3: Fix.** In `Renderer.php` switch, alias separator to the existing divider path:

```php
case 'divider':
case 'separator':
	return Renderer\Divider::render( $node, $context );
```

- [ ] **Step 4: Green. Full suite** (`composer test`, `composer phpstan`, `composer phpcs`). **Commit** `fix(elementor): render spec separator nodes as divider widgets`.

---

## Phase 1 — Tooltip infrastructure + tooltips everywhere the user asked

### Task 1.1: Accessible tooltip component

**Files:**
- Modify: `plugin/assets/admin/shell.css` (append), `plugin/assets/admin/shell.js` (append + init hook)
- Test: `e2e/tests/admin-ui.spec.ts`

- [ ] **Step 1: Markup contract.** A tooltip trigger is any element with `data-sw-tooltip="text"`. JS assigns `aria-describedby` pointing at a generated `role="tooltip"` node. Show on hover AND focus; hide on blur, mouseleave, Escape. No third-party lib.

- [ ] **Step 2: JS** (append to `shell.js`, call from the existing init sequence next to `initCopyPrompts()`):

```js
function initTooltips() {
	let tipEl = null;
	let tipId = 0;
	const show = (trigger) => {
		hide();
		const text = trigger.getAttribute('data-sw-tooltip');
		if (!text) return;
		tipEl = document.createElement('div');
		tipEl.className = 'sw-tooltip';
		tipEl.id = 'sw-tooltip-' + (++tipId);
		tipEl.setAttribute('role', 'tooltip');
		tipEl.textContent = text;
		document.body.appendChild(tipEl);
		trigger.setAttribute('aria-describedby', tipEl.id);
		const r = trigger.getBoundingClientRect();
		tipEl.style.left = Math.max(8, Math.min(window.innerWidth - tipEl.offsetWidth - 8, r.left + r.width / 2 - tipEl.offsetWidth / 2)) + 'px';
		tipEl.style.top = (r.top + window.scrollY - tipEl.offsetHeight - 8) + 'px';
		tipEl.classList.add('is-visible');
	};
	const hide = () => {
		if (!tipEl) return;
		document.querySelectorAll('[aria-describedby="' + tipEl.id + '"]').forEach((el) => el.removeAttribute('aria-describedby'));
		tipEl.remove();
		tipEl = null;
	};
	document.addEventListener('mouseover', (e) => { const t = e.target.closest('[data-sw-tooltip]'); if (t) show(t); });
	document.addEventListener('mouseout', (e) => { if (e.target.closest('[data-sw-tooltip]')) hide(); });
	document.addEventListener('focusin', (e) => { const t = e.target.closest('[data-sw-tooltip]'); if (t) show(t); });
	document.addEventListener('focusout', hide);
	document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
}
```

- [ ] **Step 3: CSS** (append to `shell.css`, both schemes — shell tokens already flip with scheme):

```css
.sw-tooltip {
	position: absolute;
	z-index: 100000;
	max-width: 280px;
	padding: var(--sw-space-2) var(--sw-space-3);
	background: var(--sw-text);
	color: var(--sw-bg);
	font-size: var(--sw-text-xs);
	line-height: 1.4;
	border-radius: var(--sw-radius-sm);
	box-shadow: var(--sw-shadow-2);
	opacity: 0;
	transition: opacity var(--sw-dur) var(--sw-ease);
	pointer-events: none;
}
.sw-tooltip.is-visible { opacity: 1; }
```

- [ ] **Step 4: e2e test.** Hover + keyboard-focus a `[data-sw-tooltip]` element → `role=tooltip` visible with matching text; Escape hides it. **Commit** `feat(admin): accessible tooltip component`.

### Task 1.2: Tooltips + honest copy on blueprint buttons

**Files:**
- Modify: `plugin/includes/Admin/Pages/BlueprintsPage.php:86-100`

- [ ] **Step 1:** Add to the two buttons (keep existing classes/data attrs):
  - Apply to draft: `data-sw-tooltip="<?php echo esc_attr__( 'Copies a ready-made AI prompt that tells your connected agent to apply this blueprint to a new draft. Paste it into your MCP client to run it.', 'stonewright' ); ?>"`
  - Copy AI Prompt: `data-sw-tooltip="<?php echo esc_attr__( 'Copies a customization prompt for this blueprint to your clipboard.', 'stonewright' ); ?>"`
- [ ] **Step 2:** Add one visible helper line under the card grid header (not tooltip-only, per user: "sau sa fie pus undeva ce mentiune"): a `<p class="sw-text-muted">` explaining that these buttons copy AI prompts for an MCP-connected agent; nothing is written to the site until the agent runs the apply ability.
- [ ] **Step 3:** e2e: both tooltips render. **Commit** `feat(admin): explain blueprint actions with tooltips and helper copy`.

### Task 1.3: Tooltips on connection method cards

**Files:**
- Modify: `plugin/includes/Admin/ConfigurationPage.php:745-764`

- [ ] **Step 1:** Add `data-sw-tooltip` to each method card:
  - Local companion (stdio): "Runs the Stonewright companion on the same machine as your AI client. Best for local development: fastest, no public endpoint needed. Requires Node.js on your machine."
  - Remote Streamable HTTP: "Your AI client connects directly to this WordPress site over HTTPS. Best for remote/production sites: nothing to install locally. Requires the application password from this page."
  (Wrap in `esc_attr__( ..., 'stonewright' )`.)
- [ ] **Step 2:** e2e: tooltips visible on hover/focus for both cards, keyboard reachable (cards are `role="radio"` — verify focus works). **Commit** `feat(admin): connection method tooltips`.

---

## Phase 2 — Prompt Library as a real tab, ≥20 correct prompts

### Task 2.1: Promote prompt library to its own tab/page

**Files:**
- Create: `plugin/includes/Admin/Pages/PromptLibraryPage.php`
- Modify: `plugin/includes/Admin/ConfigurationPage.php:580-590` (remove disclosure), the admin menu/tab registration (follow how `BlueprintsPage` registers — same pattern, same shell nav)
- Test: `plugin/tests/Unit/Admin/PromptLibraryPageTest.php` (mirror existing page tests under `plugin/tests/Unit/Admin/`)

- [ ] **Step 1:** Failing test: page class registers a submenu/tab titled "Prompts", renders one card per catalog prompt (title, outcome tag, copy button with `sw-copy-prompt` + `data-prompt`).
- [ ] **Step 2:** Implement page: reuse `PromptCatalog` for data, `sw-copy-prompt` mechanism from `shell.js:217-246` for copy buttons, group prompts by `outcome`. Remove the disclosure block from Setup and replace it with a short link to the new tab.
- [ ] **Step 3:** Green; e2e: new tab appears in shell nav, page passes Playwright protocol, copy button click puts prompt text on clipboard (Playwright: grant `clipboard-read` permission, assert `navigator.clipboard.readText()`).
- [ ] **Step 4: Commit** `feat(admin): dedicated Prompt Library tab`.

### Task 2.2: Audit the 12 existing prompts + expand to ≥20

**Files:**
- Modify: `plugin/data/prompts/catalog.json`
- Test: `plugin/tests/Unit/Support/PromptCatalogTest.php`

- [ ] **Step 1: Correctness gate first (failing test).** Extend `PromptCatalogTest` with a content-eval test: every MCP tool name mentioned in any prompt's text or `tools` array must exist in the ability contracts (load the registry/contract fixture the truth-matrix generator uses; hyphenated MCP names). Also assert: every prompt instructs calling `stonewright-task-start` first; no prompt names competitor products; no prompt tells the agent to bypass backup/validator/token gates.
- [ ] **Step 2:** Fix any of the 12 existing prompts the test flags.
- [ ] **Step 3:** Author ≥8 new Stonewright-specific prompts (target ≥20 total), covering at minimum: apply blueprint with explicit engine choice (elementor / gutenberg / fse once Phase 4.5 lands), brand-kit apply with diff preview + restore, Figma→native pixel-perfect build (references the Phase 5 workflow: external Figma MCP → `figma_token_table` → native plan → verify), profile self-upgrade ("if a tool you need is missing, activate the full profile and re-list" — matches Phase 3 behavior), site health/ops triage, WooCommerce catalog setup, content-model (ACF/CPT) scaffold, FSE template edit with snapshot/rollback.
- [ ] **Step 4:** Bump the min-count assertion from ≥10 to ≥20. Green. **Commit** `feat(prompts): 20+ evaluated prompts incl. engine choice, Figma, profile upgrade`.

---

## Phase 3 — Real MCP profile switching (plugin + companion + agent guidance + admin button)

> REQUIRED: run superpowers:writing-plans for this phase before coding; the acceptance criteria below are the spec. Constraint: companion stays tokenized (CLAUDE.md hard rule 7); no REST write endpoints from companion.

**Acceptance criteria:**
1. An agent can call `stonewright-tool-profile {action: "activate", profile: "full"}` mid-session and, on the companion (stdio) transport, the tool list actually changes in the same session — companion re-derives its registered tools and emits a real `notifications/tools/list_changed`.
2. On the direct HTTP transport, the client is told (via tool response `re_list_instruction` AND docs) to re-call `tools/list`; the list is already fresh per request (F9). If the vendor `InitializeHandler` can be filtered/extended without forking, declare `tools: { listChanged: true }`; if not, document the limitation in `docs/architecture.md` and rely on response-embedded instructions.
3. `stonewright-task-start` STOPS being silent: its output schema gains `tool_profile` (active profile), `tools_changed` (bool), and `re_list_instruction` (string, present when tools changed) — passed through from ToolProfile instead of dropped (F8c, `WorkflowPreflight.php:447-456` + schema `:103-124`).
4. Every ability error caused by a gated/missing tool includes a recovery hint: "activate a broader profile via stonewright-tool-profile (e.g. full), then re-list tools" — extend the existing `recovery_hints` machinery (`plugin/includes/Abilities/System/ToolProfile.php:1032-1042`).
5. Admin Setup page: the `stonewright_mcp_surface` select (F8d) gains (a) an "Apply now" button that saves + shows per-transport truth: "HTTP clients pick this up on their next tools/list; stdio companion sessions refresh automatically (companion ≥ the version shipping this phase) or need a client restart on older companions"; (b) the same info as a `data-sw-tooltip`.
6. Agent-facing docs updated: `skills/stonewright/SKILL.md`, `docs/admin/connect-clients.md`, `AGENTS.md` describe the self-upgrade loop (detect limitation → activate profile → re-list → continue).

**Key implementation anchors:**

- [ ] **3.1 Companion dynamic tools.** `companion/src/wordpress-mcp.ts:526-618` registers tools once. Change: keep tool registration handles (MCP SDK `server.registerTool()` returns a handle with `.enable()/.disable()/.remove()`); on any proxied ability response containing `tools_changed: true` (already detected at `:589-608`), re-fetch the ability list from the plugin (same call used at startup), diff against registered names, register/remove accordingly, THEN `sendToolListChanged()`. Also honor `STONEWRIGHT_MCP_TOOL_PROFILE` env only as the INITIAL profile. Tests: `companion/tests/` vitest — simulate a profile-activate response, assert re-registration + notification; `npm run typecheck && npm test && npm run lint`.
- [ ] **3.2 Plugin: WorkflowPreflight passthrough.** Failing test in `plugin/tests/Unit/` first: task-start response exposes `tool_profile`/`tools_changed`/`re_list_instruction`. Then fix `WorkflowPreflight.php:447-456` to pass the ToolProfile result through and extend the output schema at `:103-124`.
- [ ] **3.3 Plugin: initialize capability.** Investigate whether `plugin/vendor/wordpress/mcp-adapter` exposes a filter for the initialize result. If yes, declare `listChanged`. If no, do NOT fork the vendor package — document instead. Either way, add an architecture note in `docs/architecture.md`.
- [ ] **3.4 Admin Apply-now button** per criterion 5 (Settings API save + JS confirmation of the saved value; honest per-transport messaging; tooltip via Task 1.1 component).
- [ ] **3.5 Docs + skills** per criterion 6. Run `node scripts/check-docs-freshness.mjs`.
- [ ] **3.6 e2e:** Setup page: change surface select → Apply now → success notice; assert saved value round-trips (reload page). Playwright protocol.
- [ ] **Commit sequence:** one commit per sub-task, gates green each time.

---

## Phase 4 — Blueprints/brand kits: genuinely premium, native, centered, engine user-selectable (incl. FSE)

> REQUIRED: run superpowers:writing-plans for this phase before coding. This is the biggest phase; split into PR-sized chunks in the order below (each chunk independently shippable). Hard rules apply throughout: `Backup::snapshot_post()` before Elementor/template writes, `Validator::validate()` before render, real Permissions callbacks, confirmation tokens in production-safe mode.

**Acceptance criteria:**
1. `stonewright/blueprint-apply` accepts `engine: 'auto'|'elementor'|'gutenberg'|'fse'`; each explicit engine either works natively or returns `stonewright_engine_unavailable` — never silently falls back.
2. No node type in any bundled blueprint is dropped by any engine (extend the Task 0.4 pattern: a test iterates ALL 12 bundled blueprints × all engines and asserts `unsupported_nodes` is empty).
3. Every bundled blueprint renders centered, constrained-width content on all engines without post-hoc CSS: Elementor via authored `align_items`/`justify_content`/width hints in the JSONs so `Renderer.php:235-260` logic actually fires (F13); Gutenberg already constrained; FSE via `layout: constrained` group wrappers.
4. `Migrator::v1_to_v2()` runs inside `BlueprintApplier::apply()` before validation, so specs validate against the v2 schema and `content_facts`/`native_policy` become real constraints (F11). Bundled JSONs get REAL authored v2 content: layout intent per section, `required_content_facts` with placeholder status, honest `engine_compatibility` (with loss notes where an engine lacks a widget), ≥3 composition variants per industry where the roadmap promised them.
5. FSE engine: new dispatch branch in `BlueprintApplier.php` + an FSE renderer that produces a `wp_template`/`wp_template_part`-compatible block markup (reuse `GutenbergSpecRenderer` output serialized via the existing `BlockSerializer`, wrapped for template storage), connected to the existing `plugin/includes/Abilities/FSE/*` snapshot classes. `EditorSnapshot` taken before write; rollback on structural readback failure.
6. Transactions wired in: `BlueprintApplier::apply()` (Elementor path) runs through `ElementorTransactionRunner`/`TransactionEnvelope`; Gutenberg/FSE paths through `EditorSnapshot`/`FseTransactionQueue` (F14). On readback mismatch → automatic rollback + structured `WP_Error`.
7. `BrandKit::apply()` gets an unconditional restore point: snapshot the option/theme-mod state (new `Backup` scope or a dedicated option-snapshot record) BEFORE any write, returned as `restore_id`; `apply_elementor_kit()` keeps its post snapshot (F15).
8. Brand-kit diff preview: `stonewright/brand-kit-apply` gains `preview: true` mode returning a structured before/after diff (changed options, theme mods, Elementor kit settings) WITHOUT writing; wire the existing disconnected `PreviewRender` ability where an Elementor visual dry-run helps.
9. Render-output test suite (F16): for each bundled blueprint × {elementor, gutenberg, fse}: apply → assert Elementor JSON structure (elType/widgetType present, no unsupported nodes, flex alignment set on hero/full-width sections) / Gutenberg `parse_blocks()` round-trip validity / FSE template validity. This suite is the regression net for "nu genereaza un rahat".
10. Live visual proof: on the local dev site, apply ≥3 representative blueprints per engine and run the Playwright protocol on the FRONT-END pages (5 viewports × light/dark, no horizontal overflow, hero visually centered — assert via bounding-box symmetry within 8px). Screenshots in PR.

**Suggested PR chunks:** (a) engine=fse + FSE renderer + tests; (b) Migrator wiring + v2 authoring of 12 JSONs + layout intent + no-dropped-nodes suite; (c) transactions + brand-kit restore + diff preview; (d) render-output test suite + live visual verification evidence.

---

## Phase 5 — Figma → Elementor/Gutenberg/FSE pixel-perfect (native-first)

> REQUIRED: run superpowers:writing-plans for this phase before coding. HARD CONSTRAINT (F17): `plugin/tests/Unit/Removal/FigmaQaSurfaceTest.php` must keep passing — NO figma/playwright/screenshot/pixel-diff modules inside plugin abilities or companion src. The pipeline is: external Figma MCP (client-side) extracts → agent normalizes into DesignEvidence 1.0 (`figma_token_table`) → plugin validates/gates (`visual_build_gate`, `no_raw_figma_tree_after_normalization`) → native render → agent verifies with ITS OWN Playwright tools against the evidence.

**Acceptance criteria:**
1. DesignEvidence schema covers what pixel-perfect needs: spacing scale, typography ramp, color tokens, breakpoint frames, per-section layout intent (flex/grid, alignment, gaps), and measured target values (px) per breakpoint. Extend `plugin/includes/Design/Evidence/Validator.php` + schema if fields are missing; keep `SOURCE_TYPES` as-is.
2. `stonewright/design-native-plan` (`plugin/includes/Abilities/Design/NativePlan.php`) emits, per element, `native_mapping` (engine-native construct) or a justified `native_gap` entry. **CSS is allowed ONLY for elements with a recorded `native_gap`** — the `ImplementationContract` (`plugin/includes/Abilities/Design/ImplementationContract.php:87`) must enforce this: custom CSS in a build without a matching `native_gap` justification → `stonewright_spec_invalid`.
3. All three engines are reachable from the same evidence: the native plan targets elementor, gutenberg, or fse per user choice (reuse Phase 4's engine plumbing).
4. Verification loop documented and prompt-supported (Phase 2.3 prompt): agent renders → screenshots each breakpoint frame with its own Playwright → compares measured values against the evidence's target values → iterates. Tolerances: ±2px spacing, exact colors (hex match after token resolution), font-size exact, line-height ±0.05.
5. `skills/design-to-wordpress/SKILL.md` rewritten around this loop with a full worked example (Figma frame → token table → native plan → apply → verify), including the "when CSS is acceptable" decision rule.
6. Tests: evidence validator round-trip for the new fields; ImplementationContract rejects CSS-without-gap; native plan emits per-engine mappings for a fixture evidence file; `FigmaQaSurfaceTest` still green.

---

## Phase 6 — Interaction-level e2e coverage

**Files:** `e2e/tests/` (new spec files alongside `admin-ui.spec.ts`)

- [ ] 6.1 `blueprints.spec.ts`: card grid renders 12 blueprints; Apply-to-draft click copies prompt (clipboard assertion); tooltip content correct; button colors (Task 0.2 assertion lives here).
- [ ] 6.2 `tooltips.spec.ts`: hover + focus + Escape lifecycle on blueprint buttons and method cards; `aria-describedby` wiring.
- [ ] 6.3 `prompt-library.spec.ts`: tab present, ≥20 prompt cards, copy works, grouped by outcome.
- [ ] 6.4 `setup-profile.spec.ts`: surface select + Apply now flow (Phase 3.6).
- [ ] 6.5 `connect.spec.ts`: method picker keyboard navigation (radio group), domain lock block styled + centered (bounding-box horizontally centered within 8px of container center), notice drawer gap ≥ 24px.
- [ ] 6.6 Keep all specs within the existing 10-project matrix (5 viewports × light/dark) where meaningful; interaction specs may pin to 1440×900 light + 390×844 dark to bound runtime, but layout assertions (6.5) run across the matrix.
- [ ] **Commit** per spec file; `cd e2e && npx playwright test` green.

---

## Phase 7 — Docs, roadmap, release hygiene

- [ ] 7.1 Update in the SAME PRs as the behavior (repo rule): root/plugin/companion READMEs, both changelogs, `docs/admin/configuration.md` (new tab, tooltips, Apply-now button), `docs/admin/connect-clients.md` (profile self-upgrade loop, method choice guidance), `docs/architecture.md` (profile switching truth per transport, FSE engine, transactions), `docs/install-prompts.md`, skills.
- [ ] 7.2 Regenerate `docs/ability-truth-matrix.md` via `composer docs:matrix` after any ability schema change (Phases 3–5 all change schemas).
- [ ] 7.3 **OAuth roadmap note (only artifact for OAuth):** in the roadmap section of `docs/index.md` (or the existing roadmap doc), one line: "OAuth 2.1 authorization for the HTTP transport — planned, not scheduled." Remove/adjust any doc text implying OAuth is imminent. Nothing else.
- [ ] 7.4 Every PR: run `node scripts/check-docs-freshness.mjs` and `git diff --check`; PR body lists changed abilities + gate changes (backup/token/permission/validation/audit) + which public docs changed.

---

## Gates (every PR, no exceptions)

| Gate | Command | Where |
|------|---------|-------|
| Plugin tests | `cd plugin && composer test` | 4735+ tests stay green, new tests added per task |
| Static analysis | `cd plugin && composer phpstan` | clean |
| Code style | `cd plugin && composer phpcs` | clean |
| Companion | `cd companion && npm run typecheck && npm test && npm run lint && npm run build` | clean |
| e2e | `cd e2e && npx playwright test` | green incl. new specs |
| Docs freshness | `node scripts/check-docs-freshness.mjs` | pass |
| Whitespace | `git diff --check` | pass |
| Removal guards | `FigmaQaSurfaceTest` + friends | must stay green — never delete/weaken |

## Execution order & PR sequence

1. **PR A (small, fast):** Phase 0 + Phase 1 (UI fixes + tooltips) + specs 6.1/6.2/6.5.
2. **PR B:** Phase 2 (prompt library tab + catalog) + spec 6.3.
3. **PR C:** Phase 3 (profile switching) + spec 6.4 + docs 7.1/7.2.
4. **PR D–G:** Phase 4 chunks (a)–(d) + docs.
5. **PR H:** Phase 5 (Figma pipeline) + docs + roadmap note 7.3.

Each PR: topic branch off `feat/premium-enhancement-2026-07` (or off main after PR #8 merges — executing agent decides based on merge status), gates green, honest PR body (no automated-authorship claims, no competitor names, no internal tooling disclosure).
