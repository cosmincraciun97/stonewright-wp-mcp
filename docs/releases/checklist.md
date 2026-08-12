# Stonewright Release Checklist

Use this checklist for every release. All gates must pass before tagging.

## Automated Gates

Run from `plugin/` unless noted.

- [ ] `composer test` - all PHPUnit tests pass.
- [ ] `composer phpstan` - zero errors at configured level.
- [ ] `composer phpcs` - zero style violations.
- [ ] `composer security:audit` - exits 0.
- [ ] `composer dependencies:audit` - exits 0 and reports any abandoned compatibility packages.
- [ ] `composer provenance:lint` - imported/derived source provenance is complete.
- [ ] `composer contracts:compat` - the public ability contract remains compatible.
- [ ] `composer tokens:measure` - every plugin profile stays within its budget.
- [ ] Clean `vendor/`, run `composer install --no-dev --classmap-authoritative`,
      then `composer package:verify-manifests`.
- [ ] `composer docs:matrix` - regenerates the ability matrix cleanly.
- [ ] `cd .. && node scripts/check-docs-freshness.mjs` - versions, release notes, install prompts, and Markdown links are current.
- [ ] `cd .. && node --test scripts/tests/release-flags.test.mjs` - prerelease
      and stable versions receive exactly one correct GitHub release flag.
- [ ] `cd .. && node scripts/check-public-hygiene.mjs --require-private-terms` - source tree is free of configured private project terms.
- [ ] `cd .. && node scripts/package-verify.mjs --strict-vendor` - production package inputs and Jetpack manifests are complete.
- [ ] `cd ../companion && npm run typecheck` - zero TypeScript errors.
- [ ] `cd ../companion && npm run lint` - zero lint errors.
- [ ] `cd ../companion && npm run contracts:compat` - Direct contract remains compatible.
- [ ] `cd ../companion && npm test` - all Vitest tests pass.
- [ ] `cd ../companion && npm run tokens:measure` - Direct profiles stay within budget.
- [ ] `cd ../companion && npm run build` - build succeeds.
- [ ] `cd ../companion && npm audit --omit=dev` - zero production advisories.
- [ ] `cd ../visual && npm run typecheck && npm test && npm run build` - Visual is green.
- [ ] The PR and post-merge `main` CI both pass `e2e-admin-ui` against the
      packaged plugin, including the Setup no-refresh flow and admin spacing.
- [ ] Build the exact plugin ZIP, companion TGZ, and Visual TGZ through the
      release workflow recipe; unpack and scan each archive for secrets,
      private terms, runtime state, development junk, and missing dependencies.
- [ ] `git diff --check` - zero whitespace errors.

## Publish

Release-channel policy applies only to future tags. The existing beta.9 tag,
release record, and assets are historical and must remain untouched. A future
beta or release-candidate tag is a GitHub prerelease; only a stable SemVer tag
becomes the latest release. The native updater stays on the installed channel.

1. Update release notes under `docs/releases/<version>.md`.
2. Tag the verified commit as `v<version>`.
3. Push the tag. The release workflow packages:
   - `stonewright-<version>.zip`
   - `stonewright-companion-<version>.tgz`
   - `SHA256SUMS.txt`
4. Confirm the GitHub release links to the expected assets and checksums.
5. Confirm the staged ZIP passes the private-term scan and Jetpack manifest
   verification before upload.
6. Download every published asset, run `sha256sum -c SHA256SUMS.txt`, and
   confirm the archive metadata reports the released plugin/companion version.

## Client certification (tier-1)

- [ ] At least one filled [client-acceptance-template.md](client-acceptance-template.md)
      for a tier-1 client (Codex, Claude Code/Desktop, Cursor, or VS Code family).
- [ ] Catalog `certification_tier` / `support_tier` / `evidence` still match
      [verified-client-versions.md](../verified-client-versions.md).
- [ ] OAuth matrix unit tests green when OAuth or companion token manager changed:
      `./vendor/bin/phpunit --filter OAuth` and
      `npx vitest run tests/oauth-matrix.test.ts`.
- [ ] Release roll-up: [acceptance-report-template.md](acceptance-report-template.md).

## Manual Verification

### 1. Clean Install

- [ ] Install WordPress 6.7+ locally.
- [ ] Upload and activate Stonewright.
- [ ] Confirm activation produces no PHP errors or warnings.
- [ ] Open WordPress Admin > Stonewright > Settings.
- [ ] Enable the plugin master toggle.

### 2. MCP Ping And Context

- [ ] Call `stonewright-ping`.
- [ ] Call `stonewright-task-start` with a real task summary.
- [ ] Confirm the response includes active instructions, enabled skills, memory entries, and `stonewright_context_token`.

### 2a. WooCommerce Co-activation

- [ ] Install the currently supported WooCommerce fixture.
- [ ] Activate WooCommerce and Stonewright together on a clean WordPress site.
- [ ] Confirm no missing Composer/Jetpack manifest file or activation fatal.
- [ ] Call `stonewright-wc-status` and confirm version, HPOS state, product
      types, registered Woo blocks, and integration support levels.
- [ ] Dry-run one product save and verify no store state changed.

### 3. Ability List

- [ ] Call `stonewright-system-abilities-list`.
- [ ] Confirm there are no `stonewright/qa-*` tools and no Figma tools.
- [ ] Confirm the WP-CLI status, discover, run, batch, job-start, and job-status tools are present.

### 4. Gutenberg Write

- [ ] Submit a minimal valid Design Spec to `stonewright/design-spec-to-gutenberg` with the context token.
- [ ] Confirm the target page contains valid block markup.

### 5. Elementor V3 Write

- [ ] Submit a minimal valid Design Spec to `stonewright/design-spec-to-elementor-v3` with the context token.
- [ ] Open the post in Elementor.
- [ ] Confirm containers and native widgets are editable, with no raw HTML widget fallback.

### 6. WP-CLI Companion

- [ ] Start the companion: `cd companion && npm run start`.
- [ ] Call `stonewright-wp-cli-status`.
- [ ] Call `stonewright-wp-cli-discover`.
- [ ] Call `stonewright-wp-cli-run` with a read command such as `["plugin","list","--format=json"]`.
- [ ] Confirm `["eval","echo 1;"]` is rejected.

### 7. Production-Safe Mode

- [ ] Set `stonewright_mode` to `production-safe`.
- [ ] Attempt a destructive ability without a confirmation token.
- [ ] Confirm `stonewright_confirmation_required`.
- [ ] Issue a token through `stonewright/security-issue-confirmation-token`.
- [ ] Retry with the confirmation token and context token.

### 8. Companion Authentication

- [ ] Set `COMPANION_BEARER_TOKEN` to a strong value.
- [ ] Restart the companion.
- [ ] Send a request to `/wp-cli/status` without `Authorization: Bearer`.
- [ ] Confirm HTTP 401.

### 9. Persistence

- [ ] Save a manual instruction, skill, or memory entry.
- [ ] Start a new MCP session.
- [ ] Call `stonewright-task-start`.
- [ ] Confirm the manual instruction, skill, or memory entry is present in the task-start response.

### 10. Setup and multi-site connection

- [ ] Confirm ability enablement, mode, surface, and Elementor V4 save without a
      full-page reload and increment one shared `surface_revision` only on a
      real change.
- [ ] Confirm HTTP re-list and companion task-start/profile refresh expose the
      selected surface; document a restart only for a client that keeps a stale
      startup snapshot.
- [ ] Generate an Application Password in Setup and confirm the URL, auth tab,
      client selection, private snippet, and password inventory update in place.
- [ ] Add two synthetic aliases on different environments, reject a duplicate
      canonical endpoint, and prove config/credential rollback after a forced
      registry or adapter failure.

### 11. Custom code and browser consent

- [ ] Confirm each installed typed custom-code provider returns an exact dry-run
      handoff and refuses apply without the matching human grant.
- [ ] Confirm Direct mode cannot write custom PHP/CSS/JS/HTML.
- [ ] Confirm browser provider choice, scan consent, and install/config consent
      are independent, persistent per site/client, and never silently granted.

### 12. Audit, memory, and actor attribution

- [ ] Confirm terminal OAuth failures coalesce, retryable/server failures retain
      operational visibility, and pre-login actors resolve without N+1 queries.
- [ ] Confirm generic success cannot close an unrelated incident and unresolved
      incidents do not become active learning memory.
- [ ] Confirm paginated legacy reconciliation remains retryable after a
      synthetic eligible-memory failure.

### 13. Upgrade preservation

- [ ] Upgrade a fixture containing settings, audit rows, memory, user skills,
      site aliases, credential references, and Direct state.
- [ ] Confirm all state remains and no fresh-install seed creates user memory,
      user skills, or audit events.

## Rollback

1. Deactivate the plugin from WordPress Admin > Plugins or via WP-CLI.
2. Delete the plugin directory.
3. Upload the previous release zip.
4. Reactivate.
5. Restore mutated Elementor content from Stonewright snapshots or WordPress revisions when needed.
