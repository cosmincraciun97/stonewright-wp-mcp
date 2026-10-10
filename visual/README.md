<!-- SPDX-License-Identifier: GPL-2.0-or-later -->

# @stonewright/visual

Headless workspace foundation for WordPress editors, plus a browser bundle.
The **Stonewright → Visual Workspace** admin page that hosted the bundle is
not registered in this release.

This package is GPL-2.0-or-later. See [LICENSING.md](../LICENSING.md) for the
license of each Stonewright component.

## What it is

One top-level MCP contract, `stonewright-workspace-request`. Editor-specific
tools stay nested behind `workspace_call_page_tool`, which keeps Elementor and
Gutenberg schemas out of the top-level tool list. Nested `batch_call` supports
aliases such as `$hero`, compact summaries, mandatory mutation readback, and
rollback through editor transactions or per-tool rollback handlers.

Backend tools come from the Visual-safe discovery contract. Dangerous tools are
hidden by default, writes and elevated calls enter the confirmation state
machine before execution, and the dispatcher exposes no JavaScript eval method.

## Layout

| Path | Contents |
|---|---|
| `src/index.ts` | Node entry: the `stonewright-workspace-request` tool |
| `src/workspace-browser.ts` | Browser entry, built as an IIFE |
| `src/workspace-ui/` | Workspace controller, adapter status, confirmation panel, evidence panel |
| `src/native-blocks/`, `src/elementor-v3/`, `src/elementor-v4/` | Editor adapters (Gutenberg, Elementor V3, Elementor V4) |
| `src/editor-tools/` | Declared nested tool set and schema checks |
| `src/session/` | Request router, protocol, backend policy, confirmations and guidance |

## Build

```bash
cd visual
npm install
npm run typecheck
npm test
npm run build
```

`npm run build` emits `dist/index.js` (ESM) and `dist/workspace-browser.js`
(IIFE, global `StonewrightVisual`).

## Staging the browser bundle

The plugin loads the bundle from `plugin/assets/visual/workspace-browser.js`.
That directory is generated and is not committed. Build this package and copy
`dist/workspace-browser.js` into it before packaging.

`node scripts/package-verify.mjs` warns about a missing bundle in a source
checkout and fails under `--require-visual-bundle`, which CI and the release
workflow pass after staging.

## What the browser side does not claim

The workspace supplies browser-side evidence and the confirmation gate. It does
not certify that a page looks right. Applying a change with no evidence behind
it is reported as unverified rather than as a success — the write may have
landed, but the claim of correctness has not been earned. See
[../docs/visual.md](../docs/visual.md) for the full ladder and the admin page
contract.
