# Architecture

Stonewright has two parts:

- `plugin/`: the WordPress source of truth for abilities, permissions, memory,
  skills, Design Spec validation, rendering, backups, and audit logs.
- `companion/`: a Node sidecar for stdio MCP, optional HTTP MCP transport,
  health checks, optional MCP proxying, and guarded WP-CLI.

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
  |-- Security gates, backups, audit log
  |
Companion
  |-- /health
  |-- /mcp
  |-- /wp-cli/status
  |-- /wp-cli/discover
  |-- /wp-cli/run
```

## WordPress Writes

The plugin owns permission checks, production-safe confirmation tokens, backups,
validation, and audit logging. The companion can write only by running guarded
WP-CLI commands requested by the plugin or MCP client.

WP-CLI execution is tokenized and runs through `execFile`; arbitrary PHP and
shell entry points are blocked.

## Agent Context

Agents must call MCP tool `stonewright-context-bootstrap` at the beginning of
every Stonewright task. The response includes:

- current instructions
- matched skill playbooks
- relevant memory
- required followups
- MCP tool naming hints
- recommended external MCPs such as Playwright for browser work
- a short-lived context token for write abilities

Manual edits to skills, memory, or custom instructions persist in WordPress and
are included in future context bootstrap responses.

If `stonewright-context-bootstrap` is not visible in the MCP tool list, the
client has not loaded Stonewright. Agents must stop WordPress work and ask for a
client reload or config fix instead of inspecting private client config files,
creating scratch helper scripts, creating helper JSON argument files, launching
the companion through ad hoc shell scripts, creating action scripts, inspecting
plugin/companion source to reverse-engineer tool schemas, hand-rolling
JSON-RPC, calling the REST ability runner from shell, or running shell `wp ...`
commands.
