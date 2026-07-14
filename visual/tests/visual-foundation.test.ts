import { describe, expect, it, vi } from "vitest";
import {
  PageToolRegistry,
  STONEWRIGHT_WORKSPACE_TOOL,
  WorkspaceBackendTools,
  WorkspaceConfirmations,
  WorkspaceDispatcher,
  createWorkspaceRequestHandler,
  summarizeToolInput,
  type BackendTransport,
  type NestedEditorTool,
} from "../src/index.js";

describe("Stonewright Visual foundation", () => {
  it("exposes one compact top-level workspace tool", () => {
    expect(STONEWRIGHT_WORKSPACE_TOOL.name).toBe("stonewright-workspace-request");
    expect(STONEWRIGHT_WORKSPACE_TOOL.inputSchema.properties.method.enum).toContain("workspace_call_page_tool");
    expect(JSON.stringify(STONEWRIGHT_WORKSPACE_TOOL).length).toBeLessThan(1800);
  });

  it("summarizes nested schemas without returning defaults or deep payloads", () => {
    const summary = summarizeToolInput({
      type: "object",
      required: ["element_id"],
      properties: {
        element_id: { type: "string", description: "x".repeat(300), default: "must-not-leak" },
        settings: { type: "object", properties: { title: { type: "string", default: "hidden" } } },
      },
    }) as Record<string, unknown>;
    expect(JSON.stringify(summary)).not.toContain("must-not-leak");
    expect(JSON.stringify(summary)).not.toContain("hidden");
    expect(JSON.stringify(summary).length).toBeLessThan(700);
  });

  it("resolves batch refs, performs mandatory readback, and rolls back failed mutations", async () => {
    const calls: Array<Record<string, unknown>> = [];
    const rollback = vi.fn<() => Promise<void>>().mockResolvedValue(undefined);
    const commit = vi.fn<() => Promise<void>>().mockResolvedValue(undefined);
    const tools: NestedEditorTool[] = [
      {
        name: "create_element",
        mutates: true,
        parameters: { type: "object", required: ["widget_type"] },
        execute: async (args) => ({ content: [{ type: "text", text: "created" }], details: { element_id: "el-1", args } }),
        readback: async () => ({ id: "el-1", type: "button" }),
      },
      {
        name: "update_element",
        mutates: true,
        parameters: { type: "object", required: ["element_id"] },
        execute: async (args) => { calls.push(args); return { content: [{ type: "text", text: "updated" }] }; },
        readback: async () => ({ title: "Buy" }),
      },
      {
        name: "explode",
        execute: async () => { throw new Error("broken"); },
      },
    ];
    const registry = new PageToolRegistry(tools, { begin: async () => ({ rollback, commit }) });
    const result = await registry.batchCall({
      calls: [
        { tool: "create_element", args: { widgetType: "button" }, id: "cta" },
        { tool: "update_element", args: { elementId: "$cta" } },
        { tool: "explode" },
      ],
    });

    expect(calls[0]?.element_id).toBe("el-1");
    expect(result.details?.rolled_back).toBe(true);
    expect(rollback).toHaveBeenCalledOnce();
    expect(commit).not.toHaveBeenCalled();
    expect(result.details?.refs).toEqual({ cta: "el-1" });
  });

  it("rejects mutation tools that cannot prove readback", async () => {
    const registry = new PageToolRegistry([{ name: "update_element", mutates: true, execute: async () => ({ content: [{ type: "text", text: "ok" }] }) }]);
    await expect(registry.call("update_element", {})).rejects.toThrow("mandatory readback");
  });

  it("queues backend writes for explicit confirmation and runs approved actions", async () => {
    const transport: BackendTransport = {
      discover: async () => ({ tools: [{ name: "update_option", safe: true, mutates: true }] }),
      call: vi.fn(async () => ({ ok: true })),
    };
    const confirmations = new WorkspaceConfirmations();
    const backend = new WorkspaceBackendTools(transport, confirmations);
    const pending = await backend.call("update_option", { name: "blogname" }, "Update the approved site title") as { actionId: string; status: string };
    expect(pending.status).toBe("waiting_for_confirmation");
    const approved = await confirmations.decide(pending.actionId, "allow_once");
    expect(approved.status).toBe("succeeded");
    expect(transport.call).toHaveBeenCalledWith("update_option", { name: "blogname" });
  });

  it("dispatches only allowlisted workspace methods and keeps nested tools nested", async () => {
    const confirmations = new WorkspaceConfirmations();
    const backend = new WorkspaceBackendTools({ discover: async () => ({ tools: [] }), call: async () => ({}) }, confirmations);
    const pageTools = new PageToolRegistry([{ name: "get_page_structure", execute: async () => ({ content: [{ type: "text", text: "tree" }] }) }]);
    const dispatcher = new WorkspaceDispatcher({
      status: () => ({ ok: true }),
      listPages: () => [{ id: "p1", toolNames: ["get_page_structure"] }],
      getPageTools: () => pageTools,
    }, backend, confirmations);
    const handler = createWorkspaceRequestHandler(dispatcher);
    const result = await handler({ method: "workspace_call_page_tool", params: { pageId: "p1", toolName: "get_page_structure" } });
    expect(result).toMatchObject({ content: [{ text: "tree" }] });
    await expect(handler({ method: "workspace_eval", params: {} })).rejects.toThrow("Unknown workspace method");
  });
});
