# Connect MCP Client

This guide covers wiring supported AI clients to Stonewright. The shortest path
is the **Stonewright > Configuration** page:

1. Enable Stonewright abilities.
2. Generate a WordPress Application Password in the page.
3. Copy the setup note, or expand the JSON snippets for your client.

---

## Prerequisites

### WordPress Application Password

WordPress Application Passwords are one-time-display credentials tied to the
current WordPress user. Generate one from **Stonewright > Configuration >
Application Password**. Copy it immediately; WordPress will not show it again.

### Endpoint

The MCP endpoint is displayed on the Configuration page:

```text
https://{your-site}/wp-json/mcp/stonewright
```

### HTTPS

Production sites must use HTTPS. Application Password credentials travel in the
HTTP Authorization header. For local development with no HTTPS, set
`WP_ENVIRONMENT_TYPE=local` in `wp-config.php`.

---

## Recommended stdio config

Most clients can run the Stonewright companion with `npx`:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "@stonewright/companion@latest"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Stonewright tool names are hyphenated in MCP clients. Example:
`stonewright/context-bootstrap` is called as `stonewright-context-bootstrap`.

After restart, the AI client should show `stonewright-context-bootstrap` in the
MCP tool list. If that tool is missing, Stonewright is not connected yet: reload
the client or fix the MCP config before asking the agent to edit WordPress. Do
not use local agent skills, repository files, private client config files, or
manual JSON-RPC or `/wp-json/stonewright/v1/abilities/run` shell calls as
substitutes for the live MCP server.

---

## Claude Code

Claude Code registers MCP servers through its CLI:

```bash
claude mcp add stonewright \
  --env STONEWRIGHT_WP_URL='https://your-site.com' \
  --env STONEWRIGHT_WP_USERNAME='your-wp-username' \
  --env STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
  -- npx -y @stonewright/companion@latest
```

The server is registered for the current user. Restart or reload the client
after adding it.

---

## JSON Clients

Use the recommended stdio block for these clients unless their UI requests the
fields separately:

| Client | Config location |
|---|---|
| Claude Desktop | `%APPDATA%\Claude\claude_desktop_config.json` on Windows, `~/Library/Application Support/Claude/claude_desktop_config.json` on macOS, `~/.config/Claude/claude_desktop_config.json` on Linux |
| Cursor | `.cursor/mcp.json` in the project or `~/.cursor/mcp.json` globally |
| Windsurf | `~/.codeium/windsurf/mcp_config.json` |
| OpenCode | `.opencode/config.json` or global OpenCode config |
| Roo Code | `~/.roo/mcp.json` or the extension settings panel |
| Amazon Q Developer | `~/.aws/amazonq/mcp.json` |
| Kilo Code | `~/.kilo/mcp.json` or the extension settings panel |
| Gemini CLI | `~/.gemini/settings.json` |
| Antigravity | project or global MCP config |

VS Code-style clients use a `servers` top-level key instead of `mcpServers`:

```json
{
  "servers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "@stonewright/companion@latest"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Zed uses `context_servers`:

```json
{
  "context_servers": {
    "stonewright": {
      "command": {
        "path": "npx",
        "args": ["-y", "@stonewright/companion@latest"],
        "env": {
          "STONEWRIGHT_WP_URL": "https://your-site.com",
          "STONEWRIGHT_WP_USERNAME": "your-wp-username",
          "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
        }
      }
    }
  }
}
```

---

## Browser MCP

Add a separate Playwright MCP server when the task needs browser testing,
screenshots, or visual inspection:

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

Restart the AI client after adding Playwright so its tool list refreshes. For
visual tasks, verify a browser or screenshot tool is visible before the first
Stonewright write.

---

## First Calls

After connecting, verify Stonewright with the real MCP tools:

```text
Use MCP tool stonewright-ping.
Verify the tool list includes stonewright-context-bootstrap.
Then call stonewright-context-bootstrap and stonewright-workflow-preflight before
the first real task.
```

If `stonewright-context-bootstrap` is not visible, stop and restart or reload
the AI client. A good agent should not begin by only announcing named skills,
searching repository files, or calling Stonewright REST endpoints from shell; it
should call the live Stonewright MCP tools.

For visual work, include the target URL, screenshot or design reference, allowed
plugins, safety mode, and desktop/tablet/mobile acceptance checks.

---

## Example Prompts

### Figma to Elementor V3

```text
Use Stonewright to implement the attached Figma design in Elementor V3. Start
with stonewright-context-bootstrap and stonewright-workflow-preflight, extract
layout, spacing, colors, typography, and responsive behavior, create a validated
design spec, render with stonewright-elementor-v3-build-page-from-spec, then
use stonewright-elementor-v3-batch-mutate for polish. Verify desktop, tablet,
and mobile screenshots against the design.
```

### ACF field group

```text
Use Stonewright to create an ACF field group for Case Studies with client logo,
industry, challenge, solution, results metrics, testimonial, gallery, and CTA
fields. Attach it to the case-study post type, add three sample entries, and
verify fields are available for dynamic Elementor templates.
```

### CPT UI content model

```text
Use Stonewright with CPT UI to create a Projects post type and Project Type
taxonomy. Add labels, archive support, featured images, REST visibility, and
sensible rewrite slugs. Then seed sample projects and build a responsive
archive layout that can be filtered by taxonomy.
```

### WooCommerce cleanup

```text
Use Stonewright to inspect WooCommerce catalog data, normalize product titles,
SKUs, prices, categories, and stock for the provided list, then verify the shop
and product pages still render correctly. Use bulk or batch tools where
possible and report changed products.
```
