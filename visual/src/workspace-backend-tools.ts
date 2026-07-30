// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/workspace-backend-tools.ts
// Source SHA-256: 50afbc989d9372f3f39857f9a7f5d31a41bbfd3e1d6457ccb4778cfc24eb533b

import type { BackendToolDefinition, BackendTransport, ConfirmationAction } from "./types.js";
import { WorkspaceConfirmations } from "./workspace-confirmations.js";

export class WorkspaceBackendTools {
  private cache: Map<string, BackendToolDefinition> | null = null;

  constructor(private readonly transport: BackendTransport, private readonly confirmations: WorkspaceConfirmations) {}

  async discover(params: Record<string, unknown> = {}): Promise<{ tools: BackendToolDefinition[] }> {
    const raw = await this.transport.discover({ ...params, include_dangerous: params.include_dangerous === true });
    const tools = parseDefinitions(raw).filter((tool) => tool.safe && (params.include_dangerous === true || !tool.dangerous));
    if (!params.search && !params.category) this.cache = new Map(tools.map((tool) => [tool.name, tool]));
    return { tools };
  }

  async call(name: string, args: Record<string, unknown>, reason: string): Promise<unknown> {
    const tool = await this.getDefinition(name);
    if (!tool.safe) throw new Error(`Backend tool is not in the Visual-safe contract: ${name}`);
    if (tool.mutates && reason.trim() === "") throw new Error(`Backend mutation ${name} requires a concise reason.`);
    const run = () => this.transport.call(name, args);
    if (!tool.mutates && !tool.dangerous && !tool.requiresConfirmation) return run();
    const action = `backend_tool:${name}` as ConfirmationAction;
    return this.confirmations.enqueue(action, {
      title: `Review backend tool: ${name}`,
      description: "This WordPress backend tool can change site data or has elevated risk.",
      reason,
      details: [
        { label: "Tool", value: tool.label ? `${tool.label} (${name})` : name },
        { label: "Arguments", value: JSON.stringify(args, null, 2), format: "code" },
      ],
    }, run);
  }

  private async getDefinition(name: string): Promise<BackendToolDefinition> {
    if (!this.cache?.has(name)) await this.discover({ include_schemas: false, include_dangerous: true });
    const tool = this.cache?.get(name);
    if (!tool) throw new Error(`Backend tool is unavailable: ${name}`);
    return tool;
  }
}

function parseDefinitions(raw: unknown): BackendToolDefinition[] {
  const tools = isRecord(raw) && Array.isArray(raw.tools) ? raw.tools : [];
  return tools.filter(isRecord).map((tool) => ({
    name: typeof tool.name === "string" ? tool.name : "",
    label: typeof tool.label === "string" ? tool.label : undefined,
    description: typeof tool.description === "string" ? tool.description : undefined,
    category: typeof tool.category === "string" ? tool.category : undefined,
    inputSchema: tool.input_schema ?? tool.inputSchema,
    safe: tool.safe !== false,
    mutates: tool.mutates === true || tool.read_only === false,
    dangerous: tool.dangerous === true,
    requiresConfirmation: tool.requires_confirmation === true,
  })).filter((tool) => tool.name !== "");
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value && typeof value === "object" && !Array.isArray(value));
}
