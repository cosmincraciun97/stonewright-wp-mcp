# Stonewright agent rules

These rules apply to every coding agent operating in this repository (Claude
Code, Codex, Cursor, custom agents). They override default behavior.

## Identity

- Product name: **Stonewright**.
- PHP namespace: `Stonewright\WpMcp`.
- Ability prefix: `stonewright/`.
- MCP server id: `stonewright`.
- Composer package: `stonewright/wp-mcp`.
- NPM package: `@stonewright/companion`.
- Plugin license: `GPL-2.0-or-later`.
- Companion license: `MIT`.

## Hard rules (non-negotiable)

1. **No arbitrary PHP execution.** Never use `eval()`, `create_function()`,
   `assert()` with string arguments, or any dynamic dispatch that runs
   user-supplied PHP source. Static analysis (`Stonewright\WpMcp\Security\StaticAnalysis`)
   asserts this at boot.
2. **No `__return_true` for writes.** Every ability that writes, updates, or
   deletes state must use a real permission callback that calls into
   `Stonewright\WpMcp\Security\Permissions`. Read-only abilities may use
   simple callbacks but must still pass through the Permissions helpers.
3. **Backup before write.** Before mutating an Elementor post, a global
   styles record, a template, or any theme.json-backed content, call
   `Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id )`.
4. **Validator before render.** Before handing a design spec to any
   renderer, call `Stonewright\WpMcp\DesignSpec\Validator::validate( $spec )`.
   Reject invalid specs with a structured `WP_Error` whose code is
   `stonewright_spec_invalid`.
5. **Confirmation tokens for destructive operations.** When
   `get_option( 'stonewright_mode', 'development' ) === 'production-safe'`,
   every destructive ability must verify a token via
   `ConfirmationToken::verify( $token, $ability_name, $args )`. Tokens are
   issued by `stonewright/security-issue-confirmation-token`.
6. **Production-safe mode.** The plugin must always honor the three modes
   `development`, `staging`, and `production-safe`. The admin UI exposes
   the toggle. The Permissions and ability gates read the option.
7. **Companion never writes to WordPress.** The Node companion handles
   Figma ingestion, Playwright screenshots, pixel diff, and an optional
   MCP HTTP proxy. It must not call WordPress REST write endpoints or
   shell into WP-CLI.

## Clean-room rule

The project was researched against existing MCP plugins (Automattic
`wordpress-mcp`, msrbuilds `elementor-mcp`, Novamira, claudeus-wp-mcp).
**Do not copy code, README text, prompts, schemas, identifiers, or
documentation from any of these projects.** All naming, structure, and
behavior must be original Stonewright work.

## Required directory layout

```
stonewright-wp-mcp/
├── plugin/                  WordPress plugin (source of truth)
│   ├── stonewright.php      Bootstrap
│   ├── composer.json
│   ├── includes/
│   │   ├── Core/            Bootstrap, hooks, registry, REST
│   │   ├── Abilities/       One subdir per category
│   │   ├── Admin/           Settings page
│   │   ├── DesignSpec/      Validator + schema
│   │   ├── Renderers/       Spec → Gutenberg / Elementor
│   │   ├── Security/        Backup, ConfirmationToken, Permissions, AuditLog
│   │   ├── Memory/          Site memory store
│   │   └── Support/         Logger, Json helpers
│   ├── blocks/              Dynamic Gutenberg blocks
│   └── tests/
├── companion/               Node bridge (Figma, Playwright, pixel diff, proxy)
├── skills/                  Skill packs for Claude Code / Codex
└── docs/
```

## Build commands

```bash
# PHP side
cd plugin
composer install
composer test
composer phpstan
composer phpcs

# Node side
cd companion
npm install
npm run build
npm test
```

## Branching and changes

- Feature work happens on topic branches; main is always release-ready.
- A change touching an ability also touches its test under
  `plugin/tests/`. Reproduce bugs with a failing test first.
- Every PR description must list which abilities changed and whether the
  security envelope (backup, token, permission) was affected.

## When working in this repo (agent shortcut)

- Use the `Stonewright\WpMcp` PHP namespace.
- Use the `stonewright/` ability prefix.
- Snapshot via `Backup::snapshot_post( $post_id )` before any Elementor
  or theme.json write.
- Validate via `Validator::validate( $spec )` before rendering.
- Run `composer test` before declaring a phase done.
- Treat the companion as Figma / Playwright / pixel diff / proxy only.
