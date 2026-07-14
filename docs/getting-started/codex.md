# Getting Started With Codex

Codex CLI and the Codex IDE extension share MCP configuration. Use
`~/.codex/config.toml` for user-level setup, or `.codex/config.toml` inside a
trusted project when the Stonewright connection should be project-specific.

## Add Stonewright

Open `config.toml` and add:

```toml
[mcp_servers.stonewright]
command = "npx"
args = ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.65/stonewright-companion-1.0.0-alpha.65.tgz", "stonewright-mcp"]

[mcp_servers.stonewright.env]
STONEWRIGHT_WP_URL = "https://your-site.com"
STONEWRIGHT_WP_USERNAME = "your-wp-username"
STONEWRIGHT_WP_APP_PASSWORD = "xxxx xxxx xxxx xxxx xxxx xxxx"
STONEWRIGHT_MCP_TOOL_PROFILE = "essential"
```

For strict tool-cap sessions, use:

```toml
STONEWRIGHT_MCP_TOOL_PROFILE = "low-tools"
```

## Make Codex See It

After saving the config, restart Codex or reload the IDE MCP session. In the
Codex TUI, run `/mcp` and confirm `stonewright` is listed.

Then call:

```text
stonewright-setup-profile
stonewright-wordpress-mcp-status
stonewright-context-bootstrap
stonewright-workflow-preflight
```

If `stonewright-context-bootstrap` is not visible, Codex has not loaded the
Stonewright MCP server yet. Restart or fix the config before WordPress work.

## After Releases Or Skill Syncs

After every Stonewright release or skill sync, restart Codex and rerun:

```text
stonewright-setup-profile
stonewright-wordpress-mcp-status
```

Check these fields:

| Field | What it tells you |
|---|---|
| `companion_version` | The companion process Codex is actually running. |
| `expected_companion_package` | The release tarball the config should point to. |
| `refresh_required_tool_names` | Required tools that prove the visible tool list is current. |

If the version or package is old, update the `args` package URL in
`config.toml`, save, and restart Codex. If required tools are missing, restart
or reload the MCP session so Codex refreshes the tool list.

