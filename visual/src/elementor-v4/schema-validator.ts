// SPDX-License-Identifier: AGPL-3.0-or-later

import type { AtomicElementSchema, AtomicSettings, ElementorV4Element } from "./types.js";

export function validateAtomicEnvelope(element: Partial<ElementorV4Element>, schema: AtomicElementSchema): void {
  if (!element.version || element.version !== schema.version) throw new Error(`Atomic version mismatch: expected ${schema.version}.`);
  const actualType = element.elType === "widget" ? element.widgetType : element.elType;
  if (actualType !== schema.atomic_type) throw new Error(`Atomic type mismatch: expected ${schema.atomic_type}, got ${actualType || "<missing>"}.`);
  if (schema.kind === "widget" && element.elType !== "widget") throw new Error("Atomic widget requires elType=widget.");
  if (schema.kind === "layout" && element.elType !== schema.atomic_type) throw new Error("Atomic layout requires its native elType.");
  validateAtomicSettings(element.settings || {}, schema);
  if (!Array.isArray(element.elements || [])) throw new Error("Atomic elements must be an array.");
  if (!isRecord(element.styles || {})) throw new Error("Atomic styles must be an object keyed by style id.");
  if (!isRecord(element.editor_settings || {})) throw new Error("Atomic editor_settings must be an object.");
  const interactions = element.interactions || [];
  if (!Array.isArray(interactions) && !isRecord(interactions)) throw new Error("Atomic interactions must be an array or versioned object.");
}

export function validateAtomicSettings(settings: AtomicSettings, schema: AtomicElementSchema): void {
  for (const [key, value] of Object.entries(settings)) {
    const prop = schema.props[key];
    if (!prop) throw new Error(`Unknown Atomic setting ${schema.atomic_type}.${key}.`);
    if (!isRecord(value) || typeof value.$$type !== "string" || !("value" in value)) throw new Error(`Atomic setting ${key} requires {$$type,value}.`);
    const expected = prop.properties?.$$type?.const;
    if (expected && value.$$type !== expected) throw new Error(`Atomic setting ${key} expects $$type=${expected}, got ${value.$$type}.`);
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === "object" && !Array.isArray(value));
}
