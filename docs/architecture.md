# Stonewright Architecture

## High-level component map

```
  MCP client (Claude Code / Codex / any MCP host)
          |
          |  JSON-RPC over HTTP (Bearer auth)
          v
  +-----------------------+
  | WordPress MCP Adapter |  (wordpress/mcp-adapter)
  +-----------------------+
          |
          | wp_register_ability() dispatch
          v
  +---------------------------+
  |   Stonewright MCP Server  |  plugin/stonewright.php
  |                           |
  |  AbilityRegistry::list()  |  108 registered abilities
  |  AbilityRegistry::register_all()
  +---------------------------+
          |
          | permission_callback()   execute()
          v                         v
  +----------------+      +----------------------------+
  | Security layer |      |  Ability implementations   |
  |                |      |  plugin/includes/Abilities/ |
  | Permissions::  |      |                            |
  | ConfirmToken:: |      |  Domain subdirs:           |
  | Backup::       |      |    Content/  Media/        |
  | Validator::    |      |    Gutenberg/ Patterns/    |
  | StaticGuard::  |      |    FSE/ ElementorV3/       |
  | AuditLog::     |      |    ElementorV4/ Design/    |
  +----------------+      |    QA/ Sandbox/ Memory/    |
                          |    Security/ Site/ System/ |
                          |    ElementorWidget/        |
                          +----------------------------+
                                    |
          +--------------------------+--------------------+
          |                          |                    |
          v                          v                    v
  +----------------+   +------------------------+  +----------+
  | WordPress core |   | Elementor (optional)   |  | Companion|
  | posts, options |   | V3 element data        |  | Node.js  |
  | meta, FSE      |   | V4 atomic classes/vars |  | bridge   |
  | media library  |   | kit settings           |  +----------+
  +----------------+   +------------------------+
                                                       |
                                          +--------------------------+
                                          |  Figma REST API          |
                                          |  Playwright (screenshots)|
                                          |  axe-core (a11y)        |
                                          |  Lighthouse              |
                                          |  Pixel diff engine       |
                                          +--------------------------+
```

## Request flow

A single MCP tool call travels the following path:

1. **MCP client** sends a JSON-RPC `abilities/call` request to the WordPress REST endpoint with a Bearer application-password.
2. **wordpress/mcp-adapter** authenticates the request (`wp_set_auth_cookie` / `authenticate` filter) and resolves the ability name to its registered callbacks.
3. **permission_callback()** is invoked. All Stonewright callbacks delegate to `Permissions::` helpers that wrap `current_user_can()`. Compound checks (e.g. `can_manage_design()`) require multiple capabilities.
4. **ConfirmationToken::verify_or_error()** is called by `ConfirmationGuard::require_confirmation()` for any destructive ability when `stonewright_mode === 'production-safe'`. A token that is absent, expired, or mismatched short-circuits execution with `WP_Error`.
5. **execute()** runs the ability logic. Every mutating ability:
   a. Calls `Backup::snapshot_post( $post_id )` before touching the database.
   b. Calls `Validator::validate( $spec )` before handing a design spec to a renderer.
   c. Performs the write (wp_update_post, update_post_meta, FSE template CPT write, etc.).
6. **AuditLog::record()** appends an entry to the `stonewright_audit_log` option keyed by ability + user + timestamp.
7. The adapter returns a JSON-RPC response to the client.

```
MCP call
  --> permission_callback()
        --> Permissions::can_*()
        --> ConfirmationToken::verify_or_error() (if destructive + production-safe)
  --> execute()
        --> Backup::snapshot_post()     (writes first)
        --> Validator::validate()       (specs only)
        --> [domain logic]
        --> AuditLog::record()
  --> JSON-RPC response
```

## Security envelope (rules from AGENTS.md)

Each hard rule is enforced at a specific layer:

| Rule | Enforcer | Layer |
|---|---|---|
| No eval / create_function | `StaticGuard` (Sandbox abilities + WidgetDefine) | Pre-execution static analysis |
| No `__return_true` for writes | `Permissions::*()` is always a real callable | permission_callback |
| Backup before write | `Backup::snapshot_post()` called inside `execute()` | Ability logic |
| Validator before render | `DesignSpec\Validator::validate()` / `ThemeJson\Validator::validate()` | Ability logic |
| Confirmation tokens | `ConfirmationGuard` trait / explicit verify | execute() entry |
| Mode awareness | `Permissions::is_production_safe()` / guard checks | Ability logic |
| Companion never writes | Companion exposes read-only HTTP endpoints only | Architectural constraint |

## Companion role

The companion is a Node.js HTTP server (`companion/src/index.ts`). It handles only:

- **Figma ingestion** — fetches Figma node JSON via REST API, converts to a Stonewright Design Spec JSON payload.
- **Playwright screenshots** — headless Chromium driven by Playwright for visual QA.
- **Pixel diff** — compares reference vs. actual screenshots using `sharp`.
- **axe-core a11y** — runs axe-core 4.9.1 in the Playwright browser context.
- **Lighthouse** — executes Lighthouse audits and returns category scores.
- **MCP proxy** (optional) — forwards MCP requests to an upstream server for development environments.

The companion **never** calls WordPress REST write endpoints and **never** shells into WP-CLI. PHP `CompanionClient` calls `/health`, `/screenshot`, `/diff`, `/axe`, `/layout`, and `/lighthouse` — all read-only from WordPress's perspective.

## File layout

```
stonewright-wp-mcp/
├── plugin/                      GPL-2.0-or-later WordPress plugin
│   ├── stonewright.php          Bootstrap + plugin header
│   ├── composer.json            PHP dependency manifest
│   ├── includes/
│   │   ├── Core/                Bootstrap, hooks, AbilityRegistry, REST
│   │   ├── Abilities/           One subdir per category (108 abilities)
│   │   │   ├── Common/          Shared traits (ConfirmationGuard)
│   │   │   ├── Content/
│   │   │   ├── Design/
│   │   │   ├── ElementorV3/
│   │   │   ├── ElementorV4/
│   │   │   ├── ElementorWidget/
│   │   │   ├── FSE/
│   │   │   ├── Gutenberg/
│   │   │   ├── Media/
│   │   │   ├── Memory/
│   │   │   ├── Patterns/
│   │   │   ├── QA/
│   │   │   ├── Sandbox/
│   │   │   ├── Security/
│   │   │   ├── Site/
│   │   │   └── System/
│   │   ├── Admin/               Settings page, ability toggle UI
│   │   ├── DesignSpec/          Validator + JSON schema
│   │   ├── Renderers/           Spec → Gutenberg / Elementor renders
│   │   ├── Security/            Backup, ConfirmationToken, Permissions, AuditLog
│   │   ├── Memory/              Site memory store (wp_options backed)
│   │   └── Support/             Logger, Json helpers
│   ├── blocks/                  Dynamic Gutenberg blocks (PHP + JS)
│   └── tests/
│       ├── Unit/                PHPUnit unit tests
│       └── Integration/         PHPUnit integration tests
│
├── companion/                   MIT Node.js bridge
│   ├── src/
│   │   ├── index.ts             HTTP server entry point
│   │   ├── http-api.ts          Route handlers (screenshot, diff, axe, etc.)
│   │   ├── figma-bridge.ts      Figma REST client + DSL converter
│   │   ├── playwright-runner.ts Playwright screenshot runner
│   │   ├── pixel-diff.ts        Sharp-based pixel diff
│   │   ├── mcp-proxy.ts         Optional upstream MCP proxy
│   │   └── contracts/           JSON schemas + version constant
│   └── tests/
│
├── skills/                      Skill packs for Claude Code / Codex
├── docs/                        Documentation (CC BY 4.0)
└── AGENTS.md                    Hard rules for all coding agents
```
