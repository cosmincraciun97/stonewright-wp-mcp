# Configuration

The Configuration page is the first sub-page under the **Stonewright** menu
(`dashicons-hammer`, position 76). It owns three numbered cards: master enable,
Application Password generation, and MCP client connection.

Source: `plugin/includes/Admin/ConfigurationPage.php`

---

## Card 1 - Enable AI Abilities

### Master toggle

The `stonewright_enabled` option (boolean, default `false`) is a site-wide kill
switch. When it is `false`, the MCP server rejects inbound tool calls except for
the built-in `ping` ability, which always responds so clients can test
connectivity.

Flip the toggle, click **Save Settings**, and registered abilities become
available on the next tool-call attempt.

### Mode selector

Three modes are stored in the `stonewright_mode` option:

| Value | Behaviour |
|---|---|
| `development` | All abilities available; no confirmation required. |
| `staging` | Same as development; useful for labelling deployments. |
| `production-safe` | Destructive operations require a `confirmation_token` obtained from `stonewright/security-issue-confirmation-token`. Without the token, those calls are rejected before execution. |

### Essential tools mode

`stonewright_essential_tools_mode` defaults to enabled. It keeps MCP startup and
tool discovery compact by exposing the most common Stonewright fast-path tools
first. Turn it off only for specialist sessions that need the full registered
ability surface.

Agents should call `stonewright-tool-profile` after bootstrap or preflight when
the client has a tool cap or the user asks for token-efficient implementation.
The profile response keeps Elementor, Gutenberg, content-model, and WP-CLI
tasks on a compact set of batch-first tools. If a profile expects a disabled or
gated tool, the response includes `missing_profile_tools`, `missing_mcp_tools`,
and `recovery_hints` so the agent can recover without broad discovery loops.

### Local WP-CLI bridge (advanced)

The companion is a Node.js sidecar that handles WP-CLI, health checks, and the
optional MCP proxy. It writes to WordPress only through guarded WP-CLI commands.

Most users can skip this section. The setup note in Card 3 already runs
Stonewright through `npx` with the versioned GitHub release tarball, and direct companion
tools such as `stonewright-wp-cli-status`, `stonewright-wp-cli-discover`, and
`stonewright-wp-cli-run` do not need port `8765`.

Use **Local WP-CLI bridge (advanced)** only when you deliberately run a local
HTTP bridge for WordPress-side abilities such as `stonewright/wp-cli-run`.
Click **Generate token**, save settings, then copy **Developer launch values**
into the bridge process. The bridge token must match the saved token.

### Elementor V4 atomic

Checking `stonewright_elementor_v4_atomic` enables the experimental V4 renderer
and related abilities. It is off by default and only relevant on sites running
Elementor 3.18+.

---

## Card 2 - Application Password

AI clients authenticate using a WordPress Application Password sent as HTTP
Basic Auth with every request (`username:app-password`).

To generate one from the Configuration page:

1. Enter a required label for the client, such as `Claude Code laptop` or
   `Cursor`.
2. Click **Generate application password**.
3. Copy the displayed password immediately. WordPress only shows it once.
4. The generated password is embedded into the setup note in Card 3 for the
   current page load.

The page also lists existing Application Password names for the current user so
site owners can see whether a client credential already exists. Each row can be
revoked from this table. Passwords are created only for the currently logged-in
WordPress user.

---

## Card 3 - Connect MCP Client

### MCP endpoint URL

```text
{site_url}/wp-json/mcp/stonewright
```

For example: `https://example.com/wp-json/mcp/stonewright`.

### Recommended config block

Most local MCP clients can launch the companion through `npx` without a global
install:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.31/stonewright-companion-1.0.0-alpha.31.tgz"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://example.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

Use the WordPress URL, username, and Application Password from Cards 2 and 3.
The `STONEWRIGHT_MCP_TOOL_PROFILE=essential` env value keeps new MCP sessions
compact while preserving Stonewright fast-path tools.
The admin page also exposes per-client snippets for clients that use a slightly
different top-level key, such as VS Code's `servers`.

### Copyable setup note

The setup note is a short prompt for the current AI client. It includes the
site URL, MCP endpoint, username, generated Application Password when present,
the `npx` transport, and the required first Stonewright calls:
`stonewright-context-bootstrap` and `stonewright-workflow-preflight`.

The note also tells agents that `npx` downloads and runs the versioned GitHub
release tarball, and that Playwright MCP should be added for browser testing,
screenshots, and visual QA when the client does not already have browser tools.

### Examples

The **Examples** expander contains copyable real-world Stonewright prompts for
Elementor page builds, ACF field groups, CPT UI content models, Figma to
Elementor V3 implementation, WooCommerce catalog cleanup, and Gutenberg/FSE
updates.

### Browser MCP

Configure Playwright MCP separately when the agent needs browser testing,
screenshots, or visual inspection:

```bash
npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart the AI client after adding Playwright. If no Playwright/browser tool is
visible, the agent should stop before the first visual write.

See [connect-clients.md](./connect-clients.md) for per-client details.

## Site-Local Memory And Skills

Built-in skills shipped in `skills/` are public Stonewright assets. Skills,
memory, and custom instructions created in the admin UI are stored in the
current WordPress database and are not bundled into release ZIPs or the npm
companion. Keep credentials, private memory, and client-specific instructions
out of public examples, commits, issues, and release notes.

### HTTPS requirement

On a production site (or any site with `wp_get_environment_type()` returning
anything other than `'local'`) the endpoint should be served over HTTPS. Basic
Auth credentials sent over plain HTTP are trivially intercepted. Set
`WP_ENVIRONMENT_TYPE=local` in `wp-config.php` only on local-only dev
environments where HTTPS is unavailable.
