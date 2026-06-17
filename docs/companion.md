# Companion

The companion is a Node.js sidecar for local MCP transport, optional HTTP MCP
transport, proxying to the WordPress MCP endpoint, health checks, optional
HTTP proxying, and guarded WP-CLI.

## Endpoints

- `GET /health`
- `POST /mcp`
- `POST /wp-cli/status`
- `POST /wp-cli/discover`
- `POST /wp-cli/run`
- `POST /wp-cli/batch`
- `POST /wp-cli/job-start`
- `POST /wp-cli/job-status`

## WP-CLI Safety

The companion runs WP-CLI through `execFile` with argv tokens. It blocks
arbitrary PHP and shell entry points: `wp eval`, `wp eval-file`, `wp shell`,
`wp package`, `--exec`, and `--require`.

Use `STONEWRIGHT_WP_ROOT` or `STONEWRIGHT_WP_ALLOWED_ROOTS` to restrict working
directories. `STONEWRIGHT_WP_ROOT` is optional; when set, it must be the
absolute WordPress install folder containing `wp-config.php`, not the plugin
folder or site URL.
Local and server-side companion WP-CLI work also needs PHP CLI with
mysqli/MySQL enabled, `wp` or `wp-cli.phar`, and a running database reachable
from `wp-config.php`. Remote HTTP MCP sites do not require local PHP/MySQL
unless the companion is expected to run WP-CLI for that site. If
`stonewright-wp-cli-status` reports no loaded `php.ini`, treat that as a local
runtime setup warning before running WordPress-loading WP-CLI commands.

## Configuration

| Variable | Description |
|---|---|
| `COMPANION_BEARER_TOKEN` | Required outside local/dev mode |
| `COMPANION_ALLOWED_ORIGINS` | Required outside local/dev mode |
| `STONEWRIGHT_HTTP_ENABLE` | Set to `1` to enable the optional HTTP bridge; stdio MCP does not need it |
| `PORT` | Port for the optional HTTP transport; ignored unless `STONEWRIGHT_HTTP_ENABLE=1` or `STONEWRIGHT_HTTP_REQUIRED=1` |
| `STONEWRIGHT_HTTP_REQUIRED` | Set to `1` only when an HTTP bridge bind failure should make startup fail; this also enables the bridge |
| `STONEWRIGHT_WP_URL` | WordPress site URL; the companion derives `/wp-json/mcp/stonewright` when `STONEWRIGHT_MCP_URL` is absent |
| `STONEWRIGHT_WP_USERNAME` | WordPress username for Application Password auth |
| `STONEWRIGHT_WP_APP_PASSWORD` | WordPress Application Password |
| `STONEWRIGHT_MCP_TOOL_PROFILE` | Optional compact client-visible tool surface. Defaults to `essential` for fast startup with Stonewright fast-path tools; use `low-tools` for strict tool-cap clients; aliases like `antigravity`, `gemini`, `elementor`, `design`, `acf`, `cpt-ui`, `fse`, and `wp cli` normalize to canonical compact profiles; set `full` to proxy every WordPress MCP tool |
| `STONEWRIGHT_MCP_URL` | Explicit WordPress MCP endpoint override |
| `WP_API_USERNAME` | Legacy alias for `STONEWRIGHT_WP_USERNAME` |
| `WP_API_PASSWORD` | Legacy alias for `STONEWRIGHT_WP_APP_PASSWORD` |
| `STONEWRIGHT_MCP_AUTHORIZATION` | Optional full Authorization header override |
| `STONEWRIGHT_CREDENTIAL_STORE` | Per-project JSON file for a saved Application Password fallback |
| `STONEWRIGHT_CREDENTIAL_DIR` | Directory for generated per-project credential files |
| `STONEWRIGHT_WP_APP_PASSWORD_AUTO` | Auto-create missing local credentials through guarded WP-CLI; default `local-only` |
| `STONEWRIGHT_WP_APP_PASSWORD_NAME` | Label used when auto-creating the WordPress Application Password |
| `STONEWRIGHT_WP_CLI_BIN` | WP-CLI executable; defaults to `wp` |
| `STONEWRIGHT_WP_CLI_PHP_BIN` | PHP executable for running `wp-cli.phar`; set with `STONEWRIGHT_WP_CLI_PHAR_PATH` for explicit local runtimes |
| `STONEWRIGHT_WP_CLI_PHAR_PATH` | Explicit `wp-cli.phar` path |
| `STONEWRIGHT_WP_CLI_PHP_INI` | PHP ini file for local PHP extensions such as mysqli/MySQL |
| `STONEWRIGHT_WP_ROOT` | Optional absolute WordPress install folder containing `wp-config.php`; default WP-CLI working directory |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | Comma- or semicolon-separated allowed roots |
| `MCP_PROXY_TARGET` | Optional upstream MCP server |

## Plugin Integration

The normal `npx` setup uses the versioned GitHub release tarball and does not
need the WordPress-side HTTP bridge. Use the bridge only when a site
deliberately wants WordPress-side `stonewright/wp-cli-*` abilities to call a
local companion HTTP process.

If a `.env` file sets `PORT`, stdio MCP ignores it unless
`STONEWRIGHT_HTTP_ENABLE=1` or `STONEWRIGHT_HTTP_REQUIRED=1` is also set. Set
`STONEWRIGHT_HTTP_REQUIRED=1` only for bridge-only deployments where a bind
failure should stop startup.

For a human administrator with WP-CLI already configured, bridge options can be
set from shell:

```bash
wp option update stonewright_companion_url http://127.0.0.1:8765
wp option update stonewright_companion_token change-this-long-random-token
```

Then use Stonewright abilities:

- `stonewright-context-bootstrap`
- `stonewright-workflow-preflight`
- `stonewright-tool-profile`
- `stonewright-skills-get`
- `stonewright-wordpress-mcp-status`
- `stonewright-wp-cli-status`
- `stonewright-wp-cli-discover`
- `stonewright-wp-cli-run`
- `stonewright-wp-cli-batch-run`
- `stonewright-wp-cli-job-start`
- `stonewright-wp-cli-job-status`

If the HTTP bridge is not running, use the direct companion MCP tools instead:

- `companion_wp_cli_status`
- `companion_wp_cli_discover`
- `companion_wp_cli_run`
- `companion_wp_cli_batch_run`

Do not recover by running `wp cli info`, `wp plugin activate`,
`wp option update`, or other `wp` commands in a normal shell. Use the
Stonewright MCP tools above so the companion can apply path checks, command
guards, and tokenized argv execution.

Use `stonewright-wp-cli-batch-run` for repeated post/meta/term/media/option
operations, especially when values contain non-ASCII text. It accepts JSON
arrays of argv tokens, preserves UTF-8, and avoids large inline PowerShell or
Node scripts where shell encoding can corrupt diacritics.
Use `stonewright-wp-cli-job-start` plus `stonewright-wp-cli-job-status` only
for long imports, cache rebuilds, plugin operations, or large batches that
should not block a single MCP request.

Call `stonewright-setup-profile` once after connecting. Its `first_calls` and
`tool_visibility_checks` fields show the compact startup path: bootstrap,
preflight, optional profile verification, skill playbook retrieval, WordPress
MCP proxy status, and direct WP-CLI aliases. Use `fast_path.tool_profile` from
`stonewright-workflow-preflight` before making a separate
`stonewright-tool-profile` call.
The companion also sets compact MCP server instructions at handshake time. They
tell clients to call setup/profile/bootstrap tools first, use direct
`stonewright-wp-cli-*` recovery tools, keep low-tools sessions compact, and
avoid shell WP-CLI or arbitrary PHP workarounds before the first tool call.

If `stonewright-context-bootstrap` or other proxied WordPress tools are missing,
call `stonewright-wordpress-mcp-status`. The companion keeps this diagnostic,
`stonewright-setup-profile`, and direct `stonewright-wp-cli-*` tools available
even when the WordPress MCP endpoint cannot be reached.
Do not inspect private AI-client config files, parse repository files as the
tool list, create scratch scripts such as `query-mcp.js` or `run-ability.js`,
create helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, launch the companion through ad hoc
scripts such as `query-local-stonewright.js`, create action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, inspect plugin/companion
source to reverse-engineer tool schemas, hand-roll JSON-RPC, or call
`/wp-json/stonewright/v1/abilities/run` from shell as a substitute for the live
Stonewright MCP tools.
When the endpoint connects, the status response reports `startup_ready`,
`startup_missing_tool_names`, `local_recovery_tool_names`, and
`local_tool_names` so agents can see whether bootstrap, preflight,
skill-playbook, and direct WP-CLI tools are ready. It also reports
`profile_expected_tool_count`, `client_visible_expected_tool_count`, and
`profile_missing_tool_names` for the selected compact profile even when the
WordPress MCP endpoint cannot be reached.
Both setup and status responses include `tool_inventory`, a compact grouped map
of first-call, diagnostic, direct WP-CLI, long-running WP-CLI, and proxied
profile tools. Use it before broad tool discovery in token-sensitive sessions.
Setup responses also include `wp_cli_environment`, which tells agents that local
WP-CLI dependencies are required only for local/server-side companion WP-CLI
work and that remote HTTP MCP sites do not need local PHP/MySQL by default.

For new stdio sessions, the companion defaults to
`STONEWRIGHT_MCP_TOOL_PROFILE=essential`. It proxies only the compact
Stonewright fast-path surface while keeping direct `stonewright-wp-cli-*` tools
local and deduplicated. Set it to `full` when a specialist session needs every
registered WordPress MCP tool.
Use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` for Antigravity, Gemini API, or
other strict tool-cap clients. It keeps the total client-visible surface under
30 tools by hiding legacy duplicate aliases while direct WP-CLI recovery tools
remain local, including batch runs and background jobs.
Common aliases normalize before filtering, so `antigravity`, `gemini`,
`elementor`, `design`, `acf`, `cpt-ui`, `fse`, and `wp cli` select the closest
compact canonical profile instead of silently falling back to `essential`.

## Persistent Application Passwords

Application Passwords are one-time-display credentials in WordPress. The
companion therefore supports a local per-project credential store. If env
credentials are missing, it reads the saved credential for the current
Stonewright MCP URL and project root. For local development hosts it can create
the password once through `stonewright-wp-cli-run`-equivalent guarded WP-CLI
execution, save it outside the repo by default, and reuse it in future agent
sessions.

Do not store these credentials in Stonewright site memory, public docs, commits,
or admin instructions.
