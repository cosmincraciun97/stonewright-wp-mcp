// SPDX-License-Identifier: GPL-2.0-or-later
import { expect, it } from "vitest";
import { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";
import { createWorkspaceController } from "../src/workspace-ui/workspace.js";
function fixture(emptyEvidence = false) {
  let reads = 0, writes = 0, hash = "before";
  const set = new DeclaredToolSet([
    { name: "get_page_structure", parameters: { type: "object", additionalProperties: false }, execute: async () => { reads++; return { content: [], details: { tree_hash: hash } }; } },
    { name: "write", parameters: { type: "object", additionalProperties: false, properties: { value: { type: "string" } }, required: ["value"] }, mutates: true, execute: async () => { writes++; hash = "after"; return { content: [] }; }, readback: async () => {} },
  ]);
  const controller = createWorkspaceController({ postId: 42, nonce: "synthetic", restBase: "https://example.test", adapters: [{ kind: "gutenberg", detect: () => true, create: () => ({ registry: () => set }) }], verify: async () => emptyEvidence ? [] : [{ label: "Geometry", rule: "geometry", source: "quality", status: "pass", checked: true, evidenceType: "quality-rule" }] });
  return { controller, reads: () => reads, writes: () => writes, change: () => { hash = "foreign"; } };
}
it("stages without editor calls then applies exact arguments through a permit", async () => {
  const f = fixture(); await f.controller.connect(); await f.controller.read();
  f.controller.preview([{ tool: "write", target: "synthetic-target", summary: "Change", before: "Old", after: "New", args: { value: "exact" } }]);
  expect(f.reads()).toBe(1); expect(f.writes()).toBe(0);
  f.controller.requestConfirmation(); await f.controller.decide("allow");
  expect(f.writes()).toBe(1); expect(f.controller.getState()).toBe("complete");
});
it("rejects stale targets and does not reuse stored evidence for a new write", async () => {
  const stale = fixture(); await stale.controller.connect(); await stale.controller.read(); stale.controller.preview([{ tool: "write", target: "t", summary: "Change", args: { value: "exact" } }]); stale.controller.requestConfirmation(); stale.change(); await stale.controller.decide("allow"); expect(stale.writes()).toBe(0);
  const missing = fixture(true); await missing.controller.connect(); await missing.controller.read(); missing.controller.recordEvidence([{ label: "Previous", rule: "old", source: "quality", status: "pass", checked: true, evidenceType: "quality-rule" }]); missing.controller.preview([{ tool: "write", target: "t", summary: "Change", args: { value: "exact" } }]); missing.controller.requestConfirmation(); await missing.controller.decide("allow"); expect(missing.controller.getState()).toBe("failed");
});
