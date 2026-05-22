# Abilities Reference

Stonewright registers 67 abilities as MCP tools. All ability names use the `stonewright/` prefix. This page lists every ability by category with a brief description.

For full parameter documentation, see the individual reference pages under `docs/reference/`.

---

## Content (7)

Abilities for creating and updating WordPress posts and pages. Permission tier: `edit_posts` or `edit_pages` for writes; object-level `edit_post` for updates.

| Ability | Description |
|---|---|
| `stonewright/content/bulk-create` | Creates multiple posts or pages from an array of specs in one request. Destructive mode required if overwriting existing slugs. |
| `stonewright/content/create-page` | Creates a WordPress page. Accepts title, slug, status, template, and optional block content. |
| `stonewright/content/create-post` | Creates a WordPress post. Accepts title, slug, status, categories, tags, and content. |
| `stonewright/content/duplicate-page` | Duplicates a page including its Elementor data, meta, and template assignment. |
| `stonewright/content/get-page` | Returns a page with its full post content, Elementor JSON, and meta fields. |
| `stonewright/content/update-page` | Updates the title, status, content, or template of an existing page. Requires `edit_post` on the target. |
| `stonewright/content/update-post` | Updates the title, status, content, or excerpt of an existing post. |

---

## Design (9)

Abilities for ingesting design context and converting it to WordPress output. These are the entry points for the Figma-to-WordPress workflow.

| Ability | Description |
|---|---|
| `stonewright/design/build-spec` | Builds a Stonewright Design Spec from a free-form description or token data. |
| `stonewright/design/choose-renderer` | Detects the active page builder and returns the recommended renderer identifier. |
| `stonewright/design/extract-tokens` | Reads color, typography, and spacing tokens from the active theme or a specific page. |
| `stonewright/design/import-figma-node` | Fetches a Figma node via the companion bridge and converts it to a Design Spec. Requires `FIGMA_TOKEN` and a running companion. |
| `stonewright/design/import-image` | Uploads an image from a URL and creates a corresponding image block or Elementor widget. |
| `stonewright/design/normalize-assets` | Walks a Design Spec, uploads all remote image URLs to the media library, and rewrites the spec with local attachment IDs. |
| `stonewright/design/spec-to-elementor-v3` | Renders a Design Spec to Elementor V3 widget JSON and writes it to the target page. Calls `Backup::snapshot_post` first. |
| `stonewright/design/spec-to-elementor-v4` | Stub renderer for Elementor V4 (experimental — output format may change). |
| `stonewright/design/spec-to-gutenberg` | Renders a Design Spec to serialized Gutenberg markup and writes it to the target page. |
| `stonewright/design/validate-spec` | Validates a Design Spec against `stonewright.schema.json` without writing anything. Returns a structured error list on failure. |

---

## Elementor V3 (13)

Abilities for direct manipulation of Elementor V3 page structures. Require Elementor 3.21+. All write abilities call `Backup::snapshot_post` before mutating `_elementor_data`.

| Ability | Description |
|---|---|
| `stonewright/elementor-v3/add-container` | Adds a flex or grid container to a page at a specified position. |
| `stonewright/elementor-v3/add-widget` | Adds a widget to a container by widget type and settings object. |
| `stonewright/elementor-v3/backup-page` | Creates a named snapshot of all Elementor data for a page without modifying it. |
| `stonewright/elementor-v3/build-page-from-spec` | Builds a complete Elementor V3 page from a Design Spec in one call. |
| `stonewright/elementor-v3/get-element` | Returns the JSON data for a single element by Elementor element ID. |
| `stonewright/elementor-v3/get-page-structure` | Returns the full widget tree for a page including all element IDs and settings. |
| `stonewright/elementor-v3/get-widget-schema` | Returns the registered controls schema for an Elementor widget type. |
| `stonewright/elementor-v3/list-widgets` | Lists all registered Elementor widget types available on the site. |
| `stonewright/elementor-v3/move-element` | Moves an element to a different parent container or position within its current parent. |
| `stonewright/elementor-v3/remove-element` | Removes an element from a page's structure by element ID. |
| `stonewright/elementor-v3/save-template` | Saves a page or section as a reusable Elementor template. |
| `stonewright/elementor-v3/status` | Returns the Elementor version, active kit ID, and license type. |
| `stonewright/elementor-v3/update-element` | Updates the settings of an existing element by ID. |
| `stonewright/elementor-v3/update-kit-colors` | Adds or replaces color palette entries in the active Elementor kit. |
| `stonewright/elementor-v3/update-kit-typography` | Updates typography presets in the active Elementor kit. |
| `stonewright/elementor-v3/update-page-settings` | Updates page-level Elementor settings such as padding, background, and title visibility. |

---

## FSE (5)

Abilities for Full Site Editing themes. Require a block theme. Write abilities use `edit_theme_options`.

| Ability | Description |
|---|---|
| `stonewright/fse/create-template-part` | Creates a new FSE template part with the supplied block markup. |
| `stonewright/fse/get-theme-json` | Returns the current theme's merged theme.json settings and variations. |
| `stonewright/fse/list-templates` | Lists all FSE templates available for the active theme. |
| `stonewright/fse/update-global-styles` | Updates global color, typography, and spacing settings via the FSE Global Styles API. Calls `Backup::snapshot_post` on the global styles post. |
| `stonewright/fse/update-template` | Replaces the block markup of an FSE template by template ID. |

---

## Gutenberg (8)

Abilities for block-level operations on any post type that supports the block editor.

| Ability | Description |
|---|---|
| `stonewright/gutenberg/get-block-schema` | Returns the `block.json` schema for a registered block type. |
| `stonewright/gutenberg/insert-block` | Inserts a serialized block string at a specified position in a post's content. |
| `stonewright/gutenberg/list-registered-blocks` | Lists all block types registered on the server with their attributes. |
| `stonewright/gutenberg/parse-blocks` | Parses a post's content field into a structured block tree. |
| `stonewright/gutenberg/remove-block` | Removes a block identified by client ID from a post's content. |
| `stonewright/gutenberg/serialize-blocks` | Serializes a block tree back to WordPress block markup format. |
| `stonewright/gutenberg/transform-html` | Converts a raw HTML string into a best-effort Gutenberg block sequence. |
| `stonewright/gutenberg/update-block` | Updates a single block's attributes by client ID within a post's content. |

---

## Media (4)

Abilities for the WordPress media library. Require `upload_files`.

| Ability | Description |
|---|---|
| `stonewright/media/get-media` | Returns metadata for an attachment by ID or source URL. |
| `stonewright/media/optimize-media` | Triggers image optimization via the companion (WebP conversion, resize to max dimensions). |
| `stonewright/media/set-alt` | Sets the alt text and caption for a media attachment. |
| `stonewright/media/upload-media` | Uploads a file from a remote URL or base64-encoded string to the media library. |

---

## Patterns (2)

Abilities for WordPress block patterns.

| Ability | Description |
|---|---|
| `stonewright/patterns/create-pattern` | Creates a synced or unsynced block pattern from supplied block markup. |
| `stonewright/patterns/list-patterns` | Lists all registered block patterns with their categories and content. |

---

## QA (6)

Quality assurance abilities. All screenshot and audit abilities require a running companion.

| Ability | Description |
|---|---|
| `stonewright/qa/accessibility-check` | Runs an axe-core accessibility audit on a page URL via the companion. Returns violations by WCAG level. |
| `stonewright/qa/diff-layout` | Compares the DOM layout of two page states or URLs and returns a structural diff. |
| `stonewright/qa/diff-screenshot` | Pixel-diffs two screenshots (by URL or attachment ID) and returns a match percentage and diff image. |
| `stonewright/qa/lighthouse` | Runs a Lighthouse performance, accessibility, and best-practices audit via the companion. |
| `stonewright/qa/responsive-check` | Screenshots a page at the mobile, tablet, and desktop breakpoints defined in the Design Spec. |
| `stonewright/qa/screenshot-page` | Takes a full-page screenshot of any URL via the companion's Playwright instance. |

---

## Site (9)

Read-heavy diagnostic and introspection abilities. Most require only `read`; admin-level abilities require `manage_options`.

| Ability | Description |
|---|---|
| `stonewright/site/backup-page` | Takes a full content-and-meta snapshot of any post or page. Returns the snapshot ID. |
| `stonewright/site/capabilities` | Returns the full capability map for the currently authenticated user. |
| `stonewright/site/create-revision` | Forces a WordPress native revision for a post, independent of autosave behavior. |
| `stonewright/site/environment` | Returns PHP version, WordPress version, active plugin list, and server software. |
| `stonewright/site/health` | Runs the WordPress Site Health check suite and returns results by status. |
| `stonewright/site/info` | Returns site name, site URL, home URL, time zone, date format, and REST API base URL. |
| `stonewright/site/list-plugins` | Lists all installed plugins with name, version, status (active/inactive), and author. |
| `stonewright/site/ping` | Returns `pong` with the current server UTC timestamp. Use this to verify the MCP connection. |
| `stonewright/site/theme` | Returns the active theme, parent theme (if any), stylesheet, and template directory. |
