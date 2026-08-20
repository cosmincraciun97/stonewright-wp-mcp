# Stonewright Installation

Stonewright has two parts:

- WordPress plugin: registers the `stonewright/*` abilities.
- Node companion: exposes local stdio MCP through `npx`, proxies the WordPress
  MCP endpoint, exposes php-execute (**full** profile only), and runs tokenized WP-CLI.

**Local stdio** means the AI client starts the companion on the user's computer
and exchanges MCP messages with that local process through standard
input/output. It is required for Direct mode and local WP-CLI. **Remote
Streamable HTTP** connects the AI client straight to the WordPress plugin over
HTTPS and does not run or require a local companion.

## Requirements

- WordPress 6.7+
- PHP 8.1+
- Composer 2 for source installs
- Node.js 20+ for the optional companion
- WP-CLI for fast local WordPress work. The companion can use `wp` from `PATH`
  or auto-detect LocalWP's `wp-cli.phar` plus PHP on Windows/macOS.
- OAuth browser access (recommended) or a WordPress Application Password

## Default Plugin setup

1. Download the current `stonewright-<version>.zip`, upload it in **Plugins > Add New > Upload Plugin**, and activate Stonewright.
2. Open **Stonewright → Setup**, enable AI Abilities, and connect the client through the guided setup.
3. Fully restart the client and run the generated connection verification. A parseable config file is not runtime proof.
4. Confirm `stonewright-task-start` is visible and call it first with the real task. Keep `essential` for normal work; `bootstrap` is startup diagnostics only.

The Setup page offers OAuth when the client supports remote Streamable HTTP and Application Password/local stdio when a companion is required. Private credentials remain in the browser approval flow, OS credential store, environment reference, or private client configuration.

The release ZIP includes production Composer dependencies.

Endpoint:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

## Advanced: install from source

```bash
cd /path/to/wp-content/plugins
git clone https://github.com/cosmincraciun97/stonewright-wp-mcp.git stonewright
cd stonewright/plugin
composer install --no-dev
wp plugin activate stonewright
```

The `wp plugin activate stonewright` command is for a human source install on a
machine with WP-CLI already configured. Runtime agents should use Stonewright
MCP tools for WordPress work instead of shelling out to `wp ...`.

## Companion

The companion is optional. Use it when your MCP client needs a local stdio
server, WordPress MCP proxying, **Direct mode** (core REST without the plugin),
LocalWP/WP-CLI discovery, or the tokenized `stonewright-wp-cli-*` tools.

### Direct mode (without the WordPress plugin)

Set Application Password credentials and point at the site URL. With
`STONEWRIGHT_MODE=auto` (default), the companion probes
`/wp-json/mcp/stonewright`:

- endpoint present → plugin proxy (full Stonewright abilities)
- HTTP 404 → Direct mode (101 tools in the current full surface)

Force either path with `STONEWRIGHT_MODE=direct` or `STONEWRIGHT_MODE=plugin`.
For an installed-plugin connection, prefer the alias-based installer with
`--mode plugin-only`; `auto` is appropriate only when intentional Direct
fallback is part of the connection policy.

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.example.com",
        "STONEWRIGHT_WP_USERNAME": "admin",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MODE": "auto"
      }
    }
  }
}
```

Replace `VERSION` in every package URL with the exact release version without a
leading `v`.

For a guided Direct setup, run:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright-companion init
```

`init` is a compatibility alias for `connect add`. For multi-site installs:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias site-a \
  --url https://site-a.example \
  --username editor \
  --password-env STONEWRIGHT_TMP_APP_PASSWORD \
  --env staging \
  --mode plugin-only \
  --plugin-enabled yes \
  --wp-mode staging \
  --wp-surface essential \
  --elementor-v4 yes \
  --client cursor \
  --browser-provider playwright \
  --browser-scan-consent granted \
  --browser-install-consent denied
```

Prefer interactive password entry or `--password-env VAR` (avoid `--password` on
argv). Schema v2 stores only metadata and a `credential_ref` in
`~/.stonewright/sites.json`; secrets live in the OS credential store (or
`env://VAR`). Client config sets `STONEWRIGHT_SITE_ALIAS` only — the companion
resolves URL and credentials for that alias at startup. The alias is
authoritative and replaces stale inherited `STONEWRIGHT_WP_*` values.

If the alias already exists, reuse its saved credential and change the mode or
client binding without creating a duplicate:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect repair site-a --client cursor --mode plugin-only
```

Secure v1 migration collapses repeated aliases for one canonical
URL/environment before v2 is written. It keeps the configured default alias
for that group, or the first stable alias when none is default.

The WordPress Step 1 choices and browser consent are retained per site/client;
`playwright` (or compatibility value `recommended`) records Playwright as the
external default without embedding it;
unset browser choices cause the agent to ask once. Scan consent never implies
install consent. After restarting the client, prove the saved entry end to end:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect verify site-a --client cursor
```

Verification spawns the configured stdio server, confirms the active alias and
companion version when observable, lists tools, calls `stonewright-task-start`
and status, checks required tools, and stores a surface digest. The receipt
prints safe runtime proof, including `refresh_required_tool_names`; a non-empty
list fails verification. A parseable client config is only a structural check.

First call in Direct mode: `stonewright-task-start`. Then use
`stonewright-site-discover`; it lists REST namespaces,
post types, taxonomies, detected plugins (Elementor/Woo/ACF signals), and
plugin-only capabilities that remain unavailable until you install Stonewright.

| Capability | Direct | With plugin |
|---|---|---|
| Content / media / taxonomies | yes | yes |
| Menus, FSE templates, global styles | yes | yes |
| Settings, plugins, themes, users, search | yes | yes |
| WP-CLI (local companion) | yes | yes |
| PHP execute, Elementor engines, DesignSpec | no | yes |
| Custom PHP/CSS/JS/HTML write (approval grant) | no | yes (human grant) |
| Memory and skills store | yes (local `~/.stonewright/`) | yes (site-hosted) |
| Production-safe confirmation tokens | no | yes |

Remote destructive Direct tools require `confirm: true` by default
(`STONEWRIGHT_DIRECT_WRITES=confirm`). Local `.local`/`.test` hosts default to
`on`. Optional per-site `disabledTools` in `~/.stonewright/sites.json` can block
writes on production aliases.

Remote sites do not need Node when the AI client supports Streamable HTTP.
Copy the **Remote HTTP** snippet from **Stonewright > Configuration**; it points
directly at `/wp-json/mcp/stonewright` and authenticates with the dedicated
WordPress Application Password. The setup diagnostics panel blocks a green
status when HTTPS, Application Passwords, the endpoint, or the 20-tool budget
is missing. **Stonewright → Troubleshoot** runs the same probes in place with a
loading state; see [Troubleshoot](admin/troubleshoot.md).

Fastest MCP-client setup uses the alias installer, so Windows, macOS, and Linux
do not need a shell wrapper, global install, duplicate server block, or secret
inside the client file:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias local-site --url http://local-site.test --username editor \
  --env local --mode direct-only --client cursor
```

After adding the server, perform the **client-specific restart / MCP reload**,
call `stonewright-task-start` first, then call `stonewright-setup-profile`. It returns copy-paste MCP config,
platform checks, credential status, and notes for the current machine. Paste
blocks stay credential-free; put real secrets only in private user-level client
config. For local `.local` or `.test` sites, the companion can create one
Application Password through tokenized WP-CLI and save it in the user profile.
Do not point IDE MCP configs at `node companion/dist/index.js`; `dist` is a
source build artifact and is intentionally not committed. Use the `npx` release
tarball above, or for source development use
`npm --prefix <repo>/companion run mcp:source`.
Do not configure generic WordPress MCP adapters such as
`@automattic/mcp-wordpress-remote` as the `stonewright` server. Use the
Stonewright companion so setup, status, compact profiles, php-execute (full
profile only), and WP-CLI tools stay visible even while the WordPress endpoint
is being fixed.
`STONEWRIGHT_MCP_TOOL_PROFILE=essential-static` is the safe default for an
unknown client. It exposes a bounded useful catalog plus permanent recovery
gateways without depending on the client to process `tools/list_changed`.
Known clients generated from WordPress Setup normally use `essential`, the
bounded real working profile. `stonewright-task-start` then activates the
compact task profile for the current session in Plugin or Direct mode. Use
`bootstrap` only for the smallest startup diagnostic; it is not a permanent
working profile. Never select `full` implicitly.
Use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` for Antigravity, Gemini API, or
other strict tool-cap clients. It keeps the client-visible startup surface under
30 tools. Aliases such as `antigravity`, `gemini`,
`elementor`, `design`, `acf`, `cpt-ui`, `fse`, and `wp cli` normalize to the
closest compact profile, so agents do not need exact canonical profile names to
avoid broad discovery.

For Antigravity 2.0, Antigravity IDE, and Antigravity CLI, use
`~/.gemini/config/mcp_config.json` and the dedicated
[Antigravity setup guide](getting-started/antigravity.md).
For Codex CLI, use [`--client codex-cli`](getting-started/codex.md) and
`~/.codex/config.toml` (or a trusted project `.codex/config.toml`). For Codex
in ChatGPT Desktop, use [`--client chatgpt-desktop`](getting-started/codex.md)
and `~/Library/Application Support/ChatGPT/mcp_config.json`. Do not paste CLI
TOML into the Desktop JSON file. Installer-managed Codex CLI entries use
alias-specific TOML tables such as `[mcp_servers.stonewright-site-a]`.

Before the first WordPress task, verify the client tool list includes
`stonewright-task-start` (canonical) or compatibility
`stonewright-context-bootstrap`. If both tools are missing, reload or fix the
MCP client config before continuing. Always start real work with
`stonewright-task-start`.
Local agent skills, repository files, private
client config files, scratch scripts such as `query-mcp.js` or
`run-ability.js`, helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, direct companion shell launch
scripts such as `query-local-stonewright.js`, action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, plugin/companion
source-code spelunking to reverse-engineer tool schemas, hand-rolled JSON-RPC
calls, and
`/wp-json/stonewright/v1/abilities/run` shell calls are not substitutes for a
loaded Stonewright MCP server.
After installing a new Stonewright release or syncing local skills, restart the
MCP client and rerun `stonewright-setup-profile` plus
`stonewright-wordpress-mcp-status`. Compare `companion_version`,
`expected_companion_package`, and `refresh_required_tool_names` with the visible
tool list. Missing refresh-required tools mean the client is still using a stale
companion process or cached tool surface.

For component-by-component upgrade steps and state-preservation guarantees,
see [Updating Stonewright](updates.md).

Do not recover from a missing tool by running `wp cli info`, `wp plugin
activate`, `wp option update`, or other `wp` commands in a normal shell, and do
not switch to another PHP adapter. Use the live Stonewright MCP tools:
`stonewright-wordpress-mcp-status`, `stonewright-php-execute`,
`stonewright-wp-cli-status`, `stonewright-wp-cli-discover`,
`stonewright-wp-cli-run`, `stonewright-wp-cli-batch-run`,
`stonewright-wp-cli-job-start`, `stonewright-wp-cli-job-status`, or
`stonewright-wp-cli-install`.

## Fast Build Workflow

For design-to-WordPress and Elementor work, start with one preflight call, then
use composite writes before small corrective edits:

1. `stonewright-task-start`
2. `stonewright-theme-builder-apply-template` for Elementor Theme Builder
   templates and display conditions.
3. `stonewright-content-model-loop-grid-flow` for admin-editable repeated
   sections backed by CPT/ACF and Elementor Loop Grid.
4. `stonewright-content-bulk-upsert-posts` for repeated posts, CPT rows, and
   custom fields.
5. `stonewright-elementor-v3-build-page-from-spec` for first-pass page or
   section rendering. Use `dry_run` before writing when the spec is generated.
6. `stonewright-elementor-v3-batch-mutate` for grouped Elementor add, update,
   move, and remove operations.

This keeps MCP sessions fast and token-efficient because Stonewright validates,
backs up, audits, measures timing, and writes related changes in a few compact
calls.

From source:

```bash
cd /path/to/wp-content/plugins/stonewright/companion
npm install
npm run build
```

For MCP clients that use local stdio, use the versioned `stonewright connect
add` flow above. It writes an alias-specific entry with the requested mode and
tool profile; do not duplicate it with a generic manual block.

`STONEWRIGHT_WP_ROOT` is optional. Add it only when the companion should run
WP-CLI helper tools or discover LocalWP automatically. Use the absolute
WordPress install folder containing `wp-config.php`, not the Stonewright plugin
folder and not a URL.

Windows example: `D:\\Sites\\example\\app\\public`.

macOS example: `/Users/me/Sites/example/app/public`.

When Stonewright is installed through the Node companion MCP, the companion also
registers direct aliases named `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, `stonewright-wp-cli-run`,
`stonewright-wp-cli-batch-run`, `stonewright-wp-cli-job-start`, and
`stonewright-wp-cli-job-status`. Those aliases run WP-CLI inside the companion
and do not require the WordPress-side HTTP bridge on port `8765`.

For stdio MCP clients, leave `PORT` unset. A stale `.env` `PORT` is ignored by
stdio startup unless `STONEWRIGHT_HTTP_ENABLE=1` or
`STONEWRIGHT_HTTP_REQUIRED=1` is also set. Set `STONEWRIGHT_HTTP_REQUIRED=1`
only when an HTTP bridge bind failure should fail startup.

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

Stonewright does not include browser, screenshot, or visual-review tools. Before
browser work, the agent asks once whether to use Playwright (recommended),
another connected browser provider, or none. It must ask permission before
scanning client tools/private config and ask separately before installing or
configuring a missing provider. After approval, add Playwright next to
Stonewright:

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

Restart the AI client after adding Playwright so the tool list refreshes. If the
selected browser tool is not visible, the agent stops before visual
implementation. Browser automation is a verification or explicitly approved
dashboard-interaction path; it never bypasses Stonewright's custom-code
dry-run/approval, backup, permission, or confirmation gates.

## Example Prompts

```text
Use Stonewright to implement the attached Figma design in Elementor V3. Start
with stonewright-task-start, read stonewright-design-direction-brief once,
extract one shallow page/section manifest and one reusable token table, then
normalize each section's layout, spacing, colors, typography, assets, and
responsive measurements into DesignEvidence. Render with
stonewright-elementor-v3-build-page-from-spec, then use
stonewright-elementor-v3-batch-mutate for polish. Verify desktop, tablet, and
mobile screenshots against the same measured Figma frames.
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
| `stonewright/task-start` | `stonewright-task-start` |
| `stonewright/context-bootstrap` | `stonewright-context-bootstrap` |
| `stonewright/workflow-preflight` | `stonewright-workflow-preflight` |
| `stonewright/system-abilities-list` | `stonewright-system-abilities-list` |
| `stonewright/content-bulk-upsert-posts` | `stonewright-content-bulk-upsert-posts` |
| `stonewright/media-upload-batch` | `stonewright-media-upload-batch` |
| `stonewright/elementor-v3-capabilities-summary` | `stonewright-elementor-v3-capabilities-summary` |
| `stonewright/elementor-v3-get-kit-globals` | `stonewright-elementor-v3-get-kit-globals` |
| `stonewright/elementor-v3-build-page-from-spec` | `stonewright-elementor-v3-build-page-from-spec` |
| `stonewright/elementor-v3-batch-mutate` | `stonewright-elementor-v3-batch-mutate` |
| `stonewright/elementor-v3-apply-bundle` | `stonewright-elementor-v3-apply-bundle` |
| `stonewright/wp-cli-status` | `stonewright-wp-cli-status` |
| `stonewright/wp-cli-discover` | `stonewright-wp-cli-discover` |
| `stonewright/wp-cli-run` | `stonewright-wp-cli-run` |
| `stonewright/wp-cli-batch-run` | `stonewright-wp-cli-batch-run` |
| `stonewright/wp-cli-job-start` | `stonewright-wp-cli-job-start` |
| `stonewright/wp-cli-job-status` | `stonewright-wp-cli-job-status` |
| Companion setup profile | `stonewright-setup-profile` |

The complete command list is generated in
[`ability-truth-matrix.md`](ability-truth-matrix.md).

## First Smoke Test

1. Call `stonewright-ping`.
2. Confirm the MCP tool list includes `stonewright-context-bootstrap`. If it is
   missing, restart or reload the AI client and fix the Stonewright MCP config
   before WordPress work. Do not inspect private client config files, create
   scratch scripts, create helper JSON argument files, launch the companion
   through ad hoc shell scripts, create action scripts, inspect
   plugin/companion source to reverse-engineer tool schemas, hand-roll JSON-RPC,
   or call the REST ability runner from shell as recovery.
3. Call `stonewright-task-start` with:

```json
{
  "task": "Test Stonewright connection",
  "surface": "wordpress",
  "intent": "read"
}
```

4. Confirm the compact response includes `context_token`, `mode`,
   `fast_path.task_profile`, `fast_path.next_tools`, context refs, and the
   hashed `fast_path.visual_build_gate` for visual tasks.
5. For tool-cap or token-sensitive clients, call `stonewright-tool-profile`
   with the same task, surface, and intent, then keep to the returned
   `recommended_mcp_tools` before broad ability discovery.
6. Call `stonewright-context-bootstrap` only when diagnosing the compatibility
   bootstrap or inspecting its full instruction contract:

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
