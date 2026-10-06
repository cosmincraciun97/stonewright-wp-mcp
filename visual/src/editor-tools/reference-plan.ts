// SPDX-License-Identifier: AGPL-3.0-or-later
import { canonical, object, WorkspaceFailure } from "../session/protocol.js";
export interface PlannedCall { tool: string; args: Record<string, unknown>; alias?: string; }
export interface ResultContract { primaryResultField?: string; publicResultFields: Record<string, Record<string, unknown>>; }
function refs(value: unknown): string[] {
  if (typeof value === "string" && value.startsWith("$") && !value.startsWith("$$")) return [value.slice(1)];
  if (Array.isArray(value)) return value.flatMap(refs);
  if (value && typeof value === "object") { const row = value as Record<string, unknown>; return typeof row.$ref === "string" ? [row.$ref] : Object.values(row).flatMap(refs); }
  return [];
}
export function compileBatch(input: unknown, available: (name: string) => boolean): PlannedCall[] {
  canonical(input, 262144); const row = object(input);
  if (Object.keys(row).some((key) => key !== "calls") || !Array.isArray(row.calls) || row.calls.length < 1 || row.calls.length > 20) throw new WorkspaceFailure("workspace_batch_invalid", "A batch requires 1–20 declared calls.");
  const aliases = new Set<string>();
  return row.calls.map((raw) => {
    const call = object(raw);
    if (Object.keys(call).some((key) => !["tool", "args", "alias"].includes(key)) || typeof call.tool !== "string" || !available(call.tool)) throw new WorkspaceFailure("workspace_batch_invalid", "Unknown or non-batchable tool.");
    const args = object(call.args ?? {});
    for (const ref of refs(args)) if (!/^[a-zA-Z][\w-]{0,63}$/.test(ref) || !aliases.has(ref)) throw new WorkspaceFailure("workspace_reference_invalid", "References must name a prior result in this batch.");
    if (call.alias !== undefined && (typeof call.alias !== "string" || !/^[a-zA-Z][\w-]{0,63}$/.test(call.alias) || aliases.has(call.alias))) throw new WorkspaceFailure("workspace_reference_invalid", "Aliases must be unique bounded names.");
    if (typeof call.alias === "string") aliases.add(call.alias);
    return { tool: call.tool, args, ...(typeof call.alias === "string" ? { alias: call.alias } : {}) };
  });
}
export function resolveCallArgs(value: unknown, results: Map<string, { value: Record<string, unknown>; contract: ResultContract }>): unknown {
  const resolve = (name: string, path?: string): unknown => {
    const result = results.get(name); const field = path === undefined ? result?.contract.primaryResultField : path.startsWith("/") && path.split("/").length === 2 ? path.slice(1) : undefined;
    if (!result || !field || !Object.hasOwn(result.contract.publicResultFields, field) || !Object.hasOwn(result.value, field)) throw new WorkspaceFailure("workspace_reference_invalid", "Reference is not a declared public result field.");
    return JSON.parse(canonical(result.value[field]));
  };
  if (typeof value === "string") return value.startsWith("$$") ? value.slice(1) : value.startsWith("$") ? resolve(value.slice(1)) : value;
  if (Array.isArray(value)) return value.map((child) => resolveCallArgs(child, results));
  if (value && typeof value === "object") {
    const row = object(value);
    if (Object.hasOwn(row, "$ref")) {
      if (Object.keys(row).some((key) => !["$ref", "path"].includes(key)) || typeof row.$ref !== "string" || row.path !== undefined && typeof row.path !== "string") throw new WorkspaceFailure("workspace_reference_invalid", "Invalid reference shape.");
      return resolve(row.$ref, row.path as string | undefined);
    }
    return Object.fromEntries(Object.entries(row).map(([key, child]) => [key, resolveCallArgs(child, results)]));
  }
  return value;
}
