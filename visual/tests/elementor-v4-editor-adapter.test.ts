import { describe, expect, it } from "vitest";
import { ElementorV4EditorAdapter } from "../src/elementor-v4/editor-adapter.js";
import type { AtomicElementSchema, ElementorV4Element, ElementorV4Runtime } from "../src/elementor-v4/types.js";

const heading: AtomicElementSchema = { atomic_type: "e-heading", kind: "widget", version: "0.0", source: "live-test-runtime", props: { title: { properties: { $$type: { const: "html-v3" } } }, classes: { properties: { $$type: { const: "classes" } } } } };
const flexbox: AtomicElementSchema = {
  atomic_type: "e-flexbox",
  kind: "layout",
  version: "0.0",
  source: "live-test-runtime",
  props: { classes: { properties: { $$type: { const: "classes" } } } },
};

class MemoryRuntime implements ElementorV4Runtime {
  readonly version = "4.0.0"; readonly documentId = "42"; tree: ElementorV4Element[] = []; history: ElementorV4Element[][] = []; redoStack: ElementorV4Element[][] = []; modified = false; next = 1;
  async listAtomicTypes() { return [flexbox, heading]; }
  async getAtomicSchema(type: string) { return [flexbox, heading].find((item) => item.atomic_type === type) || null; }
  async getPageTree() { return structuredClone(this.tree); }
  async getElement(id: string) { return find(this.tree, id); }
  async createElement(input: Parameters<ElementorV4Runtime["createElement"]>[0]) { this.snapshot(); const element: ElementorV4Element = { id: `v4-${this.next++}`, ...structuredClone(input.payload), elements: [], ...(input.parentId ? { parentId: input.parentId } : {}), ...(input.position === undefined ? {} : { position: input.position }) }; const parent = input.parentId ? find(this.tree, input.parentId) : null; (parent ? parent.elements : this.tree).splice(input.position ?? (parent ? parent.elements.length : this.tree.length), 0, element); return structuredClone(element); }
  async updateElement(id: string, patch: Parameters<ElementorV4Runtime["updateElement"]>[1]) { const item = find(this.tree, id); if (!item) throw new Error("missing"); this.snapshot(); Object.assign(item, structuredClone(patch)); }
  async moveElement(id: string, parentId?: string, position = 0) { const item = find(this.tree, id); if (!item) throw new Error("missing"); this.snapshot(); remove(this.tree, id); item.parentId = parentId; item.position = position; const parent = parentId ? find(this.tree, parentId) : null; (parent ? parent.elements : this.tree).splice(position, 0, item); }
  async deleteElement(id: string) { this.snapshot(); remove(this.tree, id); }
  async undo() { const previous = this.history.pop(); if (previous) { this.redoStack.push(structuredClone(this.tree)); this.tree = previous; } }
  async redo() { const next = this.redoStack.pop(); if (next) { this.history.push(structuredClone(this.tree)); this.tree = next; } }
  async save() { this.modified = false; }
  async isModified() { return this.modified; }
  async verifyFrontend(id: string) { return { exists: Boolean(find(this.tree, id)), selector: `[data-id="${id}"]` }; }
  private snapshot() { this.history.push(structuredClone(this.tree)); this.redoStack = []; this.modified = true; }
}

describe("ElementorV4EditorAdapter", () => {
  it("exposes the stable nested verbs and no top-level tools", () => {
    const names = new ElementorV4EditorAdapter(new MemoryRuntime()).registry().definitions().map((tool) => tool.name);
    expect(names).toEqual(expect.arrayContaining(["list_widgets", "get_widget_schema", "get_page_structure", "get_element", "create_element", "update_settings", "move_element", "delete_element", "undo", "redo", "save", "batch_call"]));
  });

  it("creates native widget envelopes and verifies frontend readback", async () => {
    const runtime = new MemoryRuntime(); const registry = new ElementorV4EditorAdapter(runtime).registry();
    const created = await registry.call("create_element", { atomic_type: "e-heading", confirm_write: true, idempotency_key: "create-heading", settings: { title: html("Hello") } });
    expect(created.details?.readback_verified).toBe(true);
    const item = await runtime.getElement(String(created.details?.element_id));
    expect(item).toMatchObject({ elType: "widget", widgetType: "e-heading", version: "0.0" });
  });

  it("verifies update and move deltas instead of accepting existence-only readback", async () => {
    const runtime = new MemoryRuntime(); const registry = new ElementorV4EditorAdapter(runtime).registry();
    const parent = await registry.call("create_element", { atomic_type: "e-flexbox", confirm_write: true, idempotency_key: "parent", settings: {} });
    const child = await registry.call("create_element", { atomic_type: "e-heading", confirm_write: true, idempotency_key: "child", settings: { title: html("Before") } });
    const id = String(child.details?.element_id); const parentId = String(parent.details?.element_id);
    const updated = await registry.call("update_settings", { element_id: id, confirm_write: true, idempotency_key: "update", settings: { title: html("After") } });
    expect(updated.details?.readback_verified).toBe(true);
    const moved = await registry.call("move_element", { element_id: id, parent_id: parentId, position: 0, confirm_write: true, idempotency_key: "move" });
    expect(moved.details?.readback_verified).toBe(true); expect(await runtime.getElement(id)).toMatchObject({ parentId, position: 0 });
  });

  it("clears idempotency after readback rollback so the same request can retry", async () => {
    const runtime = new MemoryRuntime(); const registry = new ElementorV4EditorAdapter(runtime).registry();
    const created = await registry.call("create_element", { atomic_type: "e-heading", confirm_write: true, idempotency_key: "retry-create", settings: { title: html("Before") } });
    const id = String(created.details?.element_id); const realUpdate = runtime.updateElement.bind(runtime); let corrupt = true;
    runtime.updateElement = async (elementId, patch) => { await realUpdate(elementId, patch); if (corrupt) { const item = find(runtime.tree, elementId); if (item) item.settings = {}; } };
    const input = { element_id: id, confirm_write: true, idempotency_key: "retry-update", settings: { title: html("After") } };
    await expect(registry.call("update_settings", input)).rejects.toThrow("Atomic update readback mismatch");
    corrupt = false; const retry = await registry.call("update_settings", input); expect(retry.details?.idempotent_replay).toBeUndefined(); expect(retry.details?.readback_verified).toBe(true);
  });

  it("blocks writes without approval, unknown props, and V3 fallback", async () => {
    const registry = new ElementorV4EditorAdapter(new MemoryRuntime()).registry();
    await expect(registry.call("create_element", { atomic_type: "e-heading", idempotency_key: "no-approval", settings: {} })).rejects.toThrow("confirm_write");
    await expect(registry.call("create_element", { atomic_type: "e-heading", confirm_write: true, idempotency_key: "bad-prop", settings: { invented: { $$type: "string", value: "x" } } })).rejects.toThrow("Unknown Atomic setting");
    await expect(registry.call("create_element", { atomic_type: "heading", confirm_write: true, idempotency_key: "v3-fallback", settings: {} })).rejects.toThrow("V3 fallback is forbidden");
  });

  it("rolls back a failed batch after resolving refs", async () => {
    const runtime = new MemoryRuntime(); const registry = new ElementorV4EditorAdapter(runtime).registry();
    const batch = await registry.batchCall({ calls: [
      { tool: "create_element", id: "hero", args: { atomic_type: "e-heading", confirm_write: true, idempotency_key: "batch-create", settings: { title: html("Hero") } } },
      { tool: "update_settings", args: { element_id: "$hero", confirm_write: true, idempotency_key: "batch-invalid", settings: { nope: { $$type: "string", value: "x" } } } },
    ] });
    expect(batch.details?.rolled_back).toBe(true); expect(runtime.tree).toEqual([]);
  });

  it("rejects wrong typed envelopes and verifies save state", async () => {
    const runtime = new MemoryRuntime(); const registry = new ElementorV4EditorAdapter(runtime).registry();
    await expect(registry.call("create_element", { atomic_type: "e-heading", confirm_write: true, idempotency_key: "wrong-type", settings: { title: { $$type: "string", value: "Hello" } } })).rejects.toThrow("expects $$type=html-v3");
    const saved = await registry.call("save", { confirm_write: true }); expect(saved.details?.readback).toMatchObject({ modified: false });
  });

  it("requires approval and readback for undo and redo", async () => {
    const runtime = new MemoryRuntime(); const registry = new ElementorV4EditorAdapter(runtime).registry();
    await registry.call("create_element", { atomic_type: "e-heading", confirm_write: true, idempotency_key: "history-create", settings: {} });
    await expect(registry.call("undo", {})).rejects.toThrow("confirm_write");
    const undone = await registry.call("undo", { confirm_write: true }); expect(undone.details?.readback_verified).toBe(true); expect(runtime.tree).toEqual([]);
    const redone = await registry.call("redo", { confirm_write: true }); expect(redone.details?.readback_verified).toBe(true); expect(runtime.tree).toHaveLength(1);
  });
});

function html(value: string) { return { $$type: "html-v3", value: { content: { $$type: "string", value }, children: [] } }; }
function find(tree: ElementorV4Element[], id: string): ElementorV4Element | null { for (const item of tree) { if (item.id === id) return item; const nested = find(item.elements, id); if (nested) return nested; } return null; }
function remove(tree: ElementorV4Element[], id: string): boolean { const index = tree.findIndex((item) => item.id === id); if (index >= 0) { tree.splice(index, 1); return true; } return tree.some((item) => remove(item.elements, id)); }
