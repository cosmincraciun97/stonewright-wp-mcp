# Contributing to Stonewright

Thank you for considering a contribution. Before you open a PR, read this document.

## Repository layout

```
stonewright-wp-mcp/
├── plugin/                     PHP plugin (GPL-2.0-or-later)
│   ├── includes/
│   │   ├── Abilities/          One class per ability, grouped by category
│   │   ├── Core/               MCP server registration, REST routes, DI container
│   │   ├── DesignSpec/         Figma importer and JSON schema validator
│   │   ├── Memory/             Agent memory helper
│   │   ├── Renderers/          Gutenberg and Elementor V3 spec renderers
│   │   ├── Security/           Permissions, Backup, ConfirmationToken, AuditLog
│   │   └── Support/            Utility classes (Logger, Json, BlockTree, etc.)
│   ├── schemas/                stonewright.schema.json (Design Spec)
│   ├── tests/                  PHPUnit test suite
│   ├── blocks/                 Custom block source (recipe-hero, recipe-slider)
│   ├── composer.json
│   ├── phpcs.xml
│   ├── phpstan.neon
│   └── phpunit.xml
├── companion/                  Node bridge (MIT)
│   ├── src/
│   │   └── lib/
│   ├── package.json
│   └── tsconfig.json
├── skills/                     Skill packs for Claude Code and Codex
└── docs/                       Documentation (CC BY 4.0)
```

## Development workflow

### PHP side

```bash
cd plugin
composer install
composer test        # PHPUnit
composer phpstan     # PHPStan level 8 (configured in phpstan.neon)
composer phpcs       # WordPress Coding Standards
composer phpcbf      # Auto-fix coding style where possible
```

PHPUnit requires a running WordPress instance. Follow the `wp-phpunit/wp-phpunit` setup instructions or use a `wp-env` environment.

### Node side (companion)

```bash
cd companion
npm install
npm run build        # tsup -> dist/
npm test             # vitest
npm run typecheck    # tsc --noEmit
```

## Reproducing bugs

Before submitting a fix, add a failing test in `plugin/tests/` that reproduces the bug. Fix the code. Verify both `composer test` and `composer phpstan` pass.

## Branch naming

| Type | Format | Example |
|---|---|---|
| Feature | `feature/<short-description>` | `feature/elementor-v4-renderer` |
| Bug fix | `fix/<issue-or-short-description>` | `fix/backup-empty-snapshot` |
| Documentation | `docs/<short-description>` | `docs/ability-reference` |
| Chore | `chore/<short-description>` | `chore/bump-phpunit` |

Branch off `main`. Keep branches short-lived.

## Pull request expectations

A PR should include:

1. A clear title (imperative mood, under 72 characters).
2. A short description of what changed and why.
3. A test for any new ability or bug fix.
4. A passing CI run (`composer test`, `composer phpstan`, `composer phpcs`).
5. An entry in `CHANGELOG.md` under `[Unreleased]`.

For new abilities:
- Place the class under the correct namespace in `includes/Abilities/<Category>/`.
- Register it in `includes/Abilities/AbilityKernel.php`.
- Declare the minimum capability in the `permission_callback` via `Permissions`.
- Call `Backup::snapshot_post` before any write that touches post content or meta.
- Add a `ConfirmationToken` check if the ability is destructive.
- Record the result in `AuditLog::record`.

## Code style

The plugin follows WordPress Coding Standards (`WordPress-Extra` + `WordPress-Docs`) with two overrides defined in `phpcs.xml`:

- `WordPress.Files.FileName.InvalidClassFileName` — excluded because we use PascalCase filenames to match PSR-4 autoloading.
- `WordPress.Files.FileName.NotHyphenatedLowercase` — same reason.

All PHP files declare `strict_types=1` and use the `Stonewright\WpMcp` namespace.

PHPStan runs at level 8 with `szepeviktor/phpstan-wordpress` stubs. New code should not introduce PHPStan errors.

## License

By contributing to the plugin you agree that your contribution is licensed under GPL-2.0-or-later. By contributing to the companion or skills you agree that your contribution is licensed under MIT.
