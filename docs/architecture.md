# Architecture

Stonewright has two parts:

- `plugin/`: the WordPress source of truth for abilities, permissions, memory,
  skills, Design Spec validation, rendering, backups, and audit logs.
- `companion/`: a Node sidecar for stdio MCP, optional HTTP MCP transport,
  health checks, optional MCP proxying, and tokenized WP-CLI.

```
MCP client
  |
WordPress MCP adapter
  |
Stonewright plugin
  |-- Abilities
  |-- Context bootstrap
  |-- Persistent skills and memory
  |-- Design Spec validators/renderers
  |-- Direct PHP runtime, operator gates, backups, audit log
  |
Companion
  |-- /health
  |-- /mcp
  |-- /wp-cli/status
  |-- /wp-cli/discover
  |-- /wp-cli/run
```

## WordPress Writes

The plugin owns direct PHP runtime execution, permission checks,
production-safe confirmation tokens, backups, validation, and audit logging.
The companion can write by running tokenized WP-CLI commands requested by the
plugin or MCP client.

Use `stonewright/php-execute` for PHP snippets inside WordPress. WP-CLI
execution is tokenized and runs through `execFile`; WP-CLI PHP and shell entry
points are blocked.

## Agent Context

Agents must call MCP tool `stonewright-task-start` at the beginning of every
Stonewright task. It issues the same write context token while returning a
compact, task-aware response that includes:

- current instructions
- matched skill playbooks
- relevant memory
- required followups
- MCP tool naming hints
- recommended external MCPs such as Playwright for browser work
- a short-lived context token for write abilities

Manual edits to skills, memory, or custom instructions persist in WordPress and
are included in future task-start responses. `stonewright-context-bootstrap`
and `stonewright-workflow-preflight` remain compatibility paths.

If neither `stonewright-task-start` nor compatibility
`stonewright-context-bootstrap` is visible in the MCP tool list, the client has
not loaded Stonewright. Agents must stop WordPress work and ask for a client
reload or config fix instead of inspecting private client config files,
creating scratch helper scripts, creating helper JSON argument files, launching
the companion through ad hoc shell scripts, creating action scripts, inspecting
plugin/companion source to reverse-engineer tool schemas, hand-rolling
JSON-RPC, calling the REST ability runner from shell, or running shell `wp ...`
commands.


## Direct + plugin REST parity surfaces

Plugin abilities and Direct tools cover comments, users (including application passwords), widgets, allowlisted settings, themes, plugin lifecycle, revisions (with restore on the plugin), site health tests, search/oEmbed, and WooCommerce product/order/sales reads.

## MCP tool surface switching (premium finalization)

Profile and surface switching is transport-specific. Agents should treat
`tools_changed` / `re_list_instruction` on ability results as the source of truth.

### HTTP MCP transport (plugin adapter)

- **Admin option** `stonewright_mcp_surface`: `bootstrap` | `essential` | `full`
  controls which abilities the plugin exposes on `tools/list`.
- Each `tools/list` request reads the saved site surface plus an optional,
  expiring profile bound to `Mcp-Session-Id`. Bootstrap task-start activates
  only that session; it never rewrites the site option or another session.
- The vendor initialize payload may not declare `tools.listChanged`. Clients
  must honor `re_list_instruction` in the ability response and call `tools/list`
  again even when no `notifications/tools/list_changed` arrives.
- **OAuth 2.1** for the HTTP transport is planned, not scheduled.

### stdio companion transport

- For normal clients, the companion reads `stonewright_mcp_surface` from the
  plugin and treats the saved Setup value as its initial profile. Explicit
  specialist profiles and `low-tools` remain client overrides. Set
  `STONEWRIGHT_MCP_TOOL_PROFILE_LOCK=1` to force the environment profile.
- `bootstrap` is a real companion profile; it is not coerced to `essential`.
- When a proxied ability result includes `tools_changed: true`, a non-empty
  `re_list_instruction`, **or** a configured profile different from the active
  companion profile, the companion:
  1. Re-fetches `tools/list` from the plugin (schemas for newly visible tools).
  2. Diffs against registered proxy tools (register missing, disable dropped).
  3. Emits `notifications/tools/list_changed` via the MCP protocol server.
- Clients that ignore `list_changed` must still re-call `tools/list` using
  `re_list_instruction`. Older companions that only notify (or neither) need an
  MCP client / companion restart after a profile upgrade.

### Shared ability signals

- **`stonewright-tool-profile` activate**: expands `stonewright_mcp_surface`
  when leaving bootstrap and sets `tools_changed` + `re_list_instruction`.
- **`stonewright-task-start`**: surfaces `configured_mcp_surface` and
  `session_tool_profile`, binds the task profile to the current MCP session,
  and returns `tools_changed` + `re_list_instruction`. The saved Setup
  preference remains unchanged. When the admin surface is already
  essential/full (or the session profile is not bootstrap), task-start still
  sets `tools_changed` so stdio companions that started on env bootstrap
  re-register proxied tools. Companions must also parse ability JSON from
  `content[].text` when transports omit `structuredContent`.
- **Instructions forwarding:** when the companion proxies the WordPress MCP
  server, it captures plugin `initialize.instructions` during remote handshake
  and merges them into the companion MCP server instructions under
  `--- WordPress plugin instructions ---`. Unreachable sites keep companion-only
  text. AI clients that read handshake instructions therefore see plugin
  task-start rules without a separate call.
- **Pre-session read nudge:** until task-start (or compatibility bootstrap /
  preflight) marks the MCP session, read-only ability results may include a
  non-blocking `task_start_hint` string. Writes still hard-require the context
  token; the hint never blocks discovery tools.
- **Bootstrap surface** includes runtime escape hatches (`php-execute`,
  confirmation token, content/Elementor reads, `theme-file-read`) — not only
  four startup tools.
- **Diagnosis**: companion local tool `stonewright-client-surface-check` and
  `stonewright doctor --client-surface` explain profile vs client mismatches
  without REST workarounds.

### Direct/pluginless transport

- Fresh sessions also start on Bootstrap (at most eight Direct tools).
- `stonewright-task-start` selects a compact Direct profile for Elementor,
  Gutenberg, content-model, site-admin, or general work; the companion enables
  only that profile and emits `tools/list_changed`.
- Direct write tools require a prior `stonewright-task-start` for the target
  site (30-minute TTL, re-arms after expiry). Opt out with
  `STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off`.
- Pre-session Direct reads attach the same non-blocking `task_start_hint`.
- Full remains an explicit diagnostic/specialist choice, never the default.
