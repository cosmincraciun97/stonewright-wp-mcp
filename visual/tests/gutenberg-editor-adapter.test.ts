import { describe, expect, it } from "vitest";
import { GutenbergEditorAdapter } from "../src/gutenberg/editor-adapter.js";
import type { GutenbergBlock, GutenbergBlockSchema, GutenbergRuntime } from "../src/gutenberg/types.js";

const paragraph: GutenbergBlockSchema = { name: "core/paragraph", title: "Paragraph", category: "text", attributes: { content: { type: "rich-text" }, align: { type: "string" } }, supports: { color: true }, source: "wordpress-block-registry" };
const group: GutenbergBlockSchema = { name: "core/group", title: "Group", category: "design", attributes: { tagName: { type: "string" } }, supports: { layout: true }, source: "wordpress-block-registry" };

class MemoryRuntime implements GutenbergRuntime {
  readonly postId = "42"; tree: GutenbergBlock[] = []; history: GutenbergBlock[][] = []; dirty = false; next = 1;
  async listBlockTypes() { return [paragraph, group]; }
  async getBlockSchema(name: string) { return [paragraph, group].find((item) => item.name === name) || null; }
  async getBlocks() { return structuredClone(this.tree); }
  async getBlock(id: string) { return find(this.tree, id); }
  async insertBlock(input: Parameters<GutenbergRuntime["insertBlock"]>[0]) { this.snapshot(); const block: GutenbergBlock = { clientId: `block-${this.next++}`, name: input.name, attributes: structuredClone(input.attributes), innerBlocks: structuredClone(input.innerBlocks), ...(input.parentClientId ? { parentClientId: input.parentClientId } : {}), position: input.position ?? this.tree.length }; const parent = input.parentClientId ? find(this.tree, input.parentClientId) : null; (parent ? parent.innerBlocks : this.tree).splice(block.position || 0, 0, block); return structuredClone(block); }
  async updateBlock(id: string, attributes: Record<string, unknown>) { const block = find(this.tree, id); if (!block) throw new Error("missing"); this.snapshot(); Object.assign(block.attributes, structuredClone(attributes)); }
  async moveBlock(id: string, parentId?: string, position = 0) { const block = find(this.tree, id); if (!block) throw new Error("missing"); this.snapshot(); remove(this.tree, id); block.parentClientId = parentId; block.position = position; const parent = parentId ? find(this.tree, parentId) : null; (parent ? parent.innerBlocks : this.tree).splice(position, 0, block); }
  async deleteBlock(id: string) { this.snapshot(); remove(this.tree, id); }
  async undo() { const value = this.history.pop(); if (value) this.tree = value; this.dirty = this.history.length > 0; }
  async redo() {}
  async save() { this.dirty = false; }
  async isDirty() { return this.dirty; }
  async serialize() { return this.tree.map((block) => `<!-- wp:${block.name.replace("core/", "")} -->`).join(""); }
  private snapshot() { this.history.push(structuredClone(this.tree)); this.dirty = true; }
}

describe("GutenbergEditorAdapter", () => {
  it("exposes native nested tools plus batch_call", () => { const names = new GutenbergEditorAdapter(new MemoryRuntime()).registry().definitions().map((item) => item.name); expect(names).toEqual(expect.arrayContaining(["list_blocks", "get_block_schema", "get_page_structure", "get_block", "insert_block", "update_block", "move_block", "delete_block", "undo", "redo", "save", "serialize", "batch_call"])); });
  it("discovers schemas, inserts, updates, serializes, and saves with readback", async () => { const runtime = new MemoryRuntime(); const registry = new GutenbergEditorAdapter(runtime).registry(); const created = await registry.call("insert_block", { block_name: "core/paragraph", attributes: { content: "Hello" }, confirm_write: true, idempotency_key: "p1" }); const id = String(created.details?.client_id); expect(created.details?.readback_verified).toBe(true); const updated = await registry.call("update_block", { client_id: id, attributes: { align: "center" }, confirm_write: true, idempotency_key: "p2" }); expect(updated.details?.readback_verified).toBe(true); const serialized = await registry.call("serialize"); expect(serialized.details?.markup).toContain("wp:paragraph"); const saved = await registry.call("save", { confirm_write: true }); expect(saved.details?.readback).toEqual({ dirty: false }); });
  it("rejects unknown types, unknown attributes, and unconfirmed writes", async () => { const registry = new GutenbergEditorAdapter(new MemoryRuntime()).registry(); await expect(registry.call("insert_block", { block_name: "plugin/missing", confirm_write: true, idempotency_key: "bad" })).rejects.toThrow("Unknown Gutenberg block type"); await expect(registry.call("insert_block", { block_name: "core/paragraph", attributes: { invented: true }, confirm_write: true, idempotency_key: "bad-attr" })).rejects.toThrow("Unknown attributes"); await expect(registry.call("insert_block", { block_name: "core/paragraph", idempotency_key: "no-confirm" })).rejects.toThrow("confirm_write"); });
  it("resolves refs and rolls a failed batch back", async () => { const runtime = new MemoryRuntime(); const batch = await new GutenbergEditorAdapter(runtime).registry().batchCall({ calls: [{ tool: "insert_block", id: "hero", args: { block_name: "core/paragraph", attributes: { content: "Hero" }, confirm_write: true, idempotency_key: "batch-1" } }, { tool: "update_block", args: { client_id: "$hero", attributes: { nope: true }, confirm_write: true, idempotency_key: "batch-2" } }] }); expect(batch.details?.rolled_back).toBe(true); expect(runtime.tree).toEqual([]); });
});

function find(tree: GutenbergBlock[], id: string): GutenbergBlock | null { for (const block of tree) { if (block.clientId === id) return block; const nested = find(block.innerBlocks, id); if (nested) return nested; } return null; }
function remove(tree: GutenbergBlock[], id: string): boolean { const index = tree.findIndex((block) => block.clientId === id); if (index >= 0) { tree.splice(index, 1); return true; } return tree.some((block) => remove(block.innerBlocks, id)); }
