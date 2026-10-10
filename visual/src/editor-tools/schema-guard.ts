// SPDX-License-Identifier: GPL-2.0-or-later
import { canonical, object, WorkspaceFailure } from "../session/protocol.js";
export interface CheckedSchema { schema: Record<string, unknown>; canonical: string; }
const supported = new Set(["type", "properties", "required", "additionalProperties", "items", "enum", "const", "minimum", "maximum", "exclusiveMinimum", "exclusiveMaximum", "minLength", "maxLength", "minItems", "maxItems", "minProperties", "maxProperties", "anyOf", "oneOf", "allOf", "description", "title", "default", "examples", "$schema"]);
export function checkSchema(value: unknown): CheckedSchema {
  const text = canonical(value, 131072);
  const schema = object(JSON.parse(text));
  const walk = (row: Record<string, unknown>): void => {
    for (const key of Object.keys(row)) if (!supported.has(key)) throw new WorkspaceFailure("workspace_schema_unsupported", `Unsupported schema keyword: ${key}.`);
    if (Array.isArray(row.type) && row.type.length === 0) throw new WorkspaceFailure("workspace_schema_invalid", "A type union cannot be empty.");
    if (row.type !== undefined) for (const type of Array.isArray(row.type) ? row.type : [row.type]) if (typeof type !== "string" || !["object", "array", "string", "boolean", "number", "integer", "null"].includes(type)) throw new WorkspaceFailure("workspace_schema_invalid", "Unsupported schema type.");
    if (row.additionalProperties !== undefined && typeof row.additionalProperties !== "boolean" && (!row.additionalProperties || typeof row.additionalProperties !== "object" || Array.isArray(row.additionalProperties))) throw new WorkspaceFailure("workspace_schema_invalid", "Additional properties must be boolean or a schema.");
    if (row.properties !== undefined) for (const child of Object.values(object(row.properties))) walk(object(child));
    if (row.items !== undefined) walk(object(row.items));
    if (typeof row.additionalProperties === "object") walk(object(row.additionalProperties));
    if (row.required !== undefined && (!Array.isArray(row.required) || row.required.some((key) => typeof key !== "string"))) throw new WorkspaceFailure("workspace_schema_invalid", "Required fields must be named strings.");
    for (const key of ["anyOf", "oneOf", "allOf"]) if (row[key] !== undefined) {
      if (!Array.isArray(row[key]) || row[key].length === 0) throw new WorkspaceFailure("workspace_schema_invalid", "Schema composition must be nonempty.");
      for (const child of row[key]) walk(object(child));
    }
    if (row.enum !== undefined && (!Array.isArray(row.enum) || row.enum.length === 0)) throw new WorkspaceFailure("workspace_schema_invalid", "Enum must be nonempty.");
    for (const key of ["minimum", "maximum", "exclusiveMinimum", "exclusiveMaximum", "minLength", "maxLength", "minItems", "maxItems", "minProperties", "maxProperties"]) if (row[key] !== undefined && (typeof row[key] !== "number" || !Number.isFinite(row[key]))) throw new WorkspaceFailure("workspace_schema_invalid", "Schema limits must be finite numbers.");
    for (const key of ["minLength", "maxLength", "minItems", "maxItems", "minProperties", "maxProperties"]) if (row[key] !== undefined && (!Number.isInteger(row[key]) || Number(row[key]) < 0)) throw new WorkspaceFailure("workspace_schema_invalid", "Size limits must be nonnegative integers.");
  };
  walk(schema); return { schema, canonical: text };
}
export function validateArgs(checked: CheckedSchema, value: unknown): void {
  canonical(value);
  const matches = (schema: Record<string, unknown>, item: unknown): boolean => {
    if (schema.const !== undefined && canonical(schema.const) !== canonical(item)) return false;
    if (Array.isArray(schema.enum) && !schema.enum.some((candidate) => canonical(candidate) === canonical(item))) return false;
    const alternatives = schema.type === undefined ? [] : Array.isArray(schema.type) ? schema.type : [schema.type];
    if (alternatives.length && !alternatives.some((type) => type === "null" ? item === null : type === "array" ? Array.isArray(item) : type === "integer" ? typeof item === "number" && Number.isInteger(item) : type === "object" ? !!item && typeof item === "object" && !Array.isArray(item) : typeof item === type)) return false;
    for (const key of ["anyOf", "oneOf", "allOf"] as const) if (Array.isArray(schema[key])) {
      const count = schema[key].filter((child) => matches(object(child), item)).length;
      if ((key === "anyOf" && count === 0) || (key === "oneOf" && count !== 1) || (key === "allOf" && count !== schema[key].length)) return false;
    }
    if (typeof item === "number") {
      if (typeof schema.minimum === "number" && item < schema.minimum || typeof schema.maximum === "number" && item > schema.maximum || typeof schema.exclusiveMinimum === "number" && item <= schema.exclusiveMinimum || typeof schema.exclusiveMaximum === "number" && item >= schema.exclusiveMaximum) return false;
    }
    const size = typeof item === "string" ? [...item].length : Array.isArray(item) ? item.length : item && typeof item === "object" ? Object.keys(item).length : null;
    const min = typeof item === "string" ? schema.minLength : Array.isArray(item) ? schema.minItems : schema.minProperties;
    const max = typeof item === "string" ? schema.maxLength : Array.isArray(item) ? schema.maxItems : schema.maxProperties;
    if (size !== null && (typeof min === "number" && size < min || typeof max === "number" && size > max)) return false;
    if (Array.isArray(item) && schema.items && !item.every((child) => matches(object(schema.items), child))) return false;
    if (item && typeof item === "object" && !Array.isArray(item)) {
      const row = item as Record<string, unknown>; const props = schema.properties ? object(schema.properties) : {};
      if (Array.isArray(schema.required) && schema.required.some((key) => !Object.hasOwn(row, String(key)))) return false;
      for (const key of Object.keys(row)) {
        if (Object.hasOwn(props, key)) { if (!matches(object(props[key]), row[key])) return false; }
        else if (schema.additionalProperties === false) return false;
        else if (typeof schema.additionalProperties === "object" && !matches(object(schema.additionalProperties), row[key])) return false;
      }
    }
    return true;
  };
  if (!matches(checked.schema, value)) throw new WorkspaceFailure("workspace_args_invalid", "Arguments do not satisfy the declared schema.");
}
export function summarizeSchema(schema: CheckedSchema): Record<string, unknown> { return JSON.parse(schema.canonical); }
