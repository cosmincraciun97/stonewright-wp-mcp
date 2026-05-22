# Companion Contract Reference

The companion is a Node.js HTTP server that bridges Stonewright with Figma, Playwright, and QA tooling. The PHP plugin communicates with it via `Stonewright\WpMcp\Support\CompanionClient`.

## Contract version

**Current version: `1.0.0` (major: 1)**

Source of truth: `companion/src/contracts/version.ts`

```ts
export const CONTRACT_VERSION = '1.0.0';
export const CONTRACT_MAJOR = 1;
```

## Version-mismatch behavior

On every companion call the PHP `CompanionClient`:

1. Calls `GET /health` and reads `contract_version` from the JSON response.
2. Compares the major version against `CONTRACT_MAJOR`.
3. If the majors differ, returns `WP_Error( 'stonewright_companion_version_mismatch', ... )` and aborts the ability without making any further companion calls.

Minor and patch bumps are backward-compatible; only major bumps break the client.

## Endpoints

All endpoints listen on the companion HTTP server (default port 3500, configurable via `PORT` env var). Requests from PHP carry the `COMPANION_BEARER_TOKEN` header for authentication.

---

### GET /health

No request body. No authentication required.

**Response schema:** `companion/src/contracts/health.schema.json`

```json
{
  "status": "ok",
  "contract_version": "1.0.0"
}
```

Used by `CompanionClient::health_check()` and version negotiation.

---

### POST /screenshot

Captures a full-page or viewport screenshot via Playwright.

**Request schema:** `companion/src/contracts/screenshot.schema.json`

| Field | Type | Required | Description |
|---|---|---|---|
| `url` | string | Yes | Target URL (`https://` or `http://` only) |
| `artifact_path` | string | Yes | Absolute path prefix for output files (must match `/stonewright-qa/` pattern) |
| `request_id` | string | Yes | UUID v4 identifying this request |
| `full_page` | boolean | No | Capture full page height (default true) |
| `viewport_width` | integer | No | Viewport width in pixels (default 1440) |
| `viewport_height` | integer | No | Viewport height in pixels (default 900) |
| `clip` | object | No | `{x, y, width, height}` for a sub-region capture |

**Response schema:** `companion/src/contracts/screenshot.schema.json`

| Field | Type | Description |
|---|---|---|
| `path` | string | Absolute path to the saved PNG |
| `width` | integer | Actual capture width |
| `height` | integer | Actual capture height |

Used by `QA\ScreenshotPage`, `QA\ResponsiveCheck`.

---

### POST /diff

Pixel-diffs two screenshot artifacts.

**Request schema:** `companion/src/contracts/diff.schema.json`

| Field | Type | Required | Description |
|---|---|---|---|
| `request_id` | string | Yes | UUID v4 identifying this request |
| `reference_artifact_id` | string | Yes | `artifact_id` from a prior `/screenshot` response (the reference/baseline screenshot) |
| `actual_artifact_id` | string | Yes | `artifact_id` from a prior `/screenshot` response (the current screenshot to compare) |
| `artifact_path` | string | Yes | Directory where the diff PNG will be written (must match `/stonewright-qa/` pattern) |
| `threshold` | number | No | Per-channel pixel tolerance 0–1 (default 0.1); also used as the pass/fail ratio cutoff — see semantics below |
| `ignore_regions` | array | No | Array of `{x, y, width, height}` rectangles excluded from comparison |

**Response schema:** `companion/src/contracts/diff.schema.json`

| Field | Type | Description |
|---|---|---|
| `request_id` | string | Echoed UUID v4 |
| `needs_reference` | boolean | `true` when no reference screenshot exists yet — diff was skipped; remaining fields absent |
| `passed` | boolean | `true` when `diff_ratio <= threshold`; present only when `needs_reference=false` |
| `diff_ratio` | number | Fraction of mismatched pixels (0–1); present only when `needs_reference=false` |
| `threshold` | number | Threshold value echoed from the request |
| `diff_url` | string | Filesystem path to the diff PNG (PHP `QaArtifactStore` maps this to a public URL); present only when `needs_reference=false` |
| `mismatch_regions` | array | Bounding boxes of clustered mismatch regions (currently always empty — clustering is a planned enhancement); present only when `needs_reference=false` |

Used by `QA\DiffScreenshot`.

---

### POST /axe

Runs an axe-core 4.9.1 accessibility audit in the Playwright browser context.

**Request schema:** `companion/src/contracts/axe.schema.json`

| Field | Type | Required | Description |
|---|---|---|---|
| `url` | string | Yes | Target URL |
| `artifact_path` | string | Yes | Output path prefix |
| `request_id` | string | Yes | UUID v4 |
| `rules` | array | No | Specific axe rule IDs to run (default: all) |

**Response schema:** `companion/src/contracts/axe.schema.json`

| Field | Type | Description |
|---|---|---|
| `violations` | array | axe violation objects with `id`, `impact`, `nodes`, `description` |
| `passes` | integer | Count of passing rules |
| `incomplete` | integer | Count of inconclusive rules |

Used by `QA\AccessibilityCheck`.

---

### POST /layout

Captures DOM layout information for structural comparison against a design spec.

**Request schema:** `companion/src/contracts/layout.schema.json`

| Field | Type | Required | Description |
|---|---|---|---|
| `url` | string | Yes | Target URL |
| `artifact_path` | string | Yes | Output path prefix |
| `request_id` | string | Yes | UUID v4 |
| `selector` | string | No | CSS selector to scope the layout capture (default: `body`) |

**Response schema:** `companion/src/contracts/layout.schema.json`

| Field | Type | Description |
|---|---|---|
| `sections` | array | Array of `{tag, rect, children}` layout nodes |
| `alignment_diffs` | array | Detected alignment issues `{element, issue, severity}` |

Used by `QA\DiffLayout`.

---

### POST /lighthouse

Runs a Lighthouse audit against a URL.

**Request schema:** `companion/src/contracts/lighthouse.schema.json`

| Field | Type | Required | Description |
|---|---|---|---|
| `url` | string | Yes | Target URL |
| `artifact_path` | string | Yes | Output path prefix |
| `request_id` | string | Yes | UUID v4 |
| `categories` | array | No | Lighthouse category IDs (default: `['performance','accessibility','best-practices','seo']`) |

**Response schema:** `companion/src/contracts/lighthouse.schema.json`

| Field | Type | Description |
|---|---|---|
| `scores` | object | Map of category ID to score 0–1 |
| `report_path` | string | Absolute path to the saved Lighthouse HTML report |

Used by `QA\Lighthouse`.

---

### POST /mcp (and GET /mcp, DELETE /mcp)

> **Status: optional** — active only when `PORT` env var is set at companion startup.

The `/mcp` route exposes the MCP Streamable HTTP transport. It is the primary way a remote MCP client (e.g. a Claude Code instance running on a different machine) connects to Stonewright's tool surface without going through stdio.

**Authentication:** all requests (except `GET /health`) must carry `Authorization: Bearer <COMPANION_BEARER_TOKEN>`. Missing or mismatched tokens receive `401`.

**Method semantics:**

| Method | Purpose |
|---|---|
| `POST /mcp` | Send a JSON-RPC MCP message (initialize, tools/list, tools/call, etc.) |
| `GET /mcp` | Open an SSE stream for server-initiated notifications |
| `DELETE /mcp` | Close an active session |

The request and response bodies conform to the [MCP Streamable HTTP transport spec](https://spec.modelcontextprotocol.io/specification/basic/transports/#streamable-http). Body size is limited to `config.maxBodyBytes` (default 10 MB).

> See `companion/src/index.ts` (the `handleHttpRequest` function) for the exact routing logic. No separate JSON schema file — the shape is defined by the MCP SDK's `StreamableHTTPServerTransport`.

---

### GET/POST /proxy

> **Status: optional** — active only when `MCP_PROXY_TARGET` env var is set.

The `/proxy` route forwards MCP JSON-RPC requests to a remote upstream MCP server using the Streamable HTTP transport. This lets a local Stonewright companion relay tool calls to a remote backend (e.g. a cloud-hosted MCP server).

**Configuration (env vars):**

| Variable | Required | Description |
|---|---|---|
| `MCP_PROXY_TARGET` | Yes (to enable proxy) | Base URL of the upstream MCP server, e.g. `https://mcp.example.com` |
| `MCP_PROXY_TOKEN` | No | Bearer token injected into upstream `Authorization` header |
| `MCP_PROXY_TIMEOUT_MS` | No | Request timeout in ms (default 30 000) |

**Authentication:** the companion applies the standard bearer-token guard before forwarding. The upstream server receives its own Bearer token via `MCP_PROXY_TOKEN`.

**Request:** any body that passes the companion's size limit. The companion does not inspect the body — it is forwarded verbatim.

**Response:** the upstream response is streamed back to the caller. HTTP status, headers, and body are passed through unmodified.

> See `companion/src/mcp-proxy.ts` for the implementation. Security note: the proxy only forwards to the configured `MCP_PROXY_TARGET` — caller-supplied target URLs are not accepted.

---

## Security invariants enforced by the companion

1. `artifact_path` is validated against the regex `^\/[a-z0-9/_.-]+stonewright-qa\/[a-z0-9-]+\/?$` before any filesystem write.
2. `url` must match `^https?://` — no `file://`, `data:`, or relative URLs.
3. `request_id` must be UUID v4 (`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`).
4. All response fields are typed through `contracts/generated.ts` — no raw pass-through of upstream data.

## Schema files

All schemas live in `companion/src/contracts/`:

| File | Endpoint |
|---|---|
| `health.schema.json` | GET /health |
| `screenshot.schema.json` | POST /screenshot |
| `diff.schema.json` | POST /diff |
| `axe.schema.json` | POST /axe |
| `layout.schema.json` | POST /layout |
| `lighthouse.schema.json` | POST /lighthouse |
| `design-spec.schema.json` | Internal Figma bridge output validation |
| `version.ts` | Contract version constant |
| `generated.ts` | TypeScript types generated from schemas |
