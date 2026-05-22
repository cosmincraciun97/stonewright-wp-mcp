# Companion Bridge

The companion is a Node.js server that handles tasks the PHP plugin cannot do directly:

- Fetching and parsing Figma node data via the Figma REST API.
- Taking full-page screenshots with Playwright.
- Pixel-diffing two screenshots and returning a match score.
- Running axe-core accessibility audits.
- Running Lighthouse performance and quality audits.
- Proxying MCP requests to an upstream server (optional).

The companion never writes to WordPress directly. All WordPress mutations go through the plugin via the WordPress REST API.

## When you need the companion

The following abilities require a running companion and `stonewright_companion_url` set to its internal URL:

- `stonewright/design/import-figma-node` — needs `FIGMA_TOKEN` too
- `stonewright/qa/screenshot-page`
- `stonewright/qa/diff-screenshot`
- `stonewright/qa/diff-layout`
- `stonewright/qa/accessibility-check`
- `stonewright/qa/responsive-check`
- `stonewright/qa/lighthouse`
- `stonewright/media/optimize-media`

All other abilities work without the companion.

## Installation

```bash
cd companion
npm install
npm run build
```

## Configuration

Copy `.env.example` to `.env` and fill in the values:

```bash
cp .env.example .env
```

Required variables:

```
STONEWRIGHT_WP_URL=https://your-site.example.com
STONEWRIGHT_WP_APP_PASSWORD=username:xxxx xxxx xxxx xxxx xxxx xxxx
COMPANION_BEARER_TOKEN=change-this-to-a-strong-random-value
```

Optional:

```
FIGMA_TOKEN=fig-xxxxx                     # required for Figma abilities
COMPANION_ALLOWED_ORIGINS=http://localhost:8888
PORT=3500
MCP_PROXY_TARGET=                         # leave empty to disable proxy
MCP_PROXY_TOKEN=
```

## Starting the companion

Development (with file watching):

```bash
npm run dev
```

Production:

```bash
node dist/index.js
```

The companion starts an HTTP server on `PORT` (default 3500) and a stdio MCP server. The HTTP server requires a bearer token on every request. The stdio server is used when the companion runs as a direct MCP subprocess.

## Telling the plugin where the companion is

Set the `stonewright_companion_url` option to the companion's internal URL:

```bash
wp option update stonewright_companion_url http://localhost:3500
```

Or via the REST API:

```bash
curl -X POST https://your-site.example.com/wp-json/stonewright/v1/settings \
  -H "Authorization: Bearer username:app-password" \
  -H "Content-Type: application/json" \
  -d '{"companion_url": "http://localhost:3500"}'
```

## Security

- Never expose the companion port to the public internet.
- Set `COMPANION_BEARER_TOKEN` to a long random string before starting in any shared environment.
- Set `COMPANION_ALLOWED_ORIGINS` to restrict which hosts can call the HTTP server.
- The Figma token is read from the environment and is not sent to the WordPress database unless the `stonewright_figma_token` option is set separately.

## Architecture

```
Plugin (PHP) --> CompanionClient::post() --> HTTP POST /run
                                               |
                                               v
                                         companion/src/
                                               |
                              +----------------+------------------+
                              |                |                  |
                        Figma REST API    Playwright         sharp/axe-core
```

`CompanionClient` in `plugin/includes/Support/CompanionClient.php` handles all outbound calls from the plugin to the companion. It reads `stonewright_companion_url` and appends the stored bearer token from `stonewright_companion_bearer_token` (set automatically when you configure the URL).
