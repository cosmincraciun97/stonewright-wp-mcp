// SPDX-License-Identifier: GPL-2.0-or-later
import { describe, expect, it } from "vitest";
import { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";
import { ElementorV4EditorAdapter } from "../src/elementor-v4/editor-adapter.js";
import type { AtomicElementSchema, ElementorV4Element, ElementorV4Runtime } from "../src/elementor-v4/types.js";
import type { SessionBinding, WorkspaceHostPort } from "../src/session/host-port.js";
import { WorkspaceRouter } from "../src/session/router.js";
import type { NestedEditorTool } from "../src/types.js";
import { authorizedWrite } from "./authorized-write.js";
import { auditDeclaredSchema } from "./schema-audit.js";

/** The nested tools the V4 adapter declares, split by whether they change the document. */
const READS = ["list_widgets", "get_widget_schema", "get_page_structure", "get_element"];
const WRITES = ["create_element", "update_settings", "move_element", "delete_element", "undo", "redo", "save"];
const ALL = [...READS, ...WRITES];
const UNBATCHABLE = ["undo", "redo", "save"];

/** The smallest argument set each tool accepts. Every V4 write carries an explicit write confirmation. */
const VALID: Record<string, Record<string, unknown>> = {
  list_widgets: {},
  get_widget_schema: { atomic_type: "heading" },
  get_page_structure: {},
  get_element: { element_id: "a100" },
  create_element: { atomic_type: "heading", confirm_write: true, idempotency_key: "create-0001" },
  update_settings: { element_id: "a100", confirm_write: true, idempotency_key: "update-0001" },
  move_element: { element_id: "a100", confirm_write: true, idempotency_key: "move-0001" },
  delete_element: { element_id: "a100", confirm_delete: true, confirm_write: true, idempotency_key: "delete-0001" },
  undo: { confirm_write: true },
  redo: { confirm_write: true },
  save: { confirm_write: true },
};

/** Arguments each tool cannot run without, taken from the adapter's own execution checks. */
const REQUIRED: Record<string, string[]> = {
  list_widgets: [],
  get_widget_schema: ["atomic_type"],
  get_page_structure: [],
  get_element: ["element_id"],
  create_element: ["atomic_type", "confirm_write", "idempotency_key"],
  update_settings: ["element_id", "confirm_write", "idempotency_key"],
  move_element: ["element_id", "confirm_write", "idempotency_key"],
  delete_element: ["element_id", "confirm_delete", "confirm_write", "idempotency_key"],
  undo: ["confirm_write"],
  redo: ["confirm_write"],
  save: ["confirm_write"],
};

/**
 * Payload parts the element envelope cannot hold: settings, styles and editor
 * metadata are objects, and interactions are either a list of interaction
 * objects or one versioned object.
 */
const MALFORMED_PAYLOADS: Array<Record<string, unknown>> = [
  { settings: [] },
  { styles: "card" },
  { editor_settings: ["compact"] },
  { interactions: "fade-in" },
  { interactions: null },
  { interactions: [7] },
];

/** Wrongly shaped arguments the declared schema has to refuse. */
const MALFORMED: Record<string, Array<Record<string, unknown>>> = {
  list_widgets: [{ search: 7 }, { search: ["heading"] }],
  get_widget_schema: [{ atomic_type: 7 }, { atomic_type: ["heading"] }],
  get_page_structure: [{ mode: "full" }, { max_elements: 10 }],
  get_element: [{ element_id: 100 }, { element_id: null }],
  create_element: [
    { ...VALID.create_element, confirm_write: false },
    { ...VALID.create_element, confirm_write: "true" },
    { ...VALID.create_element, idempotency_key: 1 },
    { ...VALID.create_element, position: 0.5 },
    { ...VALID.create_element, parent_id: 7 },
    ...MALFORMED_PAYLOADS.map((payload) => ({ ...VALID.create_element, ...payload })),
  ],
  update_settings: [
    { ...VALID.update_settings, confirm_write: false },
    { ...VALID.update_settings, element_id: 100 },
    ...MALFORMED_PAYLOADS.map((payload) => ({ ...VALID.update_settings, ...payload })),
  ],
  move_element: [
    { ...VALID.move_element, confirm_write: false },
    { ...VALID.move_element, position: "1" },
    { ...VALID.move_element, parent_id: null },
  ],
  delete_element: [{ ...VALID.delete_element, confirm_delete: false }, { ...VALID.delete_element, confirm_write: false }],
  undo: [{ confirm_write: false }, { confirm_write: 1 }],
  redo: [{ confirm_write: false }, { confirm_write: 1 }],
  save: [{ confirm_write: false }, { confirm_write: "yes" }],
};

/**
 * Payload maps whose contents the live editor defines. The adapter checks
 * settings against the live prop schema and the whole element envelope when
 * the write runs; the declaration only fixes their outer shape.
 */
const EDITOR_PAYLOAD_MAPS: Record<string, string[]> = {
  create_element: ["settings", "styles", "editor_settings", "interactions", "interactions[]"],
  update_settings: ["settings", "styles", "editor_settings", "interactions", "interactions[]"],
};

const BOX: AtomicElementSchema = { atomic_type: "box", kind: "layout", version: "1.0", props: {}, source: "synthetic-runtime" };
const HEADING: AtomicElementSchema = { atomic_type: "heading", kind: "widget", version: "1.0", props: { title: { properties: { $$type: { const: "string" } } } }, source: "synthetic-runtime" };

/** An in-memory V4 editor runtime that records every call the adapter makes into it. */
function syntheticEditor(options: { staysModified?: boolean } = {}) {
  const calls: string[] = [];
  let elements: ElementorV4Element[] = [{ id: "a100", version: "1.0", elType: "box", isInner: false, settings: {}, styles: {}, editor_settings: {}, interactions: [], elements: [] }];
  const past: ElementorV4Element[][] = [];
  let modified = false;
  const find = (id: string): ElementorV4Element | undefined => elements.find((element) => element.id === id);
  const change = (apply: () => void): void => { past.push(structuredClone(elements)); apply(); modified = true; };
  const runtime: ElementorV4Runtime = {
    version: "1.0-synthetic",
    documentId: "201",
    async listAtomicTypes() { calls.push("listAtomicTypes"); return structuredClone([BOX, HEADING]); },
    async getAtomicSchema(type) { calls.push("getAtomicSchema"); const schema = [BOX, HEADING].find((row) => row.atomic_type === type); return schema ? structuredClone(schema) : null; },
    async getPageTree() { calls.push("getPageTree"); return structuredClone(elements); },
    async getElement(id) { calls.push("getElement"); const found = find(id); return found ? structuredClone(found) : null; },
    async createElement(input) {
      calls.push("createElement");
      const element: ElementorV4Element = { id: `a${101 + elements.length}`, ...structuredClone(input.payload), elements: [] };
      change(() => elements.push(element));
      return structuredClone(element);
    },
    async updateElement(id, patch) { calls.push("updateElement"); change(() => Object.assign(find(id)!, structuredClone(patch))); },
    async moveElement(id, parentId, position) { calls.push("moveElement"); change(() => Object.assign(find(id)!, { parentId, position })); },
    async deleteElement(id) { calls.push("deleteElement"); change(() => { elements = elements.filter((element) => element.id !== id); }); },
    async undo() { calls.push("undo"); const previous = past.pop(); if (previous) { elements = previous; modified = true; } },
    async redo() { calls.push("redo"); },
    async save() { calls.push("save"); modified = options.staysModified === true; },
    async isModified() { calls.push("isModified"); return modified; },
    async verifyFrontend(id) { calls.push("verifyFrontend"); return { exists: elements.some((element) => element.id === id) }; },
  };
  return { runtime, calls };
}

function catalog(editor = syntheticEditor()): DeclaredToolSet {
  return new ElementorV4EditorAdapter(editor.runtime).registry();
}

function declaration(name: string): NestedEditorTool {
  const tool = new ElementorV4EditorAdapter(syntheticEditor().runtime).tools().find((candidate) => candidate.name === name);
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

describe("V4 adapter declarations in the strict catalog", () => {
  it("builds the adapter registry from every declared tool", () => {
    expect(catalog().definitions().map((tool) => tool.name).sort()).toEqual([...ALL].sort());
  });

  it.each(ALL)("accepts the %s declaration on its own", (name) => {
    expect(() => new DeclaredToolSet([declaration(name)])).not.toThrow();
  });

  it.each(ALL)("declares %s as a closed object that states what every argument accepts", (name) => {
    const audit = auditDeclaredSchema(declaration(name).parameters);
    expect(audit.problems).toEqual([]);
    expect(audit.openMaps.sort()).toEqual([...(EDITOR_PAYLOAD_MAPS[name] ?? [])].sort());
  });

  it("publishes each tool's required arguments and confirmation constants in the catalog summary", () => {
    const tools = catalog();
    for (const name of ALL) expect([...(summary(tools, name).required ?? [])].sort()).toEqual([...REQUIRED[name]].sort());
    for (const name of WRITES) expect(summary(tools, name).properties?.confirm_write).toEqual({ const: true });
    expect(summary(tools, "delete_element").properties?.confirm_delete).toEqual({ const: true });
    expect(summary(tools, "get_page_structure")).toEqual({ type: "object", additionalProperties: false, properties: {} });
    for (const name of ["create_element", "update_settings"]) {
      expect(summary(tools, name).properties?.interactions).toEqual({ type: ["array", "object"], items: { type: "object" } });
    }
  });
});

describe("V4 argument enforcement", () => {
  it.each(ALL)("accepts the minimal %s arguments without calling the editor", (name) => {
    const editor = syntheticEditor();
    expect(() => catalog(editor).validate(name, VALID[name])).not.toThrow();
    expect(editor.calls).toEqual([]);
  });

  it("accepts both interaction forms the element envelope holds", () => {
    const tools = catalog();
    for (const interactions of [[], [{ trigger: "load", effect: "fade" }], { version: 1, items: [] }]) {
      expect(() => tools.validate("create_element", { ...VALID.create_element, interactions })).not.toThrow();
      expect(() => tools.validate("update_settings", { ...VALID.update_settings, interactions })).not.toThrow();
    }
  });

  it.each(ALL)("refuses %s with a missing, undeclared, or malformed argument before any editor call", async (name) => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    const refused = [...REQUIRED[name].map((key) => without(VALID[name], key)), { ...VALID[name], unexpected: true }, ...MALFORMED[name]];
    for (const args of refused) await expect(attempt(tools, name, args)).rejects.toMatchObject({ code: "workspace_args_invalid" });
    expect(editor.calls).toEqual([]);
  });

  it("refuses blank identifiers and keys before any editor call", async () => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    await expect(authorizedWrite(tools, "create_element", { ...VALID.create_element, atomic_type: "  " })).rejects.toThrow(/atomic_type is required/);
    await expect(authorizedWrite(tools, "create_element", { ...VALID.create_element, idempotency_key: "" })).rejects.toThrow(/idempotency_key is required/);
    await expect(authorizedWrite(tools, "update_settings", { ...VALID.update_settings, element_id: " " })).rejects.toThrow(/element_id is required/);
    expect(editor.calls).toEqual([]);
  });

  it("checks batch calls against each declared schema and the declared create result", () => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    const create = { tool: "create_element", alias: "card", args: VALID.create_element };
    const move = { confirm_write: true, idempotency_key: "move-0001" };
    expect(() => tools.validate("batch_call", { calls: [create, { tool: "move_element", args: { ...move, element_id: "$card", position: 0 } }] })).not.toThrow();
    expect(thrownBy(() => tools.validate("batch_call", { calls: [create, { tool: "move_element", args: { ...move, element_id: "$card", position: "first" } }] }))).toMatchObject({ code: "workspace_args_invalid" });
    expect(thrownBy(() => tools.validate("batch_call", { calls: [{ ...create, args: { ...VALID.create_element, interactions: "fade-in" } }] }))).toMatchObject({ code: "workspace_args_invalid" });
    expect(editor.calls).toEqual([]);
  });
});

describe("V4 reads", () => {
  it("returns the native tree with its hash and no implicit conversion", async () => {
    const editor = syntheticEditor();
    const outcome = await catalog(editor).call("get_page_structure", {});
    expect(outcome).toMatchObject({ details: { document_id: "201", architecture: "v4", implicit_conversion: false, tree_hash: expect.any(String), tree: [{ id: "a100", elType: "box" }] } });
    expect(editor.calls).toEqual(["getPageTree"]);
  });
});

describe("V4 mutations", () => {
  it("flags exactly the declared writes as mutating and gives each one a readback", () => {
    const tools = new ElementorV4EditorAdapter(syntheticEditor().runtime).tools();
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
      expect(thrownBy(() => tools.validate("batch_call", { calls: [{ tool: name, args: VALID[name] }] }))).toMatchObject({ code: "workspace_batch_invalid" });
    }
  });

  it("reads an approved create back from the editor model and the rendered preview", async () => {
    const editor = syntheticEditor();
    const outcome = await authorizedWrite(catalog(editor), "create_element", VALID.create_element);
    expect(outcome).toMatchObject({ details: { element_id: "a102", atomic_type: "heading" } });
    expect(editor.calls).toEqual(["getAtomicSchema", "createElement", "getElement", "getAtomicSchema", "verifyFrontend"]);
  });

  it("writes an approved interaction list and reads the same payload back", async () => {
    const editor = syntheticEditor();
    const interactions = [{ trigger: "load", effect: "fade" }];
    const outcome = await authorizedWrite(catalog(editor), "update_settings", { ...VALID.update_settings, interactions });
    expect(outcome).toMatchObject({ details: { element_id: "a100", patch_keys: ["interactions"] } });
    expect((await editor.runtime.getElement("a100"))?.interactions).toEqual(interactions);
  });

  it("applies an approved undo and reads the tree back afterwards", async () => {
    const editor = syntheticEditor();
    const tools = catalog(editor);
    await authorizedWrite(tools, "create_element", VALID.create_element);
    const before = editor.calls.length;
    expect(await authorizedWrite(tools, "undo", VALID.undo)).toMatchObject({ details: { tree_hash: expect.any(String) } });
    expect(editor.calls.slice(before)).toEqual(["getPageTree", "undo", "getPageTree", "getPageTree"]);
    expect(await editor.runtime.getPageTree()).toHaveLength(1);
  });

  it("verifies an approved save and fails it when the document stays modified", async () => {
    const clean = syntheticEditor();
    await authorizedWrite(catalog(clean), "save", VALID.save);
    expect(clean.calls).toEqual(["save", "getPageTree", "isModified"]);
    const dirty = syntheticEditor({ staysModified: true });
    await expect(authorizedWrite(catalog(dirty), "save", VALID.save)).rejects.toThrow(/save readback failed/);
  });
});

describe("V4 catalog behind the session router", () => {
  it("lists the nested tools and stages only a confirmed, well-formed mutation without editor or backend calls", async () => {
    const editor = syntheticEditor();
    let backendCalls = 0;
    const binding: SessionBinding = { id: "page-b", generation: 1, tools: catalog(editor), closed: () => false, treeHash: async () => "tree-b", schemaHash: async () => "schema-b", directionHash: async () => "direction-b" };
    const host: WorkspaceHostPort = { listPages: async () => [], openPage: async () => ({ id: "page-b", toolNames: [] }), closePage: async () => undefined, focusPage: async () => undefined, reloadPage: async () => undefined, resolveEditor: async () => binding };
    const router = new WorkspaceRouter({ host, backend: { discover: async () => { backendCalls++; return {}; }, call: async () => { backendCalls++; return null; } } });

    const listed = await router.dispatch("workspace_list_page_tools", { page_id: "page-b" }) as Array<{ name: string }>;
    expect(listed.map((tool) => tool.name).sort()).toEqual([...ALL].sort());
    await router.dispatch("workspace_call_page_tool", { page_id: "page-b", tool: "get_page_structure" });
    expect(editor.calls).toEqual(["getPageTree"]);

    await expect(router.dispatch("workspace_call_page_tool", { page_id: "page-b", tool: "save" })).rejects.toMatchObject({ code: "workspace_args_invalid" });
    await expect(router.dispatch("workspace_call_page_tool", { page_id: "page-b", tool: "create_element", args: { ...VALID.create_element, interactions: "fade-in" } })).rejects.toMatchObject({ code: "workspace_args_invalid" });
    expect(router.actions.pending()).toEqual([]);
    expect(await router.dispatch("workspace_call_page_tool", { page_id: "page-b", tool: "save", args: VALID.save })).toMatchObject({ status: "waiting_for_confirmation", requiresUserConfirmation: true });
    expect(editor.calls).toEqual(["getPageTree"]);
    expect(backendCalls).toBe(0);
  });
});
