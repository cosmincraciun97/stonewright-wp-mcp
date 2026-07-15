# Stonewright premium corrections — handoff report for next AI agent

**Date:** 2026-07-15  
**Branch:** `main`  
**Release:** `1.0.0-alpha.68` (builds on alpha.67)  
**Product:** Stonewright WP MCP (`plugin/` AGPL, `companion/` MIT)

This report describes what was implemented so another agent can audit, extend, or continue without re-deriving root causes.

---

## 1. Original problem (live browser QA)

Agent delivery of premium upgrade was real (abilities, branches, ZIP), but:

| Issue | Root cause |
|---|---|
| Dark mode unusable | Page CSS had zero dark selectors; `admin.css` hard-coded `#1d2327`; shell only remapped ~3 tokens |
| Blueprints “not implemented” | Page existed; `AdminBootstrap` missing `stonewright-blueprints` → no CSS |
| Sandbox tab underlines | Generic `.sw-shell a { text-decoration: underline }` leaked onto tabs |
| Abilities table overlap | Double sticky headers with hard-coded `top: 88px` / `148px` |
| Setup HTTPS red ✗ | Diagnostic treated HTTP as hard fail; Direct mode already allows HTTP |
| README stale | Root README never dual-path; E2E Direct skipped |

Plan source: `research/stonewright-premium-corrections-plan.md`

---

## 2. Architecture decisions

### 2.1 Semantic tokens (single source of hex)

- **File:** `plugin/assets/admin/shell.css`
- **`:root`** = light palette  
- **`.sw-theme-dark` / `html.sw-theme-dark`** = dark palette (desaturated indigo `#a2a5f8`, not inverted light colors)
- **`@media (prefers-color-scheme: dark)`** fallback when user has not set `.sw-theme-light`
- Legacy aliases: `--sw-color-card`, `--sw-gray-*`, `--sw-success`, etc. map to semantic tokens
- **Guardrails (PHPUnit):**
  - `AdminShellDarkTokensTest` — required tokens in both maps; no `#5546e8`
  - `AdminCssNoRawHexTest` — no raw `#hex` outside `shell.css`
  - `AdminThemeContrastTest` — WCAG AA pairs on both themes

### 2.2 Theme application

- Preference: user meta `stonewright_admin_theme` via AJAX `stonewright_set_admin_theme`
- Classes on shell + `document.documentElement`: `.sw-theme-light` / `.sw-theme-dark`
- Header chrome is **always dark**; mode pill colors are **fixed light-on-dark** (must not use `--sw-brand-strong` from light theme — that is dark indigo and fails on the header)

### 2.3 WordPress primitive overrides

Under `.sw-shell`, force theme tokens on:

- `.wp-list-table`, `table.widefat`, striped rows
- `.subsubsub` (Memory type tabs)
- `.button` / `.button-primary` / `.button-secondary`
- inputs/selects/textareas

Without this, WP core CSS forces white tables and black text in dark mode.

### 2.4 Learning / audit intelligence

| Piece | Location |
|---|---|
| `ErrorPatterns` | `plugin/includes/Security/ErrorPatterns.php` — signature = ability + error code + message excerpt; option store max 200; count≥2 → learning record `source=audit-error` |
| Hook | `AuditLog::record()` calls `ErrorPatterns::observe()` best-effort after insert |
| UI | Audit page “Recurring errors” panel + dismiss |
| Task start | `ContextBuilder` adds `custom_instructions` + `recurring_errors`; compact path omits empty keys to stay under token budget |
| `LearningRecord` | fields `trigger`, `severity`, `source` (`user-correction` \| `audit-error` \| `manual`) |
| `FeedbackCapture` | `stonewright/feedback-capture` → learning-record with `source=user-correction` |
| Memory UI | “Learned rules” section + disable → status `stale` |

### 2.5 Direct mode (companion)

- Branch work landed on `feature/u4-direct-mode`, merged to main
- `companion/src/direct/*` REST tools; HTTP allowed
- E2E script: `companion/scripts/e2e-direct.mjs`
- Matrix: `docs/direct-mode-e2e.md`

---

## 3. UI fixes by page (what to re-check in browser)

| Page | Slug | What was broken | Fix location |
|---|---|---|---|
| Shell / global | all | dark tokens, WP tables white, mode pill | `shell.css`, `shell.js` |
| Setup | `stonewright` | HTTPS fail | `SetupDiagnostics.php` status `info` for HTTP |
| Abilities | `stonewright-abilities` | sticky overlap | `abilities.css` — filters sticky via `--sw-shell-offset`; thead static |
| Blueprints | `stonewright-blueprints` | no CSS; short prompt; AI-looking cards | enqueue in `AdminBootstrap.php`; `blueprints.css`; **full prompt** in `BlueprintsPage::blueprint_prompt()` |
| Sandbox | `stonewright-sandbox` | underlines; white library table | `sandbox.css` + shell table overrides |
| Skills | `stonewright-skills` | invisible text | `skills-memory.css` + shell text rules |
| Memory | `stonewright-memory` | subsubsub black; ugly table | `skills-memory.css` type badges + subsubsub |
| Audit | `stonewright-audit-log` | payload overflow; weak badges | `audit.css` `table-layout: fixed`, payload `max-height` + `overflow:auto`, `overflow-wrap: anywhere` |
| Dashboard | `stonewright-status` | pulse grade glue | `dashboard.css` + StatusPage markup |

### 3.1 Blueprint copy prompt (alpha.68)

**Before:** one line  
`Apply blueprint agency and adapt copy for {business}.`

**After:** multi-line playbook including:

- blueprint id/name/industry/sections/palette/fonts
- `{business}` client placeholder
- tool path: `stonewright-task-start` → `blueprint-get` → `blueprint-apply` → adapt → QA
- constraints (native widgets, context token, backups)

Built in `BlueprintsPage::blueprint_prompt()` / `brand_kit_prompt()`.  
Copy via `shell.js` (`navigator.clipboard` + `execCommand` textarea fallback — **no** `window.prompt` / `alert`).

### 3.2 Mode pill (alpha.68)

Header is always dark. Light theme must **not** use `--sw-brand-strong` (#4338ca) on the pill.  
Pills use fixed light indigo/amber/green labels on translucent fills.

### 3.3 Audit payload overflow (alpha.68)

Cause: open `<pre>` expanded table cell past viewport.  
Fix: `table-layout: fixed`, column widths, payload `max-width:100%`, `max-height:240px`, scroll inside pre, shell content `overflow-x: hidden`.

---

## 4. Files map (high signal)

```
plugin/assets/admin/shell.css       tokens + shell + WP primitive overrides + mode pill
plugin/assets/admin/shell.js        theme on <html>, shell offset, notice drawer, silent copy
plugin/assets/admin/admin.css       legacy page chrome on tokens
plugin/assets/admin/admin.js        silent copy fallback (no prompt)
plugin/assets/admin/{abilities,audit,blueprints,dashboard,sandbox,setup,skills-memory}.css
plugin/includes/Admin/AdminBootstrap.php   page_styles + filemtime cache-bust
plugin/includes/Admin/Pages/BlueprintsPage.php   full prompts
plugin/includes/Admin/SetupDiagnostics.php      HTTP = info
plugin/includes/Security/ErrorPatterns.php
plugin/includes/Security/AuditLog.php
plugin/includes/Abilities/Memory/{LearningRecord,FeedbackCapture}.php
plugin/includes/Context/ContextBuilder.php
companion/src/direct/* + scripts/e2e-direct.mjs
docs/direct-mode-e2e.md
docs/premium-corrections-handoff-report.md   ← this file
research/stonewright-premium-corrections-plan.md
```

---

## 5. Tests & verification

```bash
cd plugin && composer test          # ~4098+ tests expected green
cd companion && npm test && npm run typecheck
```

Key filters:

- `AdminShellDarkTokensTest|AdminCssNoRawHexTest|AdminThemeContrastTest`
- `BlueprintsPageRenderTest` (full prompt assertions)
- `ErrorPatternsTest`, `LearningRecordTest`
- `SetupDiagnosticsTest`

**Not covered by unit tests:** pixel QA. Always hard-refresh admin after ZIP install (`filemtime` helps cache-bust).

---

## 6. Release packaging

```bash
# Production plugin ZIP (WordPress Upload Plugin)
cd plugin && composer install --no-dev --classmap-authoritative
rsync plugin/ → dist/stonewright/ (exclude tests, bin, phpunit, etc.)
(cd dist && zip -qr stonewright-1.0.0-alpha.68.zip stonewright)

# Companion is .tgz via npm pack — NOT installable as WP plugin
```

Upload **only** `stonewright-*.zip`, never the `stonewright/` folder or `*-companion-*.tgz`.

---

## 7. Merge history (feature branches)

Order on main:

1. `feature/u6-token-budgets`
2. `feature/u4-direct-mode`
3. `feature/u1-admin-shell`
4. Post-merge dark UI fixes + alpha.67 + alpha.68 polish

---

## 8. Suggested next work for a follow-up agent

1. Live browser pass: all 8 admin pages × light/dark × 1440/1024/782 after alpha.68 install.
2. Run Direct E2E against a site **without** the plugin:  
   `STONEWRIGHT_MODE=direct … node companion/scripts/e2e-direct.mjs` and update `docs/direct-mode-e2e.md` with real pass/fail rows.
3. Optional: move blueprint prompts into JSON fixtures so copy stays in sync with ability schemas.
4. Optional: Audit table mobile card layout instead of horizontal scroll.
5. Do not reintroduce raw hex in page CSS; do not use light-theme text tokens on the dark header.

---

## 9. Explicit non-goals / hard rules (from AGENTS.md)

- Keep `stonewright/php-execute` first-class.
- No `__return_true` on write abilities.
- Backup before Elementor/template/theme.json writes.
- Validator before render.
- Confirmation tokens in production-safe mode.
- Do not bypass MCP with scratch scripts or direct ability REST from shell.
