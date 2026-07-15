# Stonewright Premium Upgrade — delivery report

**Date:** 2026-07-15  
**Status:** Feature work complete on topic branches (not merged to `main`).  
**Install package for manual QA:** see [Install paths](#install-paths) below.

---

## Install paths

### Recommended — WordPress ZIP upload

| | |
|---|---|
| **ZIP** | `/Users/cosminiviteb/Personal/stonewright-wp-mcp/dist/stonewright-wp-mcp-premium-upgrade.zip` |
| **Size** | ~1.9 MB (includes production `vendor/`) |
| **Folder inside ZIP** | `stonewright/` |

**How to install**

1. WP Admin → Plugins → Add New → Upload Plugin  
2. Choose `stonewright-wp-mcp-premium-upgrade.zip`  
3. Activate **Stonewright**  
4. Open **Stonewright → Setup** (formerly Configuration)

If an older Stonewright is already active: deactivate + delete the old plugin first, then upload this ZIP (or overwrite via your usual deploy path).

### Source checkout (symlink / local.develop)

Full plugin source with all new code (includes tests; for Local/dev only):

```text
/Users/cosminiviteb/Personal/stonewright-wp-mcp/.worktrees/u1-admin-shell/plugin
```

Tip: symlink into your Local site `wp-content/plugins/stonewright` and run `composer install --no-dev` inside that folder if `vendor/` is missing.

### Companion (Direct mode)

```text
/Users/cosminiviteb/Personal/stonewright-wp-mcp/.worktrees/u4-direct-mode/companion
```

Build: `npm install && npm run build`. Point MCP at `node dist/index.js` (or package path).

---

## Git branches (worktrees)

| Branch | Path | Tip commit (short) | Scope |
|---|---|---|---|
| `feature/u1-admin-shell` | `.worktrees/u1-admin-shell` | `b651f40` | Plugin: UI + premium + Elementor deltas + install UX |
| `feature/u4-direct-mode` | `.worktrees/u4-direct-mode` | `6536065` | Companion: Direct mode waves 1–2 + auto-detect |
| `feature/u6-token-budgets` | `.worktrees/u6-token-budgets` | `11fe54d` | Token/tool surface budgets for CI |

`main` is unchanged and remains release-ready.

---

## What shipped (by PR id from the plan)

### Pilon A — Admin UI premium

| ID | Title | Notes |
|---|---|---|
| **U1** | Admin Shell | Sticky header, nav, mode pill, dark toggle (`user_meta`), notice drawer, design tokens `--sw-*`, button system |
| **U2** | Setup page | Stepper, diagnostics checklist, client cards, live connection test REST |
| **U3** | Restyle pages | Abilities / Dashboard / Audit / Skills / Memory / Sandbox premium CSS |

### Pilon B — Stonewright Direct (plugin-less companion)

| ID | Title | Notes |
|---|---|---|
| **U4** | Wave 1 | REST client, `~/.stonewright/sites.json`, content/media/taxonomy tools, confirm + audit JSONL |
| **U5** | Wave 2 + startup | Menus, templates, global styles, plugins, discover, gutenberg-compose; `STONEWRIGHT_MODE=auto\|direct\|plugin`; ~**40** Direct tools |

### Pilon C — Premium features

| ID | Title | Notes |
|---|---|---|
| **U7** | Site Pulse + Timeline | `site-pulse`, `change-log`, `change-restore` (+ `Backup::restore_snapshot` / timeline) |
| **U8** | Blueprints + Brand Kits | **12** blueprints, **20** kits, list/get/apply abilities, Blueprints admin page |
| **U9** | Playbooks + stock images | **25** playbooks under `skills/playbooks/`, Openverse search/import |
| **U14** | Design Mirror | `design-mirror-export` → `uploads/stonewright-mirror/*.json` |

### Pilon D — Elementor deltas

| ID | Title | Notes |
|---|---|---|
| **U10** | Digest / build-tree / aliases / CSS | `elementor-page-digest`, `elementor-build-tree` (path-exact errors), `SettingsKeyAliases`, `CssRegenerator` |
| **U11** | V4 global classes | **Already present** on tree as experimental V4 abilities (`list-classes`, `create-class`, `update-class`, …). No separate PR required beyond existing V4 surface. |

### Pilon E — Install

| ID | Title | Notes |
|---|---|---|
| **U12** | GitHub updater | `GitHubUpdater` + filter `stonewright_disable_update_check` |
| **U13** | mcpb + kill switch + vendor UX | `.mcpb` download (no credentials), admin-bar ON/OFF, VendorGuard notice + MCP 500 |

### Pilon F — Token discipline

| ID | Title | Notes |
|---|---|---|
| **U6** | CI budgets | `composer tokens:measure`, `npm run tokens:measure`, fixture over-budget exit 1 |

---

## New / notable abilities (plugin)

| Ability | Role |
|---|---|
| `stonewright/site-pulse` | Hardening/perf score 0–100 |
| `stonewright/change-log` | Compact snapshot timeline |
| `stonewright/change-restore` | Restore snapshot (token in production-safe) |
| `stonewright/blueprint-list` / `-get` / `-apply` | DesignSpec blueprints |
| `stonewright/brand-kit-list` / `-apply` | Brand kits |
| `stonewright/stock-image-search` / `-import` | Openverse (Unsplash/Pexels if keys set) |
| `stonewright/elementor-page-digest` | Compact page outline |
| `stonewright/elementor-build-tree` | Atomic Elementor tree write |
| `stonewright/design-mirror-export` | Git-friendly JSON export |

Gates: real `Permissions` callbacks, backup before tree/blueprint writes, `Validator` on blueprints, `ConfirmationToken` where required, audit on writes.

---

## Companion Direct (no plugin)

- Auto-detect: probe `/wp-json/mcp/stonewright` → plugin proxy if up, else Direct registry  
- Multi-site: `~/.stonewright/sites.json` + `site` arg  
- Destructive remote: `confirm:true` when `STONEWRIGHT_DIRECT_WRITES=confirm`  
- Audit: `~/.stonewright/audit-direct.jsonl`  
- Docs: companion README + `docs/installation.md` capability matrix  

---

## What was deliberately not done / left for you

1. **Merge to `main` / GitHub PRs** — not opened; branches stay isolated.  
2. **Live browser QA** — you verify on your machine (path above).  
3. **U11 as a greenfield PR** — V4 class abilities already exist; deepen only if you want more UX.  
4. **E2E smoke on a real WP without plugin (Direct)** — unit/integration covered; full remote E2E is for your environment.  

---

## Suggested manual checklist (you)

1. Install ZIP → activate → **Setup** page looks premium (shell, stepper, dark mode).  
2. Run **connection test** on Setup.  
3. Open Blueprints / Abilities / Dashboard / Audit.  
4. Toggle kill switch in admin bar.  
5. Download `.mcpb` (no password inside).  
6. Optional: companion Direct against a site **without** Stonewright plugin.  

---

## Plan sources

- `research/stonewright-premium-upgrade-plan.md`  
- `research/stonewright-premium-upgrade-agent-prompt.md`  
- Sibling plan (not reimplemented): `research/stonewright-mcp-improvement-plan.md`  

---

## Commit history snapshot (`feature/u1-admin-shell`)

```text
b651f40 feat(elementor): page digest, build-tree, settings aliases, CSS regen, design mirror
cd2ee65 feat: mcpb bundle, admin-bar kill switch, vendor failure UX
d74570c feat: prompt playbooks and Openverse stock image import
288d742 feat: blueprints and brand kits library with contract fixtures
8a8e78b feat: site pulse and change timeline with restore
637f815 feat: GitHub release updater for plugin
c09aeb4 feat(admin): premium restyle for abilities, dashboard, audit, skills, memory, sandbox
c11e14d feat(admin): premium setup page with stepper, checklist, client cards
9bd7f44 feat(admin): shared premium shell with sticky nav, mode pill, dark toggle, notice drawer
```

Companion:

```text
6536065 feat(companion): Direct mode wave 2 — menus, templates, discovery, auto-detect startup
cb2d1d6 feat(companion): Direct mode wave 1 — REST client, multi-site config, content/media/taxonomy tools
```

Token budgets:

```text
11fe54d feat: token surface budgets for CI
```
