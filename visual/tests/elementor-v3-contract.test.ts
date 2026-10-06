// SPDX-License-Identifier: AGPL-3.0-or-later
import { describe, expect, it } from "vitest";
import { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";
import { ElementorV3EditorAdapter } from "../src/elementor-v3/editor-adapter.js";
import type { ElementorV3Element, ElementorV3Runtime, ElementorV3WidgetSchema } from "../src/elementor-v3/types.js";
import type { SessionBinding, WorkspaceHostPort } from "../src/session/host-port.js";
import { WorkspaceRouter } from "../src/session/router.js";
import type { NestedEditorTool } from "../src/types.js";
import { authorizedWrite } from "./authorized-write.js";
import { auditDeclaredSchema } from "./schema-audit.js";

/** The nested tools the V3 adapter documents, split by whether they change the document. */
const READS = ["list_widgets", "get_widget_schema", "get_page_structure", "get_element", "get_evidence_ledger"];
const WRITES = ["create_element", "update_settings", "move_element", "delete_element", "undo", "redo", "save"];
const ALL = [...READS, ...WRITES];
const UNBATCHABLE = ["undo", "redo", "save"];

/** The smallest argument set each tool accepts. */
const VALID: Record<string, Record<string, unknown>> = {
  list_widgets: {},
  get_widget_schema: { widget_type: "heading" },
  get_page_structure: {},
  get_element: { element_id: "c100" },
  get_evidence_ledger: {},
  create_element: { element_type: "container", idempotency_key: "create-0001" },
  update_settings: { element_id: "c100", settings: {}, idempotency_key: "update-0001" },
  move_element: { element_id: "c100", idempotency_key: "move-0001" },
  delete_element: { element_id: "c100", confirm_delete: true, idempotency_key: "delete-0001" },
  undo: {},
  redo: {},
  save: {},
};

/** Arguments each tool cannot run without, taken from the adapter's own execution checks. */
const REQUIRED: Record<string, string[]> = {
  list_widgets: [],
  get_widget_schema: ["widget_type"],
  get_page_structure: [],
  get_element: ["element_id"],
  get_evidence_ledger: [],
  create_element: ["element_type", "idempotency_key"],
  update_settings: ["element_id", "settings", "idempotency_key"],
  move_element: ["element_id", "idempotency_key"],
  delete_element: ["element_id", "confirm_delete", "idempotency_key"],
  undo: [],
  redo: [],
  save: [],
};

/** Wrongly shaped arguments the declared schema has to refuse. */
const MALFORMED: Record<string, Array<Record<string, unknown>>> = {
  list_widgets: [{ page: "2" }, { per_page: 2.5 }, { search: ["heading"] }],
  get_widget_schema: [{ widget_type: 7 }, { widget_type: "heading", mode: "everything" }, { widget_type: "heading", control_names: "title" }],
  get_page_structure: [{ mode: "tree" }, { max_elements: "50" }],
  get_element: [{ element_id: 100 }],
  get_evidence_ledger: [{ element_id: "c100" }],
  create_element: [
    { element_type: "section", idempotency_key: "create-0001" },
    { element_type: "container", idempotency_key: "short" },
    { element_type: "container", position: 0.5, idempotency_key: "create-0001" },
    { element_type: "container", allowed_breakpoints: [], idempotency_key: "create-0001" },
    { element_type: "container", settings: [], idempotency_key: "create-0001" },
  ],
  update_settings: [
    { element_id: "c100", settings: "title=Hello", idempotency_key: "update-0001" },
    { element_id: "c100", settings: {}, settings_evidence: [], idempotency_key: "update-0001" },
    { element_id: "c100", settings: {}, allowed_breakpoints: ["mobile", 3], idempotency_key: "update-0001" },
  ],
  move_element: [{ element_id: "c100", position: "1", idempotency_key: "move-0001" }, { element_id: "c100", parent_id: null, idempotency_key: "move-0001" }],
  delete_element: [{ element_id: "c100", confirm_delete: false, idempotency_key: "delete-0001" }],
  undo: [{ steps: 2 }],
  redo: [{ steps: 2 }],
  save: [{ force: true }],
};

/** Maps keyed by live control names; their contents are checked against the live schema when the write runs. */
const LIVE_SCHEMA_MAPS: Record<string, string[]> = {
  create_element: ["settings", "settings_evidence"],
  update_settings: ["settings", "settings_evidence"],
};

const HEADING: ElementorV3WidgetSchema = { widget_type: "heading", title: "Heading", controls: { title: { type: "text" } } };

/** An in-memory editor runtime that records every call the adapter makes into it. */
function syntheticEditor(options: { staysModified?: boolean } = {}) {
  const calls: string[] = [];
  let elements: ElementorV3Element[] = [{ id: "c100", elType: "container", settings: {}, children: [] }];
  const past: ElementorV3Element[][] = [];
  const future: ElementorV3Element[][] = [];
  let modified = false;
  const find = (id: string): ElementorV3Element | undefined => elements.find((element) => element.id === id);
  const change = (apply: () => void): void => { past.push(structuredClone(elements)); future.length = 0; apply(); modified = true; };
  const runtime: ElementorV3Runtime = {
    version: "3.0.0-synthetic",
    documentId: "101",
    async listWidgets() { calls.push("listWidgets"); return [{ name: "heading", title: "Heading" }]; },
    async getWidgetSchema(type) { calls.push("getWidgetSchema"); return type === "heading" ? structuredClone(HEADING) : null; },
    async getContainerSchema() { calls.push("getContainerSchema"); return { widget_type: "container", controls: {} }; },
    async getPageTree() { calls.push("getPageTree"); return structuredClone(elements); },
    async getElement(id) { calls.push("getElement"); const found = find(id); return found ? structuredClone(found) : null; },
    async createElement(input) {
      calls.push("createElement");
      const element: ElementorV3Element = { id: `c${101 + elements.length}`, elType: input.elType, ...(input.widgetType ? { widgetType: input.widgetType } : {}), settings: structuredClone(input.settings), children: [] };
      change(() => elements.push(element));
      return structuredClone(element);
    },
    async updateSettings(id, settings) { calls.push("updateSettings"); change(() => Object.assign(find(id)!.settings, settings)); },
    async moveElement(id, parentId, position) { calls.push("moveElement"); change(() => Object.assign(find(id)!, { parentId, position })); },
    async deleteElement(id) { calls.push("deleteElement"); change(() => { elements = elements.filter((element) => element.id !== id); }); },
    async undo() { calls.push("undo"); const previous = past.pop(); if (previous) { future.push(structuredClone(elements)); elements = previous; modified = true; } },
    async redo() { calls.push("redo"); const next = future.pop(); if (next) { past.push(structuredClone(elements)); elements = next; modified = true; } },
    async save() { calls.push("save"); modified = options.staysModified === true; },
    async isModified() { calls.push("isModified"); return modified; },
  };
  /** Changes the tree outside the adapter, as another editing session would. */
  const foreignEdit = (): void => { elements = [...elements, { id: "c900", elType: "container", settings: {}, children: [] }]; };
  return { runtime, calls, foreignEdit };
}

function catalog(editor = syntheticEditor()): DeclaredToolSet {
  return new ElementorV3EditorAdapter(editor.runtime).registry();
}

function declaration(name: string): NestedEditorTool {
  const tool = new ElementorV3EditorAdapter(syntheticEditor().runtime).tools().find((candidate) => candidate.name === name);
  if (!tool) throw new Error(`${name} is not declared`);
  return tool;
}

function summary(tools: DeclaredToolSet, name: string): { required?: string[]; properties?: Record<string, unknown> } {
  return tools.definitions().find((definition) => definition.name === name)?.parameters as { required?: string[]; properties?: Record<string, unknown> };
}

function without(args: Record<string, unknown>, key: string): Record<string, unknown> {
  const copy = { ...args };
  delete copy[key];
  return copy;
}

/** Reads go through the public call path; writes go through a recorded approval and a one-use permit. */
function attempt(tools: DeclaredToolSet, name: string, args: Record<string, unknown>): Promise<unknown> {
  return WRITES.includes(name) ? authorizedWrite(tools, name, args) : tools.call(name, args);
}

function thrownBy(run: () => unknown): unknown {
  try { run(); } catch (cause) { return cause; }
  return undefined;
}

describe("V3 adapter declarations in the strict catalog", () => {
  it("builds the adapter registry from every documented tool", () => {
    expect(catalog().definitions().map((tool) => tool.name).sort()).toEqual([...ALL].sort());
  });

  it.each(ALL)("accepts the %s declaration on its own", (name) => {
    expect(() => new DeclaredToolSet([declaration(name)])).not.toThrow();
  });

  it.each(ALL)("declares %s as a closed object that states what every argument accepts", (name) => {
    const audit = auditDeclaredSchema(declaration(name).parameters);
    expect(audit.problems).toEqual([]);
    expect(audit.openMaps.sort()).toEqual([...(LIVE_SCHEMA_MAPS[name] ?? [])].sort());
  });

  it("publishes each tool's required arguments and limits in the catalog summary", () => {
    const tools = catalog();
    for (const name of ALL) expect([...(summary(tools, name).required ?? [])].sort()).toEqual([...REQUIRED[name]].sort());
    expect(summary(tools, "create_element").properties?.idempotency_key).toEqual({ type: "string", minLength: 8 });
    expect(summary(tools, "delete_element").properties?.confirm_delete).toEqual({ type: "boolean", const: true });
  });

  it("publishes an empty closed object for tools that take no arguments", () => {
    const tools = catalog();
    for (const name of ["get_evidence_ledger", "undo", "redo", "save"]) {
      expect(summary(tools, name)).toEqual({ type: "object", additionalProperties: false, properties: {} });
    }
  });
});

describe("V3 argument enforcement", () => {
  it.each(ALL)("accepts the minimal %s arguments without calling the editor", (name) => {
    const editor = syntheticEditor();
    expect(() => catalog(editor).validate(name, VALID[name])).not.toThrow();
    expect(editor.calls).toEqual([]);
  });

  it.each(ALL)("refuses %s with a missing, undeclared, or malformed argument before any editor call", async (name) => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    const refused = [...REQUIRED[name].map((key) => without(VALID[name], key)), { ...VALID[name], unexpected: true }, ...MALFORMED[name]];
    for (const args of refused) await expect(attempt(tools, name, args)).rejects.toMatchObject({ code: "workspace_args_invalid" });
    expect(editor.calls).toEqual([]);
  });

  it("refuses a widget create that names no widget type before any editor call", async () => {
    const editor = syntheticEditor();
    await expect(authorizedWrite(catalog(editor), "create_element", { element_type: "widget", idempotency_key: "create-0002" })).rejects.toThrow(/widget_type is required/);
    expect(editor.calls).toEqual([]);
  });

  it("checks batch calls against each declared schema and the declared create result", () => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    const create = { tool: "create_element", alias: "hero", args: VALID.create_element };
    expect(() => tools.validate("batch_call", { calls: [create, { tool: "move_element", args: { element_id: "$hero", position: 0, idempotency_key: "move-0001" } }] })).not.toThrow();
    expect(thrownBy(() => tools.validate("batch_call", { calls: [create, { tool: "move_element", args: { element_id: "$hero", position: "first", idempotency_key: "move-0001" } }] }))).toMatchObject({ code: "workspace_args_invalid" });
    expect(editor.calls).toEqual([]);
  });
});

describe("V3 mutations", () => {
  it("flags exactly the documented writes as mutating and gives each one a readback", () => {
    const tools = new ElementorV3EditorAdapter(syntheticEditor().runtime).tools();
    const mutating = tools.filter((tool) => tool.mutates);
    expect(mutating.map((tool) => tool.name).sort()).toEqual([...WRITES].sort());
    for (const tool of mutating) expect(typeof tool.readback).toBe("function");
    expect(catalog().definitions().filter((definition) => definition.mutates).map((definition) => definition.name).sort()).toEqual([...WRITES].sort());
  });

  it.each(WRITES)("refuses an unapproved %s through the public call path without calling the editor", async (name) => {
    const editor = syntheticEditor();
    await expect(catalog(editor).call(name, VALID[name])).rejects.toMatchObject({ code: "workspace_applying_required" });
    expect(editor.calls).toEqual([]);
  });

  it("keeps history and save calls out of batches", () => {
    const tools = catalog();
    for (const name of UNBATCHABLE) {
      expect(tools.definitions().find((definition) => definition.name === name)).toMatchObject({ mutates: true, batchable: false });
      expect(thrownBy(() => tools.validate("batch_call", { calls: [{ tool: name, args: {} }] }))).toMatchObject({ code: "workspace_batch_invalid" });
    }
  });

  it("reads an approved create back from the live editor", async () => {
    const editor = syntheticEditor();
    const outcome = await authorizedWrite(catalog(editor), "create_element", VALID.create_element);
    expect(outcome).toMatchObject({ details: { element_id: "c102" } });
    expect(editor.calls).toEqual(["getContainerSchema", "createElement", "getElement"]);
  });

  it("applies an approved undo and redo through editor history", async () => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    await authorizedWrite(tools, "create_element", VALID.create_element);
    expect(await tools.call("get_page_structure", {})).toMatchObject({ details: { count: 2 } });
    const before = editor.calls.length;
    expect(await authorizedWrite(tools, "undo", VALID.undo)).toMatchObject({ details: { tree_hash: expect.any(String) } });
    expect(editor.calls.slice(before).filter((call) => call === "undo")).toHaveLength(1);
    expect(await tools.call("get_page_structure", {})).toMatchObject({ details: { count: 1 } });
    await authorizedWrite(tools, "redo", VALID.redo);
    expect(await tools.call("get_page_structure", {})).toMatchObject({ details: { count: 2 } });
  });

  it.each(["undo", "redo"] as const)("reads %s back from the live editor tree instead of trusting its own result", async (name) => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    await authorizedWrite(tools, "create_element", VALID.create_element);
    if (name === "redo") await authorizedWrite(tools, "undo", VALID.undo);
    const history = new ElementorV3EditorAdapter(editor.runtime).tools().find((tool) => tool.name === name)!;
    const output = await history.execute({});
    const before = editor.calls.length;
    expect(await history.readback!({}, output)).toEqual({ tree_hash: output.details?.tree_hash });
    expect(editor.calls.slice(before)).toEqual(["getPageTree"]);
    editor.foreignEdit();
    await expect(history.readback!({}, output)).rejects.toThrow(/readback/);
  });

  it("verifies an approved save and fails it when the document stays modified", async () => {
    const clean = syntheticEditor();
    await authorizedWrite(catalog(clean), "save", VALID.save);
    expect(clean.calls).toEqual(["save", "getPageTree", "isModified", "getPageTree"]);
    const dirty = syntheticEditor({ staysModified: true });
    await expect(authorizedWrite(catalog(dirty), "save", VALID.save)).rejects.toThrow(/still modified/);
  });

  it("returns the evidence ledger without calling the editor", async () => {
    const editor = syntheticEditor();
    expect(await catalog(editor).call("get_evidence_ledger", {})).toMatchObject({ details: { entries: [] } });
    expect(editor.calls).toEqual([]);
  });
});

describe("V3 catalog behind the session router", () => {
  it("lists the nested tools and stages a valid mutation without editor or backend calls", async () => {
    const editor = syntheticEditor();
    let backendCalls = 0;
    const binding: SessionBinding = { id: "page-a", generation: 1, tools: catalog(editor), closed: () => false, treeHash: async () => "tree-a", schemaHash: async () => "schema-a", directionHash: async () => "direction-a" };
    const host: WorkspaceHostPort = { listPages: async () => [], openPage: async () => ({ id: "page-a", toolNames: [] }), closePage: async () => undefined, focusPage: async () => undefined, reloadPage: async () => undefined, resolveEditor: async () => binding };
    const router = new WorkspaceRouter({ host, backend: { discover: async () => { backendCalls++; return {}; }, call: async () => { backendCalls++; return null; } } });

    const listed = await router.dispatch("workspace_list_page_tools", { page_id: "page-a" }) as Array<{ name: string }>;
    expect(listed.map((tool) => tool.name).sort()).toEqual([...ALL].sort());
    await router.dispatch("workspace_call_page_tool", { page_id: "page-a", tool: "get_page_structure" });
    expect(editor.calls).toEqual(["getPageTree"]);

    await expect(router.dispatch("workspace_call_page_tool", { page_id: "page-a", tool: "delete_element", args: { ...VALID.delete_element, confirm_delete: false } })).rejects.toMatchObject({ code: "workspace_args_invalid" });
    await expect(router.dispatch("workspace_call_page_tool", { page_id: "page-a", tool: "undo", args: { steps: 2 } })).rejects.toMatchObject({ code: "workspace_args_invalid" });
    expect(router.actions.pending()).toEqual([]);
    expect(await router.dispatch("workspace_call_page_tool", { page_id: "page-a", tool: "save" })).toMatchObject({ status: "waiting_for_confirmation", requiresUserConfirmation: true });
    expect(editor.calls).toEqual(["getPageTree"]);
    expect(backendCalls).toBe(0);
  });
});
