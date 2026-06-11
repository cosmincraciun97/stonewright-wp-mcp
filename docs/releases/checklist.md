# Stonewright Release Checklist

Use this checklist for every release. All gates must pass before tagging.

## Automated Gates

Run from `plugin/` unless noted.

- [ ] `composer test` - all PHPUnit tests pass.
- [ ] `composer phpstan` - zero errors at configured level.
- [ ] `composer phpcs` - zero style violations.
- [ ] `composer security:audit` - exits 0.
- [ ] `composer dependencies:audit` - exits 0 and reports any abandoned compatibility packages.
- [ ] `composer docs:matrix` - regenerates the ability matrix cleanly.
- [ ] `cd ../companion && npm run typecheck` - zero TypeScript errors.
- [ ] `cd ../companion && npm test` - all Vitest tests pass.
- [ ] `cd ../companion && npm run build` - build succeeds.

## Publish

1. Update release notes under `docs/releases/<version>.md`.
2. Tag the verified commit as `v<version>`.
3. Push the tag. The release workflow packages:
   - `stonewright-<version>.zip`
   - `stonewright-companion-<version>.tgz`
   - `SHA256SUMS.txt`
4. Confirm the GitHub release links to the expected assets and checksums.

## Manual Verification

### 1. Clean Install

- [ ] Install WordPress 6.7+ locally.
- [ ] Upload and activate Stonewright.
- [ ] Confirm activation produces no PHP errors or warnings.
- [ ] Open WordPress Admin > Stonewright > Settings.
- [ ] Enable the plugin master toggle.

### 2. MCP Ping And Context

- [ ] Call `stonewright-ping`.
- [ ] Call `stonewright-context-bootstrap` with a real task summary.
- [ ] Confirm the response includes active instructions, enabled skills, memory entries, and `stonewright_context_token`.

### 3. Ability List

- [ ] Call `stonewright-system-abilities-list`.
- [ ] Confirm there are no `stonewright/qa-*` tools and no Figma tools.
- [ ] Confirm the three WP-CLI tools are present.

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
- [ ] Call `stonewright-wp-cli-run` with a safe read command such as `["plugin","list","--format=json"]`.
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
- [ ] Call `stonewright-context-bootstrap`.
- [ ] Confirm the manual instruction, skill, or memory entry is present in the bootstrap response.

## Rollback

1. Deactivate the plugin from WordPress Admin > Plugins or via WP-CLI.
2. Delete the plugin directory.
3. Upload the previous release zip.
4. Reactivate.
5. Restore mutated Elementor content from Stonewright snapshots or WordPress revisions when needed.
