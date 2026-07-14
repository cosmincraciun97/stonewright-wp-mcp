# @stonewright/companion

Node 20+ companion for the Stonewright WordPress MCP plugin.

The companion provides:

- stdio MCP transport for local clients
- stdio proxy for the Stonewright WordPress MCP endpoint
- optional Streamable HTTP transport at `POST /mcp`
- health checks at `GET /health`
- tokenized WP-CLI execution for WordPress implementation and debugging
- optional MCP HTTP proxy

The companion does not call WordPress REST write endpoints. WP-CLI changes go
through tokenized commands run with `execFile`, never shell strings. Use
`stonewright-php-execute` for direct WordPress runtime snippets; WP-CLI PHP and
shell entry points remain blocked: `wp eval`, `wp eval-file`, `wp shell`,
`wp package`, `--exec`, and `--require`.

MIT License.

## Install

Fast path for MCP clients:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.65/stonewright-companion-1.0.0-alpha.65.tgz", "stonewright-mcp"],
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

After the MCP server starts, call `stonewright-setup-profile` once. It returns
the same config shape plus platform checks, credential status, and notes for
Windows, macOS, and Linux. Use its `first_calls` and
`tool_visibility_checks` fields to verify `stonewright-context-bootstrap`,
`stonewright-workflow-preflight`, `stonewright-skills-get`,
`stonewright-wordpress-mcp-status`, and direct WP-CLI aliases are visible before
real work. Use `fast_path.tool_profile` from workflow preflight before making a
separate `stonewright-tool-profile` call.
After every Stonewright release or local skill sync, restart the MCP client and
rerun `stonewright-setup-profile` plus `stonewright-wordpress-mcp-status`.
Check `companion_version`, `expected_companion_package`, and
`refresh_required_tool_names`; if any required tool is missing from the client
tool list, the client is still running an old companion or stale MCP cache.
If `stonewright-context-bootstrap` is not visible, stop WordPress work and
reload or fix the MCP client config. Do not inspect private AI-client config
files, create scratch scripts such as `query-mcp.js` or `run-ability.js`,
create helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, launch the companion through ad hoc
scripts such as `query-local-stonewright.js`, create action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, inspect plugin/companion
source to reverse-engineer tool schemas, hand-roll JSON-RPC, or call the REST
ability runner from shell as an MCP workaround.
Do not point IDE MCP configs at `node companion/dist/index.js`; `dist` is a
source build artifact and is intentionally not committed. Use the `npx` release
tarball above, or for source development use
`npm --prefix <repo>/companion run mcp:source`.
Do not configure generic WordPress MCP adapters such as
`@automattic/mcp-wordpress-remote` as the `stonewright` server. Use the
Stonewright companion so setup, status, compact profiles, php-execute, and
WP-CLI tools stay visible even while the WordPress endpoint is being fixed.
The companion also publishes compact MCP handshake instructions, so clients get
the first-call, recovery, low-tools, php-execute, and WP-CLI routing rules
before any tool is called.
For Antigravity, Gemini API, or other strict tool-cap clients, set
`STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` before startup. It keeps the total
client-visible tool surface under 30 by hiding legacy duplicate aliases while
the canonical `stonewright-wp-cli-*` recovery tools remain local.

From a GitHub release:

```bash
npm install -g ./stonewright-companion-<version>.tgz
```

From source:

```bash
cd companion
npm install
npm run build
npm run mcp:source
```

## Configure

```bash
cp .env.example .env
```

| Variable | Required | Description |
|---|---|---|
| `COMPANION_BEARER_TOKEN` | recommended | Protects the HTTP transport |
| `COMPANION_ALLOWED_ORIGINS` | recommended | Comma-separated allowed origins |
| `STONEWRIGHT_HTTP_ENABLE` | optional | Set to `1` to enable the optional HTTP bridge; stdio MCP does not need this |
| `PORT` | with HTTP bridge | Port for the optional HTTP transport; ignored unless `STONEWRIGHT_HTTP_ENABLE=1` or `STONEWRIGHT_HTTP_REQUIRED=1` |
| `STONEWRIGHT_HTTP_REQUIRED` | optional | Set to `1` only when the HTTP bridge must start or startup should fail; this also enables the bridge |
| `STONEWRIGHT_WP_URL` | recommended for stdio | WordPress site URL; the companion derives `/wp-json/mcp/stonewright` |
| `STONEWRIGHT_WP_USERNAME` | with `STONEWRIGHT_WP_URL` | WordPress username for Application Password auth |
| `STONEWRIGHT_WP_APP_PASSWORD` | with `STONEWRIGHT_WP_URL` | WordPress Application Password |
| `STONEWRIGHT_MCP_TOOL_PROFILE` | optional | Compact client-visible tool surface; defaults to `essential` for fast startup; use `low-tools` for strict tool-cap clients; aliases like `antigravity`, `gemini`, `elementor`, `design`, `acf`, `cpt-ui`, `fse`, and `wp cli` normalize to canonical compact profiles; set `full` for every WordPress MCP tool |
| `STONEWRIGHT_MCP_URL` | optional | Explicit WordPress MCP endpoint override |
| `WP_API_USERNAME` | optional legacy alias | Alias for `STONEWRIGHT_WP_USERNAME` |
| `WP_API_PASSWORD` | optional legacy alias | Alias for `STONEWRIGHT_WP_APP_PASSWORD` |
| `STONEWRIGHT_MCP_AUTHORIZATION` | optional | Full Authorization header override for the WordPress MCP endpoint |
| `STONEWRIGHT_CREDENTIAL_STORE` | optional | Per-project JSON file for a saved Application Password fallback |
| `STONEWRIGHT_CREDENTIAL_DIR` | optional | Directory for generated per-project credential files |
| `STONEWRIGHT_WP_APP_PASSWORD_AUTO` | optional | `local-only` by default; set `never` to disable or `always` to permit remote auto-generation |
| `STONEWRIGHT_WP_APP_PASSWORD_NAME` | optional | Label used when auto-creating the WordPress Application Password |
| `STONEWRIGHT_WP_CLI_BIN` | optional | WP-CLI executable path; defaults to `wp` |
| `STONEWRIGHT_WP_ROOT` | optional | Absolute WordPress install folder containing `wp-config.php`; default WP-CLI working directory |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | optional | Comma- or semicolon-separated allowed working roots |
| `MCP_PROXY_TARGET` | optional | Upstream MCP server URL to proxy to |
| `MCP_PROXY_TOKEN` | optional | Bearer token for the proxy target |

## Run From Source

```bash
npm run build
npm start
```

The companion always starts stdio MCP. For normal MCP-client stdio use, leave
`PORT` unset. If a `.env` file sets `PORT`, stdio startup ignores it unless
`STONEWRIGHT_HTTP_ENABLE=1` or `STONEWRIGHT_HTTP_REQUIRED=1` is also set.
Set `STONEWRIGHT_HTTP_REQUIRED=1` only for deployments where the HTTP bridge
must be available or startup should fail.

## Persistent WordPress Credentials

WordPress shows an Application Password only once. If
`STONEWRIGHT_WP_APP_PASSWORD` is not set, the companion looks for a saved
per-project credential in
`STONEWRIGHT_CREDENTIAL_STORE` or in the Stonewright user credential directory.
For local development hosts (`localhost`, `127.0.0.1`, `.local`, `.test`), the
companion can create one Application Password through tokenized WP-CLI, save it,
and reuse it in future agent sessions.

Env credentials still win. Set `STONEWRIGHT_WP_APP_PASSWORD_AUTO=never` to
disable generation, or `always` to allow generation for non-local sites.

Most users do not need the HTTP bridge. Standard MCP clients should launch the
companion with the versioned GitHub release tarball shown by the WordPress admin.
Use the WordPress admin
**Local WP-CLI bridge (advanced)** controls only when you deliberately run the
optional HTTP bridge for WordPress-side WP-CLI abilities.

## WP-CLI Auto-Bootstrap

The companion automatically ensures WP-CLI is available at startup using this
resolution chain (first match wins):

1. **`STONEWRIGHT_WP_CLI_PHP_BIN` + `STONEWRIGHT_WP_CLI_PHAR_PATH`** — run a specific phar through a specific PHP.
2. **`STONEWRIGHT_WP_CLI_BIN`** — use this exact binary (`wp` on PATH, or a full path).
3. **LocalWP near the WordPress root** — prefer the phar bundled with the local site environment.
4. **LocalWP common locations** — scans `%APPDATA%`, `%LOCALAPPDATA%`, `%PROGRAMFILES%`,
   and `~/Library/Application Support` for LocalWP's bundled PHP and `wp-cli.phar`.
5. **Companion cache** — on `npm install` (via the `postinstall` script) **and** at each
   startup, downloads the official `wp-cli.phar` into:
   - Windows: `%LOCALAPPDATA%\Stonewright\wp-cli\wp-cli.phar`
   - macOS/Linux: `~/.stonewright/wp-cli/wp-cli.phar`

**No manual WP-CLI installation is required** for most setups. The download is
idempotent — if the phar already exists it is reused without re-downloading.

### MCP tools

- `stonewright-wp-cli-status` — check availability and show diagnostic info
- `stonewright-wp-cli-discover` — summarize installed WP-CLI command metadata; use `commandFilter` for ACF/CPT UI/plugin command paths
- `stonewright-wp-cli-run` — run a tokenized WP-CLI command (no shell)
- `stonewright-wp-cli-batch-run` — run repeated tokenized commands in one request
- `stonewright-wp-cli-job-start` — start long WP-CLI work without blocking the MCP request
- `stonewright-wp-cli-job-status` — poll a WP-CLI background job
- `stonewright-wp-cli-install` — manually trigger phar download into cache

- `stonewright-setup-profile` - one-call setup diagnostics and copy-paste MCP config
- `stonewright-wordpress-mcp-status` - inspect proxied WordPress MCP connection status
- `stonewright-skills-get` - load one matched site playbook on demand when proxied through WordPress MCP

`stonewright-wordpress-mcp-status` reports `startup_ready`,
`startup_missing_tool_names`, `local_recovery_tool_names`, and
`local_tool_names` so agents can recover when bootstrap, preflight, skill, or
WP-CLI tools are missing. It also reports
`profile_expected_tool_count`, `client_visible_expected_tool_count`, and
`profile_missing_tool_names` for the selected compact profile even when the
WordPress MCP endpoint cannot be reached.
Both `stonewright-setup-profile` and `stonewright-wordpress-mcp-status` also
return `tool_inventory`, a compact grouped map of first-call, diagnostic,
direct WP-CLI, long-running WP-CLI, and proxied profile tools. Agents should use
that inventory before broad tool discovery.
The same fast-start policy is present in the MCP server instructions, which
helps clients that read server instructions before listing or calling tools.

Alias names (`companion_wp_cli_*`) are also registered for backward compatibility.

Batch aliases (`stonewright-wp-cli-batch-run` and
`companion_wp_cli_batch_run`) run multiple tokenized WP-CLI commands in one
UTF-8 JSON request. Use them for repeated post/meta/term/media/option writes
instead of large inline PowerShell/Node scripts. Use background jobs only for
long imports, cache rebuilds, plugin operations, or large batches where a
single MCP request would otherwise block.

### HTTP endpoints (when `STONEWRIGHT_HTTP_ENABLE=1` and `PORT` are set)

- `POST /wp-cli/status`
- `POST /wp-cli/discover`
- `POST /wp-cli/run`
- `POST /wp-cli/batch`
- `POST /wp-cli/job-start`
- `POST /wp-cli/job-status`

Example body for `/wp-cli/run`:

```json
{
  "command": ["post", "create", "--post_type=page", "--post_title=Home"],
  "path": "D:/Sites/example",
  "user": "admin"
}
```

Example body for `/wp-cli/batch`:

```json
{
  "commands": [
    ["post", "create", "--post_type=page", "--post_title=Marius Șoflete"],
    ["term", "create", "echipa_rol", "Producție Media"]
  ],
  "stopOnError": true
}
```

Batch requests preserve Unicode through JSON and still run each command through
`execFile` argv tokens with the same blocked-command checks as single runs.

Agent recovery rule: do not run `wp cli info`, `wp plugin activate`,
`wp option update`, or other `wp ...` commands in a normal shell to debug
Stonewright. Use the direct MCP tools above so path discovery, LocalWP PHP,
blocked-command checks, and compact responses stay consistent.


## Contracts

Health contract types are generated from JSON Schema files in `src/contracts/`.

```bash
npm run build:contracts
```

Keep `src/contracts/version.ts` and
`plugin/includes/Companion/CompanionContract.php` in sync when the health
contract changes.

## Test

```bash
npm test
npm run typecheck
npm run build
```
