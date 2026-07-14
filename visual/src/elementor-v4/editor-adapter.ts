// SPDX-License-Identifier: AGPL-3.0-or-later

import { PageToolRegistry } from "../page-tool-registry.js";
import type { BatchTransaction, NestedEditorTool, NestedToolResult } from "../types.js";
import { hashValue } from "../elementor-v3/hash.js";
import { validateAtomicEnvelope, validateAtomicSettings } from "./schema-validator.js";
import type { AtomicElementSchema, AtomicSettings, AtomicStyleMap, ElementorV4Element, ElementorV4Runtime } from "./types.js";

export class ElementorV4EditorAdapter {
  private historyPosition = 0;
  private readonly idempotency = new Map<string, { hash: string; result: NestedToolResult }>();

  constructor(private readonly runtime: ElementorV4Runtime) {}

  registry(): PageToolRegistry { return new PageToolRegistry(this.tools(), { begin: async () => this.beginTransaction() }); }

  tools(): NestedEditorTool[] {
    return [this.listTypes(), this.getSchema(), this.getStructure(), this.getElement(), this.createElement(), this.updateElement(), this.moveElement(), this.deleteElement(), this.undo(), this.redo(), this.save()];
  }

  private async beginTransaction(): Promise<BatchTransaction> {
    const start = this.historyPosition;
    const keys = new Set(this.idempotency.keys());
    return { commit: async () => undefined, rollback: async () => {
      while (this.historyPosition > start) { await this.runtime.undo(); this.historyPosition--; }
      for (const key of this.idempotency.keys()) if (!keys.has(key)) this.idempotency.delete(key);
      if (!Array.isArray(await this.runtime.getPageTree())) throw new Error("Elementor V4 rollback readback failed.");
    } };
  }

  private listTypes(): NestedEditorTool {
    return { name: "list_widgets", label: "List live Elementor V4 Atomic types", description: "Lists only Atomic types discovered in the active editor runtime.", parameters: objectSchema({ search: { type: "string" } }), execute: async (args) => {
      const search = text(args.search).toLowerCase();
      const types = (await this.runtime.listAtomicTypes()).filter((item) => !search || item.atomic_type.toLowerCase().includes(search));
      return result(`${types.length} live Atomic types.`, { runtime_version: this.runtime.version, types });
    } };
  }

  private getSchema(): NestedEditorTool {
    return { name: "get_widget_schema", label: "Get live Elementor V4 Atomic schema", description: "Returns the exact live prop schema for one Atomic type.", parameters: objectSchema({ atomic_type: { type: "string" } }, ["atomic_type"]), execute: async (args) => {
      const schema = await this.requireSchema(required(args, "atomic_type"));
      return result(`${schema.atomic_type}: ${Object.keys(schema.props).length} props.`, { ...schema, schema_hash: await hashValue(schema.props) });
    } };
  }

  private getStructure(): NestedEditorTool {
    return { name: "get_page_structure", label: "Get native Elementor V4 tree", description: "Reads the native Atomic tree without V3 conversion.", execute: async () => {
      const tree = await this.runtime.getPageTree();
      return result(`${flatten(tree).length} Atomic elements.`, { document_id: this.runtime.documentId, tree, tree_hash: await hashValue(tree), architecture: "v4", implicit_conversion: false });
    } };
  }

  private getElement(): NestedEditorTool {
    return { name: "get_element", label: "Get native Elementor V4 element", description: "Reads one native Atomic element.", parameters: objectSchema({ element_id: { type: "string" } }, ["element_id"]), execute: async (args) => {
      const element = await this.requireElement(required(args, "element_id"));
      return result(`Atomic ${element.id}.`, { element, element_hash: await hashValue(element) });
    } };
  }

  private createElement(): NestedEditorTool {
    return { name: "create_element", label: "Create native Elementor V4 element", description: "Creates an exact Atomic payload after live-schema validation and explicit approval.", mutates: true,
      parameters: mutationSchema({ atomic_type: { type: "string" }, parent_id: { type: "string" }, position: { type: "integer" }, settings: { type: "object" }, styles: { type: "object" }, editor_settings: { type: "object" }, interactions: {}, confirm_write: { const: true } }, ["atomic_type", "confirm_write", "idempotency_key"]),
      execute: async (args) => this.idempotent("create_element", args, async () => {
        approved(args); const atomicType = required(args, "atomic_type"); const schema = await this.requireSchema(atomicType);
        const payload = envelope(schema, args); validateAtomicEnvelope(payload, schema);
        const element = await this.runtime.createElement({ atomicType, parentId: text(args.parent_id) || undefined, position: number(args.position), payload });
        this.historyPosition++;
        return result(`Created ${atomicType} ${element.id}.`, { element_id: element.id, atomic_type: atomicType, expected_hash: await hashValue(element) });
      }), readback: async (_args, mutation) => this.readback(String(mutation.details?.element_id || "")), rollback: async () => this.rollbackOne() };
  }

  private updateElement(): NestedEditorTool {
    return { name: "update_settings", label: "Update native Elementor V4 payload", description: "Updates separated Atomic settings/styles/editor metadata/interactions with live-schema validation.", mutates: true,
      parameters: mutationSchema({ element_id: { type: "string" }, settings: { type: "object" }, styles: { type: "object" }, editor_settings: { type: "object" }, interactions: {}, confirm_write: { const: true } }, ["element_id", "confirm_write", "idempotency_key"]),
      execute: async (args) => this.idempotent("update_settings", args, async () => {
        approved(args); const element = await this.requireElement(required(args, "element_id")); const schema = await this.requireSchema(typeOf(element));
        const settings = record(args.settings) as AtomicSettings; validateAtomicSettings(settings, schema);
        const patch = { ...(Object.keys(settings).length ? { settings } : {}), ...(args.styles !== undefined ? { styles: record(args.styles) as AtomicStyleMap } : {}), ...(args.editor_settings !== undefined ? { editor_settings: record(args.editor_settings) } : {}), ...(args.interactions !== undefined ? { interactions: interactions(args.interactions) } : {}) };
        validateAtomicEnvelope({ ...element, ...patch }, schema); await this.runtime.updateElement(element.id, patch); this.historyPosition++;
        return result(`Updated Atomic ${element.id}.`, { element_id: element.id, patch_hash: await hashValue(patch) });
      }), readback: async (_args, mutation) => this.readback(String(mutation.details?.element_id || "")), rollback: async () => this.rollbackOne() };
  }

  private moveElement(): NestedEditorTool {
    return { name: "move_element", label: "Move native Elementor V4 element", description: "Moves an Atomic element without converting its payload.", mutates: true, parameters: mutationSchema({ element_id: { type: "string" }, parent_id: { type: "string" }, position: { type: "integer" }, confirm_write: { const: true } }, ["element_id", "confirm_write", "idempotency_key"]), execute: async (args) => this.idempotent("move_element", args, async () => {
      approved(args); const id = required(args, "element_id"); await this.requireElement(id); await this.runtime.moveElement(id, text(args.parent_id) || undefined, number(args.position)); this.historyPosition++; return result(`Moved Atomic ${id}.`, { element_id: id });
    }), readback: async (_args, mutation) => this.readback(String(mutation.details?.element_id || "")), rollback: async () => this.rollbackOne() };
  }

  private deleteElement(): NestedEditorTool {
    return { name: "delete_element", label: "Delete native Elementor V4 element", description: "Deletes an Atomic element after explicit destructive confirmation.", mutates: true, parameters: mutationSchema({ element_id: { type: "string" }, confirm_delete: { const: true }, confirm_write: { const: true } }, ["element_id", "confirm_delete", "confirm_write", "idempotency_key"]), execute: async (args) => this.idempotent("delete_element", args, async () => {
      approved(args); if (args.confirm_delete !== true) throw new Error("confirm_delete=true is required."); const id = required(args, "element_id"); await this.requireElement(id); await this.runtime.deleteElement(id); this.historyPosition++; return result(`Deleted Atomic ${id}.`, { element_id: id });
    }), readback: async (_args, mutation) => { const id = String(mutation.details?.element_id || ""); if (await this.runtime.getElement(id)) throw new Error("Atomic delete readback failed."); return { element_id: id, absent: true }; }, rollback: async () => this.rollbackOne() };
  }

  private undo(): NestedEditorTool { return history("undo", async () => { await this.runtime.undo(); this.historyPosition = Math.max(0, this.historyPosition - 1); }); }
  private redo(): NestedEditorTool { return history("redo", async () => { await this.runtime.redo(); this.historyPosition++; }); }
  private save(): NestedEditorTool { return { name: "save", label: "Save Elementor V4 document", description: "Saves and verifies editor and frontend readback.", mutates: true, batchable: false, parameters: objectSchema({ confirm_write: { const: true } }, ["confirm_write"]), execute: async (args) => { approved(args); await this.runtime.save(); return result(`Saved ${this.runtime.documentId}.`, { document_id: this.runtime.documentId, tree_hash: await hashValue(await this.runtime.getPageTree()) }); }, readback: async () => { if (await this.runtime.isModified()) throw new Error("Elementor V4 save readback failed."); return { modified: false }; } }; }

  private async requireSchema(type: string): Promise<AtomicElementSchema> { const schema = await this.runtime.getAtomicSchema(type); if (!schema) throw new Error(`Live Atomic schema unavailable for ${type}; V3 fallback is forbidden.`); return schema; }
  private async requireElement(id: string): Promise<ElementorV4Element> { const element = await this.runtime.getElement(id); if (!element) throw new Error(`Atomic element not found: ${id}`); return element; }
  private async readback(id: string): Promise<Record<string, unknown>> { const element = await this.requireElement(id); const schema = await this.requireSchema(typeOf(element)); validateAtomicEnvelope(element, schema); const frontend = await this.runtime.verifyFrontend(id); if (!frontend.exists) throw new Error(`Frontend readback failed for ${id}.`); return { element_id: id, element_hash: await hashValue(element), frontend }; }
  private async rollbackOne(): Promise<void> { await this.runtime.undo(); this.historyPosition = Math.max(0, this.historyPosition - 1); }
  private async idempotent(name: string, args: Record<string, unknown>, execute: () => Promise<NestedToolResult>): Promise<NestedToolResult> { const key = required(args, "idempotency_key"); const cacheKey = `${this.runtime.documentId}:${name}:${key}`; const hash = await hashValue(args); const previous = this.idempotency.get(cacheKey); if (previous) { if (previous.hash !== hash) throw new Error("Idempotency key reused with different input."); return { ...previous.result, details: { ...previous.result.details, idempotent_replay: true } }; } const value = await execute(); this.idempotency.set(cacheKey, { hash, result: value }); return value; }
}

function envelope(schema: AtomicElementSchema, args: Record<string, unknown>): Omit<ElementorV4Element, "id" | "elements" | "parentId" | "position"> { return { version: schema.version, elType: schema.kind === "widget" ? "widget" : schema.atomic_type, ...(schema.kind === "widget" ? { widgetType: schema.atomic_type } : {}), isInner: false, settings: record(args.settings) as AtomicSettings, styles: record(args.styles) as AtomicStyleMap, editor_settings: record(args.editor_settings), interactions: interactions(args.interactions) }; }
function typeOf(element: ElementorV4Element): string { return element.elType === "widget" ? String(element.widgetType || "") : element.elType; }
function flatten(tree: ElementorV4Element[]): ElementorV4Element[] { return tree.flatMap((element) => [element, ...flatten(element.elements)]); }
function approved(args: Record<string, unknown>): void { if (args.confirm_write !== true) throw new Error("confirm_write=true is required for every Elementor V4 write."); }
function required(args: Record<string, unknown>, key: string): string { const value = text(args[key]); if (!value) throw new Error(`${key} is required.`); return value; }
function text(value: unknown): string { return typeof value === "string" ? value.trim() : ""; }
function number(value: unknown): number | undefined { return typeof value === "number" && Number.isInteger(value) ? value : undefined; }
function record(value: unknown): Record<string, unknown> { return value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : {}; }
function interactions(value: unknown): ElementorV4Element["interactions"] { return Array.isArray(value) ? value as Array<Record<string, unknown>> : record(value) as ElementorV4Element["interactions"]; }
function objectSchema(properties: Record<string, unknown>, requiredKeys: string[] = []): Record<string, unknown> { return { type: "object", additionalProperties: false, properties, ...(requiredKeys.length ? { required: requiredKeys } : {}) }; }
function mutationSchema(properties: Record<string, unknown>, requiredKeys: string[]): Record<string, unknown> { return objectSchema({ ...properties, idempotency_key: { type: "string" } }, requiredKeys); }
function result(summary: string, details: Record<string, unknown>): NestedToolResult { return { content: [{ type: "text", text: summary }], details }; }
function history(name: "undo" | "redo", action: () => Promise<void>): NestedEditorTool { return { name, label: `${name} Elementor V4 action`, description: `${name} through Elementor history.`, mutates: true, batchable: false, execute: async () => { await action(); return result(`${name} complete.`, {}); } }; }
