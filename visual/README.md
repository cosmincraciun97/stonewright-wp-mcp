<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# @stonewright/visual

Headless workspace foundation for WordPress editors, plus the browser bundle
that the Stonewright plugin hosts under **Stonewright → Visual Workspace**.

This package is AGPL-3.0-or-later. It adapts code from Novamira Visual; see
[NOTICE](NOTICE) and [../docs/upstream-code-reuse.md](../docs/upstream-code-reuse.md)
for exact source paths and fingerprints.

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
| `src/index.ts` | Node entry: dispatcher, discovery, confirmations, guidance |
| `src/workspace-browser.ts` | Browser entry, built as an IIFE for the admin page |
| `src/workspace-ui/` | Workspace controller, adapter status, confirmation panel, evidence panel |
| `src/gutenberg/`, `src/elementor-v3/`, `src/elementor-v4/` | Editor adapters |
| `src/page-tool-registry.ts` | Nested tool registry and argument checks |
| `src/skills/` | Workspace skill definitions |

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
That directory is generated and is not committed. Build this package, copy
`dist/workspace-browser.js` into it, and the admin page picks it up; without it
the page states that the bundle is missing and prints the build command instead
of rendering an empty frame.

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
