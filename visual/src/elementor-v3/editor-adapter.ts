// SPDX-License-Identifier: AGPL-3.0-or-later

import { PageToolRegistry } from "../page-tool-registry.js";
import type { BatchTransaction, NestedEditorTool, NestedToolResult } from "../types.js";
import { ElementorV3EvidenceLedger, type EvidenceLedgerEntry } from "./evidence-ledger.js";
import { hashValue } from "./hash.js";
import { hashNonTargetBreakpoints, validateElementorV3Settings } from "./settings-validator.js";
import type {
  ElementorV3Element,
  ElementorV3Runtime,
  ElementorV3Settings,
  ElementorV3WidgetSchema,
  SettingsEvidenceInput,
} from "./types.js";

interface CachedMutation { requestHash: string; result: NestedToolResult; }

export class ElementorV3EditorAdapter {
  readonly evidence = new ElementorV3EvidenceLedger();
  private readonly idempotency = new Map<string, CachedMutation>();
  private historyPosition = 0;

  constructor(private readonly runtime: ElementorV3Runtime) {}

  registry(): PageToolRegistry {
    return new PageToolRegistry(this.tools(), { begin: async () => this.beginTransaction() });
  }

  tools(): NestedEditorTool[] {
    return [
      this.listWidgetsTool(),
      this.getWidgetSchemaTool(),
      this.getPageStructureTool(),
      this.getElementTool(),
      this.createElementTool(),
      this.updateSettingsTool(),
      this.moveElementTool(),
      this.deleteElementTool(),
      this.undoTool(),
      this.redoTool(),
      this.saveTool(),
      this.evidenceLedgerTool(),
    ];
  }

  private async beginTransaction(): Promise<BatchTransaction> {
    const start = this.historyPosition;
    const idempotencyKeys = new Set(this.idempotency.keys());
    const evidenceSize = this.evidence.size();
    return {
      commit: async () => undefined,
      rollback: async () => {
        while (this.historyPosition > start) {
          await this.runtime.undo();
          this.historyPosition--;
        }
        for (const key of this.idempotency.keys()) if (!idempotencyKeys.has(key)) this.idempotency.delete(key);
        this.evidence.truncate(evidenceSize);
        const tree = await this.runtime.getPageTree();
        if (!Array.isArray(tree)) throw new Error("Elementor rollback readback failed.");
      },
    };
  }

  private listWidgetsTool(): NestedEditorTool {
    return {
      name: "list_widgets",
      label: "List live Elementor V3 widgets",
      description: "Lists the widgets registered in the active Elementor editor runtime, including third-party widgets.",
      parameters: objectSchema({ search: { type: "string" }, page: { type: "integer" }, per_page: { type: "integer" } }),
      execute: async (args) => {
        const search = optionalString(args.search).toLowerCase();
        const page = integer(args.page, 1, 1, 10_000);
        const perPage = integer(args.per_page, 30, 1, 100);
        const all = (await this.runtime.listWidgets()).filter((widget) => !search || JSON.stringify(widget).toLowerCase().includes(search));
        const widgets = all.slice((page - 1) * perPage, page * perPage);
        return result(`${widgets.length}/${all.length} live Elementor V3 widgets.`, { page, per_page: perPage, total: all.length, widgets });
      },
    };
  }

  private getWidgetSchemaTool(): NestedEditorTool {
    return {
      name: "get_widget_schema",
      label: "Get live Elementor V3 widget schema",
      description: "Returns compact, selected, or full controls from the active editor schema. Never guesses controls.",
      parameters: objectSchema({
        widget_type: { type: "string" },
        mode: { type: "string", enum: ["compact", "selected", "full"] },
        control_names: { type: "array", items: { type: "string" } },
      }, ["widget_type"]),
      execute: async (args) => {
        const widgetType = requiredString(args, "widget_type");
        const schema = await this.requireSchema(widgetType);
        const schemaHash = await this.schemaHash(schema);
        const mode = optionalString(args.mode) || "compact";
        const selected = Array.isArray(args.control_names) ? args.control_names.map(String) : [];
        const controls = mode === "selected"
          ? Object.fromEntries(selected.map((key) => [key, schema.controls[key]]).filter((row) => row[1]))
          : mode === "compact"
            ? Object.fromEntries(Object.entries(schema.controls).map(([key, control]) => [key, compactControl(control)]))
            : schema.controls;
        if (mode === "selected" && Object.keys(controls).length !== selected.length) {
          const missing = selected.filter((key) => !schema.controls[key]);
          throw new Error(`Unknown controls for ${widgetType}: ${missing.join(", ")}`);
        }
        return result(`${widgetType}: ${Object.keys(controls).length} controls (${mode}).`, {
          widget_type: widgetType,
          title: schema.title,
          schema_hash: schemaHash,
          source: schema.source || "elementor-editor-runtime",
          version: schema.version || this.runtime.version,
          controls,
        });
      },
    };
  }

  private getPageStructureTool(): NestedEditorTool {
    return {
      name: "get_page_structure",
      label: "Get live Elementor V3 page structure",
      description: "Reads the active editor tree and returns a compact outline or full live data.",
      parameters: objectSchema({ mode: { type: "string", enum: ["outline", "full"] }, max_elements: { type: "integer" } }),
      execute: async (args) => {
        const tree = await this.runtime.getPageTree();
        const flat = flatten(tree);
        const mode = optionalString(args.mode) || "outline";
        const max = integer(args.max_elements, 200, 1, 500);
        return result(`${flat.length} live Elementor V3 elements.`, {
          document_id: this.runtime.documentId,
          runtime_version: this.runtime.version,
          tree_hash: await hashValue(tree),
          count: flat.length,
          ...(mode === "full" ? { tree } : { outline: flat.slice(0, max).map(compactElement), truncated: flat.length > max }),
        });
      },
    };
  }

  private getElementTool(): NestedEditorTool {
    return {
      name: "get_element",
      label: "Get live Elementor V3 element",
      description: "Reads one element directly from the active Elementor editor model.",
      parameters: objectSchema({ element_id: { type: "string" } }, ["element_id"]),
      execute: async (args) => {
        const element = await this.requireElement(requiredString(args, "element_id"));
        return result(`${element.elType} ${element.id}.`, { element, element_hash: await hashValue(element) });
      },
    };
  }

  private createElementTool(): NestedEditorTool {
    return {
      name: "create_element",
      label: "Create native Elementor V3 element",
      description: "Creates a container or registered widget through the editor command bus after live-schema and evidence validation.",
      mutates: true,
      parameters: mutationSchema({
        element_type: { type: "string", enum: ["container", "widget"] }, widget_type: { type: "string" }, parent_id: { type: "string" },
        position: { type: "integer" }, settings: { type: "object" }, settings_evidence: { type: "object" },
        allowed_breakpoints: { type: "array", items: { type: "string" }, minItems: 1 },
      }, ["element_type", "idempotency_key"]),
      execute: async (args) => this.idempotent("create_element", args, async () => {
        const elType = requiredString(args, "element_type") as "container" | "widget";
        const widgetType = elType === "widget" ? requiredString(args, "widget_type") : "container";
        const settings = record(args.settings);
        const { entry } = await this.validateWrite(widgetType, settings, settings, args, undefined);
        const element = await this.runtime.createElement({
          elType,
          widgetType: elType === "widget" ? widgetType : undefined,
          parentId: optionalString(args.parent_id) || undefined,
          position: optionalInteger(args.position),
          settings,
        });
        this.historyPosition++;
        this.evidence.record({ ...entry, element_id: element.id });
        return result(`Created ${widgetType} ${element.id}.`, { element_id: element.id, element, schema_hash: entry.settings[0]?.schema_hash, evidence_count: entry.settings.length });
      }),
      readback: async (_args, mutation) => {
        const id = String(mutation.details?.element_id || "");
        const element = await this.requireElement(id);
        return { element_id: id, element_hash: await hashValue(element) };
      },
      rollback: async (args) => this.rollbackMutation("create_element", args),
    };
  }

  private updateSettingsTool(): NestedEditorTool {
    return {
      name: "update_settings",
      label: "Update native Elementor V3 settings",
      description: "Updates only controls proven by the live schema and records per-setting evidence.",
      mutates: true,
      parameters: mutationSchema({
        element_id: { type: "string" },
        settings: { type: "object" },
        settings_evidence: { type: "object" },
        allowed_breakpoints: { type: "array", items: { type: "string" }, minItems: 1 },
      }, ["element_id", "settings", "idempotency_key"]),
      execute: async (args) => this.idempotent("update_settings", args, async () => {
        const element = await this.requireElement(requiredString(args, "element_id"));
        const settings = record(args.settings);
        const effective = { ...element.settings, ...settings };
        const widgetType = element.elType === "widget" ? requiredWidgetType(element) : "container";
        const { entry, allowedBreakpoints } = await this.validateWrite(widgetType, settings, effective, args, element.id);
        const nonTargetBeforeHash = hashNonTargetBreakpoints(element.settings, allowedBreakpoints);
        await this.runtime.updateSettings(element.id, settings);
        this.historyPosition++;
        this.evidence.record(entry);
        return result(`Updated ${element.id}: ${Object.keys(settings).join(", ")}.`, {
          element_id: element.id,
          expected_settings: settings,
          evidence_count: entry.settings.length,
          allowed_breakpoints: allowedBreakpoints,
          non_target_before_hash: nonTargetBeforeHash,
        });
      }),
      readback: async (_args, mutation) => {
        const element = await this.requireElement(String(mutation.details?.element_id || ""));
        assertSubset(record(mutation.details?.expected_settings), element.settings, "Elementor settings readback mismatch");
        const allowedBreakpoints = stringArray(mutation.details?.allowed_breakpoints);
        const nonTargetAfterHash = hashNonTargetBreakpoints(element.settings, allowedBreakpoints);
        if (nonTargetAfterHash !== mutation.details?.non_target_before_hash) {
          throw new Error("responsive_scope_violation: non-target breakpoint settings changed during update");
        }
        return {
          element_id: element.id,
          element_hash: await hashValue(element),
          settings_verified: true,
          non_target_hash: nonTargetAfterHash,
        };
      },
      rollback: async (args) => this.rollbackMutation("update_settings", args),
    };
  }

  private moveElementTool(): NestedEditorTool {
    return {
      name: "move_element",
      label: "Move native Elementor V3 element",
      description: "Moves an existing live element through Elementor history-aware commands.",
      mutates: true,
      parameters: mutationSchema({ element_id: { type: "string" }, parent_id: { type: "string" }, position: { type: "integer" } }, ["element_id", "idempotency_key"]),
      execute: async (args) => this.idempotent("move_element", args, async () => {
        const id = requiredString(args, "element_id");
        await this.requireElement(id);
        const parentId = optionalString(args.parent_id) || undefined;
        const position = optionalInteger(args.position);
        await this.runtime.moveElement(id, parentId, position);
        this.historyPosition++;
        return result(`Moved ${id}.`, { element_id: id, expected_parent_id: parentId, expected_position: position });
      }),
      readback: async (_args, mutation) => {
        const element = await this.requireElement(String(mutation.details?.element_id || ""));
        if ((mutation.details?.expected_parent_id || undefined) !== (element.parentId || undefined)) throw new Error("Elementor move parent readback mismatch.");
        if (mutation.details?.expected_position !== undefined && mutation.details.expected_position !== element.position) throw new Error("Elementor move position readback mismatch.");
        return { element_id: element.id, parent_id: element.parentId, position: element.position };
      },
      rollback: async (args) => this.rollbackMutation("move_element", args),
    };
  }

  private deleteElementTool(): NestedEditorTool {
    return {
      name: "delete_element",
      label: "Delete native Elementor V3 element",
      description: "Deletes an element through Elementor history after an explicit destructive confirmation flag.",
      mutates: true,
      parameters: mutationSchema({ element_id: { type: "string" }, confirm_delete: { type: "boolean", const: true } }, ["element_id", "confirm_delete", "idempotency_key"]),
      execute: async (args) => this.idempotent("delete_element", args, async () => {
        if (args.confirm_delete !== true) throw new Error("delete_element requires confirm_delete=true.");
        const id = requiredString(args, "element_id");
        await this.requireElement(id);
        await this.runtime.deleteElement(id);
        this.historyPosition++;
        return result(`Deleted ${id}.`, { element_id: id });
      }),
      readback: async (_args, mutation) => {
        const id = String(mutation.details?.element_id || "");
        if (await this.runtime.getElement(id)) throw new Error(`Elementor delete readback failed: ${id} still exists.`);
        return { element_id: id, absent: true };
      },
      rollback: async (args) => this.rollbackMutation("delete_element", args),
    };
  }

  private undoTool(): NestedEditorTool {
    return historyTool("undo", "Undo last Elementor editor action", async () => {
      const before = await hashValue(await this.runtime.getPageTree());
      await this.runtime.undo();
      this.historyPosition = Math.max(0, this.historyPosition - 1);
      return { before, after: await hashValue(await this.runtime.getPageTree()) };
    });
  }

  private redoTool(): NestedEditorTool {
    return historyTool("redo", "Redo last Elementor editor action", async () => {
      const before = await hashValue(await this.runtime.getPageTree());
      await this.runtime.redo();
      this.historyPosition++;
      return { before, after: await hashValue(await this.runtime.getPageTree()) };
    });
  }

  private saveTool(): NestedEditorTool {
    return {
      name: "save",
      label: "Save Elementor document",
      description: "Persists the active Elementor document and verifies the editor is no longer dirty.",
      mutates: true,
      batchable: false,
      execute: async () => {
        await this.runtime.save();
        return result(`Saved Elementor document ${this.runtime.documentId}.`, { document_id: this.runtime.documentId, tree_hash: await hashValue(await this.runtime.getPageTree()) });
      },
      readback: async () => {
        if (await this.runtime.isModified()) throw new Error("Elementor save readback failed: editor is still modified.");
        return { document_id: this.runtime.documentId, modified: false, tree_hash: await hashValue(await this.runtime.getPageTree()) };
      },
    };
  }

  private evidenceLedgerTool(): NestedEditorTool {
    return {
      name: "get_evidence_ledger",
      label: "Get Elementor write evidence",
      description: "Returns compact per-setting evidence retained for successful editor mutations.",
      execute: async () => result(`${this.evidence.list().length} evidence entries.`, { entries: this.evidence.list() }),
    };
  }

  private async validateWrite(
    widgetType: string,
    settings: ElementorV3Settings,
    effective: ElementorV3Settings,
    args: Record<string, unknown>,
    elementId?: string,
  ): Promise<{ schema: ElementorV3WidgetSchema; entry: EvidenceLedgerEntry; allowedBreakpoints: string[] }> {
    let schema = widgetType === "container" ? await this.runtime.getContainerSchema() : await this.runtime.getWidgetSchema(widgetType);
    if (!schema && widgetType === "container" && Object.keys(settings).length === 0) {
      schema = { widget_type: "container", controls: {}, source: "empty-settings-no-schema", version: this.runtime.version };
    }
    if (!schema) throw new Error(`Live Elementor editor schema unavailable for ${widgetType}; write refused.`);
    const allowedBreakpoints = authorizedBreakpoints(args, settings);
    validateElementorV3Settings(schema, settings, effective, { allowedBreakpoints });
    const schemaHash = await this.schemaHash(schema);
    const entry = this.evidence.validate({
      operationId: requiredString(args, "idempotency_key"),
      elementId,
      widgetType,
      settingKeys: Object.keys(settings),
      schemaHash,
      evidence: record(args.settings_evidence) as SettingsEvidenceInput,
    });
    return { schema, entry, allowedBreakpoints };
  }

  private async schemaHash(schema: ElementorV3WidgetSchema): Promise<string> {
    return schema.schema_hash || hashValue(schema.controls);
  }

  private async requireSchema(widgetType: string): Promise<ElementorV3WidgetSchema> {
    const schema = await this.runtime.getWidgetSchema(widgetType);
    if (!schema) throw new Error(`Widget is not registered in the live Elementor editor: ${widgetType}`);
    return schema;
  }

  private async requireElement(id: string): Promise<ElementorV3Element> {
    const element = await this.runtime.getElement(id);
    if (!element) throw new Error(`Element not found in the live Elementor editor: ${id}`);
    return element;
  }

  private async idempotent(name: string, args: Record<string, unknown>, execute: () => Promise<NestedToolResult>): Promise<NestedToolResult> {
    const key = requiredString(args, "idempotency_key");
    const cacheKey = `${this.runtime.documentId}:${name}:${key}`;
    const requestHash = await hashValue(args);
    const previous = this.idempotency.get(cacheKey);
    if (previous) {
      if (previous.requestHash !== requestHash) throw new Error(`Idempotency key reused with different input: ${key}`);
      return { ...structuredClone(previous.result), details: { ...previous.result.details, idempotent_replay: true } };
    }
    const output = await execute();
    this.idempotency.set(cacheKey, { requestHash, result: structuredClone(output) });
    return output;
  }

  private async rollbackMutation(name: string, args: Record<string, unknown>): Promise<void> {
    await this.runtime.undo();
    this.historyPosition = Math.max(0, this.historyPosition - 1);
    const key = optionalString(args.idempotency_key);
    if (key) this.idempotency.delete(`${this.runtime.documentId}:${name}:${key}`);
    if (name === "create_element" || name === "update_settings") {
      this.evidence.truncate(Math.max(0, this.evidence.size() - 1));
    }
  }
}

function historyTool(name: "undo" | "redo", description: string, execute: () => Promise<{ before: string; after: string }>): NestedEditorTool {
  return {
    name,
    label: `${name[0].toUpperCase()}${name.slice(1)} Elementor action`,
    description,
    mutates: true,
    batchable: false,
    execute: async () => {
      const hashes = await execute();
      if (hashes.before === hashes.after) throw new Error(`Elementor ${name} produced no tree change.`);
      return result(`${name} completed.`, { tree_hash: hashes.after });
    },
    readback: async (_args, output) => ({ tree_hash: output.details?.tree_hash }),
  };
}

function objectSchema(properties: Record<string, unknown>, required: string[] = []): Record<string, unknown> {
  return { type: "object", additionalProperties: false, properties, ...(required.length > 0 ? { required } : {}) };
}
function mutationSchema(properties: Record<string, unknown>, required: string[]): Record<string, unknown> {
  return objectSchema({ ...properties, idempotency_key: { type: "string", minLength: 8 } }, required);
}
function result(text: string, details: Record<string, unknown>): NestedToolResult { return { content: [{ type: "text", text }], details }; }
function requiredString(args: Record<string, unknown>, key: string): string {
  const value = args[key];
  if (typeof value !== "string" || value.trim() === "") throw new Error(`${key} is required.`);
  return value.trim();
}
function optionalString(value: unknown): string { return typeof value === "string" ? value.trim() : ""; }
function stringArray(value: unknown): string[] {
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === "string").map((item) => item.trim().toLowerCase()).filter(Boolean)
    : [];
}
function optionalInteger(value: unknown): number | undefined { return typeof value === "number" && Number.isInteger(value) ? value : undefined; }
function integer(value: unknown, fallback: number, min: number, max: number): number {
  return typeof value === "number" && Number.isFinite(value) ? Math.max(min, Math.min(max, Math.floor(value))) : fallback;
}
function record(value: unknown): Record<string, unknown> { return isRecord(value) ? value : {}; }
function isRecord(value: unknown): value is Record<string, unknown> { return Boolean(value && typeof value === "object" && !Array.isArray(value)); }
function flatten(tree: ElementorV3Element[]): ElementorV3Element[] { return tree.flatMap((element) => [element, ...flatten(element.children)]); }
function compactElement(element: ElementorV3Element): Record<string, unknown> {
  return { id: element.id, parent_id: element.parentId, position: element.position, el_type: element.elType, widget_type: element.widgetType, settings_keys: Object.keys(element.settings), child_count: element.children.length };
}
function compactControl(control: Record<string, unknown>): Record<string, unknown> {
  return Object.fromEntries(["type", "label", "responsive", "is_responsive", "dynamic", "condition"].filter((key) => control[key] !== undefined).map((key) => [key, control[key]]));
}
function requiredWidgetType(element: ElementorV3Element): string {
  if (!element.widgetType) throw new Error(`Widget ${element.id} has no widgetType in the live editor model.`);
  return element.widgetType;
}
function authorizedBreakpoints(args: Record<string, unknown>, settings: ElementorV3Settings): string[] {
  const explicit = stringArray(args.allowed_breakpoints).map((value) => value === "base" ? "desktop" : value);
  const evidence = record(args.settings_evidence);
  const derived = Object.keys(settings).flatMap((key) => {
    const scope = optionalString(record(evidence[key]).responsive_scope).toLowerCase();
    return scope.split(/[\s,|]+/).filter(Boolean).map((value) => value === "base" ? "desktop" : value);
  });
  const allowed = [...new Set(explicit.length > 0 ? explicit : derived)];
  if (Object.keys(settings).length > 0 && allowed.length === 0) {
    throw new Error("responsive_scope_required: provide allowed_breakpoints or per-setting responsive_scope evidence");
  }
  if (explicit.length > 0) {
    const outsideAuthorization = derived.find((breakpoint) => !explicit.includes(breakpoint));
    if (outsideAuthorization) {
      throw new Error(`responsive_scope_violation: evidence targets ${outsideAuthorization}; allowed=${explicit.join(",")}`);
    }
  }
  return allowed.length > 0 ? allowed : ["desktop"];
}
function assertSubset(expected: Record<string, unknown>, actual: Record<string, unknown>, message: string): void {
  for (const [key, value] of Object.entries(expected)) if (JSON.stringify(actual[key]) !== JSON.stringify(value)) throw new Error(`${message}: ${key}`);
}
