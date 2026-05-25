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

## WP-CLI Safety

The companion runs WP-CLI through `execFile` with argv tokens. It blocks
arbitrary PHP and shell entry points: `wp eval`, `wp eval-file`, `wp shell`,
`wp package`, `--exec`, and `--require`.

Use `STONEWRIGHT_WP_ROOT` or `STONEWRIGHT_WP_ALLOWED_ROOTS` to restrict working
directories.

## Configuration

| Variable | Description |
|---|---|
| `COMPANION_BEARER_TOKEN` | Required outside local/dev mode |
| `COMPANION_ALLOWED_ORIGINS` | Required outside local/dev mode |
| `PORT` | Enables HTTP transport |
| `STONEWRIGHT_MCP_URL` | WordPress MCP endpoint proxied into stdio, e.g. `/wp-json/mcp/stonewright` |
| `WP_API_USERNAME` | WordPress username for Application Password auth |
| `WP_API_PASSWORD` | WordPress Application Password |
| `STONEWRIGHT_WP_URL` | Alias for the site URL; the companion derives `/wp-json/mcp/stonewright` when `STONEWRIGHT_MCP_URL` is absent |
| `STONEWRIGHT_WP_USERNAME` | Alias for `WP_API_USERNAME` |
| `STONEWRIGHT_WP_APP_PASSWORD` | Alias for `WP_API_PASSWORD` |
| `STONEWRIGHT_MCP_AUTHORIZATION` | Optional full Authorization header override |
| `STONEWRIGHT_WP_CLI_BIN` | WP-CLI executable; defaults to `wp` |
| `STONEWRIGHT_WP_ROOT` | Default WP working directory |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | Comma- or semicolon-separated allowed roots |
| `MCP_PROXY_TARGET` | Optional upstream MCP server |

## Plugin Integration

Set:

```bash
wp option update stonewright_companion_url http://127.0.0.1:8765
wp option update stonewright_companion_token change-this-long-random-token
```

Then use Stonewright abilities:

- `stonewright-context-bootstrap`
- `stonewright-wp-cli-status`
- `stonewright-wp-cli-discover`
- `stonewright-wp-cli-run`

If the HTTP bridge is not running, use the direct companion MCP tools instead:

- `companion_wp_cli_status`
- `companion_wp_cli_discover`
- `companion_wp_cli_run`
