// SPDX-License-Identifier: AGPL-3.0-or-later

export type AtomicTypedValue = { $$type: string; value: unknown };
export type AtomicSettings = Record<string, AtomicTypedValue>;
export type AtomicStyleMap = Record<string, { id: string; label: string; type: "class"; variants: Array<Record<string, unknown>> }>;

export interface ElementorV4Element {
  id: string;
  version: string;
  elType: string;
  widgetType?: string;
  isInner: boolean;
  settings: AtomicSettings;
  styles: AtomicStyleMap;
  editor_settings: Record<string, unknown>;
  interactions: Array<Record<string, unknown>> | { version: number; items: Array<Record<string, unknown>> };
  elements: ElementorV4Element[];
  parentId?: string;
  position?: number;
}

export interface AtomicPropSchema {
  properties?: { $$type?: { const?: string }; value?: unknown };
  required?: string[];
  [key: string]: unknown;
}

export interface AtomicElementSchema {
  atomic_type: string;
  kind: "layout" | "widget";
  version: string;
  props: Record<string, AtomicPropSchema>;
  source: string;
}

export interface ElementorV4Runtime {
  readonly version: string;
  readonly documentId: string;
  listAtomicTypes(): Promise<AtomicElementSchema[]>;
  getAtomicSchema(atomicType: string): Promise<AtomicElementSchema | null>;
  getPageTree(): Promise<ElementorV4Element[]>;
  getElement(elementId: string): Promise<ElementorV4Element | null>;
  createElement(input: { atomicType: string; parentId?: string; position?: number; payload: Omit<ElementorV4Element, "id" | "elements" | "parentId" | "position"> }): Promise<ElementorV4Element>;
  updateElement(elementId: string, patch: Partial<Pick<ElementorV4Element, "settings" | "styles" | "editor_settings" | "interactions">>): Promise<void>;
  moveElement(elementId: string, parentId?: string, position?: number): Promise<void>;
  deleteElement(elementId: string): Promise<void>;
  undo(): Promise<void>;
  redo(): Promise<void>;
  save(): Promise<void>;
  isModified(): Promise<boolean>;
  verifyFrontend(elementId: string): Promise<{ exists: boolean; selector?: string }>;
}
