// SPDX-License-Identifier: AGPL-3.0-or-later

import type {
  ElementorV3Element,
  ElementorV3Runtime,
  ElementorV3Settings,
  ElementorV3WidgetSchema,
  ElementorV3WidgetSummary,
} from "./types.js";

interface BackboneLike {
  attributes?: Record<string, unknown>;
  get?: (key: string) => unknown;
  toJSON?: () => Record<string, unknown>;
}

interface ElementorContainerLike {
  id?: string;
  type?: string;
  model?: BackboneLike;
  settings?: BackboneLike;
  controls?: Record<string, unknown>;
  children?: ElementorContainerLike[] | { _views?: Record<string, { getContainer?: () => ElementorContainerLike }> };
  parent?: ElementorContainerLike;
  view?: { _index?: number };
}

interface ElementorDocumentLike { id: string | number; container: ElementorContainerLike; }
interface ElementorWidgetLike {
  name?: string;
  title?: string;
  categories?: string[];
  keywords?: string[];
  controls?: Record<string, unknown>;
}

interface ElementorLike {
  config?: { version?: string; controls?: Record<string, unknown> };
  widgetsCache?: Record<string, ElementorWidgetLike>;
  getContainer: (id: string) => ElementorContainerLike | null;
  getPreviewContainer?: () => ElementorContainerLike;
  documents?: { getCurrent: () => ElementorDocumentLike | null };
}

interface ElementorCommandsLike {
  run: (command: string, args?: Record<string, unknown>) => unknown | Promise<unknown>;
  commands?: { getAll?: () => unknown };
  components?: { get?: (name: string) => { isEditorChanged?: () => boolean } | undefined };
}

interface ElementorWindowLike { elementor?: ElementorLike; $e?: ElementorCommandsLike; }

const REQUIRED_COMMANDS = [
  "document/elements/create", "document/elements/settings", "document/elements/move", "document/elements/delete",
  "document/history/undo", "document/history/redo", "document/save/default",
] as const;

/**
 * Connects Stonewright to the actual Elementor V3 editor command bus.
 * No DOM mutation or private post-data rewrite is used.
 */
export function createWindowElementorV3Runtime(target: ElementorWindowLike = window as unknown as ElementorWindowLike): ElementorV3Runtime {
  const elementor = target.elementor;
  const commands = target.$e;
  if (!elementor || !commands || !elementor.documents?.getCurrent()) throw new Error("Elementor V3 editor runtime is unavailable.");

  const document = (): ElementorDocumentLike => {
    const current = elementor.documents?.getCurrent();
    if (!current) throw new Error("Elementor has no active document.");
    return current;
  };

  const assertCommand = (name: string): void => {
    const available = commandNames(commands.commands?.getAll?.());
    if (available.length > 0 && !available.includes(name)) throw new Error(`Elementor runtime command is unavailable: ${name}`);
  };
  for (const command of REQUIRED_COMMANDS) assertCommand(command);

  const run = async (name: string, args?: Record<string, unknown>): Promise<unknown> => {
    assertCommand(name);
    return await commands.run(name, args);
  };
  const parent = (parentId?: string): ElementorContainerLike => {
    const result = parentId ? elementor.getContainer(parentId) : (elementor.getPreviewContainer?.() || document().container);
    if (!result) throw new Error(`Elementor parent not found: ${parentId || "<document>"}`);
    return result;
  };

  return {
    version: String(elementor.config?.version || "unknown"),
    get documentId() { return String(document().id); },
    async listWidgets(): Promise<ElementorV3WidgetSummary[]> {
      return Object.entries(elementor.widgetsCache || {}).map(([name, widget]) => ({
        name,
        title: widget.title,
        categories: widget.categories,
        keywords: widget.keywords,
      })).sort((a, b) => a.name.localeCompare(b.name));
    },
    async getWidgetSchema(widgetType): Promise<ElementorV3WidgetSchema | null> {
      const widget = elementor.widgetsCache?.[widgetType];
      if (!widget?.controls) return null;
      return { widget_type: widgetType, title: widget.title, controls: widget.controls, source: "elementor-editor-widgetsCache", version: String(elementor.config?.version || "unknown") } as ElementorV3WidgetSchema;
    },
    async getContainerSchema(): Promise<ElementorV3WidgetSchema | null> {
      const cached = elementor.widgetsCache?.container?.controls;
      if (!cached) return null;
      return { widget_type: "container", controls: cached, source: "elementor-editor-widgetsCache", version: String(elementor.config?.version || "unknown") } as ElementorV3WidgetSchema;
    },
    async getPageTree(): Promise<ElementorV3Element[]> {
      return childContainers(document().container).map((item, index) => normalizeContainer(item, undefined, index));
    },
    async getElement(elementId): Promise<ElementorV3Element | null> {
      const container = elementor.getContainer(elementId);
      return container ? normalizeContainer(container, container.parent ? containerId(container.parent) : undefined, container.view?._index) : null;
    },
    async createElement(input): Promise<ElementorV3Element> {
      if (input.elType === "widget" && !input.widgetType) throw new Error("widgetType is required for Elementor widget creation.");
      const result = await run("document/elements/create", {
        container: parent(input.parentId),
        model: {
          elType: input.elType,
          ...(input.widgetType ? { widgetType: input.widgetType } : {}),
          settings: input.settings,
        },
        options: { edit: false, external: true, ...(input.position === undefined ? {} : { at: input.position }) },
      }) as ElementorContainerLike | undefined;
      const id = result ? containerId(result) : "";
      if (!id) throw new Error("Elementor create command returned no element id.");
      const readback = elementor.getContainer(id);
      if (!readback) throw new Error(`Elementor created ${id}, but immediate readback failed.`);
      return normalizeContainer(readback, input.parentId, input.position);
    },
    async updateSettings(elementId, settings): Promise<void> {
      const container = elementor.getContainer(elementId);
      if (!container) throw new Error(`Elementor element not found: ${elementId}`);
      await run("document/elements/settings", { container, settings, options: { external: true, render: true } });
    },
    async moveElement(elementId, parentId, position): Promise<void> {
      const container = elementor.getContainer(elementId);
      if (!container) throw new Error(`Elementor element not found: ${elementId}`);
      await run("document/elements/move", {
        container,
        target: parent(parentId),
        options: { external: true, ...(position === undefined ? {} : { at: position }) },
      });
    },
    async deleteElement(elementId): Promise<void> {
      const container = elementor.getContainer(elementId);
      if (!container) throw new Error(`Elementor element not found: ${elementId}`);
      await run("document/elements/delete", { container, callerName: "stonewright_visual" });
    },
    async undo(): Promise<void> { await run("document/history/undo"); },
    async redo(): Promise<void> { await run("document/history/redo"); },
    async save(): Promise<void> { await run("document/save/default"); },
    async isModified(): Promise<boolean> {
      const save = commands.components?.get?.("document/save");
      if (!save?.isEditorChanged) throw new Error("Elementor save verification API is unavailable: document/save.isEditorChanged");
      return save.isEditorChanged();
    },
  };
}

function normalizeContainer(container: ElementorContainerLike, parentId?: string, position?: number): ElementorV3Element {
  const model = modelJson(container.model);
  const settings = modelJson(container.settings);
  const id = containerId(container);
  const children = childContainers(container);
  return {
    id,
    elType: String(model.elType || container.type || ""),
    ...(model.widgetType ? { widgetType: String(model.widgetType) } : {}),
    settings,
    children: children.map((child, index) => normalizeContainer(child, id, index)),
    ...(parentId ? { parentId } : {}),
    ...(position === undefined ? {} : { position }),
  };
}

function childContainers(container: ElementorContainerLike): ElementorContainerLike[] {
  if (Array.isArray(container.children)) return container.children;
  const views = container.children?._views || {};
  return Object.values(views).map((view) => view.getContainer?.()).filter((value): value is ElementorContainerLike => Boolean(value));
}

function containerId(container: ElementorContainerLike): string {
  return String(container.id || container.model?.get?.("id") || container.model?.attributes?.id || "");
}

function modelJson(model?: BackboneLike): ElementorV3Settings {
  if (!model) return {};
  const raw = model.toJSON?.() || model.attributes || {};
  return isRecord(raw) ? structuredClone(raw) : {};
}

function commandNames(raw: unknown): string[] {
  if (Array.isArray(raw)) return raw.filter((item): item is string => typeof item === "string");
  if (!isRecord(raw)) return [];
  return Object.keys(raw).flatMap((namespace) => {
    const value = raw[namespace];
    if (!isRecord(value)) return [namespace];
    return Object.keys(value).map((command) => command.includes("/") ? command : `${namespace}/${command}`);
  });
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === "object" && !Array.isArray(value));
}
