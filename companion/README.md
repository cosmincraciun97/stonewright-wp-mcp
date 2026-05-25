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

```bash
cd companion
npm install
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

## Run

```bash
npm run build
npm start
```

The companion always starts stdio MCP. Set `PORT` to also expose HTTP routes.

## WP-CLI

MCP tools:

- proxied WordPress tools such as `stonewright-context-bootstrap` when `STONEWRIGHT_MCP_URL` is configured
- `companion_wp_cli_status`
- `companion_wp_cli_discover`
- `companion_wp_cli_run`

HTTP endpoints:

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
