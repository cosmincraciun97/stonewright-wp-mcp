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
- WP-CLI for fast local WordPress work
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
        "STONEWRIGHT_WP_ROOT": "/path/to/wordpress"
      }
    }
  }
}
```

Windows note: use a normal Windows path for `STONEWRIGHT_WP_ROOT`, for example
`D:\\Sites\\example\\app\\public`.

macOS note: use the absolute WordPress root path, for example
`/Users/me/Sites/example/app/public`.

## Browser MCP

Stonewright does not include browser, screenshot, or visual-review tools. Add a
separate Playwright MCP server next to Stonewright:

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["@playwright/mcp@latest"]
    }
  }
}
```

Agents should connect this before implementation when a task needs browser
testing, screenshots, or visual inspection.

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
