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
- Plugin license: `AGPL-3.0-or-later`.
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

## Third-party source reuse

- Third-party source may be inspected, copied, adapted, or ported when its
  license permits it and the resulting Stonewright component uses compatible
  licensing.
- Preserve upstream copyright and SPDX notices in copied or derived files.
- Record source repository, source path, source version or hash, destination,
  modifications, and applicable license in `docs/upstream-code-reuse.md`.
- Do not mix AGPL-covered code into the GPL plugin or MIT companion without
  first making and documenting the required license change for the resulting
  combined work.
- Rename upstream identifiers and UI copy only where product integration needs
  it; never remove attribution or misrepresent copied work as original.
- Every imported component needs Stonewright-specific security review, tests,
  namespace changes, and compatibility checks. Upstream behavior is evidence,
  not proof that the port is safe in Stonewright.

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
- Public commits, changelogs, docs, skills, and UI copy must not name
  third-party competitor products. Upstream attribution lives only in
  `docs/upstream-code-reuse.md` and SPDX file headers, which must never be
  removed.

## Documentation freshness

- Documentation changes ship in the same PR as the behavior they describe.
  A release/version bump or major feature change must review and update, when
  affected: root/plugin/companion READMEs, both changelogs, release notes,
  `docs/install-prompts.md`, installation/client guides, architecture,
  capability counts, examples, skills, and the roadmap.
- Evergreen install docs use the `VERSION` placeholder for release asset URLs.
  Exact version numbers belong only in package/plugin metadata, changelogs,
  versioned release notes, migration records, and clearly dated historical
  reports.
- `stonewright-task-start` is the canonical first call. Describe
  `stonewright-context-bootstrap` and `stonewright-workflow-preflight` only as
  compatibility paths unless the document is explicitly about those tools.
- Do not edit generated/imported Markdown by hand. Regenerate
  `docs/ability-truth-matrix.md` with `composer docs:matrix`; refresh
  `docs/knowledge/` through its importer and preserve source metadata.
- Before closing documentation or release work, run
  `node scripts/check-docs-freshness.mjs` and `git diff --check`. A release is
  blocked while either command fails.
- Every PR must state which public docs changed. If none changed, state why the
  behavior, setup, capability surface, security contract, and release workflow
  remain accurately documented.

## MCP workflow

- Use the `Stonewright\WpMcp` PHP namespace.
- Use the `stonewright/` ability prefix.
- In MCP clients, call `stonewright-task-start` at the start of every
  Stonewright task. `stonewright-context-bootstrap` and
  `stonewright-workflow-preflight` remain compatibility paths.
  Slash names like `stonewright/context-bootstrap` are WordPress ability names;
  MCP tool names use hyphens.
- If neither `stonewright-task-start` nor compatibility
  `stonewright-context-bootstrap` is visible in the MCP tool list, stop
  WordPress work and ask the user to reload the AI client or fix the
  Stonewright MCP config. Do not work around a missing Stonewright MCP server.
- If status is connected and the site surface is full/essential but
  `stonewright-php-execute` (or another needed tool) is missing from the client
  list, call `stonewright-client-surface-check`, then `stonewright-task-start` /
  `stonewright-tool-profile` activate and re-list tools, or restart the MCP
  client. Do **not** invent `/abilities/run` or other REST workarounds.
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

## Context discipline

- Keep one goal per agent task. Start a fresh task when the goal changes or
  unrelated work begins.
- Compact only at stable checkpoints. Preserve the current objective, decisions,
  changed files, validation results, blockers, and next step; drop raw transcript
  history.
- Read narrowly. Search first, then request only relevant files and line ranges.
  Do not dump whole ledgers, generated artifacts, logs, lockfiles, or large JSON
  files when a summary or targeted slice answers the question.
- Batch related read-only checks when their output stays small. Avoid long chains
  of near-identical reads, shell calls, or MCP calls that each resend the same
  context.
- Reuse the result of `stonewright-task-start` for the current goal. Repeat it
  only after a
  goal, target site, mode, authentication state, or tool profile change.
- Prefer compact MCP profiles and task-aware batch abilities. Use the full tool
  surface only when the task genuinely needs it.
- Keep tool output bounded at the source. Report conclusions and decisive
  evidence instead of echoing raw output into the task.
- Attach large source material once. Refer to its path afterward; do not paste or
  reattach it on later turns.
- Never remove permission, backup, validation, confirmation-token, audit, test,
  or source-verification steps to save tokens.
