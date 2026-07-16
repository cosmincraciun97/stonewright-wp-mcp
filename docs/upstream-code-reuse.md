# Upstream Code Reuse

Stonewright may inspect, copy, adapt, and port third-party source when the
upstream license permits it. Copied or derived code must keep attribution,
carry the correct license, and pass Stonewright's own security and test gates.

## License gate

Stonewright ships the WordPress plugin as `AGPL-3.0-or-later` and the
Node companion as `MIT`. Novamira 1.9.1 is primarily
`AGPL-3.0-or-later`. Its `novamira-visual/composer.json` declares the visual
subpackage as `MIT`, while PHP integration files inside that subpackage carry
AGPL SPDX headers.

The Stonewright plugin was relicensed before the first Novamira import. The
companion remains MIT and must not receive AGPL-derived code. Stonewright
Visual is distributed as a separate AGPL-3.0-or-later package.

## Inspected upstream snapshot

- Source: `use-novamira/novamira`
- Local snapshot: `/Users/cosminiviteb/Downloads/novamira-main`
- Reported version: `1.9.1`
- Top-level license: `AGPL-3.0-or-later`
- Snapshot date: `2026-07-14`

The inspected open-source snapshot contains WordPress abilities, skill
discovery, OAuth onboarding, a Gutenberg finalization queue, and the Novamira
Visual workspace. It does not contain the commercial Elementor or Bricks tool
implementations; only integration hooks and guidance for those tools are
present.

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

## Snapshot fingerprints

These hashes identify the files inspected for the implementation plan. Recheck
them immediately before porting; a changed hash means the source snapshot has
changed and requires a new review.

| Source path | SHA-256 |
|---|---|
| `novamira.php` | `08f9af1d945579f8f77268cd8e21b4208f794e393b01a061c33d1a92df0cf0e4` |
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
| `includes/connect-methods.php` | `c24169751ae8d5a4cf675ab675a0a16f53ecde71ea5c45f011968921483574a8` |
| `includes/oauth/client-validation.php` | `16456d11d582204f8791c29f1ae9006b99f5bd4861ee72a75780f798bbc67309` |
| sorted `includes/oauth/**` hash manifest | `229221936e8f66c412d1fc33cc0b4229a125173356b874e92f76cad55fdf3112` |
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
