// SPDX-License-Identifier: AGPL-3.0-or-later

import { PageToolRegistry } from "../page-tool-registry.js";
import type { BatchTransaction, NestedEditorTool, NestedToolResult } from "../types.js";
import type { GutenbergBlock, GutenbergBlockSchema, GutenbergRuntime } from "./types.js";

interface CachedMutation { request: string; result: NestedToolResult; }

export class GutenbergEditorAdapter {
  private readonly idempotency = new Map<string, CachedMutation>();
  private historyPosition = 0;

  constructor(private readonly runtime: GutenbergRuntime) {}

  registry(): PageToolRegistry { return new PageToolRegistry(this.tools(), { begin: async () => this.beginTransaction() }); }

  tools(): NestedEditorTool[] {
    return [
      this.listBlocks(), this.getBlockSchema(), this.getPageStructure(), this.getBlock(), this.insertBlock(),
      this.updateBlock(), this.moveBlock(), this.deleteBlock(), this.undo(), this.redo(), this.save(), this.serialize(),
    ];
  }

  private async beginTransaction(): Promise<BatchTransaction> {
    const start = this.historyPosition;
    const keys = new Set(this.idempotency.keys());
    return { commit: async () => undefined, rollback: async () => {
      while (this.historyPosition > start) { await this.runtime.undo(); this.historyPosition--; }
      for (const key of this.idempotency.keys()) if (!keys.has(key)) this.idempotency.delete(key);
      if (!Array.isArray(await this.runtime.getBlocks())) throw new Error("Gutenberg rollback readback failed.");
    } };
  }

  private listBlocks(): NestedEditorTool { return { name: "list_blocks", label: "List live Gutenberg blocks", description: "Lists every block type registered by WordPress and active plugins.", parameters: objectSchema({ search: { type: "string" }, page: { type: "integer" }, per_page: { type: "integer" } }), execute: async (args) => {
    const search = optionalString(args.search).toLowerCase(); const page = integer(args.page, 1, 1, 10_000); const perPage = integer(args.per_page, 30, 1, 100);
    const all = (await this.runtime.listBlockTypes()).filter((item) => !search || `${item.name} ${item.title}`.toLowerCase().includes(search)); const items = all.slice((page - 1) * perPage, page * perPage);
    return result(`${items.length}/${all.length} live Gutenberg block types.`, { page, per_page: perPage, total: all.length, blocks: items.map(compactSchema) });
  } }; }

  private getBlockSchema(): NestedEditorTool { return { name: "get_block_schema", label: "Get live Gutenberg block schema", description: "Returns exact registered attributes and block supports; unknown block types are rejected.", parameters: objectSchema({ block_name: { type: "string" } }, ["block_name"]), execute: async (args) => {
    const schema = await this.requireSchema(requiredString(args, "block_name")); return result(`${schema.name}: ${Object.keys(schema.attributes).length} attributes.`, { schema });
  } }; }

  private getPageStructure(): NestedEditorTool { return { name: "get_page_structure", label: "Get live Gutenberg structure", description: "Reads the current block-editor tree without parsing saved HTML twice.", parameters: objectSchema({ mode: { type: "string", enum: ["outline", "full"] } }), execute: async (args) => {
    const tree = await this.runtime.getBlocks(); const flat = flatten(tree); return result(`${flat.length} live Gutenberg blocks.`, { post_id: this.runtime.postId, count: flat.length, ...(optionalString(args.mode) === "full" ? { tree } : { outline: flat.map((item) => ({ client_id: item.clientId, name: item.name, parent_client_id: item.parentClientId, position: item.position })) }) });
  } }; }

  private getBlock(): NestedEditorTool { return { name: "get_block", label: "Get live Gutenberg block", description: "Reads one block by client id from the active editor store.", parameters: objectSchema({ client_id: { type: "string" } }, ["client_id"]), execute: async (args) => { const block = await this.requireBlock(requiredString(args, "client_id")); return result(`${block.name} ${block.clientId}.`, { block }); } }; }

  private insertBlock(): NestedEditorTool { return { name: "insert_block", label: "Insert native Gutenberg block", description: "Creates a registered block after exact live attribute validation.", mutates: true, parameters: mutationSchema({ block_name: { type: "string" }, attributes: { type: "object" }, inner_blocks: { type: "array" }, parent_client_id: { type: "string" }, position: { type: "integer" } }, ["block_name", "idempotency_key"]), execute: async (args) => this.idempotent("insert_block", args, async () => {
    const name = requiredString(args, "block_name"); const attributes = record(args.attributes); await this.validateAttributes(name, attributes); const innerBlocks = Array.isArray(args.inner_blocks) ? args.inner_blocks.map(normalizeInputBlock) : [];
    const block = await this.runtime.insertBlock({ name, attributes, innerBlocks, parentClientId: optionalString(args.parent_client_id) || undefined, position: optionalInteger(args.position) }); this.historyPosition++;
    return result(`Inserted ${name} ${block.clientId}.`, { client_id: block.clientId, expected_attributes: attributes });
  }), readback: async (_args, mutation) => { const block = await this.requireBlock(String(mutation.details?.client_id || "")); assertSubset(record(mutation.details?.expected_attributes), block.attributes); return { client_id: block.clientId, attributes_verified: true }; }, rollback: async (args) => this.rollbackMutation("insert_block", args) }; }

  private updateBlock(): NestedEditorTool { return { name: "update_block", label: "Update Gutenberg block", description: "Updates exact registered attributes and verifies editor-store readback.", mutates: true, parameters: mutationSchema({ client_id: { type: "string" }, attributes: { type: "object" } }, ["client_id", "attributes", "idempotency_key"]), execute: async (args) => this.idempotent("update_block", args, async () => {
    const block = await this.requireBlock(requiredString(args, "client_id")); const attributes = record(args.attributes); await this.validateAttributes(block.name, attributes); await this.runtime.updateBlock(block.clientId, attributes); this.historyPosition++; return result(`Updated ${block.clientId}.`, { client_id: block.clientId, expected_attributes: attributes });
  }), readback: async (_args, mutation) => { const block = await this.requireBlock(String(mutation.details?.client_id || "")); assertSubset(record(mutation.details?.expected_attributes), block.attributes); return { client_id: block.clientId, attributes_verified: true }; }, rollback: async (args) => this.rollbackMutation("update_block", args) }; }

  private moveBlock(): NestedEditorTool { return { name: "move_block", label: "Move Gutenberg block", description: "Moves an existing block through the block-editor data store.", mutates: true, parameters: mutationSchema({ client_id: { type: "string" }, parent_client_id: { type: "string" }, position: { type: "integer" } }, ["client_id", "idempotency_key"]), execute: async (args) => this.idempotent("move_block", args, async () => {
    const id = requiredString(args, "client_id"); await this.requireBlock(id); const parent = optionalString(args.parent_client_id) || undefined; const position = optionalInteger(args.position); await this.runtime.moveBlock(id, parent, position); this.historyPosition++; return result(`Moved ${id}.`, { client_id: id, expected_parent: parent, expected_position: position });
  }), readback: async (_args, mutation) => { const block = await this.requireBlock(String(mutation.details?.client_id || "")); if ((mutation.details?.expected_parent || undefined) !== (block.parentClientId || undefined)) throw new Error("Gutenberg move parent readback mismatch."); if (mutation.details?.expected_position !== undefined && mutation.details.expected_position !== block.position) throw new Error("Gutenberg move position readback mismatch."); return { client_id: block.clientId, parent_client_id: block.parentClientId, position: block.position }; }, rollback: async (args) => this.rollbackMutation("move_block", args) }; }

  private deleteBlock(): NestedEditorTool { return { name: "delete_block", label: "Delete Gutenberg block", description: "Deletes a live block through editor history.", mutates: true, parameters: mutationSchema({ client_id: { type: "string" } }, ["client_id", "idempotency_key"]), execute: async (args) => this.idempotent("delete_block", args, async () => { const id = requiredString(args, "client_id"); await this.requireBlock(id); await this.runtime.deleteBlock(id); this.historyPosition++; return result(`Deleted ${id}.`, { client_id: id }); }), readback: async (_args, mutation) => { const id = String(mutation.details?.client_id || ""); if (await this.runtime.getBlock(id)) throw new Error("Gutenberg delete readback failed."); return { client_id: id, deleted: true }; }, rollback: async (args) => this.rollbackMutation("delete_block", args) }; }

  private undo(): NestedEditorTool { return { name: "undo", label: "Undo Gutenberg change", description: "Runs one native editor undo.", mutates: true, batchable: false, parameters: objectSchema({ confirm_write: { type: "boolean" } }, ["confirm_write"]), execute: async (args) => { confirm(args); await this.runtime.undo(); this.historyPosition = Math.max(0, this.historyPosition - 1); return result("Gutenberg undo complete."); }, readback: async () => ({ dirty: await this.runtime.isDirty() }) }; }
  private redo(): NestedEditorTool { return { name: "redo", label: "Redo Gutenberg change", description: "Runs one native editor redo.", mutates: true, batchable: false, parameters: objectSchema({ confirm_write: { type: "boolean" } }, ["confirm_write"]), execute: async (args) => { confirm(args); await this.runtime.redo(); this.historyPosition++; return result("Gutenberg redo complete."); }, readback: async () => ({ dirty: await this.runtime.isDirty() }) }; }
  private save(): NestedEditorTool { return { name: "save", label: "Save Gutenberg post", description: "Saves through core/editor and verifies the dirty flag clears.", mutates: true, batchable: false, parameters: objectSchema({ confirm_write: { type: "boolean" } }, ["confirm_write"]), execute: async (args) => { confirm(args); await this.runtime.save(); return result("Gutenberg post saved.", { post_id: this.runtime.postId }); }, readback: async () => { const dirty = await this.runtime.isDirty(); if (dirty) throw new Error("Gutenberg save readback reports unsaved changes."); return { dirty: false }; } }; }
  private serialize(): NestedEditorTool { return { name: "serialize", label: "Serialize live Gutenberg blocks", description: "Serializes the active editor tree with WordPress block APIs for readback or debugging.", parameters: objectSchema({}), execute: async () => { const markup = await this.runtime.serialize(); return result(`Serialized ${markup.length} characters.`, { markup }); } }; }

  private async requireSchema(name: string): Promise<GutenbergBlockSchema> { const schema = await this.runtime.getBlockSchema(name); if (!schema) throw new Error(`Unknown Gutenberg block type: ${name}`); return schema; }
  private async requireBlock(id: string): Promise<GutenbergBlock> { const block = await this.runtime.getBlock(id); if (!block) throw new Error(`Gutenberg block not found: ${id}`); return block; }
  private async validateAttributes(name: string, attributes: Record<string, unknown>): Promise<void> { const schema = await this.requireSchema(name); const unknown = Object.keys(attributes).filter((key) => !Object.hasOwn(schema.attributes, key)); if (unknown.length) throw new Error(`Unknown attributes for ${name}: ${unknown.join(", ")}`); }
  private async idempotent(tool: string, args: Record<string, unknown>, execute: () => Promise<NestedToolResult>): Promise<NestedToolResult> { confirm(args); const key = requiredString(args, "idempotency_key"); const request = JSON.stringify(args); const cacheKey = `${tool}:${key}`; const cached = this.idempotency.get(cacheKey); if (cached) { if (cached.request !== request) throw new Error(`Idempotency key reused with different arguments: ${key}`); return structuredClone(cached.result); } const value = await execute(); this.idempotency.set(cacheKey, { request, result: structuredClone(value) }); return value; }
  private async rollbackMutation(tool: string, args: Record<string, unknown>): Promise<void> { await this.runtime.undo(); this.historyPosition = Math.max(0, this.historyPosition - 1); this.idempotency.delete(`${tool}:${requiredString(args, "idempotency_key")}`); }
}

function result(text: string, details: Record<string, unknown> = {}): NestedToolResult { return { content: [{ type: "text", text }], details }; }
function compactSchema(schema: GutenbergBlockSchema): Record<string, unknown> { return { name: schema.name, title: schema.title, category: schema.category, attribute_count: Object.keys(schema.attributes).length }; }
function flatten(tree: GutenbergBlock[]): GutenbergBlock[] { return tree.flatMap((item) => [item, ...flatten(item.innerBlocks)]); }
function normalizeInputBlock(value: unknown): GutenbergBlock {
  const input = record(value);
  const children = input.innerBlocks ?? input.inner_blocks;
  return { clientId: optionalString(input.clientId) || optionalString(input.client_id), name: requiredString(input, "name"), attributes: record(input.attributes), innerBlocks: Array.isArray(children) ? children.map(normalizeInputBlock) : [] };
}
function mutationSchema(properties: Record<string, unknown>, required: string[]): Record<string, unknown> { return objectSchema({ ...properties, confirm_write: { type: "boolean" }, idempotency_key: { type: "string" } }, [...required, "confirm_write"]); }
function objectSchema(properties: Record<string, unknown>, required: string[] = []): Record<string, unknown> { return { type: "object", additionalProperties: false, properties, ...(required.length ? { required } : {}) }; }
function confirm(args: Record<string, unknown>): void { if (args.confirm_write !== true) throw new Error("confirm_write=true is required for Gutenberg mutations."); }
function requiredString(args: Record<string, unknown>, key: string): string { const value = args[key]; if (typeof value !== "string" || !value.trim()) throw new Error(`Missing required string: ${key}`); return value.trim(); }
function optionalString(value: unknown): string { return typeof value === "string" ? value.trim() : ""; }
function optionalInteger(value: unknown): number | undefined { return typeof value === "number" && Number.isInteger(value) ? value : undefined; }
function integer(value: unknown, fallback: number, min: number, max: number): number { return typeof value === "number" && Number.isFinite(value) ? Math.max(min, Math.min(Math.floor(value), max)) : fallback; }
function record(value: unknown): Record<string, unknown> { return value && typeof value === "object" && !Array.isArray(value) ? structuredClone(value as Record<string, unknown>) : {}; }
function assertSubset(expected: Record<string, unknown>, actual: Record<string, unknown>): void { for (const [key, value] of Object.entries(expected)) if (JSON.stringify(actual[key]) !== JSON.stringify(value)) throw new Error(`Gutenberg attribute readback mismatch: ${key}`); }
