// SPDX-License-Identifier: GPL-2.0-or-later
import { expect, it } from "vitest";
import { ActionLedger } from "../src/session/action-ledger.js";
import { BackendPolicy } from "../src/session/backend-policy.js";
import { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";
import { checkSchema } from "../src/editor-tools/schema-guard.js";
import { WorkspaceRouter } from "../src/session/router.js";
import type { QualityFinding, SessionBinding, WorkspaceHostPort } from "../src/session/host-port.js";
import type { NestedEditorTool } from "../src/types.js";

it("binds the injected clock receiver", async () => {
  const clock = { value: 1, now() { return this.value; } };
  const ledger = new ActionLedger({ clock, decisionAuthority: { recordedDecision: async () => true } });
  const action = ledger.propose({ action: "page_tool:write", title: "Write", args: {}, session: "a", fresh: async () => true, execute: async () => true });
  clock.value += 300001;
  expect((await ledger.decide(action.actionId, "allow_once")).status).toBe("failed");
});
it("rechecks freshness after approval and fences late completion", async () => {
  let fresh = true, writes = 0;
  const ledger = new ActionLedger({ decisionAuthority: { recordedDecision: async () => { fresh = false; return true; } } });
  const action = ledger.propose({ action: "page_tool:write", title: "Write", args: {}, session: "a", fresh: async () => fresh, execute: async () => { writes++; } });
  expect((await ledger.decide(action.actionId, "allow_once")).status).toBe("failed"); expect(writes).toBe(0);
  const lateLedger = new ActionLedger({ decisionAuthority: { recordedDecision: async () => true } }); fresh = true;
  const late = lateLedger.propose({ action: "page_tool:write", title: "Late", args: {}, session: "a", fresh: async () => fresh, current: async () => fresh, execute: async () => { fresh = false; return true; } });
  expect((await lateLedger.decide(late.actionId, "allow_once")).status).toBe("failed");
});
it("approval cannot mutate nested approved arguments", async () => {
  const ledger = new ActionLedger({ decisionAuthority: { recordedDecision: async (proposal) => { try { (proposal.args.nested as { value: number }).value = 99; } catch {} return true; } } });
  const action = ledger.propose({ action: "page_tool:write", title: "Write", args: { nested: { value: 1 } }, session: "a", fresh: async () => true, execute: async (args) => args });
  expect((await ledger.decide(action.actionId, "allow_once")).result).toEqual({ nested: { value: 1 } });
});
it("discovery snapshots safety and schemas", async () => {
  const tool = { name: "write", category: "example", safe: true, dangerous: false, mutates: true, requiresConfirmation: true, inputSchema: { type: "object", additionalProperties: false, properties: {} } };
  const policy = new BackendPolicy({ discover: async () => ({ contract: "stonewright/visual-safe-tools/v1", tools: [tool] }), call: async () => "must not dispatch" }, [tool]);
  await policy.discover({}); tool.mutates = false; tool.requiresConfirmation = false;
  await expect(policy.read("write", {})).rejects.toThrow();
});
it("requires trusted host capability declarations and blocks dangerous name variants", async () => {
  let calls = 0;
  const tools = ["undeclared_safe", "stonewright_php_execute", "stonewright-security-issue-confirmation-token", "custom_code_apply", "wp_cli_run", "theme_file_write"].map((name) => ({ name, category: "example", safe: true, dangerous: false, mutates: false, requiresConfirmation: false, inputSchema: { type: "object" } }));
  const port = { discover: async () => ({ contract: "stonewright/visual-safe-tools/v1", tools }), call: async () => { calls++; } };
  const defaultPolicy = new BackendPolicy(port);
  expect(await defaultPolicy.discover({})).toEqual([]);
  await expect(defaultPolicy.read("undeclared_safe", {})).rejects.toThrow();
  const hostilePolicy = new BackendPolicy(port, tools.slice(1));
  expect(await hostilePolicy.discover({})).toEqual([]);
  expect(calls).toBe(0);
});
it("intersects discovered category and schema with immutable trusted policy", async () => {
  const declared = { name: "example_read", category: "example", mutates: false, requiresConfirmation: false, inputSchema: { type: "object", additionalProperties: false, properties: { id: { type: "integer" } }, required: ["id"] } };
  let row: any = { ...declared, safe: true, dangerous: false };
  const policy = new BackendPolicy({ discover: async () => ({ contract: "stonewright/visual-safe-tools/v1", tools: [row] }), call: async (_name, args) => args }, [declared]);
  declared.inputSchema.properties.id.type = "string";
  row = { ...row, inputSchema: { type: "object" } };
  expect(await policy.discover({})).toEqual([]);
  row = { ...row, inputSchema: { type: "object", additionalProperties: false, properties: { id: { type: "integer" } }, required: ["id"] } };
  expect((await policy.discover({})).length).toBe(1);
  expect(await policy.read("example_read", { id: 1 })).toEqual({ id: 1 });
});
it("denial returns a terminal receipt and proposed details remain immutable", async () => {
  const details = { nested: { value: 1 } };
  const ledger = new ActionLedger();
  const action = ledger.propose({ action: "page_tool:write", title: "Write", args: {}, details, session: "a", fresh: async () => true, execute: async () => true });
  details.nested.value = 99;
  expect(ledger.status(action.actionId).result).toEqual({ nested: { value: 1 } });
  expect(await ledger.decide(action.actionId, "deny")).toMatchObject({ status: "denied", terminal: true, requiresUserConfirmation: false });
});
it("rejects malformed semantic keyword values", () => {
  for (const schema of [{ type: [] }, { type: "object", additionalProperties: "false" }, { type: "array", minItems: -1 }, { type: "string", maxLength: 1.5 }, { type: "object", properties: [] }]) expect(() => checkSchema(schema)).toThrow();
});
it("public catalog calls cannot dispatch a write or forged applying permit", async () => {
  let writes = 0;
  const set = new DeclaredToolSet([{ name: "write", parameters: { type: "object" }, mutates: true, execute: async () => { writes++; return { content: [] }; }, readback: async () => {} }]);
  await expect(set.call("write", {})).rejects.toThrow();
  await expect(set.apply("write", {}, {} as never)).rejects.toThrow();
  expect(writes).toBe(0);
});
it("snapshots tool mutation gates, schema, and readback callbacks", async () => {
  let readbacks = 0;
  const tool: NestedEditorTool = { name: "write", parameters: { type: "object", additionalProperties: false }, mutates: true, execute: async () => ({ content: [] }), readback: async () => { readbacks++; } };
  const set = new DeclaredToolSet([tool]);
  tool.mutates = false; tool.readback = undefined; (tool.parameters as any).additionalProperties = true;
  await expect(set.call("write", {})).rejects.toThrow();
  expect(() => set.validate("write", { extra: true })).toThrow();
  expect(readbacks).toBe(0);
});
it("preflights proposal arguments and requires complete checked quality evidence", async () => {
  let writes = 0; let evidence: QualityFinding[] = [{ rule: "layout", evidenceType: "layout", checked: true, status: "pass" }];
  const tools = new DeclaredToolSet([{ name: "get_page_structure", parameters: { type: "object" }, execute: async () => ({ content: [] }) }, { name: "write", parameters: { type: "object", additionalProperties: false, properties: { value: { type: "integer" }, idempotency_key: { type: "string" } }, required: ["value"] }, mutates: true, execute: async () => { writes++; return { content: [] }; }, readback: async () => {} }]);
  const binding: SessionBinding = { id: "example", generation: 1, tools, closed: () => false, treeHash: async () => "tree", schemaHash: async () => "schema", directionHash: async () => "direction", requiredEvidence: ["layout", "accessibility"], verify: async () => evidence };
  const host: WorkspaceHostPort = { listPages: async () => [], openPage: async () => ({ id: "example", toolNames: [] }), closePage: async () => {}, focusPage: async () => {}, reloadPage: async () => {}, resolveEditor: async () => binding };
  const router = new WorkspaceRouter({ host, decisionAuthority: { recordedDecision: async () => true } });
  await router.dispatch("workspace_call_page_tool", { page_id: "example", tool: "get_page_structure" });
  await expect(router.dispatch("workspace_call_page_tool", { page_id: "example", tool: "write", args: { value: "bad" } })).rejects.toThrow();
  expect(router.actions.pending()).toEqual([]); expect(writes).toBe(0);
  for (const [key, findings] of [["missing", evidence], ["unchecked", [{ rule: "layout", evidenceType: "layout", checked: true, status: "pass" }, { rule: "a11y", evidenceType: "accessibility", checked: false, status: "pass" }]]] as Array<[string, QualityFinding[]]>) {
    evidence = findings;
    const action = await router.dispatch("workspace_call_page_tool", { page_id: "example", tool: "write", args: { value: 1, idempotency_key: key } }) as { actionId: string };
    expect(await router.dispatch("workspace_decide_confirmation", { action_id: action.actionId, decision: "allow_once" })).toMatchObject({ status: "failed", result: { failure: { write_landed: true, verification_status: "unverified" } } });
  }
  evidence = [{ rule: "layout", evidenceType: "layout", checked: true, status: "pass" }, { rule: "a11y", evidenceType: "accessibility", checked: true, status: "pass" }];
  const action = await router.dispatch("workspace_call_page_tool", { page_id: "example", tool: "write", args: { value: 1, idempotency_key: "complete" } }) as { actionId: string };
  expect(await router.dispatch("workspace_decide_confirmation", { action_id: action.actionId, decision: "allow_once" })).toMatchObject({ status: "succeeded" });
});
