# Stonewright OAuth and Client Connection Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the complete reference OAuth and AI-client connection
experience into Stonewright and ship it as a verified `1.0.0-alpha.85`
release without deploying it to Transavia.

**Architecture:** Keep the current Application Password MCP route and add a
separate OAuth-protected MCP route. Port the AGPL upstream OAuth server,
repositories, discovery, security checks, consent, Connected Apps, and
client-onboarding UI into Stonewright namespaces while preserving Stonewright's
ability permissions and tool profiles.

**Tech Stack:** PHP 8.1, WordPress REST API/admin APIs/dbDelta,
`league/oauth2-server` 8.5, Nyholm PSR-7, PHPUnit 9, PHPStan, WPCS/PHPCS,
Playwright admin E2E, Composer.

## Global Constraints

- Source snapshot and hashes are recorded in the dedicated upstream reuse
  ledger.
- Preserve upstream Ovation S.r.l. copyright and AGPL SPDX headers.
- Record every copied/adapted source file in `docs/upstream-code-reuse.md`.
- Do not place AGPL-derived code in the MIT companion.
- Do not remove or break `/wp-json/mcp/stonewright` Application Password auth.
- OAuth MCP resource is `/wp-json/mcp/stonewright-oauth`.
- Every WordPress write keeps its real capability and nonce checks.
- No Transavia installation without a separate explicit approval.
- Every production method begins with a failing test and observed RED result.

---

### Task 1: Record source provenance and dependency contract

**Files:**
- Modify: `docs/upstream-code-reuse.md`
- Modify: `NOTICE`
- Modify: `plugin/composer.json`
- Modify: `plugin/composer.lock`
- Test: `plugin/tests/Unit/OAuth/DependencyContractTest.php`

**Interfaces:**
- Consumes: source hashes recorded in the dedicated upstream reuse ledger.
- Produces: installed OAuth/PSR-7 classes and an auditable import ledger.

- [ ] **Step 1: Write the failing dependency test**

```php
public function test_oauth_runtime_dependencies_are_installed(): void {
	self::assertTrue( class_exists( \League\OAuth2\Server\AuthorizationServer::class ) );
	self::assertTrue( class_exists( \Nyholm\Psr7\ServerRequest::class ) );
}
```

- [ ] **Step 2: Run RED**

Run:

```bash
cd plugin
vendor/bin/phpunit tests/Unit/OAuth/DependencyContractTest.php
```

Expected: failure because both runtime classes are absent.

- [ ] **Step 3: Add dependencies and conflicts**

Add `league/oauth2-server:^8.5`, `nyholm/psr7:^1.8`, and upstream-compatible
transitive conflicts to `plugin/composer.json`; run:

```bash
composer update league/oauth2-server nyholm/psr7 --with-all-dependencies
```

- [ ] **Step 4: Record the exact 1.10.1 snapshot and destination matrix**

Update the reuse ledger before adding copied files. Include the 21 OAuth
sources, connect-methods/connect-page portions, 15 test sources, modifications,
license, and security/test destinations.

- [ ] **Step 5: Run GREEN and audits**

```bash
composer test -- tests/Unit/OAuth/DependencyContractTest.php
composer dependencies:audit
composer provenance:lint
```

- [ ] **Step 6: Commit**

```bash
git add plugin/composer.json plugin/composer.lock \
  plugin/tests/Unit/OAuth/DependencyContractTest.php \
  docs/upstream-code-reuse.md NOTICE
git commit -m "build: add OAuth server dependencies"
```

### Task 2: Port canonical resource, transport, schema, and keys

**Files:**
- Create: `plugin/includes/OAuth/Bootstrap.php`
- Create: `plugin/includes/OAuth/Transport.php`
- Create: `plugin/includes/OAuth/Schema.php`
- Create: `plugin/includes/OAuth/Keys.php`
- Test: `plugin/tests/Unit/OAuth/TransportSecurityTest.php`
- Test: `plugin/tests/Unit/OAuth/AudienceBindingTest.php`
- Test: `plugin/tests/Unit/OAuth/SchemaTest.php`

**Interfaces:**
- Produces:
  `Bootstrap::resource_identifier(): string`,
  `Bootstrap::resource_request_allowed(string,string): bool`,
  `Bootstrap::supported_scopes(): array`,
  `Transport::allowed(): bool`,
  `Schema::maybe_install(): void`,
  `Keys::get(): array{private:CryptKey,public:CryptKey,encryption:string}`.

- [ ] **Step 1: Port and adapt upstream transport/audience/schema tests only**

Tests assert:

```php
self::assertSame(
	'https://example.org/wp-json/mcp/stonewright-oauth',
	Bootstrap::resource_identifier()
);
self::assertFalse(
	Bootstrap::resource_request_allowed(
		'https://evil.example/mcp',
		'https://example.org/wp-json/mcp/stonewright-oauth'
	)
);
self::assertSame( [ 'mcp', 'read', 'write', 'offline_access' ], Bootstrap::supported_scopes() );
```

- [ ] **Step 2: Run RED**

```bash
cd plugin
vendor/bin/phpunit tests/Unit/OAuth/TransportSecurityTest.php \
  tests/Unit/OAuth/AudienceBindingTest.php \
  tests/Unit/OAuth/SchemaTest.php
```

Expected: classes do not exist.

- [ ] **Step 3: Port minimal production sources**

Copy upstream logic into Stonewright namespaces; rename constants/options,
tables, cron hook, route, text domain, and product identifiers. Preserve SPDX
headers and branch behavior.

- [ ] **Step 4: Run GREEN, PHPCS, and PHPStan**

```bash
composer test -- tests/Unit/OAuth
composer phpcs -- includes/OAuth tests/Unit/OAuth
composer phpstan
```

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/OAuth plugin/tests/Unit/OAuth
git commit -m "feat: add OAuth resource and storage foundation"
```

### Task 3: Port DCR redirect and rate-limit security

**Files:**
- Create: `plugin/includes/OAuth/ClientValidation.php`
- Test: `plugin/tests/Unit/OAuth/ClientValidationTest.php`
- Test: `plugin/tests/Unit/OAuth/ClientValidationIpv6Test.php`
- Test: `plugin/tests/Unit/OAuth/EndpointRateLimitTest.php`
- Test: `plugin/tests/Unit/OAuth/SecurityEdgeTest.php`

**Interfaces:**
- Produces:
  `ClientValidation::is_allowed_redirect_uri(string,bool): bool`,
  `ClientValidation::within_endpoint_rate_limit(string,string): bool`,
  `ClientValidation::active_client_count(): int`,
  `ClientValidation::prune_dead_clients(): void`.

- [ ] **Step 1: Port the four upstream test families**

Keep all IPv4, IPv6, DNS, loopback, private-range, malformed-URI, wildcard,
limit, and stale-client cases. Rename only namespace/options/tables.

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit \
  tests/Unit/OAuth/ClientValidationTest.php \
  tests/Unit/OAuth/ClientValidationIpv6Test.php \
  tests/Unit/OAuth/EndpointRateLimitTest.php \
  tests/Unit/OAuth/SecurityEdgeTest.php
```

Expected: validation class is absent.

- [ ] **Step 3: Port upstream validation implementation**

Preserve exact allow/deny behavior. Use `$wpdb->prepare()` for all variable SQL,
hashed source IPs, transient rate buckets, and bounded DNS resolution.

- [ ] **Step 4: Run GREEN and security grep**

```bash
vendor/bin/phpunit tests/Unit/OAuth
rg -n '\\$wpdb->(query|get_var|get_row|get_results)\\(' includes/OAuth
composer phpcs -- includes/OAuth tests/Unit/OAuth
```

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/OAuth/ClientValidation.php plugin/tests/Unit/OAuth
git commit -m "feat: secure OAuth client registration"
```

### Task 4: Port OAuth entities, repositories, and server factory

**Files:**
- Create: `plugin/includes/OAuth/Bridge.php`
- Create: `plugin/includes/OAuth/ServerFactory.php`
- Create: `plugin/includes/OAuth/Repositories/AccessTokenRepository.php`
- Create: `plugin/includes/OAuth/Repositories/AuthCodeRepository.php`
- Create: `plugin/includes/OAuth/Repositories/ClientRepository.php`
- Create: `plugin/includes/OAuth/Repositories/RefreshTokenRepository.php`
- Create: `plugin/includes/OAuth/Repositories/ScopeRepository.php`
- Create: `plugin/includes/OAuth/Repositories/UserRepository.php`
- Test: `plugin/tests/Unit/OAuth/RepositorySignatureTest.php`
- Test: `plugin/tests/Unit/OAuth/ScopeRepositoryTest.php`
- Test: `plugin/tests/Integration/OAuth/TokenRotationTest.php`

**Interfaces:**
- Produces League repository interfaces and
  `ServerFactory::authorization_server(): AuthorizationServer`,
  `ServerFactory::resource_server(): ResourceServer`.

- [ ] **Step 1: Port repository and scope tests**

Add a real integration test asserting an authorization code can be redeemed
once and a refresh token rotates once; second reuse returns `invalid_grant`.

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Unit/OAuth/RepositorySignatureTest.php \
  tests/Unit/OAuth/ScopeRepositoryTest.php \
  tests/Integration/OAuth/TokenRotationTest.php
```

- [ ] **Step 3: Port repositories, entities, bridge, and factory**

Rename namespaces/table prefixes/options. Preserve hashing, expiration,
revocation, exact redirect URI, and user identifiers. Extend scope finalization
to preserve `offline_access` when requested.

- [ ] **Step 4: Run GREEN and full OAuth suite**

```bash
vendor/bin/phpunit tests/Unit/OAuth tests/Integration/OAuth
```

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/OAuth plugin/tests/Unit/OAuth plugin/tests/Integration/OAuth
git commit -m "feat: add OAuth token repositories"
```

### Task 5: Port discovery and OAuth endpoints

**Files:**
- Create: `plugin/includes/OAuth/Endpoints/Discovery.php`
- Create: `plugin/includes/OAuth/Endpoints/Register.php`
- Create: `plugin/includes/OAuth/Endpoints/Authorize.php`
- Create: `plugin/includes/OAuth/Endpoints/Token.php`
- Create: `plugin/includes/OAuth/Endpoints/Revoke.php`
- Create: `plugin/includes/OAuth/Endpoints/Introspect.php`
- Test: `plugin/tests/Unit/OAuth/DiscoveryPathsTest.php`
- Test: `plugin/tests/Unit/OAuth/AuthorizeRepairTest.php`
- Test: `plugin/tests/Integration/OAuth/EndpointsTest.php`

**Interfaces:**
- Produces exact discovery payloads and REST/admin handlers.

- [ ] **Step 1: Port discovery/authorize tests and add endpoint tests**

Assert the registration endpoint rejects unsafe redirect URIs, token endpoint
rejects wrong resource, revoke is idempotent, introspection requires
`manage_options`, and metadata advertises `offline_access`.

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Unit/OAuth/DiscoveryPathsTest.php \
  tests/Unit/OAuth/AuthorizeRepairTest.php \
  tests/Integration/OAuth/EndpointsTest.php
```

- [ ] **Step 3: Port all six upstream endpoint sources**

Keep endpoint payloads, HTTP status codes, OAuth error codes, cache headers,
request repair, and WordPress login redirect behavior equivalent.

- [ ] **Step 4: Run GREEN**

```bash
vendor/bin/phpunit tests/Unit/OAuth tests/Integration/OAuth
composer phpcs -- includes/OAuth tests/Unit/OAuth tests/Integration/OAuth
```

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/OAuth/Endpoints plugin/tests
git commit -m "feat: add OAuth discovery and endpoints"
```

### Task 6: Port bearer middleware and separate MCP server

**Files:**
- Create: `plugin/includes/OAuth/Middleware.php`
- Modify: `plugin/includes/Core/ServerRegistration.php`
- Modify: `plugin/includes/Core/PluginRegistration.php`
- Test: `plugin/tests/Unit/OAuth/MiddlewareTest.php`
- Test: `plugin/tests/Integration/OAuth/McpServerTest.php`

**Interfaces:**
- Produces:
  `Middleware::authenticate(mixed): mixed`,
  `Middleware::challenge_unauthenticated(...)`,
  a registered `stonewright-oauth` MCP server.

- [ ] **Step 1: Port middleware tests and add route-isolation test**

Assert bearer auth applies only to `/mcp/stonewright-oauth`, Basic auth remains
valid on `/mcp/stonewright`, audience/scope/user failures return 401, and
successful bearer auth exposes the current WordPress user.

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Unit/OAuth/MiddlewareTest.php \
  tests/Integration/OAuth/McpServerTest.php
```

- [ ] **Step 3: Port middleware and register the server**

Use the same ability list/profile source as the existing server. Do not bypass
any ability permission callback.

- [ ] **Step 4: Run GREEN plus Application Password regressions**

```bash
vendor/bin/phpunit tests/Unit/OAuth tests/Integration/OAuth \
  tests/Unit/ConnectClientConfigTest.php \
  tests/Unit/Admin/McpLoopbackSelfTestTest.php
```

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/OAuth/Middleware.php \
  plugin/includes/Core/ServerRegistration.php \
  plugin/includes/Core/PluginRegistration.php plugin/tests
git commit -m "feat: expose OAuth protected MCP server"
```

### Task 7: Port consent and Connected Apps

**Files:**
- Create: `plugin/includes/OAuth/Consent.php`
- Create: `plugin/includes/OAuth/ConnectedApps.php`
- Test: `plugin/tests/Unit/OAuth/ConsentTest.php`
- Test: `plugin/tests/Unit/OAuth/ConnectedAppsTest.php`
- Modify: `plugin/assets/admin/setup.css`

**Interfaces:**
- Produces hidden consent/authorize pages and Connected Apps submenu.

- [ ] **Step 1: Write render/action tests**

Assert output escaping, login redirect, nonce failure, capability failure,
approve/deny behavior, own-grant visibility, administrator visibility, and
cascading revocation.

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Unit/OAuth/ConsentTest.php \
  tests/Unit/OAuth/ConnectedAppsTest.php
```

- [ ] **Step 3: Port upstream UI/actions**

Rename page slugs, text domain, classes/IDs, nonce actions, URLs, and database
prefixes. Preserve all security branches and upstream attribution.

- [ ] **Step 4: Run GREEN and XSS/auth checks**

```bash
vendor/bin/phpunit tests/Unit/OAuth
rg -n '\\$_(GET|POST|REQUEST)' includes/OAuth
rg -n 'echo\\s+\\$' includes/OAuth
composer phpcs -- includes/OAuth tests/Unit/OAuth
```

- [ ] **Step 5: Commit**

```bash
git add plugin/includes/OAuth plugin/assets/admin/setup.css plugin/tests/Unit/OAuth
git commit -m "feat: add OAuth consent and connected apps"
```

### Task 8: Port exact client onboarding and authentication chooser

**Files:**
- Create: `plugin/includes/Admin/OAuthClientConfig.php`
- Create: `plugin/includes/Admin/OAuthConnectPanel.php`
- Modify: `plugin/includes/Admin/ConfigurationPage.php`
- Modify: `plugin/includes/Admin/ClientCatalog.php`
- Modify: `plugin/data/clients/*.json`
- Modify: `plugin/assets/admin/setup.css`
- Modify: `plugin/assets/admin/admin.js`
- Test: `plugin/tests/Unit/OAuth/ConnectorLinkTest.php`
- Test: `plugin/tests/Unit/Admin/OAuthClientConfigTest.php`
- Test: `plugin/tests/Unit/Admin/ConfigurationPageTest.php`
- Test: `e2e/tests/admin-ui.spec.ts`

**Interfaces:**
- Produces OAuth config data for all 17 upstream tabs and renders the exact
two-method chooser and per-client instructions.

- [ ] **Step 1: Port upstream config-builder tests**

Assert exact server URL/name, Codex command/TOML, Claude connector steps,
ChatGPT steps, Cursor install URL, VS Code shape, and `mcp-remote` bridge JSON.

- [ ] **Step 2: Run RED**

```bash
cd plugin
vendor/bin/phpunit tests/Unit/OAuth/ConnectorLinkTest.php \
  tests/Unit/Admin/OAuthClientConfigTest.php \
  tests/Unit/Admin/ConfigurationPageTest.php
```

- [ ] **Step 3: Port upstream OAuth config builders**

Use Stonewright product/server identifiers and endpoint. Retain upstream client
list/order, config wrappers, notes, paths, direct/bridge split, server-name
editing, and copy actions.

- [ ] **Step 4: Port the chooser and client panel**

Embed the upstream Step 2/3 experience into the existing Stonewright Setup page.
Keep Application Password fallback, compact surface selection, diagnostics, and
existing password management.

- [ ] **Step 5: Run unit and browser GREEN**

```bash
cd plugin
vendor/bin/phpunit tests/Unit/Admin tests/Unit/OAuth
cd ../e2e
npm test -- admin-ui.spec.ts
```

Expected: OAuth card selected by default, all 17 tabs render, client
instructions switch without console errors, fallback password path still works.

- [ ] **Step 6: Commit**

```bash
git add plugin/includes/Admin plugin/data/clients plugin/assets/admin \
  plugin/tests e2e/tests/admin-ui.spec.ts
git commit -m "feat: add OAuth client connection center"
```

### Task 9: Lifecycle, diagnostics, and documentation

**Files:**
- Modify: `plugin/includes/Core/PluginRegistration.php`
- Modify: `plugin/includes/Admin/SetupDiagnostics.php`
- Modify: `README.md`
- Modify: `plugin/README.md`
- Modify: `docs/admin/connect-clients.md`
- Modify: `docs/admin/configuration.md`
- Modify: `docs/installation.md`
- Modify: `docs/install-prompts.md`
- Modify: `docs/licensing.md`
- Modify: `CHANGELOG.md`
- Modify: `plugin/CHANGELOG.md`
- Modify: `docs/upstream-code-reuse.md`
- Test: `plugin/tests/Unit/OAuth/SupportReportTest.php`

**Interfaces:**
- Produces accurate activation/deactivation, diagnostics, troubleshooting, and
public setup documentation.

- [ ] **Step 1: Port support-report tests and add lifecycle tests**

Assert schema/key/discovery/transport/client-cap/rate-limit diagnostics and cron
schedule/unschedule behavior.

- [ ] **Step 2: Run RED**

```bash
vendor/bin/phpunit tests/Unit/OAuth/SupportReportTest.php
```

- [ ] **Step 3: Implement lifecycle and diagnostics**

Activate installs schema/keys and schedules GC. Deactivation unschedules GC.
Diagnostics report actionable failures without printing secrets.

- [ ] **Step 4: Update every affected public document**

Document OAuth as recommended, Application Password as fallback, exact current
steps for each major client, remote/public requirements, Connected Apps,
revocation, subdirectory discovery, HTTPS, and Transavia deployment boundary.

- [ ] **Step 5: Run docs/provenance checks**

```bash
composer docs:matrix
composer provenance:lint
node ../scripts/check-docs-freshness.mjs
git diff --check
```

- [ ] **Step 6: Commit**

```bash
git add plugin/includes plugin/tests README.md plugin/README.md docs \
  CHANGELOG.md plugin/CHANGELOG.md
git commit -m "docs: document OAuth client connections"
```

### Task 10: Version, full verification, PR, and release

**Files:**
- Modify: `plugin/stonewright.php`
- Modify: `plugin/composer.json`
- Modify: `companion/package.json`
- Modify: exact versioned changelog/release-note files required by repo checks.

**Interfaces:**
- Produces official `v1.0.0-alpha.85` artifacts.

- [ ] **Step 1: Set version to `1.0.0-alpha.85`**

Keep visual version unchanged unless visual code changed.

- [ ] **Step 2: Run the complete local gate**

```bash
cd plugin
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit
composer provenance:lint
composer tokens:measure
composer contracts:compat
composer docs:matrix

cd ../companion
npm run typecheck
npm run lint
npm test
npm run build

cd ../visual
npm run typecheck
npm test
npm run build

cd ..
node scripts/check-docs-freshness.mjs
git diff --check
```

- [ ] **Step 3: Run security review**

Review SQL injection, XSS, input validation, authz/nonces, secrets, redirect
SSRF, token audience/scope, replay, refresh rotation, and information
disclosure. Fix every finding with a failing regression test.

- [ ] **Step 4: Build and inspect local artifacts**

Run the repository release packaging path. Verify no tests/dev binaries or
secrets are in archives and all OAuth runtime dependencies are present.

- [ ] **Step 5: Push and open PR**

PR body must list affected routes, tables, permissions,
backups/tokens/validation/audit impact, tests, docs, and no-live-deployment
statement. Source provenance remains only in the dedicated technical ledger
and required file headers.

- [ ] **Step 6: Wait for every check, fix failures, merge**

Do not merge with pending, skipped-required, or failing checks.

- [ ] **Step 7: Tag and verify official release**

Create `v1.0.0-alpha.85`, wait for the release workflow, download all official
assets, run `shasum -a 256 -c SHA256SUMS.txt`, and inspect archive contents and
embedded versions.

- [ ] **Step 8: Stop before Transavia**

Report the verified release and ask for explicit approval to install and test it
on Transavia.
