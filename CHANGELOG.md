# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **Companion stdio MCP transport — logs no longer corrupt the JSON-RPC channel.** `info` and `debug` lines were being written to stdout, which is the MCP JSON-RPC stream when the companion runs under the stdio transport. Every stdio MCP client (Claude Desktop, Codex, Continue, Cursor, Cline, any MCP-capable IDE/CLI) tried to parse those `{level,time,msg}` lines as JSON-RPC messages and rejected the handshake with `invalid_union` / `unrecognized_keys: ["level","time","msg"]`. All log levels now write to stderr; a regression-guard test (`companion/tests/log.test.ts > never writes to stdout at any level`) fails the build if anything ever writes to stdout from the logger.

---

## [1.0.0-alpha.2] - 2026-05-22

This release is the "Elementor-first hardening" milestone. It expands the ability surface from ~67 to 108, hardens the entire security envelope, and ships comprehensive documentation.

### Added — Abilities

- **Elementor V3 expanded:** AddContainer, AddWidget, GetElement, MoveElement, RemoveElement, UpdateElement, UpdateKitColors, UpdateKitTypography, UpdatePageSettings — full Elementor V3 CRUD surface with snapshot-before-write on every mutating call.
- **Elementor V4 (experimental):** CreateClass, CreateVariable, ListClasses, ListVariables, ReadAtomicTree, RenderFromSpec, Status, UpdateClass, UpdateVariable — behind `elementor_v4_atomic` feature flag.
- **Elementor Widget Builder:** WidgetDefine, WidgetRegister, WidgetList — DSL-to-PHP widget compiler with StaticGuard enforcement.
- **FSE extended:** ReadGlobalStyles, ReadTemplate, WriteGlobalStyles, WriteTemplate, WriteTemplatePart — full FSE read/write surface with ThemeJson\Validator and ConfirmationGuard.
- **Design pipeline extended:** ApplyToPost, IngestFigma, PreviewRender — Figma ingestion via companion, dry-run preview, spec-to-Elementor apply with token gate.
- **Gutenberg extended:** ApplyToPost, RenderBlocks — apply full design spec to a post; render spec to markup without writing.
- **QA extended:** AccessibilityCheck, ApplyFixPlan, DiffLayout, Lighthouse, Report, SuggestFixes — full QA pipeline via companion (axe-core, Lighthouse, layout diffing).
- **Memory (Wave 3a):** MemoryList, MemoryGet, MemorySave, MemoryDelete — typed key-value memory store backed by wp_options.
- **System (Wave 3b):** InstructionsGet, InstructionsSet, AbilitiesList — system introspection and custom instructions.
- **Sandbox (Wave 3c):** SandboxList, SandboxRead, SandboxWrite, SandboxEdit, SandboxDelete, SandboxActivate, SandboxDeactivate, SandboxToggle — fully sandboxed PHP file staging with StaticGuard on every write.
- **Site extended:** Environment, Health, CreateRevision — runtime environment reporting and explicit revision creation.
- **Content:** BulkCreate (up to 50 posts/pages per call with ConfirmationGuard), OptimizeMedia.

### Added — Security hardening

- `StaticGuard` — PHP token-based static analysis runs before any PHP file is staged or activated (Sandbox, WidgetDefine, WidgetRegister).
- `ConfirmationToken` — HMAC-signed short-lived tokens (120 s TTL) required for all destructive abilities in `production-safe` mode. 25 abilities are token-gated.
- `Backup::snapshot_post()` — called before every write to Elementor data, FSE templates, global styles, and post content. 32 abilities snapshot before mutating.
- `DesignSpec\Validator` — validates design specs against bundled JSON Schema before any render. 12 abilities validate before render/write.
- `ThemeJson\Validator` — validates theme.json payloads before writing to global styles.
- SSRF defense in companion — all URLs validated against `^https?://` before passing to Playwright.
- Path-traversal defense in Sandbox — all file names and artifact paths validated against strict regex allow-lists.
- `AuditLog` — every ability execution appended to `stonewright_audit_log`.

### Added — Documentation

- `docs/ability-truth-matrix.md` — 108-ability truth matrix with R/W, permission, token, backup, validator, and test columns.
- `docs/architecture.md` — ASCII diagram, request flow, security envelope, companion role, file layout.
- `docs/security-guarantees.md` — each AGENTS.md rule stated explicitly with enforcing class and verification grep/test.
- `docs/companion-contract.md` — contract version 1.0.0, all 6 endpoints with JSON schema references.
- `plugin/README.md` — updated to 108 abilities, added quick start, local dev, and "adding a new ability" walkthrough.
- `plugin/bin/generate-ability-matrix.php` — CLI script to regenerate the truth matrix from AbilityRegistry::list().
- `composer docs:matrix` script added.

### Added — Tests

- `tests/Unit/Documentation/AbilityTruthMatrixTest.php` — asserts every slug in AbilityRegistry::list() is present in the truth matrix markdown.
- `tests/Unit/Documentation/AbilityDocblockTest.php` — asserts every Ability subclass has a non-empty class-level docblock first sentence.
- Test count grew from ~80 (project start) to 1845 tests / 4517 assertions across unit and integration test suites.

### Changed

- Plugin version bumped to `1.0.0-alpha.2`.
- `stonewright_mode` now actively gates 25 destructive abilities via ConfirmationToken (previously declared but not fully enforced).
- All FSE write abilities moved from `UpdateGlobalStyles`/`UpdateTemplate` pattern to dual API: `Update*` (merge-friendly) + `Write*` (validator-backed, token-gated).

---

## [1.0.0-alpha.1] - 2026-05-21

Initial tagged release of Stonewright WP MCP (~67 abilities).

### Added

- Initial plugin scaffold registering `stonewright` MCP server through `wordpress/mcp-adapter`.
- Core abilities: `stonewright/ping`, `stonewright/site-info`, `stonewright/site-capabilities`.
- Content abilities: create-page, update-page, get-page, duplicate-page, create-post.
- Media abilities: upload, get, set-alt.
- Gutenberg abilities: blocks.parse, blocks.serialize, blocks.list-registered, patterns.create.
- FSE abilities: get-theme-json, update-global-styles, list-templates, update-template.
- Elementor V3 abilities: status, list-widgets, get-page-structure, build-page-from-spec, save-template, backup-page.
- Elementor V4 (experimental) abilities behind `elementor_v4_atomic` feature flag.
- Design abilities: validate-spec, extract-tokens, import-figma-node, import-image, choose-renderer, spec-to-gutenberg, spec-to-elementor-v3.
- QA abilities: screenshot-page, diff-screenshot, responsive-check, suggest-fixes, report.
- `transavia-hero` dynamic Gutenberg block with functional slider, client-editable controls.
- Companion Node bridge: Figma bridge, Playwright runner, pixel diff, MCP proxy.
- Skills bundle: design-to-wordpress, elementor-v3-builder, elementor-v4-atomic, gutenberg-fse-builder, pixel-perfect-qa, wp-plugin-dev, stonewright-review.
- Baseline test suite: 1296 tests / 3640 assertions.

---

[1.0.0-alpha.2]: https://github.com/stonewright/wp-mcp/releases/tag/v1.0.0-alpha.2
[1.0.0-alpha.1]: https://github.com/stonewright/wp-mcp/releases/tag/v1.0.0-alpha.1
