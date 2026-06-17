# Open Source Readiness

This checklist tracks the public-release surface for Stonewright.

## Repository Hygiene

- Root plugin license: GPL-2.0-or-later.
- Companion license: MIT.
- Security policy present.
- Code of conduct present.
- Issue templates present.
- Pull request template present.
- CI runs PHP and companion checks.

## Security Envelope

- Direct PHP runtime execution is exposed only through
  `stonewright/php-execute`.
- Write abilities use explicit permission callbacks.
- Elementor, template, and theme-backed writes snapshot first.
- Design specs validate before rendering.
- Destructive production-safe operations require confirmation tokens.
- Companion WP-CLI execution uses tokenized argv; PHP snippets use
  `stonewright/php-execute`, while WP-CLI PHP/shell entry points remain
  blocked.

## Release Checks

Run before tagging a release:

```powershell
Push-Location plugin
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit
composer docs:matrix
Pop-Location

Push-Location companion
npm run typecheck
npm test
npm run build
Pop-Location
```

Also run a public trace scan for private notes, generated-authorship claims,
co-author trailers, secrets, tokens, and local artifacts before publishing.

## Current Hardening Plan

- Keep the public repository clean-room: public docs, changelog entries,
  commits, skills, and PR text describe Stonewright only.
- Keep runtime work inside Stonewright abilities. Use
  `/wp-json/stonewright/v1/abilities/run` only as the Stonewright REST runner
  for clients that cannot execute the MCP ability transport directly.
- Keep visual QA outside Stonewright with a separate browser MCP. If a one-off
  Playwright CLI install is blocked by npm cache or network permissions, use
  the already-connected Playwright MCP server.
- Verify visual work at every active WordPress or Elementor breakpoint:
  desktop, tablet, and mobile at minimum, plus any site-specific custom
  breakpoints. Horizontal overflow is a hard failure.
- Prefer native WordPress and Elementor controls. Do not use Elementor HTML
  widgets unless the user explicitly asks for HTML.
- Improve first-pass generation quality: global-style preflight before
  Elementor writes, centered max-width inner containers, row gap/column width
  checks, native widgets for forms/galleries/social/menu/countdown, and
  persistent skills/memory for repeatable lessons.
- Keep Elementor widget work schema-driven: every widget write should inspect
  Content, Style, and Advanced controls first, use Advanced controls such as
  positioning, z-index, responsive visibility, attributes, CSS ID/classes, and
  motion effects when appropriate, and name only major parent containers.
- Keep Gutenberg/block-theme work native: use `theme.json`, templates, template
  parts, patterns, block supports, and external browser verification before
  signoff.
- Keep onboarding obvious in both public docs and the plugin admin UI:
  connection snippets, bootstrap prompt, task prompt template, and acceptance
  checks.
- Commit and push topic branches regularly after tests and public trace scans
  are clean.

## Maintainer Audit

- Confirm ignored local artifacts such as `research/`, `output/`,
  `.playwright-mcp/`, dependency folders, and local environment files are not
  tracked or staged.
- Audit local and remote branch names plus commit subjects for private
  provenance terms before publishing.
- Do not rewrite history, delete branches, or rename public refs without
  maintainer approval.
