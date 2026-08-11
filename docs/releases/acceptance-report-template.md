# Release acceptance report template

Copy this file for each public release candidate. A repository copy such as
`docs/releases/acceptance-VERSION.md` must remain publication-safe; keep any
environment-specific copy untracked and outside the repository. Use it as the
human roll-up after automated gates in
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
- [ ] `cd plugin && composer provenance:lint`
- [ ] `cd plugin && composer contracts:compat`
- [ ] `cd plugin && composer tokens:measure`
- [ ] `cd companion && npm run typecheck`
- [ ] `cd companion && npm run lint`
- [ ] `cd companion && npm run contracts:compat`
- [ ] `cd companion && npm test`
- [ ] `cd companion && npm run tokens:measure`
- [ ] `cd companion && npm run build`
- [ ] `cd companion && npm audit --omit=dev`
- [ ] `cd visual && npm run typecheck && npm test && npm run build`
- [ ] PR and post-merge `main` `e2e-admin-ui`
- [ ] `node scripts/check-docs-freshness.mjs`
- [ ] `git diff --check`
- [ ] `node scripts/check-public-hygiene.mjs --require-private-terms` (release packaging)
- [ ] Plugin ZIP, companion TGZ, and Visual TGZ unpacked and scanned; published
      checksums match downloaded assets.
- [ ] Focused OAuth matrix when OAuth changed:
  - `cd plugin && ./vendor/bin/phpunit --filter OAuth`
  - `cd companion && npx vitest run tests/oauth-matrix.test.ts tests/oauth-token-manager.test.ts tests/wordpress-mcp-oauth.test.ts`

## Documentation

- [ ] Evergreen install docs use the `VERSION` placeholder for release asset URLs.
- [ ] Exact version numbers appear only in package metadata, changelogs, and versioned release notes.
- [ ] `stonewright-task-start` is documented as the canonical first call.
- [ ] Compatibility bootstrap (`stonewright-context-bootstrap`) is not the primary path.
- [ ] Tool-profile documentation matches the client policy (`essential` for known clients, `essential-static` for unknown/stale-list clients, `low-tools` for strict caps; `bootstrap` is diagnostic only).
- [ ] Credential-free paste prompts remain free of real secrets.
- [ ] Certified vs compatible client language matches
  [client-acceptance-template.md](client-acceptance-template.md) and
  [verified-client-versions.md](../verified-client-versions.md).
- [ ] Claims limited to behaviors on the shipped SHA (do not document unmerged PR features).

## Client certification matrix (tier-1)

Complete one [client-acceptance-template.md](client-acceptance-template.md) per
row, or keep environment-specific evidence private. Only a publication-safe,
repository-relative report may be written to a public catalog descriptor.

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
- [ ] Step 1 enablement, mode, surface, and Elementor V4 changes expose the
      documented surface revision and re-list/restart contract.
- [ ] Application Password generation stays in the current Setup tab and the
      credential-free prompt remains placeholder-only.
- [ ] Two synthetic site/environment aliases coexist; duplicate endpoints and
      a forced config failure leave registry, credential, and client config
      state unchanged.
- [ ] Browser provider selection, scan consent, and install/config consent are
      separate and no provider is silently installed.
- [ ] Custom-code dry run stops at human approval; Direct mode has no custom-code
      write path.

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
