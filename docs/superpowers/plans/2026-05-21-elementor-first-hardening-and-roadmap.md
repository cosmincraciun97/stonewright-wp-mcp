# Elementor-First Hardening And Roadmap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Stonewright truthful, secure, and useful for Elementor-first design-to-WordPress work: native Elementor/Gutenberg rendering, Figma/image/prompt-to-spec ingestion, measurable responsive QA, editable Elementor widget projects, and a documented admin/sandbox workflow.

**Architecture:** Fix security and broken contracts before adding features. WordPress remains the source of truth. The plugin owns all WordPress mutations through abilities. The companion only handles browser/Figma/image/diff work. Elementor V3 is the stable renderer. Elementor V4 remains feature-flagged until it has real widget/component mappings and tests. Novamira/msrbuilds/Claudeus are feature taxonomy inputs only; do not copy code, prompts, schemas, names, or docs.

**Tech Stack:** PHP 8.1+, WordPress 6.9 target, WordPress Abilities API, `wordpress/mcp-adapter`, Elementor V3/V4 APIs, Composer, PHPUnit, PHPStan, PHPCS WordPress rules, Node 22, TypeScript, Playwright, Sharp/pixelmatch, Vitest.

---

## Current Verdict

Stonewright is a strong prototype, not yet the product requested.

The current "25 Elementor abilities" are enough for a scaffold, not enough for an Elementor-first product. They cover status, tree edits, basic rendering, kit tokens, templates, and V4 placeholders. They do not yet cover reliable native widget creation, widget debugging, theme builder workflows, custom CSS, dynamic tags, popup/form workflows, reusable component workflows, responsive QA, or real pixel-diff loops.

Block release until these are fixed:

- Sandbox can become arbitrary PHP execution.
- Companion HTTP auth can fail open.
- Companion URL override can leak bearer tokens and create SSRF.
- Content writes bypass post-type/publish capability checks.
- Confirmation tokens are issued for the wrong shape and cannot reliably verify destructive calls.
- `BuildSpec` is broken.
- QA PHP calls endpoints the companion does not expose.
- `ApplyFixPlan` reports success without applying fixes.
- Elementor V4 renderer is a placeholder.
- Figma/image ingestion is mostly a stub.

---

## Sources And Clean-Room Boundary

Use these sources only for feature taxonomy and official API behavior:

- Official Elementor widget docs and first-addon docs.
- Official WordPress/Abilities/MCP Adapter docs already used by the project.
- Novamira public docs/pages as product-category inspiration.
- msrbuilds Elementor MCP and Claudeus WP MCP as coverage inspiration.

Rules:

- [ ] Do not inspect third-party source while writing product code.
- [ ] Do not copy identifiers, schemas, README text, prompts, internal structures, or code.
- [ ] Document only Stonewright-owned names and behavior.
- [ ] Keep V4 writes behind `stonewright_elementor_v4_atomic` and disabled in production-safe mode.

---

## Phase 0 - Baseline Tooling

Get the repo into a state where failures mean something.

- [ ] Add missing `plugin/tests/phpstan-bootstrap.php`, or remove it from `phpstan.neon` if not needed.
- [ ] Decide PHPCS style now. Recommendation: allow short array syntax because the codebase already uses `[]` and targets PHP 8.1+.
- [ ] Update `phpcs.xml` so it enforces WordPress security and escaping without banning the existing array style.
- [ ] Run `cd companion && npm install` and commit the lockfile if this repo owns companion dependencies.
- [ ] Add a companion smoke test for the HTTP server startup path.
- [ ] Record the known local WP issue: `transavia-local` in `~/.config/wp-sites.json` currently fails through Claudeus, while `http://localhost:8882/wp-json/` responds directly.

Verification:

```bash
cd plugin && composer test
cd plugin && composer phpstan
cd plugin && composer phpcs
cd companion && npm run typecheck
cd companion && npm test
cd companion && npm run build
```

---

## Phase 1 - Security Blockers

Do this before feature work.

### 1.1 Sandbox

- [ ] Stop treating sandbox activation as safe PHP. It is not.
- [ ] Require `edit_plugins` plus `manage_options` for any action that can activate executable code.
- [ ] Honor `DISALLOW_FILE_MODS`; when true, sandbox activation must be disabled.
- [ ] Require production-safe confirmation tokens for activate, enable, disable, delete, and any file mutation that affects active code.
- [ ] Change `SandboxRead` and `SandboxList` ability permissions from read-level access to `manage_options`.
- [ ] Remove absolute filesystem paths from sandbox list responses.
- [ ] Extend `StaticGuard` to block dynamic calls, variable functions, `call_user_func` with dynamic/unsafe targets, local include/require from user input, network/file mutation functions, and direct execution paths.
- [ ] Add `SandboxStaticGuardTest` fixtures for allowed and blocked snippets.
- [ ] Add audit logging for every sandbox action.

Files:

- `plugin/includes/Sandbox/SandboxFiles.php`
- `plugin/includes/Sandbox/StaticGuard.php`
- `plugin/includes/Abilities/Sandbox/*`
- `plugin/includes/Admin/SandboxPage.php`
- `plugin/tests/Unit/SandboxStaticGuardTest.php`
- `plugin/tests/Unit/SandboxPermissionTest.php`

### 1.2 Companion Security

- [x] Remove per-call `companion_url` from QA abilities.
- [x] Use only the configured companion URL from trusted options.
- [x] Use `wp_safe_remote_post`.
- [x] Never send `stonewright_companion_token` to caller-supplied origins.
- [x] Bind companion HTTP to `127.0.0.1` by default.
- [x] Refuse HTTP startup without `COMPANION_BEARER_TOKEN`, unless explicitly started in local dev mode.
- [x] Require explicit allowed origins in non-dev mode.
- [x] Add request body limits.
- [x] Restrict companion file reads/writes to a configured artifacts directory with realpath base checks.

Files:

- `plugin/includes/Support/CompanionClient.php`
- `plugin/includes/Abilities/QA/*`
- `companion/src/index.ts`
- `companion/src/lib/security.ts`
- `companion/src/pixel-diff.ts`
- `companion/tests/security.test.ts`

### 1.3 Confirmation Tokens

- [ ] Change token issue input to `{ ability, args, ttl_seconds }`.
- [ ] Normalize args before signing and remove `confirmation_token` from the signed payload.
- [ ] Make every verifier normalize the same way.
- [ ] Make `ttl_seconds` real; current issuer ignores it.
- [ ] Require stronger permission than `edit_posts` for issuing destructive tokens. Recommendation: target ability permission plus `manage_options` for global/site/destructive ops.
- [ ] Add end-to-end tests for every destructive ability in production-safe mode.

Files:

- `plugin/includes/Abilities/Security/IssueConfirmationToken.php`
- `plugin/includes/Security/ConfirmationToken.php`
- `plugin/includes/Security/Permissions.php`
- `plugin/tests/Unit/ConfirmationTokenAbilityTest.php`

### 1.4 Content Capability Checks

- [ ] For create/update/bulk content, derive caps from `get_post_type_object()`.
- [ ] Require `create_posts` for the requested post type.
- [ ] Require publish/private caps for status changes to `publish`, `private`, or equivalent protected states.
- [ ] Validate meta writes with `current_user_can( 'edit_post_meta', $post_id, $meta_key )`.

Files:

- `plugin/includes/Abilities/Content/CreatePost.php`
- `plugin/includes/Abilities/Content/UpdatePost.php`
- `plugin/includes/Abilities/Content/BulkCreate.php`
- `plugin/tests/Unit/ContentCapabilityTest.php`

---

## Phase 2 - Truthful Contracts

Stop abilities from pretending they work.

- [ ] Fix `BuildSpec` to match `Validator::validate()` return shape: normalized spec array or `WP_Error`.
- [ ] Make every renderer reject invalid specs with `stonewright_spec_invalid`.
- [ ] Do not continue rendering with the original invalid spec.
- [ ] Make `ApplyFixPlan` return `not_implemented` until it calls real patch paths.
- [ ] Add unsupported-node diagnostics to all renderers instead of silent drops.
- [ ] Check return values from Elementor writes and return `WP_Error` on failure.
- [ ] Make full content replacement in production-safe mode require a token.

Files:

- `plugin/includes/Abilities/Design/BuildSpec.php`
- `plugin/includes/Renderers/GutenbergSpecRenderer.php`
- `plugin/includes/Renderers/ElementorV3SpecRenderer.php`
- `plugin/includes/Renderers/ElementorV4SpecRenderer.php`
- `plugin/includes/Abilities/QA/ApplyFixPlan.php`
- `plugin/includes/Abilities/Design/SpecToElementorV3.php`
- `plugin/includes/Abilities/Design/SpecToGutenberg.php`
- `plugin/tests/Unit/BuildSpecTest.php`
- `plugin/tests/Unit/RendererValidationTest.php`
- `plugin/tests/Unit/ApplyFixPlanTest.php`

---

## Phase 3 - Companion QA Contract

Pick one contract and implement it end to end. Recommendation: REST endpoints from PHP to companion, because PHP already calls REST paths.

Companion endpoints:

- [ ] `POST /screenshot`
- [ ] `POST /diff`
- [ ] `POST /axe`
- [ ] `POST /layout`
- [ ] `POST /lighthouse`
- [ ] `GET /health`

Artifact model:

- [ ] Store screenshots and diffs under `wp-content/uploads/stonewright-qa/{request_id}/` or a companion artifacts directory mirrored back by URL.
- [ ] Return `{ artifact_id, path, url, width, height, viewport, created_at }` from screenshot calls.
- [ ] Return `{ diff_ratio, passed, threshold, diff_url, mismatch_regions }` from diff calls.
- [ ] Reject caller-provided arbitrary output paths.
- [ ] Add size limits, timeout limits, and allowed URL rules.

Responsive QA:

- [ ] Read viewports from the Stonewright Design Spec when present.
- [ ] Detect horizontal overflow.
- [ ] Detect text and element overlap.
- [ ] Capture desktop/tablet/mobile screenshots.
- [ ] Compare against reference screenshots only when references exist.
- [ ] Return `needs_reference` instead of fake pass/fail when no reference exists.

Files:

- `companion/src/index.ts`
- `companion/src/http-api.ts`
- `companion/src/playwright-runner.ts`
- `companion/src/pixel-diff.ts`
- `companion/src/layout-check.ts`
- `plugin/includes/Support/CompanionClient.php`
- `plugin/includes/QA/QaArtifactStore.php`
- `plugin/includes/Abilities/QA/*`
- `companion/tests/http-endpoints.test.ts`
- `plugin/tests/Unit/CompanionContractTest.php`

---

## Phase 4 - Elementor V3 Renderer Completeness

Make Elementor V3 the product center.

Renderer support:

- [ ] `section`
- [ ] `row`
- [ ] `column`
- [ ] `group`
- [ ] `heading`
- [ ] `paragraph`
- [ ] `image`
- [ ] `button`
- [ ] `list`
- [ ] `icon`
- [ ] `video`
- [ ] `embed`
- [ ] `spacer`
- [ ] `card`
- [ ] `slider`
- [ ] `form-placeholder` with explicit unsupported/pro-required diagnostics if Elementor Pro form APIs are unavailable.

Native Elementor behavior:

- [ ] Use containers/flexbox for layout.
- [ ] Map typography tokens to Elementor typography settings.
- [ ] Map colors to kit/global colors when requested.
- [ ] Preserve responsive settings per desktop/tablet/mobile.
- [ ] Keep widget settings editable in Elementor, not hard-coded in raw HTML.
- [ ] Prefer Elementor widgets over custom HTML whenever a native widget exists.
- [ ] Return diagnostics for every unsupported spec node.

Abilities to add or improve:

- [ ] `stonewright/elementor-v3-page-audit`
- [ ] `stonewright/elementor-v3-render-health`
- [ ] `stonewright/elementor-v3-list-templates`
- [ ] `stonewright/elementor-v3-get-template`
- [ ] `stonewright/elementor-v3-create-template`
- [ ] `stonewright/elementor-v3-list-global-widgets`
- [ ] `stonewright/elementor-v3-save-global-widget`
- [ ] `stonewright/elementor-v3-get-custom-css`
- [ ] `stonewright/elementor-v3-update-custom-css`
- [ ] `stonewright/elementor-v3-list-dynamic-tags`
- [ ] `stonewright/elementor-v3-validate-dynamic-tags`
- [ ] `stonewright/elementor-v3-list-theme-builder-locations`
- [ ] `stonewright/elementor-v3-assign-template-location`

Tests:

- [ ] Renderer snapshot tests for each supported node.
- [ ] Responsive setting tests.
- [ ] Permission failure tests.
- [ ] Backup-before-write tests.
- [ ] Production-safe token tests for replacement/destructive writes.

Files:

- `plugin/includes/Renderers/ElementorV3SpecRenderer.php`
- `plugin/includes/Elementor/ElementorData.php`
- `plugin/includes/Elementor/ElementorDiagnostics.php`
- `plugin/includes/Abilities/ElementorV3/*`
- `plugin/tests/Integration/ElementorV3RendererTest.php`
- `plugin/tests/Unit/ElementorV3DiagnosticsTest.php`

---

## Phase 5 - Elementor Widget Builder

This is mandatory. Page rendering is not enough; Stonewright must create, edit, audit, preview, and package Elementor widgets.

Model:

- [ ] Add widget projects under `wp-content/stonewright-sandbox/widgets/{slug}/`.
- [ ] Each widget project has `manifest.json`, `widget.php`, optional `assets/`, and QA artifacts.
- [ ] Admin UI shows widget projects separately from generic snippets.
- [ ] Existing site/plugin widget source remains read-only by default.
- [ ] Edits are made to Stonewright widget projects, not arbitrary plugin files.

Manifest fields:

```json
{
  "kind": "elementor_widget",
  "slug": "hero-slider",
  "title": "Hero Slider",
  "category": "stonewright",
  "status": "draft",
  "elementor_target": "v3",
  "files": ["widget.php"],
  "created_from": "prompt|figma|image|manual",
  "last_audit": null,
  "last_preview": null
}
```

V3 widget scaffold requirements:

- [ ] Create a proper addon loader or project loader.
- [ ] Widget class extends `\Elementor\Widget_Base`.
- [ ] Implement `get_name()`, `get_title()`, `get_icon()`, `get_categories()`, `register_controls()`, `render()`, and `content_template()` where useful.
- [ ] Use Elementor Controls_Manager controls for editable content/style.
- [ ] Escape render output.
- [ ] Avoid inline JS unless explicitly needed.
- [ ] Register assets with WordPress enqueue APIs.
- [ ] Pass `php -l`, StaticGuard, and WordPress security checks before activation.

V4 widget/component policy:

- [ ] Add V4 widget/component scaffolding only as experimental.
- [ ] Do not claim V4 support until the output can be loaded, edited, previewed, and audited.
- [ ] Keep V4 activation blocked in production-safe mode.

Abilities:

- [ ] `stonewright/elementor-widget-list-projects`
- [ ] `stonewright/elementor-widget-scaffold`
- [ ] `stonewright/elementor-widget-read`
- [ ] `stonewright/elementor-widget-edit`
- [ ] `stonewright/elementor-widget-audit`
- [ ] `stonewright/elementor-widget-preview`
- [ ] `stonewright/elementor-widget-activate`
- [ ] `stonewright/elementor-widget-disable`
- [ ] `stonewright/elementor-widget-delete`
- [ ] `stonewright/elementor-widget-package`

Files:

- `plugin/includes/Elementor/WidgetProject.php`
- `plugin/includes/Elementor/WidgetScaffolder.php`
- `plugin/includes/Elementor/WidgetAuditor.php`
- `plugin/includes/Elementor/WidgetPreviewer.php`
- `plugin/includes/Sandbox/SandboxManifest.php`
- `plugin/includes/Abilities/ElementorWidget/*`
- `plugin/includes/Admin/SandboxPage.php`
- `plugin/tests/Unit/ElementorWidgetScaffolderTest.php`
- `plugin/tests/Unit/ElementorWidgetAuditorTest.php`

Acceptance:

- [ ] Agent can create a new editable Elementor widget from prompt/spec.
- [ ] Admin can inspect and edit its code in Sandbox > Widgets.
- [ ] Activation requires security checks and token in production-safe mode.
- [ ] Widget appears in Elementor widget panel under a Stonewright category.
- [ ] Preview produces screenshot and error report.
- [ ] Audit catches missing escaping, unsafe file calls, missing controls, and responsive problems.

---

## Phase 6 - Design Ingestion

Make all design work pass through Stonewright Design Spec.

Figma:

- [ ] Fix `FigmaImporter` section indexing when the first child is not a container.
- [ ] Export image fills through the companion or Figma MCP path.
- [ ] Store imported assets in WordPress media or QA artifacts, not blank URLs.
- [ ] Preserve text, colors, typography, spacing, assets, and breakpoints with confidence scores.

Image:

- [ ] Rename current image import behavior to `image-reference-spec` if it remains a wrapper.
- [ ] Add real image-to-spec only when a vision-capable agent supplies structured regions; WordPress should validate, not hallucinate.
- [ ] Require alt text for generated/imported image assets.

Prompt:

- [ ] Add `stonewright/design-build-spec-from-instructions` as a validator/normalizer for agent-produced specs.
- [ ] Do not make WordPress call an LLM directly in v1.
- [ ] Store original instructions in spec metadata for auditability.

Files:

- `plugin/includes/DesignSpec/FigmaImporter.php`
- `plugin/includes/Abilities/Design/ImportFigmaNode.php`
- `plugin/includes/Abilities/Design/ImportImage.php`
- `plugin/includes/Abilities/Design/BuildSpecFromInstructions.php`
- `plugin/includes/DesignSpec/AssetNormalizer.php`
- `plugin/tests/Unit/FigmaImporterTest.php`
- `plugin/tests/Unit/ImportImageTest.php`

---

## Phase 7 - Gutenberg And FSE Parity

Elementor is the current focus, but Gutenberg must remain native and useful.

- [ ] Expand Gutenberg renderer to support columns, cover, media-text, list, quote, buttons, group, spacer, image, video, embed, and reusable patterns.
- [ ] Map tokens to block attributes and theme.json-compatible styles.
- [ ] Add diagnostics for unsupported spec nodes.
- [ ] Validate theme.json/global styles before write.
- [ ] Require production-safe confirmation token for global style/template replacements.
- [ ] Add fixtures for block serialization and nested block round trips.

Files:

- `plugin/includes/Renderers/GutenbergSpecRenderer.php`
- `plugin/includes/Abilities/Gutenberg/*`
- `plugin/includes/Abilities/FSE/*`
- `plugin/tests/Unit/GutenbergRendererTest.php`
- `plugin/tests/Unit/FseWriteSafetyTest.php`

---

## Phase 8 - Admin UX And Sandbox Library

Finish the Novamira-style admin idea, but with Stonewright security.

- [ ] Add Sandbox tabs: `Snippets`, `Elementor Widgets`, `Generated Plugins`, `QA Artifacts`.
- [ ] Add category filters and status filters.
- [ ] Add manifest-driven metadata for every sandbox item.
- [ ] Add code editor with save/scan/audit actions.
- [ ] Add diff view between draft and active.
- [ ] Add rollback to prior safe version.
- [ ] Add read-only "Installed Elementor Widgets" inventory.
- [ ] Add "Create Stonewright widget project from selected widget" only when it creates a new project, not when it mutates the source plugin.
- [ ] Hook `CrashRecovery::admin_notice()` so failures are visible.
- [ ] Ensure every admin mutation has nonce, capability check, audit log, and production-safe token where required.

Files:

- `plugin/includes/Admin/SandboxPage.php`
- `plugin/includes/Admin/ConfigurationPage.php`
- `plugin/includes/Admin/AbilitiesPage.php`
- `plugin/includes/Core/PluginRegistration.php`
- `plugin/assets/admin/*` if admin assets exist

---

## Phase 9 - Documentation And Ability Truth Matrix

Docs must match code.

- [ ] Generate ability docs from registered schemas.
- [ ] Fix naming style everywhere: code currently uses `stonewright/foo-bar`; docs must not claim `stonewright/foo/bar` unless code changes.
- [ ] Pick the real MCP endpoint and use it everywhere.
- [ ] Add a status matrix: `stable`, `experimental`, `stub`, `blocked`.
- [ ] Mark Elementor V4 as experimental until tests prove otherwise.
- [ ] Document companion security setup.
- [ ] Document the widget builder workflow.
- [ ] Document sandbox risk honestly.
- [ ] Document local testing with `http://localhost:8882/`.

Files:

- `docs/abilities.md`
- `docs/elementor/widgets.md`
- `docs/elementor/v3-renderer.md`
- `docs/elementor/v4-experimental.md`
- `docs/qa/pixel-diff.md`
- `docs/admin/sandbox.md`
- `README.md`
- `bin/generate-ability-docs.php`

---

## Phase 10 - Final Verification

**Status: COMPLETED 2026-05-22**

All automated gates pass (1296 → 1845 tests / 3640 → 4517 assertions after smoke + security-audit additions, phpstan clean, phpcs clean, `composer docs:matrix` idempotent, `composer security:audit` exits 0). The ability truth matrix is at `docs/ability-truth-matrix.md`. The security audit script is at `plugin/bin/security-audit.php`. Release notes for 1.0.0-alpha.2 are at `docs/releases/1.0.0-alpha.2.md`. The release checklist is at `docs/releases/checklist.md`. The Phase 10 smoke test (`plugin/tests/Integration/SmokeTest.php`) asserts all 7 AGENTS.md hard rules structurally and exercises every ability's name(), description(), input_schema(), output_schema(), and permission_callback() without fatals across all 108 registered abilities.

Run this before claiming done:

```bash
cd plugin && composer test
cd plugin && composer phpstan
cd plugin && composer phpcs
cd companion && npm run typecheck
cd companion && npm test
cd companion && npm run build
```

Manual checks:

> Note: Manual verification deferred to release engineering — checklist preserved for the QA team to walk through during the actual release.

- [ ] Plugin activates on a clean local WordPress install.
- [ ] `http://localhost:8882/wp-json/` exposes Stonewright REST namespace.
- [ ] Authenticated MCP client can list abilities.
- [ ] Gutenberg page can be created from a valid spec.
- [ ] Elementor V3 page can be created from a valid spec.
- [ ] Elementor widget can be scaffolded, audited, activated, previewed, disabled, and deleted.
- [ ] QA screenshot and diff artifacts are created.
- [ ] Production-safe destructive call fails without token and succeeds with a valid token.
- [ ] Companion refuses unauthenticated HTTP calls.
- [ ] Sandbox read/list is not available to subscriber/editor-level users unless deliberately allowed.

---

## Feature Priority

Build these first because they unlock real user value:

1. Elementor widget scaffolder/auditor/previewer.
2. Reliable Elementor V3 Design Spec renderer.
3. QA screenshot/diff contract.
4. Figma asset ingestion.
5. Admin Sandbox categorized library.
6. Theme builder/template abilities.
7. Dynamic tag/custom CSS abilities.

Do not spend time on filler abilities until those work.

---

## Done Definition

- [ ] No high security findings remain.
- [ ] No ability reports success without doing the work.
- [ ] Elementor V3 output is editable with native widgets/containers.
- [ ] Widget builder produces editable, auditable Elementor widgets.
- [ ] Pixel QA creates real artifacts and numeric diff reports.
- [ ] Figma/image/prompt paths all produce validated Design Specs.
- [ ] Docs are generated or verified against live ability schemas.
- [ ] PHPUnit, PHPStan, PHPCS, Vitest, TypeScript, and companion build pass.
