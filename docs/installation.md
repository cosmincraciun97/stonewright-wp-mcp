# Stonewright Installation

Stonewright has two parts:

- WordPress plugin: registers the `stonewright/*` abilities.
- Node companion: exposes local stdio MCP, proxies the WordPress MCP endpoint,
  and runs guarded WP-CLI.

## Requirements

- WordPress 6.7+
- PHP 8.1+
- Composer 2 for source installs
- Node.js 20+ for the optional companion
- WP-CLI for fast local WordPress work. The companion can use `wp` from `PATH`
  or auto-detect LocalWP's `wp-cli.phar` plus PHP on Windows/macOS.
- A WordPress Application Password

## Install The WordPress Plugin From Release

1. Download `stonewright-<version>.zip` from
   <https://github.com/cosmincraciun97/stonewright-wp-mcp/releases>.
2. In WordPress Admin, open **Plugins > Add New > Upload Plugin**.
3. Upload the ZIP and activate **Stonewright**.
4. Open **Stonewright > Configuration** and enable AI Abilities.
5. Create an Application Password from **Users > Profile**. The MCP client
   authenticates with `username:application-password`.

The release ZIP includes production Composer dependencies.

Endpoint:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

## Install The WordPress Plugin From Source

```bash
cd /path/to/wp-content/plugins
git clone https://github.com/cosmincraciun97/stonewright-wp-mcp.git stonewright
cd stonewright/plugin
composer install --no-dev
wp plugin activate stonewright
```

## Companion

The companion is optional. Use it when your MCP client needs a local stdio
server, WordPress MCP proxying, LocalWP/WP-CLI discovery, or the guarded
`stonewright-wp-cli-*` tools.

From a release package:

```bash
npm install -g ./stonewright-companion-<version>.tgz
```

From source:

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
      "command": "stonewright-mcp",
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

The companion also registers `stonewright-wp-cli-install` and
`companion_wp_cli_install`. The installer downloads the official `wp-cli.phar`
into the Stonewright companion cache and does not modify system `PATH`.

### WP-CLI Discovery

Discovery order:

1. `STONEWRIGHT_WP_CLI_PHP_BIN` + `STONEWRIGHT_WP_CLI_PHAR_PATH`.
2. `STONEWRIGHT_WP_CLI_BIN`.
3. LocalWP-style `wp-cli.phar` near the WordPress root or common LocalWP install
   locations, paired with LocalWP PHP from `lightning-services`.
4. Stonewright companion cache from `stonewright-wp-cli-install`.
5. Fallback to `wp` from `PATH`.

Optional env vars:

| Variable | Purpose |
|---|---|
| `STONEWRIGHT_WP_ROOT` | WordPress root used for `cwd`, `--path`, and LocalWP discovery. |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | Comma/semicolon list of roots allowed for `cwd` and `--path`. |
| `STONEWRIGHT_WP_CLI_BIN` | Explicit `wp` executable when it is not on `PATH`. |
| `STONEWRIGHT_WP_CLI_PHP_BIN` | Explicit PHP executable for `wp-cli.phar`. |
| `STONEWRIGHT_WP_CLI_PHAR_PATH` | Explicit `wp-cli.phar` path. |
| `STONEWRIGHT_WP_CLI_PHP_INI` | Optional PHP ini path for LocalWP/site PHP extensions. |
| `STONEWRIGHT_WP_CLI_INSTALL_DIR` | Optional cache directory for `stonewright-wp-cli-install`. |

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
| `stonewright/workflow-preflight` | `stonewright-workflow-preflight` |
| `stonewright/system-abilities-list` | `stonewright-system-abilities-list` |
| `stonewright/media-upload-batch` | `stonewright-media-upload-batch` |
| `stonewright/elementor-v3-capabilities-summary` | `stonewright-elementor-v3-capabilities-summary` |
| `stonewright/elementor-v3-apply-bundle` | `stonewright-elementor-v3-apply-bundle` |
| `stonewright/wp-cli-status` | `stonewright-wp-cli-status` |
| `stonewright/wp-cli-discover` | `stonewright-wp-cli-discover` |
| `stonewright/wp-cli-run` | `stonewright-wp-cli-run` |

The complete command list is generated in
[`ability-truth-matrix.md`](ability-truth-matrix.md).

## First Smoke Test

1. Call `stonewright-ping`.
2. Call `stonewright-workflow-preflight` with:

```json
{
  "task": "Test Stonewright connection",
  "surface": "wordpress",
  "intent": "read"
}
```

3. Confirm the response includes `context_token`, `mode`, `auth_guidance`, and
   `fast_path`.
4. Call `stonewright-context-bootstrap` with:

```json
{
  "task": "Test Stonewright connection",
  "surface": "wordpress",
  "intent": "read"
}
```

5. Confirm the response includes `mcp_tool_naming`, instructions, skills,
   memory, recommended external MCPs, and required followups.
6. Call `stonewright-system-abilities-list` and confirm every row includes
   `name` and `mcp_tool_name`.
