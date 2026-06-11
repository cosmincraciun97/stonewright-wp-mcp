# @stonewright/companion

Node 20+ companion for the Stonewright WordPress MCP plugin.

The companion provides:

- stdio MCP transport for local clients
- stdio proxy for the Stonewright WordPress MCP endpoint
- optional Streamable HTTP transport at `POST /mcp`
- health checks at `GET /health`
- guarded WP-CLI execution for WordPress implementation and debugging
- optional MCP HTTP proxy

The companion does not call WordPress REST write endpoints. WordPress changes go
through tokenized WP-CLI commands run with `execFile`, never shell strings.
Dangerous arbitrary PHP and shell entry points are blocked: `wp eval`,
`wp eval-file`, `wp shell`, `wp package`, `--exec`, and `--require`.

MIT License.

## Install

From a GitHub release:

```bash
npm install -g ./stonewright-companion-<version>.tgz
```

From source:

```bash
cd companion
npm install
npm run build
```

## Configure

```bash
cp .env.example .env
```

| Variable | Required | Description |
|---|---|---|
| `COMPANION_BEARER_TOKEN` | recommended | Protects the HTTP transport |
| `COMPANION_ALLOWED_ORIGINS` | recommended | Comma-separated allowed origins |
| `PORT` | optional | Enables HTTP transport on this port |
| `STONEWRIGHT_MCP_URL` | recommended for stdio | WordPress MCP endpoint, e.g. `https://site.test/wp-json/mcp/stonewright` |
| `WP_API_USERNAME` | with `STONEWRIGHT_MCP_URL` | WordPress username for Application Password auth |
| `WP_API_PASSWORD` | with `STONEWRIGHT_MCP_URL` | WordPress Application Password |
| `STONEWRIGHT_MCP_AUTHORIZATION` | optional | Full Authorization header override for the WordPress MCP endpoint |
| `STONEWRIGHT_WP_CLI_BIN` | optional | WP-CLI executable path; defaults to `wp` |
| `STONEWRIGHT_WP_ROOT` | optional | Default WP-CLI working directory |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | optional | Comma- or semicolon-separated allowed working roots |
| `MCP_PROXY_TARGET` | optional | Upstream MCP server URL to proxy to |
| `MCP_PROXY_TOKEN` | optional | Bearer token for the proxy target |

## Run From Source

```bash
npm run build
npm start
```

The companion always starts stdio MCP. Set `PORT` to also expose HTTP routes.

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
- `stonewright-wp-cli-discover` — dump installed WP-CLI command metadata
- `stonewright-wp-cli-run` — run a tokenized WP-CLI command (no shell)
- `stonewright-wp-cli-install` — manually trigger phar download into cache

Alias names (`companion_wp_cli_*`) are also registered for backward compatibility.

### HTTP endpoints (when `PORT` is set)

- `POST /wp-cli/status`
- `POST /wp-cli/discover`
- `POST /wp-cli/run`

Example body for `/wp-cli/run`:

```json
{
  "command": ["post", "create", "--post_type=page", "--post_title=Home"],
  "path": "D:/Sites/example",
  "user": "admin"
}
```


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
