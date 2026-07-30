# MCP adapter: no server-push of tools/list_changed (plugin-direct)

## What

The vendored WordPress MCP adapter does not push `notifications/tools/list_changed`:

- `Initialize/InitializeHandler.php` returns `capabilities.tools` as an empty object, without `listChanged: true`.
- The SSE request path is a 405 stub, so there is no server-to-client event stream.

## Consequence

A **plugin-direct** MCP client (no companion) will not receive an automatic re-list when the operator changes the site surface in admin. It only re-lists when it calls a gateway tool again and observes a newer `surface_revision`, or when the user restarts the client.

## Why we do not patch it here

The adapter is third-party code under its own SPDX header (see `docs/upstream-code-reuse.md`). Editing its behavior would fork upstream. The supported propagation path is:

- **plugin-proxy / Direct mode:** the companion emits `tools/list_changed` on `surface_revision` change (see companion `handleToolsChangedResponse`).
- **plugin-direct:** poll-on-next-call via `surface_revision` on every gateway response.

## If upstream gains listChanged

Re-evaluate: advertise `capabilities.tools.listChanged` and wire a push from `stonewright_tool_surface_changed`. Track the upstream version in `docs/upstream-code-reuse.md` before adopting.
