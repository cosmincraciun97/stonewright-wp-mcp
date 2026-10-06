// SPDX-License-Identifier: AGPL-3.0-or-later
import type { BackendToolDefinition, BackendTransport } from "../types.js";
import { checkSchema, validateArgs } from "../editor-tools/schema-guard.js";
import { canonical, deepFreeze, object, WorkspaceFailure } from "./protocol.js";
export interface BackendCapability { name: string; category: string; inputSchema: unknown; mutates: boolean; requiresConfirmation: boolean; }
const forbiddenName = (name: string): boolean => /(?:php.?execute|wp.?cli|theme.?files?|theme.?custom.?css|custom.?code|confirmation|wpcode|code.?snippets?|customizer|(?:php|css|javascript|html).?(?:apply|write|eval)|(?:apply|write|eval).?(?:php|css|javascript|html))/.test(name.toLowerCase().replace(/_/g, "-"));
export class BackendPolicy {
  private tools = new Map<string, BackendToolDefinition>();
  private readonly capabilities = new Map<string, BackendCapability>();
  constructor(private readonly port: BackendTransport, allowed: readonly BackendCapability[] = []) {
    if (allowed.length > 128) throw new WorkspaceFailure("workspace_backend_policy_invalid", "Trusted backend policy is too large.");
    for (const input of allowed) {
      if (typeof input.name !== "string" || !/^[\w-]{1,64}$/.test(input.name) || this.capabilities.has(input.name) || typeof input.category !== "string" || !input.category || typeof input.mutates !== "boolean" || typeof input.requiresConfirmation !== "boolean") throw new WorkspaceFailure("workspace_backend_policy_invalid", "Trusted backend policy requires unique typed capabilities.");
      checkSchema(input.inputSchema);
      if (!forbiddenName(input.name)) this.capabilities.set(input.name, deepFreeze(JSON.parse(canonical(input))));
    }
  }
  async discover(params: Record<string, unknown>): Promise<BackendToolDefinition[]> {
    const payload = object(await this.port.discover(params));
    if (payload.contract !== "stonewright/visual-safe-tools/v1" || !Array.isArray(payload.tools) || payload.tools.length > 128) throw new WorkspaceFailure("workspace_backend_catalog_invalid", "No valid Visual-safe discovery contract was supplied.");
    const next = new Map<string, BackendToolDefinition>(); const seen = new Set<string>();
    for (const value of payload.tools) {
      const row = object(value);
      if (typeof row.name !== "string" || !/^[\w-]{1,64}$/.test(row.name) || seen.has(row.name) || ["safe", "mutates", "dangerous", "requiresConfirmation"].some((key) => typeof row[key] !== "boolean")) throw new WorkspaceFailure("workspace_backend_catalog_invalid", "Backend tools must carry unique typed safety declarations.");
      seen.add(row.name);
      checkSchema(row.inputSchema);
      const capability = this.capabilities.get(row.name);
      if (capability && !forbiddenName(row.name) && row.safe === true && row.dangerous === false && row.category === capability.category && row.mutates === capability.mutates && row.requiresConfirmation === capability.requiresConfirmation && canonical(row.inputSchema) === canonical(capability.inputSchema)) next.set(row.name, deepFreeze(JSON.parse(canonical(row))) as BackendToolDefinition);
    }
    this.tools = next; return [...next.values()].map((tool) => structuredClone(tool));
  }
  definition(name: string, args: Record<string, unknown>): BackendToolDefinition { const tool = this.tools.get(name); if (!tool || forbiddenName(name)) throw new WorkspaceFailure("workspace_backend_forbidden", "Backend tool is outside the declared Visual-safe policy."); validateArgs(checkSchema(tool.inputSchema), args); return tool; }
  async read(name: string, args: Record<string, unknown>): Promise<unknown> { const tool = this.definition(name, args); if (tool.mutates || tool.requiresConfirmation) throw new WorkspaceFailure("workspace_backend_preview_unavailable", "This host supplies no verified backend write preview; dispatch is blocked."); return this.port.call(name, args); }
}
