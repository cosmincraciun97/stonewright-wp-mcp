// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AtomicElementSchema, AtomicPropSchema, AtomicSettings, AtomicStyleMap, ElementorV4Element, ElementorV4Runtime } from "./types.js";

interface ModelLike { attributes?: Record<string, unknown>; get?: (key: string) => unknown; toJSON?: () => Record<string, unknown>; }
interface ContainerLike { id?: string; type?: string; model?: ModelLike; settings?: ModelLike; children?: ContainerLike[] | { _views?: Record<string, { getContainer?: () => ContainerLike }> }; parent?: ContainerLike; view?: { _index?: number }; }
interface AtomicConfig { atomic?: boolean; version?: string; title?: string; atomic_props_schema?: Record<string, AtomicPropSchema>; }
interface ElementorLike {
  config?: { version?: string; elements?: Record<string, AtomicConfig> };
  widgetsCache?: Record<string, AtomicConfig>;
  documents?: { getCurrent: () => { id: string | number; container: ContainerLike } | null };
  getContainer: (id: string) => ContainerLike | null;
  getPreviewContainer?: () => ContainerLike;
  $preview?: ArrayLike<{ contentDocument?: Document }>;
}
interface CommandsLike { run: (command: string, args?: Record<string, unknown>) => unknown | Promise<unknown>; components?: { get?: (name: string) => { isEditorChanged?: () => boolean } | undefined }; }
interface WindowLike {
  elementor?: ElementorLike;
  $e?: CommandsLike;
  stonewrightElementorV4?: {
    updateElement: (elementId: string, patch: Partial<Pick<ElementorV4Element, "settings" | "styles" | "editor_settings" | "interactions">>) => void | Promise<void>;
  };
}

export function createWindowElementorV4Runtime(target: WindowLike = window as unknown as WindowLike): ElementorV4Runtime {
  const elementor = target.elementor; const commands = target.$e;
  if (!elementor || !commands || !elementor.documents?.getCurrent()) throw new Error("Elementor V4 editor runtime is unavailable.");
  const document = () => { const value = elementor.documents?.getCurrent(); if (!value) throw new Error("Elementor has no active document."); return value; };
  const run = async (name: string, args?: Record<string, unknown>) => await commands.run(name, args);
  const parent = (id?: string): ContainerLike => { const value = id ? elementor.getContainer(id) : (elementor.getPreviewContainer?.() || document().container); if (!value) throw new Error(`Elementor parent not found: ${id || "<document>"}`); return value; };
  const schemas = (): Map<string, AtomicElementSchema> => {
    const out = new Map<string, AtomicElementSchema>();
    for (const [type, config] of Object.entries(elementor.config?.elements || {})) if (config.atomic && config.atomic_props_schema) out.set(type, schema(type, "layout", config));
    for (const [type, config] of Object.entries(elementor.widgetsCache || {})) if (config.atomic && config.atomic_props_schema) out.set(type, schema(type, "widget", config));
    return out;
  };
  return {
    version: String(elementor.config?.version || "unknown"),
    get documentId() { return String(document().id); },
    async listAtomicTypes() { return [...schemas().values()].sort((a, b) => a.atomic_type.localeCompare(b.atomic_type)); },
    async getAtomicSchema(type) { return schemas().get(type) || null; },
    async getPageTree() { return children(document().container).map((item, index) => normalize(item, undefined, index)); },
    async getElement(id) { const item = elementor.getContainer(id); return item ? normalize(item, item.parent ? containerId(item.parent) : undefined, item.view?._index) : null; },
    async createElement(input) {
      const model = { ...input.payload, ...(input.payload.elType === "widget" ? { widgetType: input.atomicType } : {}) };
      const created = await run("document/elements/create", { container: parent(input.parentId), model, options: { edit: false, external: true, ...(input.position === undefined ? {} : { at: input.position }) } }) as ContainerLike | undefined;
      const id = created ? containerId(created) : ""; const readback = id ? elementor.getContainer(id) : null;
      if (!readback) throw new Error("Elementor V4 create command returned no readable element.");
      return normalize(readback, input.parentId, input.position);
    },
    async updateElement(id, patch) {
      const container = elementor.getContainer(id); if (!container) throw new Error(`Atomic element not found: ${id}`);
      const requiresBridge = patch.styles !== undefined || patch.editor_settings !== undefined || patch.interactions !== undefined;
      if (requiresBridge) {
        if (!target.stonewrightElementorV4?.updateElement) throw new Error("The installed Elementor runtime exposes no verified history-aware V4 payload bridge; write refused.");
        await target.stonewrightElementorV4.updateElement(id, patch);
        return;
      }
      await run("document/elements/settings", { container, settings: patch.settings || {}, options: { external: true, render: true } });
    },
    async moveElement(id, parentId, position) { const container = elementor.getContainer(id); if (!container) throw new Error(`Atomic element not found: ${id}`); await run("document/elements/move", { container, target: parent(parentId), options: { external: true, ...(position === undefined ? {} : { at: position }) } }); },
    async deleteElement(id) { const container = elementor.getContainer(id); if (!container) throw new Error(`Atomic element not found: ${id}`); await run("document/elements/delete", { container, callerName: "stonewright_visual_v4" }); },
    async undo() { await run("document/history/undo"); }, async redo() { await run("document/history/redo"); }, async save() { await run("document/save/default"); },
    async isModified() { const save = commands.components?.get?.("document/save"); if (!save?.isEditorChanged) throw new Error("Elementor save verification API is unavailable."); return save.isEditorChanged(); },
    async verifyFrontend(id) { const doc = elementor.$preview?.[0]?.contentDocument; if (!doc) throw new Error("Elementor preview document is unavailable for frontend verification."); const selectors = [`.elementor-element-${css(id)}`, `[data-id="${css(id)}"]`, `#${css(id)}`]; const selector = selectors.find((candidate) => doc.querySelector(candidate)); return { exists: Boolean(selector), ...(selector ? { selector } : {}) }; },
  };
}

function schema(type: string, kind: "layout" | "widget", config: AtomicConfig): AtomicElementSchema { return { atomic_type: type, kind, version: String(config.version || "0.0"), props: structuredClone(config.atomic_props_schema || {}), source: "elementor-editor-live-config" }; }
function normalize(container: ContainerLike, parentId?: string, position?: number): ElementorV4Element {
  const data = model(container.model); const id = containerId(container); const nested = children(container);
  return { id, version: String(data.version || ""), elType: String(data.elType || container.type || ""), ...(data.widgetType ? { widgetType: String(data.widgetType) } : {}), isInner: Boolean(data.isInner), settings: record(data.settings || model(container.settings)) as AtomicSettings, styles: record(data.styles) as AtomicStyleMap, editor_settings: record(data.editor_settings), interactions: Array.isArray(data.interactions) ? data.interactions as Array<Record<string, unknown>> : record(data.interactions) as ElementorV4Element["interactions"], elements: nested.map((child, index) => normalize(child, id, index)), ...(parentId ? { parentId } : {}), ...(position === undefined ? {} : { position }) };
}
function children(container: ContainerLike): ContainerLike[] { if (Array.isArray(container.children)) return container.children; return Object.values(container.children?._views || {}).map((view) => view.getContainer?.()).filter((item): item is ContainerLike => Boolean(item)); }
function containerId(container: ContainerLike): string { return String(container.id || container.model?.get?.("id") || container.model?.attributes?.id || ""); }
function model(value?: ModelLike): Record<string, unknown> { return record(value?.toJSON?.() || value?.attributes); }
function record(value: unknown): Record<string, unknown> { return value && typeof value === "object" && !Array.isArray(value) ? structuredClone(value as Record<string, unknown>) : {}; }
function css(value: string): string { return typeof CSS !== "undefined" && CSS.escape ? CSS.escape(value) : value.replace(/[^a-zA-Z0-9_-]/g, ""); }
