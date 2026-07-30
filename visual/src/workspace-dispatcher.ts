// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/workspace-dispatcher.ts
// Source SHA-256: 0d7818ef8d76347b32770be6aba2ce9fce1dee0528b309d9f9bf1fd749af4253

import type { ConfirmationDecision, WorkspacePageDescriptor } from "./types.js";
import { PageToolRegistry } from "./page-tool-registry.js";
import { WorkspaceBackendTools } from "./workspace-backend-tools.js";
import { WorkspaceConfirmations } from "./workspace-confirmations.js";

export interface WorkspaceHost {
  status: () => unknown | Promise<unknown>;
  listPages: () => WorkspacePageDescriptor[] | Promise<WorkspacePageDescriptor[]>;
  openPage?: (params: Record<string, unknown>) => Promise<unknown>;
  closePage?: (pageId: string) => Promise<unknown>;
  focusPage?: (pageId: string) => Promise<unknown>;
  reloadPage?: (pageId: string) => Promise<unknown>;
  getPageTools: (pageId: string) => PageToolRegistry;
}

export class WorkspaceDispatcher {
  constructor(
    private readonly host: WorkspaceHost,
    private readonly backend: WorkspaceBackendTools,
    private readonly confirmations: WorkspaceConfirmations,
  ) {}

  async dispatch(method: string, params: unknown = {}): Promise<unknown> {
    const input = toRecord(params);
    switch (method) {
      case "workspace_status": return this.host.status();
      case "workspace_list_pages": return this.host.listPages();
      case "workspace_open_page": return this.requireHost("openPage")(input);
      case "workspace_close_page": return this.requireHost("closePage")(requiredString(input, "pageId"));
      case "workspace_focus_page": return this.requireHost("focusPage")(requiredString(input, "pageId"));
      case "workspace_reload_page": return this.requireHost("reloadPage")(requiredString(input, "pageId"));
      case "workspace_discover_backend_tools": return this.backend.discover(input);
      case "workspace_call_backend_tool": {
        return this.backend.call(requiredString(input, "toolName"), toRecord(input.args), optionalReason(input));
      }
      case "workspace_list_page_tools": return this.host.getPageTools(requiredString(input, "pageId")).definitions();
      case "workspace_call_page_tool": {
        const parsed = parseToolCall(requiredString(input, "toolName"), toRecord(input.args));
        return this.host.getPageTools(requiredString(input, "pageId")).call(parsed.toolName, parsed.args);
      }
      case "workspace_pending_confirmations": return this.confirmations.pending();
      case "workspace_decide_confirmation": {
        return this.confirmations.decide(
          requiredString(input, "actionId"),
          requiredDecision(input.decision),
        );
      }
      case "workspace_get_action_status": return this.confirmations.get(requiredString(input, "actionId"));
      case "workspace_wait_action": return this.confirmations.wait(requiredString(input, "actionId"), optionalTimeout(input.timeoutMs));
      default: throw new Error(`Unknown workspace method: ${method}`);
    }
  }

  private requireHost<K extends "openPage" | "closePage" | "focusPage" | "reloadPage">(key: K): NonNullable<WorkspaceHost[K]> {
    const handler = this.host[key];
    if (!handler) throw new Error(`Workspace host does not support ${key}.`);
    return handler as NonNullable<WorkspaceHost[K]>;
  }
}

function parseToolCall(toolName: string, args: Record<string, unknown>): { toolName: string; args: Record<string, unknown> } {
  if (Object.keys(args).length > 0) return { toolName, args };
  const match = toolName.match(/^([A-Za-z0-9_-]+)\s*(?::|\s)\s*({[\s\S]*})\s*$/);
  if (!match) return { toolName, args };
  const parsed: unknown = JSON.parse(match[2]);
  if (!isRecord(parsed)) throw new Error("Nested tool arguments must be a JSON object.");
  return { toolName: match[1], args: parsed };
}

function requiredString(input: Record<string, unknown>, key: string): string {
  const value = input[key] ?? input[key.replace(/[A-Z]/g, (letter) => `_${letter.toLowerCase()}`)];
  if (typeof value !== "string" || value.trim() === "") throw new Error(`Missing required string param: ${key}`);
  return value;
}
function optionalReason(input: Record<string, unknown>): string { return typeof input.reason === "string" ? input.reason.trim() : ""; }
function optionalTimeout(value: unknown): number { return typeof value === "number" ? Math.max(0, Math.min(value, 60_000)) : 60_000; }
function requiredDecision(value: unknown): ConfirmationDecision {
  if (["allow_once", "allow_session", "allow_all_tools_session", "deny"].includes(String(value))) return value as ConfirmationDecision;
  throw new Error("Invalid confirmation decision.");
}
function isRecord(value: unknown): value is Record<string, unknown> { return Boolean(value && typeof value === "object" && !Array.isArray(value)); }
function toRecord(value: unknown): Record<string, unknown> { return isRecord(value) ? value : {}; }
