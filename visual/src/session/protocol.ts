// SPDX-License-Identifier: AGPL-3.0-or-later
export class WorkspaceFailure extends Error {
  constructor(readonly code: string, message: string, readonly details: Record<string, unknown> = {}, readonly retryable = false) { super(message); this.name = "WorkspaceFailure"; }
}
export function deepFreeze<T>(value: T): T { if (value && typeof value === "object") { for (const child of Object.values(value)) deepFreeze(child); Object.freeze(value); } return value; }
export function canonical(value: unknown, limit = 65536): string {
  let nodes = 0;
  const visit = (item: unknown, depth: number): unknown => {
    if (++nodes > 10000 || depth > 32) throw new WorkspaceFailure("workspace_input_limit", "Input exceeds structural bounds.");
    if (item === null || typeof item === "string" || typeof item === "boolean") return item;
    if (typeof item === "number" && Number.isFinite(item)) return item;
    if (Array.isArray(item)) return item.map((child) => visit(child, depth + 1));
    if (item && typeof item === "object" && (Object.getPrototypeOf(item) === Object.prototype || Object.getPrototypeOf(item) === null)) {
      const result: Record<string, unknown> = Object.create(null);
      for (const key of Object.keys(item).sort()) {
        if (["__proto__", "prototype", "constructor"].includes(key)) throw new WorkspaceFailure("workspace_input_unsafe", "Unsafe input key.");
        result[key] = visit((item as Record<string, unknown>)[key], depth + 1);
      }
      return result;
    }
    throw new WorkspaceFailure("workspace_input_invalid", "Only bounded JSON data is accepted.");
  };
  const text = JSON.stringify(visit(value, 0));
  if (new TextEncoder().encode(text).length > limit) throw new WorkspaceFailure("workspace_input_limit", "Input exceeds byte bounds.");
  return text;
}
export function object(value: unknown): Record<string, unknown> {
  canonical(value);
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new WorkspaceFailure("workspace_args_invalid", "Expected object arguments.");
  return value as Record<string, unknown>;
}
export async function digest(value: unknown): Promise<string> {
  const bytes = new TextEncoder().encode(canonical(value, 2097152));
  return Array.from(new Uint8Array(await globalThis.crypto.subtle.digest("SHA-256", bytes))).map((byte) => byte.toString(16).padStart(2, "0")).join("");
}
export const REQUEST_METHODS = ["workspace_status", "workspace_list_pages", "workspace_open_page", "workspace_close_page", "workspace_focus_page", "workspace_reload_page", "workspace_discover_backend_tools", "workspace_call_backend_tool", "workspace_list_page_tools", "workspace_call_page_tool", "workspace_pending_confirmations", "workspace_decide_confirmation", "workspace_get_action_status", "workspace_wait_action"] as const;
