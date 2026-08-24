# Getting Started With Codex

Stonewright uses one canonical Codex local adapter. `--client codex-cli` is the
canonical slug; `--client codex` and the compatibility slug
`--client chatgpt-desktop` resolve to the same Codex TOML entry.

## Codex CLI

Codex CLI uses `~/.codex/config.toml` (or `.codex/config.toml` in a trusted
project) plus `codex mcp add` / `codex mcp login` for OAuth HTTP.

Installer flag: `--client codex-cli`.

## Codex in ChatGPT Desktop

For Stonewright local stdio, `--client chatgpt-desktop` is a compatibility alias
for the Codex adapter above. It reads and writes `~/.codex/config.toml` (or the
trusted project TOML), not a second Desktop JSON file.

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

ChatGPT Desktop compatibility alias (same Codex TOML target):

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias site-a --url https://site-a.example --username editor \
  --env production --mode plugin-only --client chatgpt-desktop \
  --plugin-enabled yes --wp-mode production-safe --wp-surface essential
```

The hidden prompt keeps the password off argv and shell history. Do not add a
second generic `[mcp_servers.stonewright]` block or a Desktop JSON duplicate. If
the alias already exists, reuse its saved credential:

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

Then call these tools in this exact successful order:

```text
stonewright-task-start
stonewright-setup-profile
stonewright-wordpress-mcp-status
stonewright-client-surface-check
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
stonewright-task-start
stonewright-setup-profile
stonewright-wordpress-mcp-status
stonewright-client-surface-check
```

Check these fields:

| Field | What it tells you |
|---|---|
| `companion_version` | The companion process Codex is actually running. |
| `expected_companion_package` | The release tarball the config should point to. |
| `refresh_required_tool_names` | Server-required refresh candidates; an empty list is not client visibility proof. |
| saved/effective mode and surface | Authoritative plugin state; it must agree with the active runtime. |
| `client_has_tool` / `relist_required` | Actual visibility gate for the required client tool and catalog. |

If the version or package is old, rerun the versioned `connect repair` command
for that client slug, then restart Codex. If saved/effective mode or surface
differs, follow the returned Setup remediation. If visibility fails or relisting
is required, reload the MCP session and repeat all four calls. Never copy the
alias entry to a generic server name: that reintroduces cross-site ambiguity.
