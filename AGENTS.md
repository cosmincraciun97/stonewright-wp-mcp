# Stonewright agent rules

These rules apply to every coding agent operating in this repository. They
override default behavior.

## Identity

- Product name: **Stonewright**.
- PHP namespace: `Stonewright\WpMcp`.
- Ability prefix: `stonewright/`.
- MCP server id: `stonewright`.
- Composer package: `stonewright/wp-mcp`.
- NPM package: `@stonewright/companion`.
- Plugin license: `GPL-2.0-or-later`.
- Companion license: `MIT`.

## Hard rules

1. **No arbitrary PHP execution.** Never use `eval()`, `create_function()`,
   `assert()` with string arguments, or dynamic dispatch that runs
   user-supplied PHP source. Static analysis
   (`Stonewright\WpMcp\Security\StaticAnalysis`) asserts this at boot.
2. **No `__return_true` for writes.** Every ability that writes, updates, or
   deletes state must use a real permission callback that calls into
   `Stonewright\WpMcp\Security\Permissions`. Read-only abilities may use simple
   callbacks but must still pass through the Permissions helpers.
3. **Backup before write.** Before mutating an Elementor post, a global styles
   record, a template, or any theme.json-backed content, call
   `Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id )`.
4. **Validator before render.** Before handing a design spec to any renderer,
   call `Stonewright\WpMcp\DesignSpec\Validator::validate( $spec )`. Reject
   invalid specs with a structured `WP_Error` whose code is
   `stonewright_spec_invalid`.
5. **Confirmation tokens for destructive operations.** When
   `get_option( 'stonewright_mode', 'development' ) === 'production-safe'`,
   every destructive ability must verify a token via
   `ConfirmationToken::verify( $token, $ability_name, $args )`. Tokens are
   issued by `stonewright/security-issue-confirmation-token`.
6. **Production-safe mode.** The plugin must always honor the three modes
   `development`, `staging`, and `production-safe`. The admin UI exposes the
   toggle. Permissions and ability gates read the option.
7. **Companion writes only through guarded WP-CLI.** The Node companion handles
   WP-CLI, health checks, and an optional MCP HTTP proxy. It must not call
   WordPress REST write endpoints, must run WP-CLI with `execFile` argv tokens
   only, and must block arbitrary PHP/shell entry points such as `wp eval`,
   `wp eval-file`, `wp shell`, `wp package`, `--exec`, and `--require`.

## Clean-room rule

The project may be compared with third-party WordPress automation and MCP tools
only at the product-requirements level. Do not copy code, README text, prompts,
schemas, identifiers, documentation, changelog text, UI copy, or proprietary
workflow structure from third-party projects. Public Stonewright docs,
changelog entries, commit messages, PR descriptions, skills, and agent guidance
must describe original Stonewright work only.

## Required directory layout

```text
stonewright-wp-mcp/
|-- plugin/                  WordPress plugin source
|   |-- stonewright.php      Bootstrap
|   |-- composer.json
|   |-- includes/
|   |   |-- Core/            Bootstrap, hooks, registry, REST
|   |   |-- Abilities/       One subdir per category
|   |   |-- Admin/           Settings page
|   |   |-- DesignSpec/      Validator and schema
|   |   |-- Renderers/       Spec to Gutenberg / Elementor
|   |   |-- Security/        Backup, ConfirmationToken, Permissions, AuditLog
|   |   |-- Memory/          Site memory store
|   |   `-- Support/         Logger, JSON helpers
|   |-- blocks/              Dynamic Gutenberg blocks
|   `-- tests/
|-- companion/               Node bridge: WP-CLI, health, optional proxy
|-- skills/                  Skill packs for AI coding agents
`-- docs/
```

## Build commands

```bash
cd plugin
composer install
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit

cd ../companion
npm install
npm run typecheck
npm test
npm run build
```

## Branching and changes

- Feature work happens on topic branches; `main` stays release-ready.
- A change touching an ability also touches its test under `plugin/tests/`.
- Every PR description must list changed abilities and whether backup, token,
  permission, validation, or audit gates changed.
- Public commits, changelog entries, docs, skills, and PR text must not claim
  automated authorship or disclose internal development tooling.

## MCP workflow

- Use the `Stonewright\WpMcp` PHP namespace.
- Use the `stonewright/` ability prefix.
- In MCP clients, call `stonewright-context-bootstrap` or
  `stonewright-workflow-preflight` at the start of every Stonewright task.
  Slash names like `stonewright/context-bootstrap` are WordPress ability names;
  MCP tool names use hyphens.
- Persistent site skills and memory are active constraints across sessions.
- If the user corrects a repeatable mistake, record it with
  `stonewright/learning-record`.
- Snapshot via `Backup::snapshot_post( $post_id )` before Elementor,
  template, global-style, or theme.json writes.
- Validate via `Validator::validate( $spec )` before rendering.
- Use `stonewright/wp-cli-status`, `stonewright/wp-cli-discover`, and
  `stonewright/wp-cli-run` for WordPress, Elementor, Gutenberg, ACF, CPT UI,
  cache, rewrite, plugin, option, post, media, menu, and taxonomy work when it
  speeds up implementation or debugging.
