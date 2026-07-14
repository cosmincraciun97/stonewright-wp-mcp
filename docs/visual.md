# Stonewright Visual

`visual/` is an AGPL-licensed, headless workspace foundation for WordPress
editors. It exports one top-level MCP contract:
`stonewright-workspace-request`.

Editor-specific tools remain nested behind `workspace_call_page_tool`. This
keeps Elementor and Gutenberg schemas out of the top-level MCP tool list.
Nested `batch_call` supports aliases such as `$hero`, compact summaries,
mandatory mutation readback, and rollback through editor transactions or
per-tool rollback handlers.

Backend tools must come from the Visual-safe discovery contract. Dangerous
tools are hidden by default; writes and elevated calls enter the confirmation
state machine before execution. The dispatcher does not expose a JavaScript
eval method.

```bash
cd visual
npm install
npm run typecheck
npm test
npm run build
```
