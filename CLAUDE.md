# Stonewright Agent Guide

Stonewright is a WordPress MCP plugin with an optional Node companion.

## Identity

- PHP namespace: `Stonewright\WpMcp`
- Ability prefix: `stonewright/`
- MCP server id: `stonewright`
- Composer package: `stonewright/wp-mcp`
- NPM package: `@stonewright/companion`
- Plugin license: `GPL-2.0-or-later`
- Companion license: `MIT`

## Hard Rules

- Do not add arbitrary PHP execution, dynamic PHP eval paths, or shell escape
  hatches.
- Write, update, and delete abilities must use real permission callbacks.
- Snapshot posts, templates, Elementor data, and theme JSON-backed content
  before mutation.
- Validate design specs before rendering.
- Honor `development`, `staging`, and `production-safe` modes.
- Destructive production-safe operations require confirmation tokens.
- Companion writes go through guarded WP-CLI only.
- Keep public docs, changelogs, commits, and release notes original and free of
  automated-authorship claims.

## Checks

```bash
cd plugin
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit

cd ../companion
npm run typecheck
npm test
npm run build
```

## Task Start

- Use `stonewright-context-bootstrap` or `stonewright-workflow-preflight` before
  Stonewright MCP work.
- Treat persistent memory and skills as active project constraints.
- Record repeatable lessons with `stonewright/learning-record`.
