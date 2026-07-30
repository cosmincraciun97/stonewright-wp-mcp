// SPDX-License-Identifier: AGPL-3.0-or-later

import { defineConfig } from "tsup";

/**
 * Two entries, deliberately built differently.
 *
 * `src/index.ts` is the package API: ESM plus declarations, consumed by the
 * companion. `src/workspace-browser.ts` is loaded by wp-admin as a plain
 * script, so it is bundled into one self-contained IIFE with no chunk imports
 * and no declarations.
 *
 * Neither config cleans: tsup runs them in parallel, so a clean owned by one
 * config can delete the other's output. The `prebuild` script removes `dist`
 * once, before either build starts.
 */
export default defineConfig([
  {
    entry: ["src/index.ts"],
    format: ["esm"],
    dts: true,
    clean: false,
    outDir: "dist",
  },
  {
    entry: { "workspace-browser": "src/workspace-browser.ts" },
    format: ["iife"],
    globalName: "StonewrightVisual",
    splitting: false,
    dts: false,
    clean: false,
    platform: "browser",
    outDir: "dist",
    outExtension: () => ({ js: ".js" }),
  },
]);
