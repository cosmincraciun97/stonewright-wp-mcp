import { describe, expect, it } from "vitest";
import {
  ElementorV3EditorAdapter,
  createWindowElementorV3Runtime,
  hashValue,
  type ElementorV3Element,
  type ElementorV3ControlSchema,
  type ElementorV3Runtime,
  type ElementorV3Settings,
  type ElementorV3WidgetSchema,
} from "../src/index.js";

const controls: Record<string, Record<string, ElementorV3ControlSchema>> = {
  heading: {
    title: { type: "text" },
    header_size: { type: "select", options: { h1: "H1", h2: "H2", h3: "H3" } },
    align: { type: "choose", options: { left: "Left", center: "Center" }, responsive: true },
  },
  button: {
    text: { type: "text" },
    link: { type: "url" },
  },
  container: {
    container_type: { type: "select", options: { flex: "Flex", grid: "Grid" } },
    flex_direction: { type: "select", options: { row: "Row", column: "Column" }, condition: { container_type: "flex" } },
  },
};

class MemoryElementorRuntime implements ElementorV3Runtime {
  readonly version = "3.31.0";
  readonly documentId = "501";
  tree: ElementorV3Element[] = [{ id: "root", elType: "container", settings: { container_type: "flex" }, children: [] }];
  history: ElementorV3Element[][] = [];
  redoStack: ElementorV3Element[][] = [];
  createCalls = 0;
  modified = false;

  async listWidgets() { return [{ name: "button", title: "Button" }, { name: "heading", title: "Heading" }, { name: "third-party-card", title: "Card" }]; }
  async getWidgetSchema(widgetType: string): Promise<ElementorV3WidgetSchema | null> {
    const widgetControls = controls[widgetType];
    return widgetControls ? { widget_type: widgetType, controls: widgetControls, source: "memory-runtime", version: this.version } : null;
  }
  async getContainerSchema(): Promise<ElementorV3WidgetSchema | null> { return { widget_type: "container", controls: controls.container, source: "memory-runtime", version: this.version }; }
  async getPageTree() { return structuredClone(this.tree); }
  async getElement(elementId: string) { return structuredClone(find(this.tree, elementId) || null); }
  async createElement(input: { parentId?: string; position?: number; elType: "container" | "widget"; widgetType?: string; settings: ElementorV3Settings }) {
    this.snapshot();
    this.createCalls++;
    const element: ElementorV3Element = {
      id: `el-${this.createCalls}`,
      elType: input.elType,
      widgetType: input.widgetType,
      settings: structuredClone(input.settings),
      children: [],
      parentId: input.parentId,
      position: input.position,
    };
    const children = input.parentId ? requireElement(this.tree, input.parentId).children : this.tree;
    const at = input.position === undefined ? children.length : input.position;
    children.splice(at, 0, element);
    reindex(this.tree);
    this.modified = true;
    return structuredClone(requireElement(this.tree, element.id));
  }
  async updateSettings(elementId: string, settings: ElementorV3Settings) {
    this.snapshot();
    const element = requireElement(this.tree, elementId);
    element.settings = { ...element.settings, ...structuredClone(settings) };
    this.modified = true;
  }
  async moveElement(elementId: string, parentId?: string, position?: number) {
    this.snapshot();
    const element = detach(this.tree, elementId);
    const target = parentId ? requireElement(this.tree, parentId).children : this.tree;
    target.splice(position === undefined ? target.length : position, 0, element);
    reindex(this.tree);
    this.modified = true;
  }
  async deleteElement(elementId: string) { this.snapshot(); detach(this.tree, elementId); reindex(this.tree); this.modified = true; }
  async undo() {
    const previous = this.history.pop();
    if (!previous) return;
    this.redoStack.push(structuredClone(this.tree));
    this.tree = previous;
    this.modified = true;
  }
  async redo() {
    const next = this.redoStack.pop();
    if (!next) return;
    this.history.push(structuredClone(this.tree));
    this.tree = next;
    this.modified = true;
  }
  async save() { this.modified = false; }
  async isModified() { return this.modified; }
  private snapshot() { this.history.push(structuredClone(this.tree)); this.redoStack = []; }
}

describe("ElementorV3EditorAdapter", () => {
  it("exposes live V3 tools only as nested page tools", () => {
    const definitions = new ElementorV3EditorAdapter(new MemoryElementorRuntime()).registry().definitions();
    expect(definitions.map((tool) => tool.name)).toEqual(expect.arrayContaining([
      "list_widgets", "get_widget_schema", "get_page_structure", "get_element", "create_element", "update_settings",
      "move_element", "delete_element", "undo", "redo", "save", "batch_call",
    ]));
    expect(definitions.find((tool) => tool.name === "save")?.batchable).toBe(false);
  });

  it("lists third-party widgets and returns a deterministic compact schema", async () => {
    const registry = new ElementorV3EditorAdapter(new MemoryElementorRuntime()).registry();
    const listed = await registry.call("list_widgets", { search: "card" });
    expect(listed.details?.widgets).toEqual([{ name: "third-party-card", title: "Card" }]);
    const schema = await registry.call("get_widget_schema", { widget_type: "heading", mode: "compact" });
    expect(schema.details?.schema_hash).toMatch(/^[a-f0-9]{64}$/);
    expect(JSON.stringify(schema.details)).not.toContain("H1");
  });

  it("blocks unknown settings and CTA widgets without a live URL", async () => {
    const adapter = new ElementorV3EditorAdapter(new MemoryElementorRuntime());
    const registry = adapter.registry();
    const buttonHash = await hashValue(controls.button);
    await expect(registry.call("create_element", {
      element_type: "widget", widget_type: "button", parent_id: "root", idempotency_key: "button-no-link",
      settings: { text: "Buy" },
      settings_evidence: { text: evidence(buttonHash) },
    })).rejects.toThrow("URL/action required");
    const headingHash = await hashValue(controls.heading);
    await expect(registry.call("create_element", {
      element_type: "widget", widget_type: "heading", parent_id: "root", idempotency_key: "unknown-heading",
      settings: { invented_control: "x" },
      settings_evidence: { invented_control: evidence(headingHash) },
    })).rejects.toThrow("unknown setting");
  });

  it("creates with evidence, verifies readback, and replays idempotently without duplicates", async () => {
    const runtime = new MemoryElementorRuntime();
    const adapter = new ElementorV3EditorAdapter(runtime);
    const registry = adapter.registry();
    const schemaHash = await hashValue(controls.button);
    const input = {
      element_type: "widget", widget_type: "button", parent_id: "root", idempotency_key: "hero-cta-create",
      settings: { text: "Buy", link: { url: "https://example.com/buy" } },
      settings_evidence: { text: evidence(schemaHash), link: evidence(schemaHash) },
    };
    const first = await registry.call("create_element", input);
    const replay = await registry.call("create_element", input);
    expect(first.details?.readback_verified).toBe(true);
    expect(replay.details?.idempotent_replay).toBe(true);
    expect(runtime.createCalls).toBe(1);
    expect(adapter.evidence.list()[0]?.settings).toHaveLength(2);
    await expect(registry.call("create_element", { ...input, settings: { ...input.settings, text: "Different" } })).rejects.toThrow("different input");
  });

  it("permits a settings-free container when the runtime exposes no container schema", async () => {
    const runtime = new MemoryElementorRuntime();
    runtime.getContainerSchema = async () => null;
    const created = await new ElementorV3EditorAdapter(runtime).registry().call("create_element", {
      element_type: "container", parent_id: "root", idempotency_key: "empty-container-create", settings: {},
    });
    expect(created.details?.readback_verified).toBe(true);
    expect((await runtime.getElement(String(created.details?.element_id)))?.settings).toEqual({});
  });

  it("updates responsive settings only when live schema and evidence agree", async () => {
    const runtime = new MemoryElementorRuntime();
    runtime.tree[0].children.push({ id: "h1", elType: "widget", widgetType: "heading", settings: { title: "Old" }, children: [], parentId: "root", position: 0 });
    const registry = new ElementorV3EditorAdapter(runtime).registry();
    const schemaHash = await hashValue(controls.heading);
    const updated = await registry.call("update_settings", {
      element_id: "h1", idempotency_key: "update-heading-1", settings: { title: "New", align_tablet: "center" },
      settings_evidence: { title: evidence(schemaHash), align_tablet: evidence(schemaHash, "tablet") },
    });
    expect(updated.details?.readback_verified).toBe(true);
    expect((await runtime.getElement("h1"))?.settings).toMatchObject({ title: "New", align_tablet: "center" });
  });

  it("rolls back a failed batch and resolves create refs", async () => {
    const runtime = new MemoryElementorRuntime();
    const adapter = new ElementorV3EditorAdapter(runtime);
    const registry = adapter.registry();
    const headingHash = await hashValue(controls.heading);
    const before = await hashValue(runtime.tree);
    const batch = await registry.batchCall({ calls: [
      { tool: "create_element", id: "hero", args: {
        element_type: "widget", widget_type: "heading", parent_id: "root", idempotency_key: "batch-heading-create",
        settings: { title: "Hero" }, settings_evidence: { title: evidence(headingHash) },
      } },
      { tool: "update_settings", args: {
        element_id: "$hero", idempotency_key: "batch-heading-invalid", settings: { made_up: true },
        settings_evidence: { made_up: evidence(headingHash) },
      } },
    ] });
    expect(batch.details?.rolled_back).toBe(true);
    expect(batch.details?.refs).toEqual({ hero: "el-1" });
    expect(await hashValue(runtime.tree)).toBe(before);
    expect(adapter.evidence.list()).toEqual([]);
  });

  it("undoes a mutation and clears replay state when mandatory readback fails", async () => {
    const runtime = new MemoryElementorRuntime();
    const adapter = new ElementorV3EditorAdapter(runtime);
    const registry = adapter.registry();
    const schemaHash = await hashValue(controls.heading);
    const input = {
      element_type: "widget", widget_type: "heading", parent_id: "root", idempotency_key: "readback-failure-1",
      settings: { title: "Hero" }, settings_evidence: { title: evidence(schemaHash) },
    };
    const realGet = runtime.getElement.bind(runtime);
    let failOnce = true;
    runtime.getElement = async (id: string) => {
      if (failOnce && id === "el-1") { failOnce = false; return null; }
      return realGet(id);
    };
    await expect(registry.call("create_element", input)).rejects.toThrow("Element not found");
    expect(runtime.tree[0].children).toEqual([]);
    expect(adapter.evidence.list()).toEqual([]);
    const retry = await registry.call("create_element", input);
    expect(retry.details?.idempotent_replay).toBeUndefined();
    expect(retry.details?.readback_verified).toBe(true);
  });

  it("moves, deletes, undoes, redoes, and saves with immediate readback", async () => {
    const runtime = new MemoryElementorRuntime();
    runtime.tree.push({ id: "target", elType: "container", settings: { container_type: "flex" }, children: [] });
    runtime.tree[0].children.push({ id: "h1", elType: "widget", widgetType: "heading", settings: { title: "Hero" }, children: [], parentId: "root", position: 0 });
    const registry = new ElementorV3EditorAdapter(runtime).registry();
    const moved = await registry.call("move_element", { element_id: "h1", parent_id: "target", position: 0, idempotency_key: "move-heading-1" });
    expect(moved.details?.readback_verified).toBe(true);
    await registry.call("delete_element", { element_id: "h1", confirm_delete: true, idempotency_key: "delete-heading-1" });
    expect(await runtime.getElement("h1")).toBeNull();
    await registry.call("undo", {});
    expect(await runtime.getElement("h1")).not.toBeNull();
    await registry.call("redo", {});
    expect(await runtime.getElement("h1")).toBeNull();
    const saved = await registry.call("save", {});
    expect(saved.details?.readback).toMatchObject({ modified: false });
    const batch = await registry.batchCall({ calls: [{ tool: "save" }] });
    expect(batch.details?.failures).toEqual(expect.arrayContaining([expect.objectContaining({ error: expect.stringContaining("cannot run inside") })]));
  });

  it("serializes writes through the official Elementor command bus", async () => {
    const calls: Array<{ command: string; args?: Record<string, unknown> }> = [];
    const root = container("document", "document", {}, []);
    const containers = new Map<string, ReturnType<typeof container>>([["document", root]]);
    const commands = [
      "document/elements/create", "document/elements/settings", "document/elements/move", "document/elements/delete",
      "document/history/undo", "document/history/redo", "document/save/default",
    ];
    let next = 1;
    const runtime = createWindowElementorV3Runtime({
      elementor: {
        config: { version: "3.31.0" },
        widgetsCache: { heading: { title: "Heading", controls: controls.heading } },
        getContainer: (id: string) => containers.get(id) || null,
        documents: { getCurrent: () => ({ id: 501, container: root }) },
      },
      $e: {
        commands: { getAll: () => commands },
        components: { get: () => ({ isEditorChanged: () => false }) },
        run: async (command: string, args?: Record<string, unknown>) => {
          calls.push({ command, args });
          if (command === "document/elements/create") {
            const model = args?.model as Record<string, unknown>;
            const created = container(`new-${next++}`, String(model.elType), model.settings as Record<string, unknown>, [], String(model.widgetType || ""));
            created.parent = args?.container as typeof root;
            (args?.container as typeof root).children.push(created);
            containers.set(created.id, created);
            return created;
          }
          return undefined;
        },
      },
    });
    const created = await runtime.createElement({ elType: "widget", widgetType: "heading", settings: { title: "Hello" } });
    await runtime.updateSettings(created.id, { title: "World" });
    expect(calls.map((call) => call.command)).toEqual(["document/elements/create", "document/elements/settings"]);
    expect(calls[0]?.args).toMatchObject({ model: { elType: "widget", widgetType: "heading", settings: { title: "Hello" } }, options: { edit: false, external: true } });
  });
});

function evidence(schemaHash: string, scope = "desktop") {
  return { schema_hash: schemaHash, source: "figma:node/hero", confidence: 0.98, responsive_scope: scope, requires_confirmation: false };
}
function find(tree: ElementorV3Element[], id: string): ElementorV3Element | undefined {
  for (const element of tree) { if (element.id === id) return element; const nested = find(element.children, id); if (nested) return nested; }
  return undefined;
}
function requireElement(tree: ElementorV3Element[], id: string): ElementorV3Element {
  const element = find(tree, id); if (!element) throw new Error(`missing ${id}`); return element;
}
function detach(tree: ElementorV3Element[], id: string): ElementorV3Element {
  const index = tree.findIndex((element) => element.id === id);
  if (index >= 0) return tree.splice(index, 1)[0];
  for (const element of tree) { try { return detach(element.children, id); } catch { /* continue */ } }
  throw new Error(`missing ${id}`);
}
function reindex(tree: ElementorV3Element[], parentId?: string): void {
  tree.forEach((element, position) => { element.parentId = parentId; element.position = position; reindex(element.children, element.id); });
}
interface MockContainer {
  id: string;
  type: string;
  children: MockContainer[];
  parent?: MockContainer;
  model: { attributes: Record<string, unknown>; get: (key: string) => unknown; toJSON: () => Record<string, unknown> };
  settings: { attributes: Record<string, unknown>; toJSON: () => Record<string, unknown> };
  view: { _index: number };
}
function container(id: string, elType: string, settings: Record<string, unknown>, children: MockContainer[], widgetType = ""): MockContainer {
  const modelData: Record<string, unknown> = { id, elType, ...(widgetType ? { widgetType } : {}) };
  return {
    id, type: elType, children, parent: undefined,
    model: { attributes: modelData, get: (key: string) => modelData[key], toJSON: () => modelData },
    settings: { attributes: settings, toJSON: () => settings },
    view: { _index: 0 },
  };
}
