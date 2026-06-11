# Configuration

The Configuration page is the first sub-page under the **Stonewright** menu
(`dashicons-hammer`, position 76). It owns three numbered cards: master
enable, application password, and client connection config.

Source: `plugin/includes/Admin/ConfigurationPage.php`

---

## Card 1 — Enable AI Abilities

### Master toggle

The `stonewright_enabled` option (boolean, default `false`) is a site-wide
kill switch. When it is `false` the MCP server rejects every inbound tool
call with an error — the only exception is the built-in `ping` ability,
which always responds so clients can test connectivity.

Flip the toggle, click **Save changes**, and all registered abilities become
available on the next tool-call attempt.

### Mode selector

Three modes are stored in the `stonewright_mode` option:

| Value | Behaviour |
|---|---|
| `development` | All abilities available; no confirmation required. |
| `staging` | Same as development; useful for labelling deployments. |
| `production-safe` | Destructive operations require a `confirmation_token` obtained from `stonewright/security-issue-confirmation-token`. Without the token, those calls are rejected before execution. |

### Production warning banner

When `wp_get_environment_type()` returns `'production'` **and** the master
toggle is `true`, a yellow notice appears at the top of the page. This is
purely informational — it does not block saving. The recommended response is
either to switch to `production-safe` mode or to disable AI Abilities until
you are ready to accept the risk.

### Companion bridge

The companion is a Node.js sidecar that handles WP-CLI, health checks, and the
optional MCP proxy. It writes to WordPress only through guarded WP-CLI commands.

| Setting | Option | Default |
|---|---|---|
| Bridge URL | `stonewright_companion_url` | `http://127.0.0.1:8765` |
| Bearer token | `stonewright_companion_token` | _(empty)_ |

The bridge URL is only required for WordPress-side abilities such as
`stonewright/wp-cli-run` when the client talks directly to the WordPress MCP
endpoint. When the client talks through the Node companion MCP, WP-CLI is also
available as direct tools named `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, and `stonewright-wp-cli-run`; these do not need
port `8765`.

WP-CLI discovery uses explicit env vars first, then the Stonewright companion
cache, then LocalWP-style `wp-cli.phar` and PHP paths, then `wp` from `PATH`.
If no phar exists, the direct companion tool `stonewright-wp-cli-install` can
download the official `wp-cli.phar` into the companion cache without modifying
system `PATH`.

### Elementor V4 atomic (experimental)

Checking `stonewright_elementor_v4_atomic` enables the experimental V4
renderer and related abilities. Off by default. Only relevant on sites
running Elementor 3.18+.

### Custom Instructions toggle

`stonewright_custom_instructions_enabled` is registered on this page (boolean,
default `true`) so it persists even before the Memory & Instructions sub-page
is visited. The actual text is managed on the Memory & Instructions page;
this option only controls whether that text is injected into the MCP server
description and ability-list responses.

---

## Card 2 — Application Password

AI clients authenticate using a WordPress Application Password sent as HTTP
Basic Auth with every request (`username:app-password`).

**To generate one:**

1. Click **Manage application passwords** — this opens your WordPress profile
   at `wp-admin/profile.php#application-passwords-section`.
2. Enter a name (e.g. "Stonewright Claude Code") and click **Add New
   Application Password**.
3. Copy the displayed password immediately — WordPress will not show it again.
4. Paste the username and password into your client config (see Card 3).

You can revoke individual passwords from the same profile section at any time.
Application Passwords are always generated for the currently logged-in user;
you cannot create them on behalf of another user from this page.

---

## Card 3 — Connect Your AI Client

### MCP endpoint URL

```
{site_url}/wp-json/mcp/stonewright
```

For example: `https://example.com/wp-json/mcp/stonewright`

### Universal config block

Install the companion release package with
`npm install -g ./stonewright-companion-<version>.tgz`, then use the same
stdio transport in supported clients:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "stonewright-mcp",
      "args": [],
      "env": {
        "STONEWRIGHT_MCP_URL": "https://example.com/wp-json/mcp/stonewright",
        "WP_API_USERNAME": "your-wp-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Replace `STONEWRIGHT_MCP_URL` with the endpoint shown on the page, `WP_API_USERNAME`
with your WordPress login, and `WP_API_PASSWORD` with the application password
generated in Card 2.

Also configure Playwright MCP separately when the agent needs browser testing,
screenshots, or visual inspection:

```bash
npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart the AI client after adding Playwright. If no Playwright/browser tool is
visible, the agent should stop before the first visual write.

The tab strip on Card 3 shows the exact config file path and snippet for each
of the 15 supported clients. See [connect-clients.md](./connect-clients.md)
for full per-client details.

### HTTPS requirement

On a production site (or any site with `wp_get_environment_type()` returning
anything other than `'local'`) the endpoint should be served over HTTPS.
Basic Auth credentials sent over plain HTTP are trivially intercepted. Set
`WP_ENVIRONMENT_TYPE=local` in `wp-config.php` only on local-only dev
environments where HTTPS is unavailable.
