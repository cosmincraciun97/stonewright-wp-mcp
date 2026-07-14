// SPDX-License-Identifier: AGPL-3.0-or-later

export type ElementorV3Settings = Record<string, unknown>;

export interface ElementorV3Element {
  id: string;
  elType: string;
  widgetType?: string;
  settings: ElementorV3Settings;
  children: ElementorV3Element[];
  parentId?: string;
  position?: number;
}

export interface ElementorV3WidgetSummary {
  name: string;
  title?: string;
  categories?: string[];
  keywords?: string[];
  pro?: boolean;
}

export interface ElementorV3ControlSchema {
  type?: string;
  label?: string;
  default?: unknown;
  options?: Record<string, unknown>;
  fields?: Record<string, ElementorV3ControlSchema>;
  condition?: Record<string, unknown>;
  responsive?: boolean;
  is_responsive?: boolean;
  dynamic?: unknown;
  selectors?: Record<string, string>;
  [key: string]: unknown;
}

export interface ElementorV3WidgetSchema {
  widget_type: string;
  title?: string;
  controls: Record<string, ElementorV3ControlSchema>;
  schema_hash?: string;
  source?: string;
  version?: string;
}

export interface ElementorV3Runtime {
  readonly version: string;
  readonly documentId: string;
  listWidgets(): Promise<ElementorV3WidgetSummary[]>;
  getWidgetSchema(widgetType: string): Promise<ElementorV3WidgetSchema | null>;
  getContainerSchema(): Promise<ElementorV3WidgetSchema | null>;
  getPageTree(): Promise<ElementorV3Element[]>;
  getElement(elementId: string): Promise<ElementorV3Element | null>;
  createElement(input: {
    parentId?: string;
    position?: number;
    elType: "container" | "widget";
    widgetType?: string;
    settings: ElementorV3Settings;
  }): Promise<ElementorV3Element>;
  updateSettings(elementId: string, settings: ElementorV3Settings): Promise<void>;
  moveElement(elementId: string, parentId?: string, position?: number): Promise<void>;
  deleteElement(elementId: string): Promise<void>;
  undo(): Promise<void>;
  redo(): Promise<void>;
  save(): Promise<void>;
  isModified(): Promise<boolean>;
}

export interface SettingEvidence {
  control_key: string;
  schema_hash: string;
  source: string;
  confidence: number;
  responsive_scope: string;
  requires_confirmation: boolean;
}

export type SettingsEvidenceInput = Record<string, Omit<SettingEvidence, "control_key"> & { control_key?: string }>;
