# Stonewright OAuth and Client Connection Parity Design

**Date:** 2026-07-24  
**Target release:** `1.0.0-alpha.85`  
**Approved direction:** Port the complete inspected open-source reference
OAuth and AI-client connection experience into Stonewright, preserving upstream
copyright, SPDX notices, behavior, security checks, and attribution.

## Goal

Stonewright must offer the same browser-based OAuth connection flow, discovery
compatibility, client-specific setup instructions, consent experience, and
Connected Apps management as the inspected reference snapshot, while
retaining Stonewright's existing Application Password path and its permission,
domain-lock, mode, audit, and compact tool-surface contracts.

## Source and license

- Upstream identity and local inspected snapshot are recorded only in the
  dedicated technical reuse ledger.
- Reported version: `1.10.1`
- Upstream license: `AGPL-3.0-or-later`
- Stonewright plugin license: `AGPL-3.0-or-later`
- Port type: copied and adapted source, not behavioral reference only
- Required notices: upstream copyright and SPDX license headers.
- The MIT companion must not contain copied AGPL PHP or JavaScript.

## Non-goals

- Do not replace or remove WordPress Application Password authentication.
- Do not proxy OAuth through the Node companion.
- Do not install, activate, or test the release on Transavia without a new,
  explicit approval.
- Do not weaken Stonewright permission callbacks, domain lock, operating modes,
  confirmation tokens, backups, validation, or audit behavior.
- Do not claim source originality.

## Architecture

### Routes

The existing Application Password server remains:

```text
/wp-json/mcp/stonewright
```

The OAuth-protected server is separate:

```text
/wp-json/mcp/stonewright-oauth
```

The separate server prevents WordPress Basic/Application Password
authentication and bearer authentication from interfering with one another.
Both servers expose the same Stonewright ability registry and the same compact
surface selected in Setup.

OAuth endpoints mirror upstream:

```text
/.well-known/oauth-protected-resource
/.well-known/oauth-authorization-server
/.well-known/openid-configuration
/wp-json/stonewright/v1/oauth/register
/wp-json/stonewright/v1/oauth/token
/wp-json/stonewright/v1/oauth/revoke
/wp-json/stonewright/v1/oauth/introspect
/wp-admin/admin.php?page=stonewright-oauth-authorize
/wp-admin/admin.php?page=stonewright-oauth-consent
```

Discovery must support root and subdirectory installs using the upstream
append/insert path matrix.

### OAuth protocol

- OAuth 2.1 authorization-code flow.
- PKCE `S256` is mandatory for public clients.
- Dynamic Client Registration follows RFC 7591.
- Protected Resource Metadata follows RFC 9728.
- Authorization Server Metadata follows RFC 8414.
- Resource indicators and token audience binding follow RFC 8707.
- Public HTTP is rejected; HTTPS and WordPress local environments are allowed.
- Accepted scopes match upstream: `mcp`, `read`, and `write`; issued access
  always includes `mcp`.
- Add and advertise `offline_access` so ChatGPT can retain a refresh token.
  This is the one forward-compatible extension required by current ChatGPT.
- Access tokens expire after one hour.
- Refresh tokens expire after fourteen days and rotate on use, matching the
  inspected upstream implementation.
- Authorization codes and refresh tokens are single-use.
- Token, authorization-code, and secret identifiers are stored hashed.
- RSA keys are stored as non-autoload WordPress options.

### Storage

Port the four upstream tables with Stonewright names:

```text
{$wpdb->prefix}stonewright_oauth_clients
{$wpdb->prefix}stonewright_oauth_auth_codes
{$wpdb->prefix}stonewright_oauth_access_tokens
{$wpdb->prefix}stonewright_oauth_refresh_tokens
```

Schema installation uses `dbDelta()`. Version and RSA key options use
`autoload: false`. Daily garbage collection removes expired rows. Plugin
deactivation unschedules only the Stonewright OAuth cron event; it does not
delete grants or keys.

### Authentication and authorization

Bearer middleware runs only for the OAuth MCP route. It:

1. validates the token with `league/oauth2-server`;
2. verifies the token audience equals the canonical OAuth MCP resource;
3. requires the `mcp` scope;
4. resolves the WordPress user from the token subject;
5. rejects deleted, disabled, or unauthorized users;
6. sets the current WordPress user only for the current request;
7. passes the request through Stonewright's existing ability permission
   callbacks, domain lock, modes, confirmation tokens, backups, validation, and
   audit gates.

OAuth never grants a capability that the WordPress user does not currently
have. A role or capability change therefore takes effect immediately without
waiting for token expiry.

### DCR and redirect security

Port the upstream controls exactly:

- exact redirect URI matching;
- HTTPS redirects, with loopback HTTP exceptions;
- private, reserved, multicast, unspecified, and link-local destination
  rejection;
- IPv4, IPv6, IPv4-mapped IPv6, and DNS resolution checks;
- per-IP registration limits;
- per-endpoint token/revoke throttles;
- active-client cap;
- stale unused-client pruning;
- no wildcard redirect matching;
- no secrets in logs, notices, or query strings.

### Admin experience

Stonewright Setup keeps its current Step 1 enablement and compact tool-surface
controls. Step 2 becomes the upstream two-card authentication chooser:

- **OAuth — Recommended**
- **Application Password**

Step 3 ports the upstream client tabs and instructions:

- Claude Code
- Claude Desktop
- Claude.ai
- ChatGPT
- Codex
- Antigravity
- Cursor
- VS Code
- GitHub Copilot
- Windsurf
- Cline
- Gemini CLI
- Roo Code
- Amazon Q
- Zed
- Kilo Code
- OpenCode

The selected server name remains editable and defaults to a site-derived
`stonewright-<site>-<locale>` name. Native OAuth clients receive direct remote
HTTP instructions. Clients without verified native remote OAuth receive the
same `mcp-remote` bridge configuration as upstream. Cursor keeps its one-click
install link.

Application Password instructions remain available as the fallback method and
continue using Stonewright's versioned companion package and tool-profile
environment variable where appropriate.

### Connected Apps

Port the upstream hidden submenu page under Stonewright:

```text
Stonewright > Connected Apps
```

It lists client name, current connection state, created time, last-used time,
and expiry. Users may revoke their own grants. Administrators may manage all
grants. State-changing actions require a capability check and nonce. Revocation
cascades across authorization codes, access tokens, and refresh tokens.

### Dependencies

Add:

```json
"league/oauth2-server": "^8.5",
"nyholm/psr7": "^1.8"
```

Keep Stonewright's PHP `>=8.1` floor. Pin incompatible transitive major lines
using the upstream conflict constraints:

```json
"lcobucci/jwt": ">=5.0",
"lcobucci/clock": ">=3.0",
"league/uri": ">=6.8"
```

Composer lock and packaged vendor files must be updated and audited.

## Source mapping

| Upstream | Stonewright destination |
|---|---|
| `includes/oauth/bootstrap.php` | `plugin/includes/OAuth/Bootstrap.php` |
| `includes/oauth/bridge.php` | `plugin/includes/OAuth/Bridge.php` |
| `includes/oauth/client-validation.php` | `plugin/includes/OAuth/ClientValidation.php` |
| `includes/oauth/connected-apps.php` | `plugin/includes/OAuth/ConnectedApps.php` |
| `includes/oauth/consent.php` | `plugin/includes/OAuth/Consent.php` |
| `includes/oauth/keys.php` | `plugin/includes/OAuth/Keys.php` |
| `includes/oauth/middleware.php` | `plugin/includes/OAuth/Middleware.php` |
| `includes/oauth/schema.php` | `plugin/includes/OAuth/Schema.php` |
| `includes/oauth/server-factory.php` | `plugin/includes/OAuth/ServerFactory.php` |
| `includes/oauth/endpoints/*.php` | `plugin/includes/OAuth/Endpoints/*.php` |
| `includes/oauth/repositories/*.php` | `plugin/includes/OAuth/Repositories/*.php` |
| `includes/connect-methods.php` OAuth builders | `plugin/includes/Admin/OAuthClientConfig.php` |
| `includes/connect-page.php` OAuth chooser/client UI | `plugin/includes/Admin/OAuthConnectPanel.php` |
| `tests/oauth/*.php` | `plugin/tests/Unit/OAuth/*.php` |

The port may convert global functions to namespaced static methods where
Stonewright's autoloading and tests require it, but observable behavior,
validation branches, endpoint payloads, error codes, and UI instructions remain
equivalent.

## Verification

Automated verification must cover:

- all 15 upstream OAuth test families after namespace/product adaptation;
- discovery documents and exact path matrix;
- DCR validation and throttling;
- IPv4/IPv6 redirect rejection;
- authorization request repair and consent;
- audience binding;
- middleware route isolation;
- repository signatures and single-use revocation;
- Application Password route regression;
- Setup chooser and every client tab;
- Connected Apps nonce/capability/revocation;
- WordPress subdirectory installs;
- PHP 8.1 through the repository's supported matrix;
- Composer audit, PHPStan, PHPCS, provenance lint, docs freshness, package
  contents, and SHA256 verification.

Manual client smoke testing before release:

1. Codex native remote OAuth (`codex mcp add`, then `codex mcp login`);
2. Claude custom connector;
3. ChatGPT custom app with refresh-token renewal;
4. Cursor one-click and manual configuration;
5. VS Code/Copilot native remote configuration;
6. one `mcp-remote` bridge client;
7. existing Application Password connection.

## Release and deployment boundary

After every local gate passes:

1. push `codex/oauth-client-connect`;
2. open a PR that describes the OAuth and security changes;
3. resolve review and CI failures;
4. merge only when every required check is green;
5. tag `v1.0.0-alpha.85`;
6. verify the official plugin ZIP, companion TGZ, visual TGZ, archive contents,
   and `SHA256SUMS.txt`;
7. stop and ask for explicit permission before installing anything on
   Transavia.
