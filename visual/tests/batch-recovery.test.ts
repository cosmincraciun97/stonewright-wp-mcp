// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, it } from "vitest";
import { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";
import { authorizedWrite } from "./authorized-write.js";

const params = { type: "object", additionalProperties: false, properties: { id: { type: "string" } }, required: ["id"] };
it("resolves typed prior aliases and independently reads every mutation", async () => {
  const seen: string[] = [];
  const tools = new DeclaredToolSet([
    { name: "create", parameters: params, mutates: true, execute: async (args) => ({ content: [], details: { element_id: args.id } }), readback: async () => { seen.push("read"); }, rollback: async () => {} },
    { name: "read", parameters: params, execute: async (args) => { seen.push(String(args.id)); return { content: [] }; } },
  ], undefined, { create: { primaryResultField: "element_id", publicResultFields: { element_id: { type: "string" } } } }, async () => seen);
  await authorizedWrite(tools, "batch_call", { calls: [{ tool: "create", args: { id: "synthetic" }, alias: "hero" }, { tool: "read", args: { id: "$hero" } }] });
  expect(seen).toEqual(["read", "synthetic"]);
});
it("rejects forward references before the first mutation", async () => {
  let writes = 0;
  const tools = new DeclaredToolSet([{ name: "write", parameters: params, mutates: true, execute: async () => { writes++; return { content: [] }; }, readback: async () => {}, rollback: async () => {} }]);
  await expect(authorizedWrite(tools, "batch_call", { calls: [{ tool: "write", args: { id: "$later" } }, { tool: "write", args: { id: "b" }, alias: "later" }] })).rejects.toThrow();
  expect(writes).toBe(0);
});
it("rolls back the attempted mutation and completed changes on readback failure", async () => {
  const state: string[] = [];
  const tools = new DeclaredToolSet([{ name: "write", parameters: params, mutates: true, execute: async (args) => { state.push(String(args.id)); return { content: [] }; }, readback: async (args) => { if (args.id === "bad") throw new Error("readback"); }, rollback: async () => { state.pop(); } }], undefined, {}, async () => state);
  await expect(authorizedWrite(tools, "batch_call", { calls: [{ tool: "write", args: { id: "first" } }, { tool: "write", args: { id: "bad" } }] })).rejects.toMatchObject({ code: "workspace_batch_failed", details: { rollback: "restored" } });
  expect(state).toEqual([]);
});
it("does not report restoration solely because a rollback callback resolved", async () => {
  let state = "before";
  const tools = new DeclaredToolSet([{ name: "write", parameters: params, mutates: true, execute: async () => { state = "after"; return { content: [] }; }, readback: async () => { throw new Error("failed"); }, rollback: async () => {} }], undefined, {}, async () => state);
  await expect(authorizedWrite(tools, "batch_call", { calls: [{ tool: "write", args: { id: "a" } }] })).rejects.toMatchObject({ details: { rollback: "failed", retry: "manual_recovery" } });
});
it("rejects private result selectors before any earlier call writes", async () => {
  let writes = 0;
  const tools = new DeclaredToolSet([{ name: "create", parameters: params, mutates: true, execute: async () => { writes++; return { content: [], details: { element_id: "public", secret: "private" } }; }, readback: async () => {}, rollback: async () => {} }, { name: "read", parameters: params, execute: async () => ({ content: [] }) }], undefined, { create: { primaryResultField: "element_id", publicResultFields: { element_id: { type: "string" } } } }, async () => []);
  await expect(authorizedWrite(tools, "batch_call", { calls: [{ tool: "create", args: { id: "a" }, alias: "created" }, { tool: "read", args: { id: { $ref: "created", path: "/secret" } } }] })).rejects.toMatchObject({ code: "workspace_reference_invalid" });
  expect(writes).toBe(0);
});
it("checks the declared type of public result projections", async () => {
  const tools = new DeclaredToolSet([{ name: "create", parameters: params, mutates: true, execute: async () => ({ content: [], details: { element_id: 7 } }), readback: async () => {}, rollback: async () => {} }], undefined, { create: { primaryResultField: "element_id", publicResultFields: { element_id: { type: "string" } } } }, async () => []);
  await expect(authorizedWrite(tools, "batch_call", { calls: [{ tool: "create", args: { id: "a" }, alias: "created" }] })).rejects.toMatchObject({ code: "workspace_batch_failed", details: { rollback: "restored" } });
});
