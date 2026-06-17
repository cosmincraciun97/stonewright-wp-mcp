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

1. **Full PHP runtime access is first-class.** Use
   `stonewright/php-execute` for direct PHP snippets inside the loaded
   WordPress runtime. Do not replace it with another MCP adapter, direct REST
   runner calls, shell scripts, or private client-config workarounds.
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
6. **Mode support.** The plugin must always honor the three modes
   `development`, `staging`, and `production-safe`. The admin UI exposes the
   toggle. Permissions and ability gates read the option.
7. **Companion WP-CLI stays tokenized.** The Node companion handles WP-CLI,
   health checks, and an optional MCP HTTP proxy. It must not call WordPress
   REST write endpoints, must run WP-CLI with `execFile` argv tokens only, and
   PHP snippets must go through `stonewright/php-execute` rather than WP-CLI
   eval, shell, package, `--exec`, or `--require` entry points.

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
- If `stonewright-context-bootstrap` is not visible in the MCP tool list, stop
  WordPress work and ask the user to reload the AI client or fix the
  Stonewright MCP config. Do not work around a missing Stonewright MCP server.
- Do not inspect private AI-client config files, parse repository files as a
  substitute for the live MCP tool list, hand-roll JSON-RPC calls, create
  scratch scripts such as `query-mcp.js` or `run-ability.js`, helper JSON
  argument files such as `bootstrap-args.json`, `cli_command.json`, or
  `get_structure.json`, direct companion shell launch scripts such as
  `query-local-stonewright.js`, action scripts such as `run-loop-mutate.js` or
  `run-bootstrap-and-mutate.js`, plugin/companion source-code spelunking to
  reverse-engineer tool schemas, calls to `/wp-json/stonewright/v1/abilities/run`
  from shell, or shell `wp ...` commands as an MCP workaround.
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
- Use `stonewright/php-execute` for direct WordPress runtime inspection,
  plugin API calls, and short PHP snippets when that is faster than many typed
  calls.
