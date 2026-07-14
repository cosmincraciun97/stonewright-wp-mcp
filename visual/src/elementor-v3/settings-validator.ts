// SPDX-License-Identifier: AGPL-3.0-or-later

import { baseResponsiveKey } from "./evidence-ledger.js";
import type { ElementorV3ControlSchema, ElementorV3Settings, ElementorV3WidgetSchema } from "./types.js";

const CTA_WIDGETS = new Set(["button", "call-to-action", "read-more", "price-table"]);

export function validateElementorV3Settings(
  schema: ElementorV3WidgetSchema,
  settings: ElementorV3Settings,
  effectiveSettings: ElementorV3Settings = settings,
): void {
  const violations: string[] = [];
  for (const [key, value] of Object.entries(settings)) {
    if (["__dynamic__", "__globals__"].includes(key)) {
      validateBindings(key, value, schema.controls, violations);
      continue;
    }
    const controlKey = baseResponsiveKey(key);
    const control = schema.controls[controlKey];
    if (!control || (controlKey !== key && !isResponsive(control))) {
      violations.push(`${key}: unknown setting in live schema`);
      continue;
    }
    validateValue(key, value, control, violations);
    if (!conditionActive(control.condition, effectiveSettings, schema.controls)) {
      violations.push(`${key}: control condition is inactive`);
    }
  }
  validateCta(schema, effectiveSettings, violations);
  if (violations.length > 0) throw new Error(`Elementor V3 settings rejected: ${violations.slice(0, 8).join("; ")}`);
}

function validateBindings(
  key: string,
  value: unknown,
  controls: Record<string, ElementorV3ControlSchema>,
  violations: string[],
): void {
  if (!isRecord(value)) {
    violations.push(`${key}: expected an object keyed by live controls`);
    return;
  }
  for (const target of Object.keys(value)) if (!controls[target]) violations.push(`${key}.${target}: unknown binding target`);
}

function validateValue(path: string, value: unknown, control: ElementorV3ControlSchema, violations: string[]): void {
  if (control.fields) {
    if (!Array.isArray(value)) {
      violations.push(`${path}: expected repeater rows`);
      return;
    }
    value.forEach((row, index) => {
      if (!isRecord(row)) {
        violations.push(`${path}.${index}: expected repeater object`);
        return;
      }
      for (const [field, fieldValue] of Object.entries(row)) {
        if (field === "_id") continue;
        const fieldSchema = control.fields?.[field];
        if (!fieldSchema) violations.push(`${path}.${index}.${field}: unknown repeater field`);
        else validateValue(`${path}.${index}.${field}`, fieldValue, fieldSchema, violations);
      }
    });
    return;
  }

  const type = String(control.type || "").toLowerCase();
  if (["select", "choose", "select2"].includes(type) && control.options && (typeof value === "string" || typeof value === "number")) {
    if (!Object.hasOwn(control.options, String(value))) violations.push(`${path}: invalid live option`);
  }
  if (type === "url" && !validUrl(value)) violations.push(`${path}: invalid URL value`);
  if (["media", "gallery"].includes(type) && !validMedia(value)) violations.push(`${path}: invalid media value`);
  if (type === "dimensions" && (!isRecord(value) || Object.keys(value).some((key) => !["top", "right", "bottom", "left", "unit", "isLinked"].includes(key)))) {
    violations.push(`${path}: invalid dimensions value`);
  }
  if (["number", "slider"].includes(type) && !(typeof value === "number" || numeric(value) || (isRecord(value) && numeric(value.size)))) {
    violations.push(`${path}: expected numeric/slider value`);
  }
}

function validateCta(schema: ElementorV3WidgetSchema, settings: ElementorV3Settings, violations: string[]): void {
  if (!CTA_WIDGETS.has(schema.widget_type)) return;
  const linkKeys = Object.entries(schema.controls)
    .filter(([key, control]) => String(control.type || "") === "url" || /(^|_)(link|url)$/.test(key))
    .map(([key]) => key);
  if (linkKeys.length === 0) {
    violations.push("cta: live schema exposes no URL/action control");
    return;
  }
  const resolved = linkKeys.some((key) => {
    const value = settings[key];
    return typeof value === "string" ? value.trim() !== "" : isRecord(value) && typeof value.url === "string" && value.url.trim() !== "";
  });
  if (!resolved) violations.push(`cta: URL/action required via ${linkKeys.slice(0, 4).join(", ")}`);
}

function conditionActive(
  condition: Record<string, unknown> | undefined,
  settings: ElementorV3Settings,
  controls: Record<string, ElementorV3ControlSchema>,
): boolean {
  if (!condition) return true;
  return Object.entries(condition).every(([rawKey, expected]) => {
    const negated = rawKey.endsWith("!");
    const key = negated ? rawKey.slice(0, -1) : rawKey;
    const actual = settings[key] ?? controls[key]?.default;
    const matches = Array.isArray(expected) ? expected.includes(actual) : String(actual) === String(expected);
    return negated ? !matches : matches;
  });
}

function isResponsive(control: ElementorV3ControlSchema): boolean { return control.responsive === true || control.is_responsive === true; }
function numeric(value: unknown): boolean { return (typeof value === "string" && value.trim() !== "" && Number.isFinite(Number(value))); }
function validUrl(value: unknown): boolean {
  if (typeof value === "string") return value === "" || value.startsWith("/") || value.startsWith("#") || /^https?:\/\//i.test(value) || /^(mailto|tel):/i.test(value);
  return isRecord(value) && typeof value.url === "string" && validUrl(value.url);
}
function validMedia(value: unknown): boolean {
  if (Array.isArray(value)) return value.every(validMedia);
  return isRecord(value) && ((typeof value.id === "number" && value.id > 0) || (typeof value.url === "string" && value.url !== ""));
}
function isRecord(value: unknown): value is Record<string, unknown> { return Boolean(value && typeof value === "object" && !Array.isArray(value)); }
