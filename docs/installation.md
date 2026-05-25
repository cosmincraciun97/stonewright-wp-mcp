# Stonewright Installation

Stonewright has two parts:

- WordPress plugin: registers the `stonewright/*` abilities.
- Node companion: exposes local stdio MCP, proxies the WordPress MCP endpoint,
  and runs guarded WP-CLI.

## Requirements

- WordPress 6.7+
- PHP 8.1+
- Composer 2
- Node.js 20+
- WP-CLI for fast local WordPress work. The companion can use `wp` from `PATH`
  or auto-detect LocalWP's `wp-cli.phar` plus PHP on Windows/macOS.
- A WordPress Application Password

## WordPress Plugin

```bash
cd /path/to/wp-content/plugins
git clone https://github.com/stonewright/stonewright-wp-mcp.git stonewright
cd stonewright/plugin
composer install --no-dev
wp plugin activate stonewright
```

In WordPress Admin, open **Stonewright > Configuration** and enable AI
Abilities.

Endpoint:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

## Companion

```bash
cd /path/to/wp-content/plugins/stonewright/companion
npm install
npm run build
```

For local stdio MCP clients, configure:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "--package", "@stonewright/companion@latest", "stonewright-mcp"],
      "env": {
        "STONEWRIGHT_MCP_URL": "https://your-site.example.com/wp-json/mcp/stonewright",
        "WP_API_USERNAME": "your-wp-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_WP_ROOT": "/path/to/wordpress",
        "PORT": "8765",
        "COMPANION_BEARER_TOKEN": "change-this-long-random-token",
        "COMPANION_ALLOWED_ORIGINS": "http://localhost,http://127.0.0.1"
      }
    }
  }
}
```

Windows note: use a normal Windows path for `STONEWRIGHT_WP_ROOT`, for example
`D:\\Sites\\example\\app\\public`.

macOS note: use the absolute WordPress root path, for example
`/Users/me/Sites/example/app/public`.

For the WordPress-side `stonewright/wp-cli-*` abilities, set the WordPress
option to the same bridge URL and token:

```bash
wp option update stonewright_companion_url http://127.0.0.1:8765
wp option update stonewright_companion_token change-this-long-random-token
```

If you do not enable the HTTP bridge, agents should use the direct companion
MCP tools `companion_wp_cli_status`, `companion_wp_cli_discover`, and
`companion_wp_cli_run` instead of the WordPress-side `stonewright/wp-cli-*`
abilities.

When Stonewright is installed through the Node companion MCP, the companion also
registers direct aliases named `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, and `stonewright-wp-cli-run`. Those aliases run
WP-CLI inside the companion and do not require the WordPress-side HTTP bridge on
port `8765`.

### WP-CLI Discovery

Discovery order:

1. `STONEWRIGHT_WP_CLI_PHP_BIN` + `STONEWRIGHT_WP_CLI_PHAR_PATH`.
2. `STONEWRIGHT_WP_CLI_BIN`.
3. LocalWP-style `wp-cli.phar` near the WordPress root or common LocalWP install
   locations, paired with LocalWP PHP from `lightning-services`.
4. Fallback to `wp` from `PATH`.

Optional env vars:

| Variable | Purpose |
|---|---|
| `STONEWRIGHT_WP_ROOT` | WordPress root used for `cwd`, `--path`, and LocalWP discovery. |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | Comma/semicolon list of roots allowed for `cwd` and `--path`. |
| `STONEWRIGHT_WP_CLI_BIN` | Explicit `wp` executable when it is not on `PATH`. |
| `STONEWRIGHT_WP_CLI_PHP_BIN` | Explicit PHP executable for `wp-cli.phar`. |
| `STONEWRIGHT_WP_CLI_PHAR_PATH` | Explicit `wp-cli.phar` path. |
| `STONEWRIGHT_WP_CLI_PHP_INI` | Optional PHP ini path for LocalWP/site PHP extensions. |

If `STONEWRIGHT_WP_ROOT` is omitted, callers can pass an absolute `path` in
`stonewright-wp-cli-*` input; the companion uses that path as the working
directory and allowed root for that command.

## Browser MCP

Stonewright does not include browser, screenshot, or visual-review tools. Add a
separate Playwright MCP server next to Stonewright:

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp@latest", "--caps=testing,vision,devtools"]
    }
  }
}
```

Agents should connect this before implementation when a task needs browser
testing, screenshots, or visual inspection. If the MCP client cannot see a
browser/screenshot tool, the agent should stop before visual implementation and
ask the user to connect Playwright instead of building blind.

## Tool Names

WordPress ability names use slashes. MCP tool names use hyphens.

| WordPress ability | MCP tool |
|---|---|
| `stonewright/context-bootstrap` | `stonewright-context-bootstrap` |
| `stonewright/system-abilities-list` | `stonewright-system-abilities-list` |
| `stonewright/wp-cli-status` | `stonewright-wp-cli-status` |
| `stonewright/wp-cli-discover` | `stonewright-wp-cli-discover` |
| `stonewright/wp-cli-run` | `stonewright-wp-cli-run` |

The complete command list is generated in
[`ability-truth-matrix.md`](ability-truth-matrix.md).

## First Smoke Test

1. Call `stonewright-ping`.
2. Call `stonewright-context-bootstrap` with:

```json
{
  "task": "Test Stonewright connection",
  "surface": "wordpress",
  "intent": "read"
}
```

3. Confirm the response includes `mcp_tool_naming`, instructions, skills,
   memory, recommended external MCPs, and required followups.
4. Call `stonewright-system-abilities-list` and confirm every row includes
   `name` and `mcp_tool_name`.
