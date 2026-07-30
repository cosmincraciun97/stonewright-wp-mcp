// SPDX-License-Identifier: AGPL-3.0-or-later

import { baseResponsiveKey } from "./evidence-ledger.js";
import type { ElementorV3ControlSchema, ElementorV3Settings, ElementorV3WidgetSchema } from "./types.js";

const CTA_WIDGETS = new Set(["button", "call-to-action", "read-more", "price-table"]);

const BREAKPOINT_SUFFIX: Record<string, string> = {
  widescreen: "widescreen",
  laptop: "laptop",
  tablet_extra: "tablet_extra",
  tablet: "tablet",
  mobile_extra: "mobile_extra",
  mobile: "mobile",
};

export function settingBreakpoint(key: string): string {
  for (const name of Object.values(BREAKPOINT_SUFFIX)) {
    if (key.endsWith(`_${name}`)) return name;
  }
  return "desktop";
}

/**
 * Enforce design-derived responsive scope. Mobile-only evidence may only
 * mutate mobile keys. Non-responsive controls return a structured no-op error.
 */
export function assertResponsiveScope(
  settings: ElementorV3Settings,
  allowedBreakpoints: string[],
  schema?: ElementorV3WidgetSchema,
): void {
  const allowed = allowedBreakpoints.map((b) => b.toLowerCase().trim()).filter(Boolean);
  if (allowed.length === 0) return;

  for (const key of Object.keys(settings)) {
    if (["__dynamic__", "__globals__"].includes(key)) continue;
    const bp = settingBreakpoint(key);
    if (!allowed.includes(bp)) {
      throw new Error(
        `responsive_scope_violation: ${key} targets ${bp}; allowed=${allowed.join(",")}`,
      );
    }
    const base = baseResponsiveKey(key);
    const control = schema?.controls[base];
    if (control && !isResponsive(control) && key !== base) {
      throw new Error(
        `unsupported_responsive_control: widget=${schema?.widget_type ?? "unknown"} control=${base} breakpoint=${bp}`,
      );
    }
    if (
      control &&
      isResponsive(control) &&
      key === base &&
      !allowed.includes("desktop") &&
      !allowed.includes("base")
    ) {
      throw new Error(
        `responsive_scope_violation: base key ${key} outside allowed scope ${allowed.join(",")}; use breakpoint-suffixed key`,
      );
    }
  }
}

/** Hash settings outside the allowed breakpoint scope for before/after integrity. */
export function hashNonTargetBreakpoints(
  settings: ElementorV3Settings,
  allowedBreakpoints: string[],
): string {
  const allowed = allowedBreakpoints.map((b) => b.toLowerCase());
  const kept: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(settings)) {
    if (["__dynamic__", "__globals__"].includes(key)) continue;
    if (!allowed.includes(settingBreakpoint(key))) kept[key] = value;
  }
  const keys = Object.keys(kept).sort();
  const ordered: Record<string, unknown> = {};
  for (const k of keys) ordered[k] = kept[k];
  // Simple stable hash without node crypto dependency in browser bundles.
  const json = JSON.stringify(ordered);
  let h = 2166136261;
  for (let i = 0; i < json.length; i++) {
    h ^= json.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return (h >>> 0).toString(16).padStart(8, "0");
}

export function validateElementorV3Settings(
  schema: ElementorV3WidgetSchema,
  settings: ElementorV3Settings,
  effectiveSettings: ElementorV3Settings = settings,
  options: { allowedBreakpoints?: string[] } = {},
): void {
  if (options.allowedBreakpoints?.length) {
    assertResponsiveScope(settings, options.allowedBreakpoints, schema);
  }
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
