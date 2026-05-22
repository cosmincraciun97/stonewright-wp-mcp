# Stonewright Plugin

Version: 1.0.0-alpha.2
Requires WordPress: 6.7+
Requires PHP: 8.1+
License: GPL-2.0-or-later

Stonewright registers 108 WordPress Abilities as MCP tools and exposes them through the official `wordpress/mcp-adapter`. It supports Gutenberg block editing, Full Site Editing templates, Elementor V3 pages, Elementor V4 atomic design, a renderer-agnostic design spec that targets all three from a single JSON payload, a sandboxed Elementor widget builder, pixel-perfect QA tooling, and a site memory store.

## Quick start

```bash
# 1. Install PHP dependencies (from plugin/)
cd plugin
composer install --no-dev

# 2. Activate
wp plugin activate stonewright

# 3. (Optional) Start the companion for Figma ingestion, screenshots, and QA
cd ../companion
npm install
npm run build
node dist/index.js
```

Set the companion URL in WordPress: `wp option update stonewright_companion_url http://localhost:3500`

## Local development

```bash
# PHP — run from plugin/
composer install          # install all dev deps
composer test             # PHPUnit (1288+ tests)
composer phpstan          # static analysis
composer phpcs            # coding standards
composer docs:matrix      # regenerate docs/ability-truth-matrix.md

# Node — run from companion/
npm install
npm run build
npm test
```

## Adding a new ability

1. Create a class extending `AbilityKernel` in `plugin/includes/Abilities/<Category>/ClassName.php`.

   ```php
   namespace Stonewright\WpMcp\Abilities\Content;

   use Stonewright\WpMcp\Abilities\AbilityKernel;
   use Stonewright\WpMcp\Security\Permissions;

   /**
    * Short description used by the ability truth matrix.
    */
   final class MyAbility extends AbilityKernel {
       public function name(): string {
           return 'stonewright/content-my-ability';
       }
       public function label(): string {
           return __( 'My Ability', 'stonewright' );
       }
       public function description(): string {
           return __( 'What this ability does.', 'stonewright' );
       }
       public function category(): string {
           return 'content';
       }
       public function permission_callback( array $args ): bool|\WP_Error {
           return Permissions::edit_posts();
       }
       public function execute( array $args ): array|\WP_Error {
           // For write abilities: call Backup::snapshot_post() first.
           // For spec-consuming abilities: call Validator::validate() first.
           return [ 'ok' => true ];
       }
   }
   ```

2. Register the class in `AbilityRegistry::list()` (`plugin/includes/Core/AbilityRegistry.php`).

3. Add a fixture in `plugin/tests/fixtures/abilities/` if the ability has structured input.

4. Write a PHPUnit test in `plugin/tests/Unit/` or `plugin/tests/Integration/`.

5. Regenerate the truth matrix: `composer docs:matrix`

6. Run `composer test && composer phpstan && composer phpcs` — all three must pass before merging.

## Installation

### From source

```bash
cd /path/to/wp-content/plugins
git clone https://github.com/stonewright/stonewright-wp-mcp.git stonewright
cd stonewright/plugin
composer install --no-dev
wp plugin activate stonewright
```

The plugin uses the Jetpack Autoloader. If another plugin already loaded `wordpress/mcp-adapter`, Stonewright defers to the higher version.

### Requirements

- The `wordpress/mcp-adapter` and `wordpress/abilities-api` packages must be present. They ship as Composer dependencies and are loaded from `vendor/`. No separate installation is needed when you run `composer install`.
- Elementor 3.21 or later is required only if you use the `ElementorV3` abilities. The plugin activates without it.

## Configuration

All settings are stored as WordPress options and can be read or written via the REST endpoint at `/wp-json/stonewright/v1/settings` (requires `manage_options`).

### stonewright_mode

Controls which abilities are available.

| Value | Behavior |
|---|---|
| `development` | All **108** abilities active. |
| `staging` | All abilities active; extra logging enabled. |
| `production-safe` | Destructive abilities (delete, overwrite without confirmation) are blocked at the permission layer. |

Set via WP-CLI: `wp option update stonewright_mode production-safe`

### stonewright_companion_url

Internal URL of the running companion Node server. Required for `ImportFigmaNode`, `DiffScreenshot`, `ScreenshotPage`, `AccessibilityCheck`, `ResponsiveCheck`, and `Lighthouse` abilities. Example: `http://localhost:3500`.

### stonewright_figma_token

Figma personal access token stored in the database. Used as a fallback when the companion does not have `FIGMA_TOKEN` set in its environment. Prefer the companion environment variable so the token is not written to the database.

## Wiring to Claude Code

The MCP server endpoint is:

```
https://your-site.example.com/wp-json/stonewright/v1/mcp
```

This endpoint is registered by `wordpress/mcp-adapter` using the server ID `stonewright`. Authentication uses WordPress Application Passwords.

Generate an Application Password: **WordPress Admin > Users > Profile > Application Passwords**.

Claude Code configuration (`.claude/settings.json` or `claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "stonewright": {
      "url": "https://your-site.example.com/wp-json/stonewright/v1/mcp",
      "headers": {
        "Authorization": "Bearer username:xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Replace the bearer value with the actual application password string. WordPress expects it in the format generated by the Application Passwords UI.

## Abilities overview

Abilities are registered in `includes/Abilities/` and grouped by namespace prefix `stonewright/`.

**Content** (7): BulkCreate, CreatePage, CreatePost, DuplicatePage, GetPage, UpdatePage, UpdatePost

**Design** (13): ApplyToPost, BuildSpec, ChooseRenderer, ExtractTokens, ImportFigmaNode, ImportImage, IngestFigma, NormalizeAssets, PreviewRender, SpecToElementorV3, SpecToElementorV4, SpecToGutenberg, ValidateSpec

**Elementor V3** (16): AddContainer, AddWidget, BackupPage, BuildPageFromSpec, GetElement, GetPageStructure, GetWidgetSchema, ListWidgets, MoveElement, RemoveElement, SaveTemplate, Status, UpdateElement, UpdateKitColors, UpdateKitTypography, UpdatePageSettings

**Elementor V4** (9, experimental): CreateClass, CreateVariable, ListClasses, ListVariables, ReadAtomicTree, RenderFromSpec, Status, UpdateClass, UpdateVariable

**Elementor Widget Builder** (3): WidgetDefine, WidgetList, WidgetRegister

**FSE** (10): CreateTemplatePart, GetThemeJson, ListTemplates, ReadGlobalStyles, ReadTemplate, UpdateGlobalStyles, UpdateTemplate, WriteGlobalStyles, WriteTemplate, WriteTemplatePart

**Gutenberg** (10): ApplyToPost, GetBlockSchema, InsertBlock, ListRegisteredBlocks, ParseBlocks, RemoveBlock, RenderBlocks, SerializeBlocks, TransformHtml, UpdateBlock

**Media** (4): GetMedia, OptimizeMedia, SetAlt, UploadMedia

**Memory** (4): MemoryDelete, MemoryGet, MemoryList, MemorySave

**Patterns** (2): CreatePattern, ListPatterns

**QA** (9): AccessibilityCheck, ApplyFixPlan, DiffLayout, DiffScreenshot, Lighthouse, Report, ResponsiveCheck, ScreenshotPage, SuggestFixes

**Sandbox** (8): SandboxActivate, SandboxDeactivate, SandboxDelete, SandboxEdit, SandboxList, SandboxRead, SandboxToggle, SandboxWrite

**Security** (1): IssueConfirmationToken

**Site** (9): BackupPage, Capabilities, CreateRevision, Environment, Health, Info, ListPlugins, Ping, Theme

**System** (3): AbilitiesList, InstructionsGet, InstructionsSet

Total: 108 abilities across 15 categories.

See [docs/ability-truth-matrix.md](../docs/ability-truth-matrix.md) for the full reference with security properties per ability.

## Troubleshooting

**"Abilities API not registered" admin notice**

The `wordpress/abilities-api` package is not loaded. Run `composer install` in `plugin/` and confirm the `vendor/` directory exists. If the MCP adapter is loaded by another plugin, verify it is version 0.3.0 or later.

**"MCP Adapter missing" admin notice**

`wordpress/mcp-adapter` is not available. Same resolution: run `composer install`. If you installed the plugin from a zip that did not include `vendor/`, you need to install dependencies manually or use the version that bundles them.

**Abilities return 401**

The Application Password is invalid or the user account does not have the required capability for that ability. Each ability declares its minimum capability (typically `edit_posts`, `edit_pages`, or `manage_options`). Check the audit log at `/wp-json/stonewright/v1/audit-log`.

**Companion-dependent abilities return an error**

Set `stonewright_companion_url` to the URL where the companion is running and confirm the companion is reachable from the WordPress server (not just from your local machine).

**production-safe mode is blocking an ability I need**

Change `stonewright_mode` to `staging` or `development`. Never change it on a live public site without reviewing what each destructive ability does first.
