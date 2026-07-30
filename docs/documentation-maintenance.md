# Documentation Maintenance

Stonewright documentation is part of the product contract. Setup, capability,
safety, and release claims must match the code shipped in the same commit.

## Sources of truth

| Claim | Canonical source |
|---|---|
| Plugin version | `plugin/stonewright.php` |
| Companion version | `companion/package.json` and `companion/src/version.ts` |
| Plugin abilities | generated `docs/ability-truth-matrix.md` |
| Direct tools | `DIRECT_TOOL_NAMES` in `companion/src/direct/registry.ts` |
| Current release notes | `docs/releases/<version>.md` |
| Public workflow rules | `AGENTS.md` and runtime agent instructions |
| Installation prompts | `docs/install-prompts.md` and generated wp-admin client snippets |

## Evergreen versus historical documents

Evergreen READMEs, installation guides, client guides, skills, and admin docs
must not pin an old prerelease asset. Use this pattern and tell the reader to
replace `VERSION` with the exact release version, without a leading `v`:

```text
https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz
```

Exact versions are valid in plugin/package metadata, changelogs, versioned
release notes, migration records, and dated historical reports.

`stonewright-task-start` is the canonical first call. The older
`stonewright-context-bootstrap` and `stonewright-workflow-preflight` tools stay
documented as compatibility paths.

`docs/ability-truth-matrix.md` is generated. Files under `docs/knowledge/` are
imported reference snapshots with source metadata. Do not hand-edit either.

## Required review for releases and major changes

Review every affected item in the same PR:

- `README.md`, `plugin/README.md`, and `companion/README.md`;
- `CHANGELOG.md`, `plugin/CHANGELOG.md`, and `docs/releases/<version>.md`;
- `docs/install-prompts.md`, `docs/installation.md`, `docs/onboarding.md`;
- `docs/admin/` and `docs/getting-started/` client instructions;
- architecture, security, capability counts, examples, and relevant skills;
- active plans when scope, ordering, gates, or known baselines changed.

If a document does not need a change, the PR description must say why its
contract remains accurate.

## Verification

From the repository root:

```bash
node scripts/check-docs-freshness.mjs
git diff --check
```

Regenerate the ability matrix after ability metadata changes:

```bash
cd plugin
composer docs:matrix
```

The documentation check validates version agreement, current release notes,
changelog heads, evergreen package URLs, required install-prompt contracts, and
relative Markdown links. CI and the release workflow run it automatically.
