# Stonewright Release Checklist

Use this checklist for every release. All gates must pass before tagging.

---

## Automated gates

Run from `plugin/` unless noted.

- [ ] `composer test` — all PHPUnit tests pass; count must be >= baseline
- [ ] `composer phpstan` — zero errors at configured level
- [ ] `composer phpcs` — zero style violations
- [ ] `composer security:audit` — exits 0 (no findings)
- [ ] `composer docs:matrix` — idempotent (re-run produces no diff against committed file)
- [ ] `cd ../companion && npm run typecheck` — zero TypeScript errors
- [ ] `cd ../companion && npm test` — all Vitest tests pass
- [ ] `cd ../companion && npm run build` — build succeeds with no errors

---

## Manual verification steps

### 1. Clean install

- [ ] Install WordPress 6.7+ on a clean local environment.
- [ ] Upload and activate the Stonewright plugin.
- [ ] Confirm activation produces no PHP errors or warnings in `debug.log`.
- [ ] Navigate to **WordPress Admin > Stonewright > Settings**.
- [ ] Enable the plugin (master toggle).

### 2. MCP ping

- [ ] Using an MCP client (or `curl`), call `stonewright/ping`.
- [ ] Confirm response: `{ "ok": true, "version": "1.0.0-alpha.2" }`.

### 3. Ability list

- [ ] Call `stonewright/abilities-list` via MCP.
- [ ] Confirm >= 108 abilities returned.

### 4. Create page via spec (Gutenberg)

- [ ] Submit a minimal spec to `stonewright/spec-to-gutenberg`.
- [ ] Confirm a new page is created and the content contains valid block markup.

### 5. Create page via spec (Elementor V3)

- [ ] Submit a minimal spec to `stonewright/spec-to-elementor-v3` with a valid `post_id`.
- [ ] Open the post in the Elementor editor.
- [ ] Confirm containers and widgets are present and editable — not raw HTML.

### 6. Companion screenshot

- [ ] Start the companion: `cd companion && npm run start`.
- [ ] Call `stonewright/qa-screenshot-page` with a valid `url`.
- [ ] Confirm `artifact_id`, `url`, `width`, and `height` fields in the response.
- [ ] Confirm the screenshot file exists on disk under `wp-content/uploads/stonewright-qa/`.

### 7. Production-safe mode

- [ ] Set `stonewright_mode` option to `production-safe` in the database or via the admin toggle.
- [ ] Attempt a destructive ability (e.g., `stonewright/elementor-v3-build-page-from-spec` that replaces all content) **without** a token.
- [ ] Confirm the response is a `stonewright_confirmation_required` WP_Error.
- [ ] Issue a token via `stonewright/security-issue-confirmation-token`.
- [ ] Retry with the token.
- [ ] Confirm the operation succeeds.

### 8. Companion authentication

- [ ] Stop the companion.
- [ ] Set `COMPANION_BEARER_TOKEN` to a known value.
- [ ] Restart the companion.
- [ ] Send a request to `/screenshot` without the `Authorization: Bearer` header.
- [ ] Confirm the companion returns HTTP 401.

### 9. Sandbox permission boundary

- [ ] Log in to WordPress as an Editor (not Administrator).
- [ ] Call `stonewright/sandbox-list` via MCP.
- [ ] Confirm the response is a `stonewright_permission_denied` WP_Error (or the ability is blocked).
- [ ] Confirm an Administrator can call the same ability successfully.

---

## Rollback steps

If a release is found to be defective after deployment:

1. Deactivate the plugin from **WordPress Admin > Plugins** (or via WP-CLI: `wp plugin deactivate stonewright`).
2. Delete the plugin directory.
3. Upload the previous release zip.
4. Reactivate.
5. No database rollback is needed — the plugin stores no schema. The `stonewright_confirmation_secret` option persists but is safe to leave.
6. If Elementor page data was mutated, restore via Stonewright snapshots (`_stonewright_backups` post meta) or WordPress revisions.

---

## Release tagging

Once all gates and manual steps pass:

```bash
git tag -a v1.0.0-alpha.2 -m "Stonewright 1.0.0-alpha.2"
git push origin v1.0.0-alpha.2
```

Create the GitHub release from the tag and attach:
- `stonewright-wp-mcp-1.0.0-alpha.2.zip` (plugin directory zipped)
- Release notes from `docs/releases/1.0.0-alpha.2.md`
