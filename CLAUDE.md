# Claude Code working notes for Stonewright

Stonewright is a WordPress MCP plugin. See `AGENTS.md` for the hard rules.

## When working in this repo

- Use the `Stonewright\WpMcp` PHP namespace and the `stonewright/` ability prefix.
- Use `Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id )` before any Elementor or theme.json write.
- Use `Stonewright\WpMcp\DesignSpec\Validator::validate( $spec )` before rendering. Reject invalid specs with a structured `WP_Error`.
- Run `composer test` before declaring a phase done.
- Use the companion (`companion/`) only for: Figma ingestion, Playwright screenshots, pixel diff, optional MCP HTTP proxy. Never let the companion write to WordPress directly.

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
