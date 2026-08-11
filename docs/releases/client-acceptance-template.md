# Client acceptance template

Use one copy of this template per AI client version when certifying Stonewright
MCP connectivity. Fill evidence with synthetic or private notes only — never
commit site URLs, usernames, Application Passwords, OAuth tokens, cookies, or
customer content.

Related:

- Catalog source: `plugin/data/clients/*.json` (`support_tier`, `evidence`)
- Manual smoke table: [verified-client-versions.md](../verified-client-versions.md)
- Install prompts: [install-prompts.md](../install-prompts.md)
- Release gate roll-up: [acceptance-report-template.md](acceptance-report-template.md)

## Tier definitions

| Tier | Meaning |
|---|---|
| **tier-1** | Primary certified path. Docs, catalog, and smoke matrix are maintained first: Codex, Claude Code/Desktop, Cursor, VS Code / GitHub Copilot. |
| **tier-2** | Supported with known caveats; smoke runs on demand. |
| **compatible** | Config snippets and discovery work; full certification not claimed. |
| **experimental** | Best-effort only; may break without notice. |

**Certified** means a completed acceptance report for a named client version
passed the required checks below. **Compatible** means the client can load the
documented MCP config and call `stonewright-task-start`, without a full
certification matrix.

## Header

| Field | Value |
|---|---|
| Client slug (catalog) | e.g. `codex` |
| Client product name | |
| Client version | |
| OS / shell | |
| Stonewright plugin version | |
| Companion package version (`VERSION` placeholder resolved) | |
| Connection mode | plugin OAuth HTTP / plugin Application Password stdio / Direct stdio |
| Support tier (catalog) | tier-1 / tier-2 / compatible / experimental |
| Tester | |
| Date (UTC) | YYYY-MM-DD |
| Result | pass / fail / blocked |

## Preconditions

- [ ] Release artifacts use the evergreen `VERSION` URL pattern (no hardcoded stale version in evergreen docs).
- [ ] Secrets stay in user-level private client config (`secret_storage: user-level`).
- [ ] Paste-to-agent prompts contain placeholders only (no real credentials).
- [ ] Exactly one Stonewright MCP server entry is configured.
- [ ] Startup profile is intentional (`STONEWRIGHT_MCP_TOOL_PROFILE=essential-static` default, or `bootstrap` / `low-tools` when required).

## Install and restart

- [ ] Applied catalog snippet or official CLI add for this client.
- [ ] Performed the **client-specific restart / MCP reload** (not only a chat refresh).
- [ ] Confirmed the client lists the `stonewright` server.
- [ ] Confirmed `stonewright-task-start` is visible in the live tool list.

If neither `stonewright-task-start` nor compatibility
`stonewright-context-bootstrap` appears, stop: the MCP server is not loaded.

## Connection truth (PR1 / current branch)

These checks match behaviors present on the connection-truth bootstrap path:

- [ ] Called `stonewright-setup-profile` when connection or credentials were unclear.
- [ ] Called `stonewright-wordpress-mcp-status` (or companion doctor) and recorded:
  - `companion_version` (stdio) or plugin endpoint reachability (remote HTTP)
  - `mode` / backend honesty (plugin vs Direct) without silent fallback
  - `refresh_required_tool_names` empty (or listed recovery steps taken)
- [ ] Permanent gateways / status contract: status reports are truthful when the
  endpoint is missing, unauthorized, or degraded (no “connected” lie).

Do **not** claim later-PR features (setup persistence UI, multi-site installer
extras, Elementor native parity expansions) unless they exist on the tested
build.

## Canonical first call

- [ ] Called `stonewright-task-start` as the first WordPress task call.
- [ ] Followed `fast_path.tool_profile` from the response when present.
- [ ] Did not invent REST `/abilities/run` shell workarounds or private config spelunking.

## OAuth path (when testing remote HTTP OAuth)

- [ ] Resource URL is exactly `https://example.test/wp-json/mcp/stonewright-oauth` shape for the site under test.
- [ ] Discovery well-known documents reachable (protected resource + authorization server / OIDC).
- [ ] Authorization uses PKCE S256 only.
- [ ] Token refresh rotates the refresh token; replay of a rotated token is rejected server-side (family revoke).
- [ ] Terminal `invalid_grant` / `invalid_client` / `unauthorized_client` forces explicit reauthorization (local token store cleared on companion).
- [ ] Unauthenticated MCP OAuth route returns JSON error + `WWW-Authenticate` with `resource_metadata` and `scope="mcp"`.

Deterministic CI coverage for these contracts lives in:

- `plugin/tests/Unit/OAuth/OAuthMatrixContractTest.php`
- `plugin/tests/Unit/OAuth/*` and `plugin/tests/Integration/OAuth/*`
- `companion/tests/oauth-matrix.test.ts`
- `companion/tests/oauth-token-manager.test.ts`
- `companion/tests/wordpress-mcp-oauth.test.ts`

## Application Password / stdio path

- [ ] Companion starts via release tarball `npx` args (not committed `dist/`).
- [ ] Env vars carry credentials (never inline secrets in args).
- [ ] `stonewright-task-start` succeeds against the configured site.

## Direct mode limits (when mode is Direct)

- [ ] Custom PHP/CSS/JS/HTML write is **not** available; agent reports Plugin-mode approval-gated path.
- [ ] Destructive tools require `confirm: true` (or documented local override).
- [ ] No silent upgrade to plugin-only engines.

## Evidence log

| Check | Result | Notes (no secrets) |
|---|---|---|
| Tool list shows task-start | | |
| task-start response profile | | |
| OAuth discovery | | |
| Refresh / reauth | | |
| Status / doctor | | |
| Custom-code boundary | | |

## Catalog update after a pass

When this report is accepted:

1. Set `evidence.manual_smoke` to `pass` (or keep `pending` if only docs-verified).
2. Set `evidence.stdio` / `evidence.oauth_http` to `certified` or `compatible` as proven.
3. Optionally set `evidence.certification_report` to a private tracker id or release note path (never a secret).
4. Refresh `verified_against_docs_on` and [verified-client-versions.md](../verified-client-versions.md).

## Failures

Record the first failing check, the recovery attempted (restart, profile,
`stonewright-client-surface-check` if available), and whether the failure is
client, network, plugin, or companion. Do not paste tokens or Authorization
headers into the report.
