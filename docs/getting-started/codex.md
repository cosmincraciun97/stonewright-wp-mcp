# Getting Started With Codex

Stonewright treats **Codex in ChatGPT Desktop** and **Codex CLI** as separate
clients. Pick the surface you actually use. `--client codex` still aliases to
CLI, but installer commands should use the canonical slugs below.

## Codex CLI

Codex CLI uses `~/.codex/config.toml` (or `.codex/config.toml` in a trusted
project) plus `codex mcp add` / `codex mcp login` for OAuth HTTP.

Installer flag: `--client codex-cli`.

## Codex in ChatGPT Desktop

The ChatGPT Desktop app stores GUI MCP servers in
`~/Library/Application Support/ChatGPT/mcp_config.json` (`mcpServers` JSON with
`url` for remote HTTP, or `command`/`args` for local stdio). Do not paste CLI
TOML into that file.

Installer flag: `--client chatgpt-desktop`.

## Add Stonewright

Use the versioned installer. It creates a collision-safe alias-specific entry
and keeps the Application Password in the OS credential store.

Codex CLI (TOML):

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias site-a --url https://site-a.example --username editor \
  --env production --mode plugin-only --client codex-cli \
  --plugin-enabled yes --wp-mode production-safe --wp-surface essential
```

Codex in ChatGPT Desktop (`mcpServers` JSON):

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias site-a --url https://site-a.example --username editor \
  --env production --mode plugin-only --client chatgpt-desktop \
  --plugin-enabled yes --wp-mode production-safe --wp-surface essential
```

The hidden prompt keeps the password off argv and shell history. Do not add a
second generic `[mcp_servers.stonewright]` block (CLI) or a second generic
`mcpServers.stonewright` key (Desktop). If the alias already exists, reuse its
saved credential:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect repair site-a --client codex-cli --mode plugin-only
```

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect repair site-a --client chatgpt-desktop --mode plugin-only
```

For strict tool-cap sessions, add this installer option:

```text
--profile low-tools
```

Codex CLI is a **tier-1 certification target**
([verified-client-versions.md](../verified-client-versions.md)). Keep secrets in
user-level or private config; never paste real Application Passwords into chat.

## Make Codex See It

After installation, **restart Codex or reload the MCP session** (required
for tool list refresh). In the Codex TUI, run `/mcp` and confirm the
`stonewright` alias entry is listed. Then run `stonewright connect verify
site-a --client codex-cli` or `--client chatgpt-desktop`; require the same
alias, Plugin mode, the expected companion, task-start, status, and no missing
required tools.

Then call:

```text
stonewright-task-start
stonewright-setup-profile
stonewright-wordpress-mcp-status
```

`stonewright-task-start` is the canonical first WordPress call. If neither it
nor compatibility `stonewright-context-bootstrap` is visible, Codex has not
loaded the Stonewright MCP server yet. Restart or fix the config before
WordPress work.

For a client that cannot hold the full catalog, activate opt-in
`discover-execute` (`discover-abilities` → `get-ability-info` →
`execute-ability`). `php-execute` stays on `full`.

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

If the version or package is old, rerun the versioned `connect repair` command
for that client slug, then restart Codex. If required tools are missing, reload
the MCP session so Codex refreshes the tool list. Never copy the alias entry to
a generic server name: that reintroduces cross-site ambiguity.
