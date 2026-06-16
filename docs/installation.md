# Stonewright Installation

Stonewright has two parts:

- WordPress plugin: registers the `stonewright/*` abilities.
- Node companion: exposes local stdio MCP through `npx`, proxies the WordPress
  MCP endpoint, and runs guarded WP-CLI.

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
5. Generate an Application Password from **Stonewright > Configuration**. The
   MCP client authenticates with `username:application-password`.

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

Fastest MCP-client setup uses `npx`, so Windows, macOS, and Linux do not need a
shell wrapper, global install, or manual bridge:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.36/stonewright-companion-1.0.0-alpha.36.tgz"],
      "env": {
        "STONEWRIGHT_WP_URL": "http://mcp-test.local",
        "STONEWRIGHT_WP_ROOT": "/absolute/path/to/wordpress",
        "STONEWRIGHT_WP_APP_PASSWORD_AUTO": "local-only",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

After adding the server, call `stonewright-setup-profile`. It returns
copy-paste MCP config, platform checks, credential status, and notes for the
current machine. For local `.local` or `.test` sites, the companion can create
one Application Password through guarded WP-CLI and save it in the user profile.
`STONEWRIGHT_MCP_TOOL_PROFILE=essential` keeps startup compact while preserving
the general Stonewright fast paths for Elementor, Gutenberg, content-model, and
WP-CLI work.

Before the first WordPress task, verify the client tool list includes
`stonewright-context-bootstrap`. If that tool is missing, reload or fix the MCP
client config before continuing. Local agent skills, repository files, private
client config files, and manual JSON-RPC or
`/wp-json/stonewright/v1/abilities/run` shell calls are not substitutes for a
loaded Stonewright MCP server.

## Fast Build Workflow

For design-to-WordPress and Elementor work, start with one preflight call, then
use composite writes before small corrective edits:

1. `stonewright-workflow-preflight`
2. `stonewright-content-bulk-upsert-posts` for repeated posts, CPT rows, and
   custom fields.
3. `stonewright-elementor-v3-build-page-from-spec` for first-pass page or
   section rendering. Use `dry_run` before writing when the spec is generated.
4. `stonewright-elementor-v3-batch-mutate` for grouped Elementor add, update,
   move, and remove operations.

This keeps MCP sessions fast and token-efficient because Stonewright validates,
backs up, audits, measures timing, and writes related changes in a few guarded
calls.

From source:

```bash
cd /path/to/wp-content/plugins/stonewright/companion
npm install
npm run build
```

For MCP clients that use a local stdio server, configure:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.36/stonewright-companion-1.0.0-alpha.36.tgz"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.example.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

`STONEWRIGHT_WP_ROOT` is optional. Add it only when the companion should run
WP-CLI helper tools or discover LocalWP automatically. Use the absolute
WordPress install folder containing `wp-config.php`, not the Stonewright plugin
folder and not a URL.

Windows example: `D:\\Sites\\example\\app\\public`.

macOS example: `/Users/me/Sites/example/app/public`.

When Stonewright is installed through the Node companion MCP, the companion also
registers direct aliases named `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, `stonewright-wp-cli-run`, and
`stonewright-wp-cli-batch-run`. Those aliases run WP-CLI inside the companion
and do not require the WordPress-side HTTP bridge on port `8765`.

Most users can ignore the optional HTTP bridge. Use **Stonewright >
Configuration > Local WP-CLI bridge (advanced)** only when you deliberately run
a local bridge for WordPress-side `stonewright/wp-cli-*` abilities. The page
can generate a bridge token and copy matching launch env values.

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
| `STONEWRIGHT_WP_ROOT` | Optional absolute WordPress install folder containing `wp-config.php`; used for `cwd`, `--path`, and LocalWP discovery. |
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
testing, screenshots, or visual inspection. Restart the AI client after adding
Playwright so the tool list refreshes. If the MCP client cannot see a
browser/screenshot tool, the agent should stop before visual implementation and
ask the user to connect Playwright instead of building blind.

## Example Prompts

```text
Use Stonewright to implement the attached Figma design in Elementor V3. Start
with stonewright-context-bootstrap and stonewright-workflow-preflight, extract
layout, spacing, colors, typography, and responsive behavior, render with
stonewright-elementor-v3-build-page-from-spec, then use
stonewright-elementor-v3-batch-mutate for polish. Verify desktop, tablet, and
mobile screenshots against the design.
```

```text
Use Stonewright to create an ACF field group for Case Studies with client logo,
industry, challenge, solution, results metrics, testimonial, gallery, and CTA
fields. Attach it to the case-study post type, add three sample entries, and
verify fields are available for dynamic Elementor templates.
```

```text
Use Stonewright with CPT UI to create a Projects post type and Project Type
taxonomy. Add labels, archive support, featured images, REST visibility, and
sensible rewrite slugs. Then seed sample projects and build a responsive
archive layout that can be filtered by taxonomy.
```

## Privacy Boundary

Release ZIPs and the npm companion contain public Stonewright code, docs, and
built-in skills only. Site-specific memory, site skills, and custom
instructions live in that WordPress install and are returned only to authorized
MCP clients. Keep credentials and private site memory out of public issues,
commits, docs, release notes, and examples.

## Tool Names

WordPress ability names use slashes. MCP tool names use hyphens.

| WordPress ability | MCP tool |
|---|---|
| `stonewright/context-bootstrap` | `stonewright-context-bootstrap` |
| `stonewright/workflow-preflight` | `stonewright-workflow-preflight` |
| `stonewright/system-abilities-list` | `stonewright-system-abilities-list` |
| `stonewright/content-bulk-upsert-posts` | `stonewright-content-bulk-upsert-posts` |
| `stonewright/media-upload-batch` | `stonewright-media-upload-batch` |
| `stonewright/elementor-v3-capabilities-summary` | `stonewright-elementor-v3-capabilities-summary` |
| `stonewright/elementor-v3-build-page-from-spec` | `stonewright-elementor-v3-build-page-from-spec` |
| `stonewright/elementor-v3-batch-mutate` | `stonewright-elementor-v3-batch-mutate` |
| `stonewright/elementor-v3-apply-bundle` | `stonewright-elementor-v3-apply-bundle` |
| `stonewright/wp-cli-status` | `stonewright-wp-cli-status` |
| `stonewright/wp-cli-discover` | `stonewright-wp-cli-discover` |
| `stonewright/wp-cli-run` | `stonewright-wp-cli-run` |
| Companion setup profile | `stonewright-setup-profile` |

The complete command list is generated in
[`ability-truth-matrix.md`](ability-truth-matrix.md).

## First Smoke Test

1. Call `stonewright-ping`.
2. Confirm the MCP tool list includes `stonewright-context-bootstrap`. If it is
   missing, restart or reload the AI client and fix the Stonewright MCP config
   before WordPress work.
3. Call `stonewright-workflow-preflight` with:

```json
{
  "task": "Test Stonewright connection",
  "surface": "wordpress",
  "intent": "read"
}
```

4. Confirm the response includes `context_token`, `mode`, `auth_guidance`,
   `fast_path.task_profile`, `fast_path.recommended_mcp_tools`, and
   `fast_path.call_sequence`. For visual tasks, also confirm
   `fast_path.visual_build_gate`.
5. For tool-cap or token-sensitive clients, call `stonewright-tool-profile`
   with the same task, surface, and intent, then keep to the returned
   `recommended_mcp_tools` before broad ability discovery.
6. Call `stonewright-context-bootstrap` with:

```json
{
  "task": "Test Stonewright connection",
  "surface": "wordpress",
  "intent": "read"
}
```

7. Confirm the response includes `mcp_tool_naming`, `tool_profile_hint`,
   instructions, skills,
   memory, recommended external MCPs, `visual_quality_contract`,
   `visual_build_gate`, and required followups.
8. Call `stonewright-system-abilities-list` and confirm every row includes
   `name` and `mcp_tool_name`.
