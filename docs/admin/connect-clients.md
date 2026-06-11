# Connect Your AI Client

This guide walks through generating credentials and wiring up any of the 15
supported AI clients to your Stonewright MCP endpoint.

---

## Prerequisites

### 1. Generate an Application Password

WordPress Application Passwords are the authentication mechanism. Each
password is a one-time-display credential tied to your WordPress user account.

Steps:

1. Log in to wp-admin.
2. Go to **Users > Profile** (or navigate directly to
   `wp-admin/profile.php#application-passwords-section`).
3. Scroll to the **Application Passwords** section.
4. Enter a descriptive name (e.g. `Claude Code – dev laptop`).
5. Click **Add New Application Password**.
6. Copy the displayed password — it will not be shown again.

### 2. Find your endpoint URL

The MCP endpoint is displayed on the Stonewright Configuration page:

```
https://{your-site}/wp-json/mcp/stonewright
```

### 3. HTTPS

Production sites must use HTTPS. Application Password credentials travel in
the HTTP Authorization header; over plain HTTP they are readable in transit.
For local development with no HTTPS, set `WP_ENVIRONMENT_TYPE=local` in
`wp-config.php` — this suppresses the production warning banner in the
Stonewright admin.

---

## Universal config block

For local development, install the companion release package first:

```bash
npm install -g ./stonewright-companion-<version>.tgz
```

Then use the Stonewright companion stdio transport:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "stonewright-mcp",
      "args": [],
      "env": {
        "STONEWRIGHT_SITE_URL": "https://your-site.com",
        "WP_API_URL": "https://your-site.com/wp-json/mcp/stonewright",
        "WP_API_USERNAME": "your-wp-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_URL": "https://your-site.com/wp-json/mcp/stonewright"
      }
    }
  }
}
```

Also add the separate Playwright MCP server when the agent needs browser
testing, screenshots, or visual inspection:

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

Restart the AI client after adding the Playwright MCP server so its tool list
refreshes. For visual tasks, verify a Playwright/browser tool is visible before
the first Stonewright write.

Stonewright tool names are hyphenated in MCP clients. Example:
`stonewright/context-bootstrap` is called as `stonewright-context-bootstrap`.

---

## Per-client config paths

### Claude Code
Claude Code does not use a JSON config file — it registers MCP servers via its CLI.
Run the generated `claude mcp add` command shown in the Stonewright admin page.
The command takes this form:

```bash
claude mcp add stonewright -- stonewright-mcp \
  --env STONEWRIGHT_SITE_URL='...' \
  --env STONEWRIGHT_MCP_URL='...' \
  --env WP_API_URL='...' \
  --env WP_API_USERNAME='...' \
  --env WP_API_PASSWORD='...'
```

The server is registered globally for the user. No file edits required.

### Claude Desktop
Config file:
- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`
- Linux: `~/.config/Claude/claude_desktop_config.json`

Paste the universal `mcpServers` block at the top level of the JSON object.
Restart Claude Desktop after saving.

### Cursor
Config file: `.cursor/mcp.json` in the project root, or
`~/.cursor/mcp.json` globally.

```json
{
  "mcpServers": {
    "stonewright": { ... }
  }
}
```

### VS Code (Copilot / GitHub Copilot MCP)
Config file: `.vscode/mcp.json` in the workspace root.

```json
{
  "servers": {
    "stonewright": {
      "command": "stonewright-mcp",
      "args": [],
      "env": {
        "WP_API_URL": "...",
        "WP_API_USERNAME": "...",
        "WP_API_PASSWORD": "...",
        "STONEWRIGHT_MCP_URL": "..."
      }
    }
  }
}
```

Note: VS Code uses `"servers"` rather than `"mcpServers"` at the top level.

### GitHub Copilot (standalone)
Same as VS Code — `.vscode/mcp.json` with `"servers"` key.

### Windsurf
Config file: `~/.codeium/windsurf/mcp_config.json`.

Uses the standard `mcpServers` top-level key.

### Zed
Config file: `~/.config/zed/settings.json`.

Add inside the existing settings object:

```json
{
  "context_servers": {
    "stonewright": {
      "command": {
        "path": "stonewright-mcp",
        "args": [],
        "env": {
          "STONEWRIGHT_MCP_URL": "...",
          "WP_API_USERNAME": "...",
          "WP_API_PASSWORD": "..."
        }
      }
    }
  }
}
```

### OpenCode
Config file: `.opencode/config.json` in the project root.

Uses `mcpServers` at the top level.

### Cline (VS Code extension)
Config lives in VS Code settings. Open the Cline sidebar, click the MCP
Servers icon, and add a new server with the `npx` command and the three env
variables.

### Roo Code
Config file: `~/.roo/mcp.json` or via the Roo Code settings panel in VS Code.

Uses `mcpServers` at the top level.

### Amazon Q Developer
Config file: `~/.aws/amazonq/mcp.json`.

Uses `mcpServers` at the top level.

### Kilo Code
Config file: `~/.kilo/mcp.json` or via the Kilo Code extension settings.

Uses `mcpServers` at the top level.

### Gemini CLI
Config file: `~/.gemini/settings.json`.

Add under the `"mcpServers"` key.

### Antigravity
Config file: `~/.antigravity/mcp.json`.

Uses `mcpServers` at the top level.

---

## Paste-to-agent onboarding prompt

If you would rather let the AI configure itself, paste the following into a
new chat after connecting the MCP server:

```
You are connected to a Stonewright MCP server at {endpoint URL}.
Your WordPress username is {username}.
Please call MCP tool stonewright-ping to confirm the connection, then call
stonewright-system-abilities-list and summarise which ability categories are
available. At the start of the first real task, call stonewright-context-bootstrap.
```

The agent will verify connectivity, enumerate available tools, and confirm
it is ready to work.
