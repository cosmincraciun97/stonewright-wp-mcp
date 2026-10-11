# Contributing

## Setup

```bash
git clone https://github.com/<you>/stonewright-wp-mcp.git
cd stonewright-wp-mcp/plugin && composer install
cd ../companion && npm install
```

## Branches

- `main` - stable
- `phase/<n>-<short-name>` - feature work
- `review/<short-name>` - review branch

## Tests

```bash
cd plugin && composer test
cd ../companion && npm test
```

PHPStan level 5, PHPCS WordPress-Extra, PHPUnit, and Vitest for companion.

## Commit style

Use conventional commits without scopes that leak automated authorship.

```text
feat(abilities): add stonewright/wp-cli-run
fix(elementor): backup post meta before write
docs(security): clarify confirmation token TTL
```

## Admin UI

Stonewright admin pages are built from the shared `sw-ui` components and the control-size tokens in
`plugin/assets/admin/sw-ui.css` (the token table is in `DESIGN.md`, "Control size"). Use the `Ui\Button` and
`Ui\FormField` helpers and the `sw-ui-toolbar` / `sw-ui-control-row` rows.

- A page stylesheet places controls and never sets the height, padding or line-height of a button or an input.
- The controls of a row share one height and one centre line; a button beside a field with a label above it lines up
  with the input.
- Keep the familiar UI: do not redesign, remove visible elements or rename things as a side effect of a sizing fix, and
  attach before and after screenshots to the pull request when the visible UI changes.

Before a pull request that touches admin UI, run both checks:

```bash
node scripts/check-admin-control-sizing.mjs
cd e2e && npx playwright test admin-layout.spec.ts
```

The first fails when a page stylesheet sizes a button or an input (the `docs` CI job runs it). The second is the
layout check: it needs a test site with the plugin active (`WP_BASE_URL`, `WP_USERNAME` and `WP_PASSWORD` as in
`e2e/README.md`) and measures every page of the admin menu at 1440, 782 and 390px, so a new page is covered without
editing the test.

## Pull requests

Each PR must:

- pass CI
- include or update tests
- update docs if behavior changes
- pass `node scripts/check-docs-freshness.mjs`
- pass `node scripts/check-admin-control-sizing.mjs` when it touches an admin stylesheet
