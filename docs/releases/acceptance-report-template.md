# Release acceptance report template

Copy this file for each public release candidate (for example
`docs/releases/acceptance-VERSION.md` is optional and private; keep secrets out
of git). Use it as the human roll-up after automated gates in
[checklist.md](checklist.md).

## Release identity

| Field | Value |
|---|---|
| Version | `VERSION` (exact tag without leading `v` for assets) |
| Git SHA | |
| Plugin ZIP | `stonewright-VERSION.zip` |
| Companion tarball | `stonewright-companion-VERSION.tgz` |
| Date (UTC) | YYYY-MM-DD |
| Owner | |
| Overall result | pass / fail / blocked |

## Automated gates

From repository root unless noted:

- [ ] `cd plugin && composer test`
- [ ] `cd plugin && composer phpstan`
- [ ] `cd plugin && composer phpcs`
- [ ] `cd plugin && composer security:audit`
- [ ] `cd plugin && composer dependencies:audit`
- [ ] `cd companion && npm run typecheck`
- [ ] `cd companion && npm test`
- [ ] `cd companion && npm run build`
- [ ] `node scripts/check-docs-freshness.mjs`
- [ ] `git diff --check`
- [ ] `node scripts/check-public-hygiene.mjs --require-private-terms` (release packaging)
- [ ] Focused OAuth matrix when OAuth changed:
  - `cd plugin && ./vendor/bin/phpunit --filter OAuth`
  - `cd companion && npx vitest run tests/oauth-matrix.test.ts tests/oauth-token-manager.test.ts tests/wordpress-mcp-oauth.test.ts`

## Documentation

- [ ] Evergreen install docs use the `VERSION` placeholder for release asset URLs.
- [ ] Exact version numbers appear only in package metadata, changelogs, and versioned release notes.
- [ ] `stonewright-task-start` is documented as the canonical first call.
- [ ] Compatibility bootstrap (`stonewright-context-bootstrap`) is not the primary path.
- [ ] Default tool profile documentation matches companion default (`essential-static` unless locked or Setup overrides).
- [ ] Credential-free paste prompts remain free of real secrets.
- [ ] Certified vs compatible client language matches
  [client-acceptance-template.md](client-acceptance-template.md) and
  [verified-client-versions.md](../verified-client-versions.md).
- [ ] Claims limited to behaviors on the shipped SHA (do not document unmerged PR features).

## Client certification matrix (tier-1)

Complete one [client-acceptance-template.md](client-acceptance-template.md) per
row, or attach private evidence links.

| Client | Catalog slug | Mode tested | Result | Evidence |
|---|---|---|---|---|
| Codex | `codex` | | pass/fail/blocked | |
| Claude Code | `claude-code` | | | |
| Claude Desktop | `claude-desktop` | | | |
| Cursor | `cursor` | | | |
| VS Code (Copilot) | `vscode-copilot` | | | |
| GitHub Copilot | `github-copilot` | | | |

Minimum for a public release:

- [ ] At least one **tier-1** stdio path pass (task-start visible after client-specific restart).
- [ ] At least one OAuth HTTP path pass **or** documented deferral with risk accepted.
- [ ] Direct-mode custom-code write boundary confirmed (cannot write custom code).

## Connection and OAuth smoke

- [ ] Plugin Setup: enable abilities; OAuth and/or Application Password path works.
- [ ] In-admin Verify connection (loopback) when available.
- [ ] Companion `doctor` (stdio) exits cleanly without printing secrets.
- [ ] Terminal OAuth reauth path: invalid/revoked refresh forces browser reauthorization (no infinite retry).
- [ ] Status / gateway honesty: disconnected and unauthorized states are not reported as healthy.

## Security and hygiene

- [ ] No Application Passwords, OAuth tokens, private hostnames, or customer content in git, release notes, or fixtures.
- [ ] Release package private-term scan clean.
- [ ] Plugin license and companion license headers unchanged as required.

## Sign-off

| Role | Name | Result | Date |
|---|---|---|---|
| Engineering | | | |
| Docs | | | |
| Release owner | | | |

## Notes

Record blockers, deferred clients, and follow-ups. Prefer bug classes over site
identity.
