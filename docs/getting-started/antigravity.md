# Getting Started With Antigravity

This guide configures Stonewright for Antigravity 2.0, Antigravity IDE, and
Antigravity CLI with a compact tool profile.

## Why `low-tools`

Antigravity and other strict MCP clients work best when startup exposes a small,
stable tool surface. Use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` first. It keeps
Stonewright under the strict startup budget while preserving the tools agents
need for setup, diagnostics, composite WordPress writes, guarded WP-CLI, and
long-running jobs. It also keeps Elementor template save and guarded sandbox
write/activate visible for Loop Grid, Loop Item, shortcode, and query glue work.

Switch to a specialist profile such as `elementor`, `acf`, `cpt-ui`, `fse`, or
`wp cli` only when a task needs a narrower advanced surface. Use `full` only for
maintainer debugging.

## 1. Install And Enable Stonewright

Install the WordPress plugin from a GitHub release ZIP, activate it, then open
**Stonewright > Configuration**.

Generate a WordPress Application Password from the Configuration page and copy
it immediately. WordPress shows the password once.

## 2. Open The Antigravity MCP Config

Antigravity 2.0, Antigravity IDE, and Antigravity CLI share this config file:

```text
~/.gemini/config/mcp_config.json
```

In Antigravity IDE you can also open it from the agent panel:

1. Open **MCP Servers** from the `...` menu.
2. Click **Manage MCP Servers**.
3. Click **View raw config**.

## 3. Add Stonewright

Use the latest release tarball shown by the Stonewright Configuration page. This
example uses `1.0.0-alpha.60`:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": [
        "-y",
        "--package",
        "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.60/stonewright-companion-1.0.0-alpha.60.tgz",
        "stonewright-mcp"
      ],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.example.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "low-tools"
      }
    }
  }
}
```

For local WordPress sites, add `STONEWRIGHT_WP_ROOT` only when you want guarded
WP-CLI discovery and local credential helpers:

```json
"STONEWRIGHT_WP_ROOT": "D:\\Local Sites\\example\\app\\public"
```

Use the WordPress install folder that contains `wp-config.php`, not the
Stonewright plugin folder.

## 4. Refresh Antigravity

After saving the config:

- In Antigravity 2.0 or IDE, open **Settings > Customizations** and click
  **Refresh** under installed MCP servers.
- In Antigravity CLI, start `agy`, type `/mcp`, and confirm `stonewright` is
  listed.

If the task needs local WP-CLI, the Stonewright companion also needs PHP CLI
with mysqli/MySQL enabled, `wp` or `wp-cli.phar`, `STONEWRIGHT_WP_ROOT` pointing
at `wp-config.php`, and the database running. Remote HTTP MCP sites do not need
local PHP/MySQL unless the companion will run WP-CLI for that site. Refresh
again after changing env vars, PHP/WP-CLI paths, or the release tarball.

The first visible Stonewright tools should include:

```text
stonewright-setup-profile
stonewright-wordpress-mcp-status
stonewright-context-bootstrap
stonewright-workflow-preflight
stonewright-wp-cli-status
stonewright-wp-cli-run
stonewright-wp-cli-batch-run
stonewright-wp-cli-job-start
stonewright-wp-cli-job-status
```

## 5. First Prompt

Use this smoke test before asking for site changes:

```text
Use Stonewright. First call stonewright-setup-profile, then verify
stonewright-context-bootstrap and stonewright-workflow-preflight are visible.
If Stonewright is not connected, call stonewright-wordpress-mcp-status and stop
with the exact missing config value. Do not inspect private client config
files, create scratch helper scripts, create helper JSON argument files, launch
the companion through ad hoc shell scripts, create action scripts, inspect
plugin/companion source to reverse-engineer tool schemas, hand-roll JSON-RPC,
call the REST runner from shell, or run wp commands in a normal shell.
```

For real work:

```text
Use Stonewright for this WordPress task. Start with
stonewright-context-bootstrap and stonewright-workflow-preflight. Keep
STONEWRIGHT_MCP_TOOL_PROFILE=low-tools unless the preflight response says a
specialist profile is required. Use batch or composite tools before many small
calls, and use guarded stonewright-wp-cli-* tools for WP-CLI work.
```

## Troubleshooting

| Symptom | Fix |
|---|---|
| `stonewright` does not appear in `/mcp` | Confirm the server is in `~/.gemini/config/mcp_config.json`, save, then refresh installed MCP servers. |
| Server appears but WordPress tools are missing | Call `stonewright-wordpress-mcp-status` and check `STONEWRIGHT_WP_URL`, username, and Application Password. |
| Too many tools or startup failure | Confirm `STONEWRIGHT_MCP_TOOL_PROFILE` is `low-tools`, not `essential` or `full`. |
| WP-CLI helpers missing or `php_ini_not_loaded` | Add `STONEWRIGHT_WP_ROOT`, set matching `STONEWRIGHT_WP_CLI_PHP_BIN`/`STONEWRIGHT_WP_CLI_PHP_INI` when needed, confirm mysqli/MySQL and the database are available, then refresh Antigravity. |
| Agent tries shell `wp ...` commands | Restart the task and tell it to use `stonewright-wp-cli-status`, `stonewright-wp-cli-run`, or `stonewright-wp-cli-batch-run` instead. |

## Skill Search Duplicates In Codex

Codex slash entries such as `/stonewright` come from local Codex skills, not
from the Antigravity MCP config. If `/stonewright` appears more than once in
Codex, resync the skills from this repository:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\sync-codex-skills.ps1
```

The sync script stores backups outside the indexed skill root at
`~/.codex/skill-backups/stonewright`, removes stale nested skill copies, and
relocates old `*.backup-*` directories that older script versions created under
`~/.codex/skills`. Restart Codex after syncing.
