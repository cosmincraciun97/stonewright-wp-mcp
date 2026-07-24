# Upstream Code Reuse

Stonewright may inspect, copy, adapt, and port third-party source when the
upstream license permits it. Copied or derived code must keep attribution,
carry the correct license, and pass Stonewright's own security and test gates.

## License gate

Stonewright ships the WordPress plugin as `AGPL-3.0-or-later` and the
Node companion as `MIT`. The inspected Novamira 1.10.1 snapshot is primarily
`AGPL-3.0-or-later`. Its `novamira-visual/composer.json` declares the visual
subpackage as `MIT`, while PHP integration files inside that subpackage carry
AGPL SPDX headers.

The Stonewright plugin was relicensed before the first Novamira import. The
companion remains MIT and must not receive AGPL-derived code. Stonewright
Visual is distributed as a separate AGPL-3.0-or-later package.

## Inspected upstream snapshot

- Source: `use-novamira/novamira`
- Local snapshot: `/Users/cosminiviteb/Downloads/novamira-main`
- Reported version: `1.10.1`
- Top-level license: `AGPL-3.0-or-later`
- Snapshot date: `2026-07-24`

The inspected open-source snapshot contains WordPress abilities, skill
discovery, OAuth onboarding, a Gutenberg finalization queue, and the Novamira
Visual workspace. It does not contain the commercial Elementor or Bricks tool
implementations; only integration hooks and guidance for those tools are
present.

### OAuth parity import source set

The `1.10.1` OAuth parity work imports all 21 PHP files under
`includes/oauth/`, the OAuth configuration builders from
`includes/connect-methods.php`, the OAuth chooser/client panel from
`includes/connect-page.php`, and the 15 test families under `tests/oauth/`.
Machine-readable ledger rows are added with each destination file so
`composer provenance:lint` verifies the copied source header and source hash.

- `novamira.php`: `bab84cac0eb3fa5101e506a7b19aa16818a2b953430180e6ba26dda3fae3bbf7`
- `includes/connect-methods.php`: `f3c46c9cfaf027a864c1671063d10b0b0c945c0458a9fa952bb86c668feb55db`
- `includes/connect-page.php`: `cad3348707d451e431c1b0aa964ea53d31c502bd8b86b5ca6fb9f15a3bf217d0`
- sorted `includes/oauth/**` manifest:
  `fce687f4b99d6f9b6dc87d494fd024338cc857ee913d878880fe7d7d342490be`
- sorted `tests/oauth/**` manifest:
  `9ca2d7d1a7983907e963db86ec356024348f2ae47d7e9ff4b8e781d9850aa887`

## Reuse ledger

Add one row before importing each component.

| Upstream source | Source hash/version | Stonewright destination | Reuse type | License | Modifications | Tests/security review |
|---|---|---|---|---|---|---|
| `novamira-visual/src/expose-tools.ts` | `b4423c9f610af5faf7898dd36ed42e8f81b04e6a0b23c5c7121d168bc58d6f1a` | `visual/src/page-tool-registry.ts` | Adapted port | AGPL-3.0-or-later | Renamed globals and product identifiers; extracted a headless registry; kept aliases, refs, compact summaries, and batch rollback; added 50-call cap, transactions, and mandatory readback | `visual/tests/visual-foundation.test.ts`; typecheck/build; rollback/readback/ref tests |
| `novamira-visual/src/workspace-tool-schema-summary.ts` | `e879f2f80db51a59af3c288334cabcb6c74feb2c08873b209704c3432b10c549` | `visual/src/tool-schema-summary.ts` | Adapted port | AGPL-3.0-or-later | Preserved depth-limited schema summaries; removed undefined fields and kept defaults out of compact output | Compact-schema and token-size test |
| `novamira-visual/src/workspace-dispatcher.ts` | `0d7818ef8d76347b32770be6aba2ce9fce1dee0528b309d9f9bf1fd749af4253` | `visual/src/workspace-dispatcher.ts` | Adapted port | AGPL-3.0-or-later | Replaced browser-specific page operations with an injected host; removed JavaScript eval; allowlisted one workspace gateway and nested page/backend calls | Dispatcher allowlist, nested-call, and unknown-method tests |
| `novamira-visual/src/workspace-confirmations.ts` | `86c20504f8f5e4b255183e3d6ea876ff3f92953f9d55b0fcfd3cad1b1165d5fd` | `visual/src/workspace-confirmations.ts` | Adapted port | AGPL-3.0-or-later | Replaced DOM rendering with a headless pending-action state machine; retained once/session/all/deny decisions and bounded waits | Backend-write confirmation and approved-action tests |
| `novamira-visual/src/workspace-backend-tools.ts` | `50afbc989d9372f3f39857f9a7f5d31a41bbfd3e1d6457ccb4778cfc24eb533b` | `visual/src/workspace-backend-tools.ts` | Adapted port | AGPL-3.0-or-later | Replaced Novamira AJAX with an injected Stonewright transport; requires Visual-safe discovery, hides dangerous tools by default, and confirms writes/elevated calls | Safe discovery and confirmation tests |
| `novamira-visual/src/workspace-agent-guidance.ts` | `0ec7be4b5ded0cc29643be0650d76c944e18193468fc6a2a1fcd2332bed33c26` | `visual/src/workspace-agent-guidance.ts` | Adapted port | AGPL-3.0-or-later | Compressed builder guidance; made native-first, schema-first, CTA validation, readback, and rollback explicit; removed Novamira UI copy | Typecheck/build; exported guidance contract |
| `novamira-visual/src/skills/loader.ts` | `89f94dfaaf7995f2ff1fb0a5e50dcda7f418327a223a80d67877fc9f9cdbf96a` | `visual/src/skills/skill-registry.ts` | Adapted port | AGPL-3.0-or-later | Replaced Vite glob imports with an injected, host-agnostic bundle; expanded editor scoping to Elementor V3/V4; rejects malformed metadata and duplicate names | `visual/tests/skills.test.ts`; typecheck/build; progressive-disclosure and compatibility tests |
| `novamira-visual/src/tools/use-skill.ts` | `4ba576a344ceef78316c9a87910f7e21823c6ab37bea880ae545588156126079` | `visual/src/skills/use-skill-tool.ts` | Adapted port | AGPL-3.0-or-later | Adapted to Stonewright nested tool types; kept the tool off the top-level MCP surface; added exact-path validation and traversal rejection | `visual/tests/skills.test.ts`; typecheck/build; body/reference/path tests |
| `includes/oauth/bootstrap.php` | `4415540ea0aeebca8c65c4086924802dc936af8efca71c477101bef8b9ac2bb3` | `plugin/includes/OAuth/Bootstrap.php` | Adapted port | AGPL-3.0-or-later | Converted namespace functions to a static Stonewright bootstrap contract; renamed the MCP resource and added `offline_access` for current ChatGPT refresh-token compatibility | `plugin/tests/Unit/OAuth/AudienceBindingTest.php`; PHPUnit, PHPStan, PHPCS |
| `includes/connect-methods.php` | `f3c46c9cfaf027a864c1671063d10b0b0c945c0458a9fa952bb86c668feb55db` | `plugin/includes/OAuth/Transport.php` | Adapted port | AGPL-3.0-or-later | Extracted the OAuth HTTPS/local-environment transport gate into a namespaced Stonewright class | `plugin/tests/Unit/OAuth/TransportSecurityTest.php`; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/schema.php` | `6269e68bcc64e68c41f4bf5973df8fd6f14c3ae24e2b028636fb77c1f582c8d8` | `plugin/includes/OAuth/Schema.php` | Adapted port | AGPL-3.0-or-later | Renamed tables, options, and cron hook; converted functions to an autoloadable class; retained four-table schema and expiry GC | `plugin/tests/Unit/OAuth/SchemaTest.php`; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/keys.php` | `40e5b817d895463f19cff98380d773dc7bb5f4ddfd275f2dded62b5d3890dcf7` | `plugin/includes/OAuth/Keys.php` | Adapted port | AGPL-3.0-or-later | Renamed non-autoload options and converted functions to a class; retained atomic add-option persistence and public-key derivation | `plugin/tests/Unit/OAuth/KeysTest.php`; PHPUnit, PHPStan, PHPCS |
| `tests/oauth/AudienceBindingTest.php` | `1d46ab879da5564dce85bfbe48175337620e2a5ce6715dac8237d005765c696b` | `plugin/tests/Unit/OAuth/AudienceBindingTest.php` | Adapted test port | AGPL-3.0-or-later | Adapted namespace, product route, PHPUnit style, and expected `offline_access` scope | Verifies resource matching and supported scope contract |
| `tests/oauth/TransportSecurityTest.php` | `8cf95ba712ab021734aa858c80cd9c05aa718ab77636507eb3bea3f7377a6ad3` | `plugin/tests/Unit/OAuth/TransportSecurityTest.php` | Adapted test port | AGPL-3.0-or-later | Adapted namespace and PHPUnit data-provider syntax; preserved all HTTPS/local cases | Verifies transport gate across public/local host shapes |
| `includes/oauth/client-validation.php` | `aa9d865d5a8d7fa41f73f6740523c65a248294653ad8d99530f82759ae578523` | `plugin/includes/OAuth/ClientValidation.php` | Adapted port | AGPL-3.0-or-later | Converted functions/constants to a Stonewright class; renamed filters, transients, and tables; preserved redirect, DNS/IP, DCR, endpoint throttle, cap, and pruning behavior | Three adapted upstream test families; PHPUnit, PHPStan, PHPCS, SQL review |
| `tests/oauth/ClientValidationTest.php` | `0de3b6f279d4f187d9cc18ed4b5fbec846d23bc1ec832fc9d72080787da98251` | `plugin/tests/Unit/OAuth/ClientValidationTest.php` | Adapted test port | AGPL-3.0-or-later | Adapted namespace and grouped equivalent assertions under PHPUnit 9 | Redirect scheme, fragment, private IPv4, and dev-loopback coverage |
| `tests/oauth/ClientValidationIpv6Test.php` | `34f4429fcc2df3387d15d36f8de58395992df6e7d656874b93440a035c547401` | `plugin/tests/Unit/OAuth/ClientValidationIpv6Test.php` | Adapted test port | AGPL-3.0-or-later | Adapted namespace and grouped equivalent assertions under PHPUnit 9 | IPv6 loopback, private, link-local, mapped, public, and HTTP coverage |
| `tests/oauth/EndpointRateLimitTest.php` | `0b39f0fd679dc1cdec7651d4144500692a33b0849cca137993e4fdb1b56ec15e` | `plugin/tests/Unit/OAuth/EndpointRateLimitTest.php` | Adapted test port | AGPL-3.0-or-later | Adapted namespace and Stonewright transient fixtures | Token/revoke bucket, per-IP, cap, and empty-IP coverage |
| `includes/oauth/repositories/client-repository.php` | `db363947de4e5d1a9b2ef98b3ada525a4c5513e5d976c9a820296f79f2cc2796` | `plugin/includes/OAuth/Repositories/ClientRepository.php` | Adapted port | AGPL-3.0-or-later | Renamed tables and namespace; retained DCR persistence, League entity shape, validation, touch, and revoke behavior | Repository signature subprocess test; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/repositories/access-token-repository.php` | `6deada7078fb258c7c06c8ba7d39ffc64df2cd258579dace46f764af6046bbf9` | `plugin/includes/OAuth/Repositories/AccessTokenRepository.php` | Adapted port | AGPL-3.0-or-later | Renamed tables and resource; retained RFC 8707 JWT audience, hashed identifiers, expiry, owner checks, and grant cascade revocation | Repository signature and audience tests; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/repositories/auth-code-repository.php` | `54d80fc3a5c262f916a7e81e1c439d4b5d9734fb2e97da7bf8ccca2042902894` | `plugin/includes/OAuth/Repositories/AuthCodeRepository.php` | Adapted port | AGPL-3.0-or-later | Renamed table/namespace; retained hashed persistence, expiry checks, and atomic single-use claim | Repository signature test; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/repositories/refresh-token-repository.php` | `a7d5db0cf30a2a682643c6514a90c5db1b659f256fb6e7c4b2627827e03b31b8` | `plugin/includes/OAuth/Repositories/RefreshTokenRepository.php` | Adapted port | AGPL-3.0-or-later | Renamed table/namespace; retained hashed pairing, expiry, atomic rotation, and paired access-token lookup | Repository signature test; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/repositories/scope-repository.php` | `df5d2af8a667f7d3d81fa4274be2b59e171be9954d3746aa98a321ebf4eed446` | `plugin/includes/OAuth/Repositories/ScopeRepository.php` | Adapted port | AGPL-3.0-or-later | Converted scope source to the Stonewright bootstrap contract; retained `mcp` normalization and added `offline_access` preservation | Adapted scope repository tests; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/repositories/user-repository.php` | `c38a70f966717115088ad50a39b3bd6f1b966f1147f91cfa46eae3f9a793d777` | `plugin/includes/OAuth/Repositories/UserRepository.php` | Adapted port | AGPL-3.0-or-later | Renamed namespace; retained disabled password-grant repository contract | Repository signature subprocess test |
| `includes/oauth/bridge.php` | `0f73fa3bb6d05d0384e8ae524a8c1e775ee38b1305c803702cafa4c39f7496a0` | `plugin/includes/OAuth/Bridge.php` | Adapted port | AGPL-3.0-or-later | Converted functions to an autoloaded Stonewright class; retained WordPress REST, PSR-7, body, cookie, header, and global request conversions | `plugin/tests/Unit/OAuth/BridgeTest.php`; PHPUnit, PHPStan, PHPCS |
| `includes/oauth/server-factory.php` | `b380ba448da4736c501849ae1997679cafda5dc863ea7a9ea511b4e9a561a44e` | `plugin/includes/OAuth/ServerFactory.php` | Adapted port | AGPL-3.0-or-later | Converted functions to a Stonewright class; retained PKCE auth-code and refresh grants, one-hour access tokens, and fourteen-day rotating refresh tokens | `plugin/tests/Unit/OAuth/ServerFactoryTest.php`; PHPUnit, PHPStan, PHPCS |
| `tests/oauth/RepositorySignatureTest.php` | `6487f1a29832a820082eecc493a3d7b4df3453660697f558ac8085afa7ee57b0` | `plugin/tests/Unit/OAuth/RepositorySignatureTest.php` | Adapted test port | AGPL-3.0-or-later | Adapted subprocess loader to Composer PSR-4 Stonewright classes | Guards League 8.5 interface compatibility without crashing the main runner |
| `tests/oauth/ScopeRepositoryTest.php` | `28ca06d55e0d92f597e5e2a7ebc009bda166dc212ed9c82d78b9c69347587176` | `plugin/tests/Unit/OAuth/ScopeRepositoryTest.php` | Adapted test port | AGPL-3.0-or-later | Adapted namespace/PHPUnit style and added current `offline_access` expectation | Supported-scope and finalization coverage |

## Snapshot fingerprints

These hashes identify the files inspected for the implementation plan. Recheck
them immediately before porting; a changed hash means the source snapshot has
changed and requires a new review.

| Source path | SHA-256 |
|---|---|
| `novamira.php` | `bab84cac0eb3fa5101e506a7b19aa16818a2b953430180e6ba26dda3fae3bbf7` |
| `novamira-visual/src/expose-tools.ts` | `b4423c9f610af5faf7898dd36ed42e8f81b04e6a0b23c5c7121d168bc58d6f1a` |
| `novamira-visual/src/workspace-tool-schema-summary.ts` | `e879f2f80db51a59af3c288334cabcb6c74feb2c08873b209704c3432b10c549` |
| `novamira-visual/src/workspace-dispatcher.ts` | `0d7818ef8d76347b32770be6aba2ce9fce1dee0528b309d9f9bf1fd749af4253` |
| `novamira-visual/src/workspace-confirmations.ts` | `86c20504f8f5e4b255183e3d6ea876ff3f92953f9d55b0fcfd3cad1b1165d5fd` |
| `novamira-visual/src/workspace-backend-tools.ts` | `50afbc989d9372f3f39857f9a7f5d31a41bbfd3e1d6457ccb4778cfc24eb533b` |
| `novamira-visual/src/workspace-agent-guidance.ts` | `0ec7be4b5ded0cc29643be0650d76c944e18193468fc6a2a1fcd2332bed33c26` |
| `novamira-visual/src/skills/loader.ts` | `89f94dfaaf7995f2ff1fb0a5e50dcda7f418327a223a80d67877fc9f9cdbf96a` |
| `novamira-visual/src/tools/use-skill.ts` | `4ba576a344ceef78316c9a87910f7e21823c6ab37bea880ae545588156126079` |
| `includes/skills/catalog.php` | `1d437e36d25a016200315d21c6c123864eb7570b08e1d3efe75692dab48116ee` |
| `includes/skills/parser.php` | `ed7707d7004b854df2f99ed5b9b0a5181681c9b8de5be5f5b77cf1dfff8ab6a7` |
| `includes/skills/sources.php` | `409ef84ad24edeca243d847346f1073660d3b42a789241c96c5a140720bb3d79` |
| `includes/connect-methods.php` | `f3c46c9cfaf027a864c1671063d10b0b0c945c0458a9fa952bb86c668feb55db` |
| `includes/connect-page.php` | `cad3348707d451e431c1b0aa964ea53d31c502bd8b86b5ca6fb9f15a3bf217d0` |
| `includes/oauth/client-validation.php` | `aa9d865d5a8d7fa41f73f6740523c65a248294653ad8d99530f82759ae578523` |
| sorted `includes/oauth/**` hash manifest | `fce687f4b99d6f9b6dc87d494fd024338cc857ee913d878880fe7d7d342490be` |
| sorted `tests/oauth/**` hash manifest | `9ca2d7d1a7983907e963db86ec356024348f2ae47d7e9ff4b8e781d9850aa887` |
| sorted `novamira-visual/src/tools/gutenberg/**` hash manifest | `2471aab10ca51c7365642971087abdf2e8ef997342ef7de37116e93fb306e94f` |

## Required review per import

- Verify the exact source file license instead of relying only on directory
  names.
- Preserve copyright and SPDX headers.
- Replace namespaces, capability checks, nonces, storage keys, URLs, and UI
  integration with Stonewright equivalents without weakening controls.
- Route writes through Stonewright permissions, backups, confirmation tokens,
  validators, and audit logs.
- Add unit, integration, failure, rollback, and packaging tests.
- Compare tool count and token cost before and after the import.
- Record deviations from upstream and the reason for each one.

## Behavioral reference — core REST parity (Wave 3)

### MIT WordPress MCP server (behavioral reference only)

- Source repository: https://github.com/deus-h/claudeus-wp-mcp
- Local snapshot: /Users/cosminiviteb/Downloads/claudeus-wp-mcp-master
- Version: 3.0.2
- License: MIT (LICENSE additionally grants unrestricted rename/reuse)
- Reuse type: Behavioral evidence only — endpoint inventory and tool-surface
  comparison for REST parity work (comments, users, application passwords,
  widgets, settings, themes, site health, oEmbed/editor utilities, WooCommerce
  read endpoints, revisions/autosaves). No source files copied or ported.
- Destination: plugin/includes/Abilities/{Comments,Users,Widgets,Settings,
  Themes,PluginsManage,Revisions,Search,WooCommerce}/,
  companion/src/direct/tools/{comments,widgets,health,woocommerce,rest-request}.ts
- Security review: all writes rerouted through Stonewright Permissions/
  Backup/ConfirmationToken/AuditLog gates; upstream had no equivalent gating.
