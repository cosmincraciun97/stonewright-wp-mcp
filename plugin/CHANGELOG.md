# Changelog

All notable changes to the Stonewright plugin are documented here.

This project uses [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) formatting and [Semantic Versioning](https://semver.org/).

---

## [1.0.0-alpha.2] - 2026-05-22

### Added

- Elementor V3 renderer completeness — native container/widget output for all 14 spec node types; diagnostics for unsupported nodes; responsive settings preserved per viewport (Phase 4).
- Elementor V4 atomic experimental abilities behind `stonewright_elementor_v4_atomic` feature flag: CreateClass, CreateVariable, ListClasses, ListVariables, ReadAtomicTree, RenderFromSpec, Status, UpdateClass, UpdateVariable (Phase 4).
- Sandbox library admin UI — `SandboxList`, `SandboxRead`, `SandboxWrite`, `SandboxEdit`, `SandboxDelete`, `SandboxActivate`, `SandboxDeactivate`, `SandboxToggle` with `StaticGuard` enforcement on every write (Phase 8).
- Gutenberg + FSE write abilities — `ApplyToPost`, `RenderBlocks`; FSE `ReadGlobalStyles`, `ReadTemplate`, `WriteGlobalStyles`, `WriteTemplate`, `WriteTemplatePart` with `ThemeJson\Validator` and `ConfirmationGuard` (Phase 7).
- Companion QA REST contract — `CompanionContract` typed request/response validation; `QaArtifactStore` for artifacts; `AccessibilityCheck`, `DiffLayout`, `Lighthouse`, `Report` endpoints (Phase 3).
- Figma ingestion — `FigmaImporter` section-index fix; `NormalizeAssets` sidelo‌ads image fills to WordPress media; `BuildSpecFromInstructions` validates agent-produced specs (Phase 6).
- Elementor Widget Builder DSL — `WidgetDefine`, `WidgetRegister`, `WidgetList` DSL-to-PHP compiler with `StaticGuard` enforcement (Phase 5).
- Documentation suite — `docs/architecture.md`, `docs/security-guarantees.md`, `docs/companion-contract.md`, `plugin/README.md` updated; `plugin/bin/generate-ability-matrix.php` CLI script; `composer docs:matrix` script (Phase 9).
- Ability truth matrix at `docs/ability-truth-matrix.md` — 108-ability matrix with R/W, permission, token, backup, validator, and test columns (Phase 9).
- Memory abilities — `MemoryList`, `MemoryGet`, `MemorySave`, `MemoryDelete` backed by `wp_options`.
- System abilities — `InstructionsGet`, `InstructionsSet`, `AbilitiesList`.
- Phase 10 smoke test — `plugin/tests/Integration/SmokeTest.php` asserts all 7 hard rules and exercises every registered ability's interface without fatals (Phase 10).
- Security audit script — `plugin/bin/security-audit.php` runnable via `composer security:audit` (Phase 10).
- `AbilityTruthMatrixTest` and `AbilityDocblockTest` documentation coverage tests.

### Changed

- Ability count grew from 67 → 108.
- `StaticGuard` hardened against `Reflection`, `Closure::fromCallable`, case-insensitivity, whitespace normalization, and nested `call_user_func` bypass vectors (Phase 1 + Task 16).
- `stonewright_mode` now actively gates 25 destructive abilities via `ConfirmationToken` (previously declared but not fully enforced).
- Plugin version bumped to `1.0.0-alpha.2`.

### Fixed

- `ConfirmationToken` HMAC test flake — token now signs over a canonical args hash with replay protection via transients.
- URL escaping in Gutenberg renderer block attributes.
- `SandboxFiles` `index.php` deletion bug.
- `SandboxLibrary` empty-hash bypass.

### Security

- 7 AGENTS.md hard rules enforced structurally via `SmokeTest` and `composer security:audit`.
- `StaticGuard` blocks dynamic dispatch, variable functions, `Reflection`, `Closure::fromCallable`, and `call_user_func` with unsafe targets.
- `ConfirmationToken` — HMAC-signed 120 s TTL tokens for all destructive abilities in `production-safe` mode.
- SSRF defense in companion — all URLs validated against `^https?://` before Playwright.
- Path-traversal defense in Sandbox — file names validated against strict regex allow-lists.
- Ability truth matrix security columns documented at `docs/ability-truth-matrix.md`.

### Tests

- Test count: 1296 → 1845 tests; assertions 3640 → 4517.

---

## [1.0.0-alpha.1] - 2026-05-21

Initial alpha release. The plugin is functional but the ability interface and JSON schema may change before 1.0.0 stable.

### Added

#### MCP server

- Registers a `stonewright` MCP server via `wordpress/mcp-adapter` with server ID `stonewright` and ability prefix `stonewright/*`.
- REST endpoints: `/wp-json/stonewright/v1/mcp` (MCP transport), `/wp-json/stonewright/v1/audit-log` (read-only, `manage_options`), `/wp-json/stonewright/v1/settings` (read/write, `manage_options`).

#### Stonewright Design Spec v1.0.0

- JSON Schema at `schemas/stonewright.schema.json` (`$id`: `https://stonewright.dev/schemas/design-spec/1.0.0.json`).
- Renderer-agnostic page description: `version`, `source`, `page`, `tokens` (colors, typography, spacing, radius, shadow), `responsive` (breakpoints), `sections` (with nested `blocks`).
- Block types: `heading`, `paragraph`, `image`, `button`, `spacer`, `separator`, `list`, `icon`, `video`, `embed`, `slider`, `card`, `row`, `column`.
- Validated by `Stonewright\WpMcp\DesignSpec\Validator` using `opis/json-schema`. Invalid specs are rejected with a structured `WP_Error` before any renderer is invoked.

#### Figma importer

- `Stonewright\WpMcp\DesignSpec\FigmaImporter` converts a Figma node (via the Figma REST API or companion bridge) into a Stonewright Design Spec.

#### Gutenberg renderer

- `Stonewright\WpMcp\Renderers\GutenbergSpecRenderer` converts a validated Design Spec into serialized block markup.
- Supports all 14 block types defined in the schema.

#### Elementor V3 renderer

- `Stonewright\WpMcp\Renderers\ElementorV3SpecRenderer` converts a validated Design Spec into Elementor V3 widget JSON written to `_elementor_data` post meta.
- Wraps all output in a pre-write `Backup::snapshot_post` call.

#### Elementor V4 stub

- `Design/SpecToElementorV4` ability and renderer skeleton — outputs a placeholder pending Elementor V4 stable API.

#### Abilities — Content (7)

| Ability | Description |
|---|---|
| `stonewright/content/bulk-create` | Creates multiple posts or pages from an array of specs in a single request. |
| `stonewright/content/create-page` | Creates a WordPress page with optional template and Gutenberg content. |
| `stonewright/content/create-post` | Creates a WordPress post with categories, tags, and content. |
| `stonewright/content/duplicate-page` | Duplicates an existing page including its Elementor data. |
| `stonewright/content/get-page` | Reads a page with its full block content and Elementor metadata. |
| `stonewright/content/update-page` | Updates page title, status, content, or template. |
| `stonewright/content/update-post` | Updates post title, status, content, or excerpt. |

#### Abilities — Design (9)

| Ability | Description |
|---|---|
| `stonewright/design/build-spec` | Builds a Design Spec from free-form description or extracted tokens. |
| `stonewright/design/choose-renderer` | Detects the active page builder and returns the best renderer. |
| `stonewright/design/extract-tokens` | Extracts color, typography, and spacing tokens from a page or theme. |
| `stonewright/design/import-figma-node` | Fetches a Figma node via the companion and returns a Design Spec. |
| `stonewright/design/import-image` | Uploads an image and creates an image block or Elementor widget. |
| `stonewright/design/normalize-assets` | Uploads remote image URLs to the media library and rewrites the spec. |
| `stonewright/design/spec-to-elementor-v3` | Renders a Design Spec to Elementor V3 data and writes it to a page. |
| `stonewright/design/spec-to-elementor-v4` | Stub: renders a Design Spec to Elementor V4 format (experimental). |
| `stonewright/design/spec-to-gutenberg` | Renders a Design Spec to Gutenberg block markup and writes it to a page. |
| `stonewright/design/validate-spec` | Validates a Design Spec against the JSON schema without writing anything. |

#### Abilities — Elementor V3 (13)

| Ability | Description |
|---|---|
| `stonewright/elementor-v3/add-container` | Adds a flex or grid container to a page's Elementor structure. |
| `stonewright/elementor-v3/add-widget` | Adds a widget to a container by widget type and settings. |
| `stonewright/elementor-v3/backup-page` | Creates a snapshot of all Elementor data for a page. |
| `stonewright/elementor-v3/build-page-from-spec` | Builds a complete Elementor V3 page from a Design Spec. |
| `stonewright/elementor-v3/get-element` | Returns the JSON for a single element by ID. |
| `stonewright/elementor-v3/get-page-structure` | Returns the full widget tree for a page. |
| `stonewright/elementor-v3/get-widget-schema` | Returns the registered controls schema for an Elementor widget type. |
| `stonewright/elementor-v3/list-widgets` | Lists all registered Elementor widget types. |
| `stonewright/elementor-v3/move-element` | Moves an element to a different parent or position. |
| `stonewright/elementor-v3/remove-element` | Removes an element from a page's Elementor structure. |
| `stonewright/elementor-v3/save-template` | Saves a page or section as a reusable Elementor template. |
| `stonewright/elementor-v3/status` | Returns Elementor version, active kit ID, and license status. |
| `stonewright/elementor-v3/update-element` | Updates the settings of an existing element by ID. |
| `stonewright/elementor-v3/update-kit-colors` | Updates color palette entries in the active Elementor kit. |
| `stonewright/elementor-v3/update-kit-typography` | Updates typography presets in the active Elementor kit. |
| `stonewright/elementor-v3/update-page-settings` | Updates page-level Elementor settings (padding, background, etc.). |

#### Abilities — FSE (5)

| Ability | Description |
|---|---|
| `stonewright/fse/create-template-part` | Creates a new FSE template part. |
| `stonewright/fse/get-theme-json` | Returns the current theme's merged theme.json settings. |
| `stonewright/fse/list-templates` | Lists all available FSE templates. |
| `stonewright/fse/update-global-styles` | Updates global styles (colors, typography, spacing) via the FSE API. |
| `stonewright/fse/update-template` | Replaces the block markup of an FSE template. |

#### Abilities — Gutenberg (8)

| Ability | Description |
|---|---|
| `stonewright/gutenberg/get-block-schema` | Returns the `block.json` schema for a registered block type. |
| `stonewright/gutenberg/insert-block` | Inserts a serialized block at a position in a post's content. |
| `stonewright/gutenberg/list-registered-blocks` | Lists all block types registered on the server. |
| `stonewright/gutenberg/parse-blocks` | Parses post content into a structured block tree. |
| `stonewright/gutenberg/remove-block` | Removes a block by client ID from post content. |
| `stonewright/gutenberg/serialize-blocks` | Serializes a block tree back to post content markup. |
| `stonewright/gutenberg/transform-html` | Converts raw HTML into Gutenberg block markup. |
| `stonewright/gutenberg/update-block` | Updates a single block's attributes by client ID. |

#### Abilities — Media (4)

| Ability | Description |
|---|---|
| `stonewright/media/get-media` | Returns metadata for an attachment by ID or URL. |
| `stonewright/media/optimize-media` | Triggers image optimization via companion (WebP conversion, resize). |
| `stonewright/media/set-alt` | Sets the alt text for an attachment. |
| `stonewright/media/upload-media` | Uploads a file from a URL or base64 string to the media library. |

#### Abilities — Patterns (2)

| Ability | Description |
|---|---|
| `stonewright/patterns/create-pattern` | Creates a synced or unsynced block pattern. |
| `stonewright/patterns/list-patterns` | Lists all registered block patterns with their content. |

#### Abilities — QA (6)

| Ability | Description |
|---|---|
| `stonewright/qa/accessibility-check` | Runs an axe-core accessibility scan via the companion. |
| `stonewright/qa/diff-layout` | Compares the DOM layout of two page states and returns a diff. |
| `stonewright/qa/diff-screenshot` | Pixel-diffs two screenshots and returns a match score and diff image. |
| `stonewright/qa/lighthouse` | Runs a Lighthouse audit via the companion and returns scores. |
| `stonewright/qa/responsive-check` | Screenshots a page at mobile, tablet, and desktop breakpoints. |
| `stonewright/qa/screenshot-page` | Takes a full-page screenshot via the companion's Playwright instance. |

#### Abilities — Site (9)

| Ability | Description |
|---|---|
| `stonewright/site/backup-page` | Takes a full content + meta snapshot of any post or page. |
| `stonewright/site/capabilities` | Returns the current user's WordPress capabilities. |
| `stonewright/site/create-revision` | Forces a WordPress revision for a post. |
| `stonewright/site/environment` | Returns PHP version, WordPress version, active plugins, and server info. |
| `stonewright/site/health` | Runs WordPress Site Health checks and returns results. |
| `stonewright/site/info` | Returns site name, URL, time zone, and REST URL. |
| `stonewright/site/list-plugins` | Lists active and inactive plugins with versions. |
| `stonewright/site/ping` | Returns `pong` with the current server timestamp. |
| `stonewright/site/theme` | Returns the active theme, parent theme, and stylesheet details. |

#### Security layer

- `Permissions` class — all capability checks in one file.
- `Backup::snapshot_post` — pre-write snapshot with restore support.
- `ConfirmationToken` — 5-minute single-use tokens for destructive operations.
- `AuditLog` — append-only custom table with hashed IP and UA.
- `StaticAnalysis::assert_environment` — boot-time check for dangerous PHP functions.

---

[1.0.0-alpha.2]: https://github.com/stonewright/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.2
[1.0.0-alpha.1]: https://github.com/stonewright/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.1
