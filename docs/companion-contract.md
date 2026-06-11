# Companion Contract Reference

The companion is a Node.js bridge for health checks, optional MCP HTTP
transport/proxying, and guarded WP-CLI execution. It does not own Figma
ingestion, screenshots, browser QA, Lighthouse, accessibility scans, or pixel
diffing.

## Contract Version

Current version: `1.0.0`

Source of truth: `companion/src/contracts/version.ts`

```ts
export const CONTRACT_VERSION = '1.0.0';
export const CONTRACT_MAJOR = 1;
```

The PHP `CompanionClient` checks `GET /health` before companion-backed calls and
rejects major-version mismatches with
`stonewright_companion_version_mismatch`.

## Authentication

All routes except `GET /health` require:

```http
Authorization: Bearer <COMPANION_BEARER_TOKEN>
```

Set `COMPANION_ALLOWED_ORIGINS` when the HTTP server is reachable by browsers.
Run the companion on loopback or a private network.

## Endpoints

### GET /health

Returns companion status and contract version.

```json
{
  "status": "ok",
  "contract_version": "1.0.0"
}
```

### POST /wp-cli/status

Runs `wp cli info --format=json` through the guarded WP-CLI runner.

Request fields:

| Field | Type | Required | Description |
|---|---|---|---|
| `path` | string | No | WordPress root passed as `--path`. |
| `timeoutMs` | integer | No | Command timeout in milliseconds. |

### POST /wp-cli/discover

Runs `wp cli cmd-dump --format=json` so agents can discover installed command
groups and plugin-provided commands.

Request fields are the same as `/wp-cli/status`.

### POST /wp-cli/run

Runs one WP-CLI command.

| Field | Type | Required | Description |
|---|---|---|---|
| `command` | string[] | Yes | Command argv without the `wp` executable, for example `["plugin","list","--format=json"]`. |
| `path` | string | No | WordPress root passed as `--path`. Must stay inside allowed roots. |
| `url` | string | No | Site URL passed as `--url`. |
| `user` | string | No | WP user passed as `--user` when a command requires auth context. |
| `context` | string | No | WP-CLI context passed as `--context`. |
| `timeoutMs` | integer | No | Command timeout in milliseconds. |
| `parseJson` | boolean | No | Parse stdout as JSON and return it in `parsed_json`. |

### POST /wp-cli/batch

Runs up to 100 WP-CLI commands from one UTF-8 JSON request.

| Field | Type | Required | Description |
|---|---|---|---|
| `commands` | string[][] | Yes | Tokenized WP-CLI command argv arrays. |
| `path` | string | No | WordPress root passed as `--path`. Must stay inside allowed roots. |
| `url` | string | No | Site URL passed as `--url`. |
| `user` | string | No | WP user passed as `--user` when commands require auth context. |
| `context` | string | No | WP-CLI context passed as `--context`. |
| `timeoutMs` | integer | No | Per-command timeout in milliseconds. |
| `parseJson` | boolean | No | Parse each stdout as JSON and return it in `parsed_json`. |
| `stopOnError` | boolean | No | Defaults to true. Stop after the first failed command. |

Each command is still validated and executed through `execFile` with shell
disabled. Use this endpoint for repeated operations with UTF-8 data instead of
large inline shell scripts.

Response fields:

| Field | Type | Description |
|---|---|---|
| `ok` | boolean | Whether the command exited with code 0. |
| `available` | boolean | Whether the `wp` executable was found. |
| `command` | string[] | Full command argv passed to the runner. |
| `cwd` | string | Working directory used for the process. |
| `stdout` | string | Captured stdout. |
| `stderr` | string | Captured stderr. |
| `exit_code` | integer | Process exit code, or `-1` for runner/validation failures. |
| `duration_ms` | integer | Runtime duration. |
| `parsed_json` | mixed | Present when `parseJson` is true and stdout is valid JSON. |
| `error` | string | Present when validation or process startup fails. |

## MCP HTTP Transport

When `PORT` is set, the companion exposes MCP Streamable HTTP routes at
`/mcp`. The route is guarded by the same bearer token.

## Optional MCP Proxy

When `MCP_PROXY_TARGET` is set, `/proxy` forwards MCP JSON-RPC payloads to that
fixed upstream. Callers cannot choose arbitrary proxy targets.

## WP-CLI Safety Invariants

1. Commands are executed with `execFile`, not a shell.
2. The command argv must be an array of non-empty strings.
3. Arbitrary PHP and shell-oriented groups are blocked: `eval`, `eval-file`,
   `shell`, and `package`.
4. Unsafe flags are blocked: `--exec`, `--require`, and `--prompt`.
5. `path`/cwd must resolve inside `STONEWRIGHT_WP_ROOT`,
   `STONEWRIGHT_WP_ALLOWED_ROOTS`, or the companion process cwd fallback.
   `STONEWRIGHT_WP_ROOT` is optional and, when configured, is the absolute
   WordPress install folder containing `wp-config.php`.
6. The companion never calls WordPress REST write endpoints.

## Schema Files

The companion keeps the stable health schema in `companion/src/contracts/`.
WP-CLI request/response validation lives in `companion/src/wp-cli.ts` because it
is runtime-dependent on the installed WP-CLI command tree.
