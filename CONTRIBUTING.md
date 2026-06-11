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

PHPStan level 8, PHPCS WordPress-Extra, PHPUnit, and Vitest for companion.

## Commit style

Use conventional commits without scopes that leak automated authorship.

```text
feat(abilities): add stonewright.wp-cli.run
fix(elementor): backup post meta before write
docs(security): clarify confirmation token TTL
```

## Pull requests

Each PR must:

- pass CI
- include or update tests
- update docs if behavior changes
- pass `bin/check-docs-tone.sh`
