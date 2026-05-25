# Claude Code working notes for Stonewright

Stonewright is a WordPress MCP plugin. See `AGENTS.md` for the hard rules.

## When working in this repo

- Use the `Stonewright\WpMcp` PHP namespace and the `stonewright/` ability prefix.
- In MCP clients, call `stonewright-context-bootstrap` at the start of every Stonewright task and obey returned skills, memory, and instructions through the whole task. Slash names such as `stonewright/context-bootstrap` are WordPress ability names; MCP tool names use hyphens.
- Use `stonewright/learning-record` when the user corrects a repeatable mistake so the lesson persists across sessions.
- Use `Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id )` before any Elementor or theme.json write.
- Use `Stonewright\WpMcp\DesignSpec\Validator::validate( $spec )` before rendering. Reject invalid specs with a structured `WP_Error`.
- Run `composer test` before declaring a phase done.
- Use the companion (`companion/`) for WP-CLI, health checks, and the optional MCP HTTP proxy. WP-CLI must use argv tokens through `execFile`, never shell strings or arbitrary PHP entry points.
- Do not add Figma ingestion or automated QA back into Stonewright; use separate tools and user feedback for those workflows.

## Useful project commands

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

## When fixing a bug

Reproduce with a failing test first under `plugin/tests/`. Then fix. Then verify.
