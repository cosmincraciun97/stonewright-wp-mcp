# @stonewright/companion

Node 20+ bridge for the [Stonewright WordPress MCP plugin](https://github.com/stonewright/stonewright-wp-mcp).

Handles things PHP can't do well: Figma REST ingestion, Playwright screenshots,
pixel-level visual regression, and an optional MCP HTTP proxy. **Never writes to
WordPress directly** — that's the PHP plugin's job.

MIT License.

---

## Install

```bash
cd companion
npm install
npx playwright install chromium   # one-time browser binary download
```

## Configure

```bash
cp .env.example .env
# Edit .env — set at minimum FIGMA_TOKEN and COMPANION_BEARER_TOKEN
```

| Variable | Required | Description |
|---|---|---|
| `FIGMA_TOKEN` | for Figma tools | Personal access token from figma.com/settings |
| `STONEWRIGHT_WP_URL` | optional | WordPress base URL |
| `STONEWRIGHT_WP_APP_PASSWORD` | optional | `username:app-password` for WP REST |
| `COMPANION_BEARER_TOKEN` | recommended | Protects the HTTP transport |
| `COMPANION_ALLOWED_ORIGINS` | optional | Comma-separated origins; empty = allow all |
| `PORT` | optional | Enables HTTP transport on this port |
| `MCP_PROXY_TARGET` | optional | Upstream MCP server URL to proxy to |
| `MCP_PROXY_TOKEN` | optional | Bearer token for the proxy target |

## Run

```bash
# Build then start
npm run build && npm start

# Dev mode (watch + restart)
npm run dev
```

The companion always starts a **stdio MCP transport**. Set `PORT` to also expose
a **Streamable HTTP transport** at `POST /mcp`.

## MCP Tools

### `companion_figma_fetch`

Fetch a Figma node and map it to WordPress-friendly section/block descriptors.

| Arg | Type | Description |
|---|---|---|
| `url` | `string` | Figma share URL |
| `token` | `string?` | Override `FIGMA_TOKEN` |

Returns `{ fileKey, nodeId, rawNode, blocks[] }`.

### `companion_figma_export`

Export PNG images for a list of Figma node IDs.

| Arg | Type | Description |
|---|---|---|
| `file_key` | `string` | Figma file key |
| `node_ids` | `string[]` | Node IDs to export |
| `token` | `string?` | Override `FIGMA_TOKEN` |

Returns `{ fileKey, images: Record<nodeId, cdnUrl> }`.

### `companion_screenshot`

Take a Playwright screenshot, returns base64 PNG + metadata.

| Arg | Type | Default |
|---|---|---|
| `url` | `string (url)` | — |
| `viewport_width` | `number` | 1280 |
| `viewport_height` | `number` | 800 |
| `full_page` | `boolean` | false |
| `wait_for` | `load\|domcontentloaded\|networkidle\|commit` | networkidle |
| `selector` | `string?` | — |
| `delay_ms` | `number?` | — |

### `companion_pixel_diff`

Compare two PNGs pixel-by-pixel; writes a red-highlight diff image.

| Arg | Type | Default |
|---|---|---|
| `reference_path` | `string` | — |
| `actual_path` | `string` | — |
| `threshold` | `number 0–1` | 0.1 |
| `ignore_regions` | `{x,y,width,height}[]?` | — |
| `diff_output_path` | `string?` | `<actual>.diff.png` |

Returns `{ mismatched_pixels, total_pixels, ratio, diff_png_path }`.

## Test

```bash
npm test
```

## Build

```bash
npm run build          # tsup → dist/
npm run lint           # eslint
npm run typecheck      # tsc --noEmit
```

## QA REST API contracts

The companion exposes a QA REST API (`/screenshot`, `/diff`, `/axe`, `/layout`,
`/lighthouse`, `/health`) used exclusively by the PHP plugin. Contracts are
defined as JSON Schema files under `src/contracts/`. TypeScript types are
generated from those schemas.

**Regenerate TypeScript types after editing a schema:**

```bash
npm run build:contracts
```

This runs `scripts/build-contracts.mjs` which compiles each schema's `$defs`
into `src/contracts/generated.ts` using `json-schema-to-typescript`. Commit
`generated.ts` — consumers don't need the code-gen tool at runtime.

The PHP plugin has mirror shape constants under
`plugin/includes/Companion/Contracts/` and a `CompanionContract::validate()`
class. Keep these in sync with the JSON Schema files when the contract changes.
The version is in `src/contracts/version.ts`; update `CONTRACT_VERSION` and
`CompanionContract::EXPECTED_CONTRACT_VERSION` together.
