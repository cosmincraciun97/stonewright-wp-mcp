// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/expose-tools.ts
// Source SHA-256: b4423c9f610af5faf7898dd36ed42e8f81b04e6a0b23c5c7121d168bc58d6f1a

import type { BatchTransactionFactory, NestedEditorTool, NestedToolResult } from "./types.js";
import { summarizeToolInput } from "./tool-schema-summary.js";

export interface BatchCallInput {
  calls: Array<{ tool?: string; toolName?: string; name?: string; args?: unknown; id?: string }>;
  stop_on_error?: boolean;
  rollback_on_error?: boolean;
  include_call_details?: boolean;
  require_readback?: boolean;
  max_text_chars?: number;
}

export class PageToolRegistry {
  private readonly tools = new Map<string, NestedEditorTool>();

  constructor(tools: NestedEditorTool[], private readonly transactions?: BatchTransactionFactory) {
    for (const tool of tools) {
      if (!tool.name || tool.name === "batch_call" || this.tools.has(tool.name)) {
        throw new Error(`Invalid or duplicate nested editor tool: ${tool.name || "<missing>"}`);
      }
      this.tools.set(tool.name, tool);
    }
  }

  definitions(): Array<Record<string, unknown>> {
    return [
      ...Array.from(this.tools.values()).map((tool) => ({
        name: tool.name,
        label: tool.label,
        description: tool.description,
        mutates: tool.mutates === true,
        batchable: tool.batchable !== false,
        input: summarizeToolInput(tool.parameters),
      })),
      {
        name: "batch_call",
        label: "Batch calls",
        description: "Run related nested editor calls with aliases, mandatory mutation readback, and rollback on failure.",
        mutates: true,
        input: summarizeToolInput(batchCallSchema()),
      },
    ];
  }

  async call(name: string, params: unknown = {}): Promise<NestedToolResult> {
    if (name === "batch_call") return this.batchCall(toRecord(normalizeArgAliases(params)) as unknown as BatchCallInput);
    const tool = this.tools.get(name);
    if (!tool) throw new Error(`Nested editor tool is unavailable: ${name}`);
    const args = toRecord(normalizeArgAliases(params));
    const missing = missingRequired(tool.parameters, args);
    if (missing.length > 0) throw new Error(`Missing required args for ${name}: ${missing.join(", ")}.`);
    return this.executeWithReadback(tool, args, true);
  }

  async batchCall(input: BatchCallInput): Promise<NestedToolResult> {
    const calls = Array.isArray(input.calls) ? input.calls : [];
    if (calls.length === 0) throw new Error("batch_call requires a non-empty calls array.");
    if (calls.length > 50) throw new Error("batch_call accepts at most 50 calls.");

    const stopOnError = input.stop_on_error !== false;
    const rollbackOnError = stopOnError && input.rollback_on_error !== false;
    const includeDetails = input.include_call_details === true;
    const requireReadback = input.require_readback !== false;
    const maxText = clamp(input.max_text_chars, 80, 4000, 400);
    const refs: Record<string, unknown> = {};
    const rows: Array<Record<string, unknown>> = [];
    const summaries: string[] = [];
    const completedMutations: Array<{ tool: NestedEditorTool; args: Record<string, unknown>; result: NestedToolResult }> = [];
    const transaction = rollbackOnError && this.transactions ? await this.transactions.begin() : null;
    let rolledBack = false;

    for (let index = 0; index < calls.length; index++) {
      const call = toRecord(calls[index]);
      const toolName = firstString(call.tool, call.toolName, call.name);
      const tool = this.tools.get(toolName);
      if (!tool || toolName === "batch_call") {
        const error = `batch_call[${index}] has unavailable tool: ${toolName || "<missing>"}`;
        rows.push({ index, tool: toolName, ok: false, error });
        summaries.push(`${index + 1}. ${toolName || "<missing>"}: ERROR ${truncate(error, 400)}`);
        if (stopOnError) {
          rolledBack = await this.rollback(transaction, completedMutations, rollbackOnError);
          break;
        }
        continue;
      }
      if (tool.batchable === false) {
        const error = `batch_call[${index}] tool cannot run inside a transaction: ${toolName}`;
        rows.push({ index, tool: toolName, ok: false, error });
        summaries.push(`${index + 1}. ${toolName}: ERROR ${error}`);
        if (stopOnError) {
          rolledBack = await this.rollback(transaction, completedMutations, rollbackOnError);
          break;
        }
        continue;
      }

      try {
        const args = toRecord(normalizeArgAliases(resolveRefs(call.args ?? {}, refs)));
        const missing = missingRequired(tool.parameters, args);
        if (missing.length > 0) throw new Error(`Missing required args for ${toolName}: ${missing.join(", ")}.`);
        const result = await this.executeWithReadback(tool, args, requireReadback);
        const ref = pickRefValue(result);
        if (typeof call.id === "string" && call.id.trim() && ref !== undefined) refs[call.id] = ref;
        if (tool.mutates) completedMutations.push({ tool, args, result });
        const text = result.content[0]?.text ?? "ok";
        summaries.push(`${index + 1}. ${toolName}: ${truncate(text, maxText)}`);
        rows.push({ index, tool: toolName, id: call.id, ok: true, ref, ...(includeDetails ? { details: result.details } : {}) });
      } catch (cause) {
        const error = cause instanceof Error ? cause.message : String(cause);
        summaries.push(`${index + 1}. ${toolName}: ERROR ${truncate(error, 400)}`);
        rows.push({ index, tool: toolName, id: call.id, ok: false, error });
        if (stopOnError) {
          rolledBack = await this.rollback(transaction, completedMutations, rollbackOnError);
          break;
        }
      }
    }

    const failures = rows.filter((row) => row.ok === false);
    if (transaction && failures.length === 0) await transaction.commit();
    return {
      content: [{ type: "text", text: summaries.join("\n") }],
      details: {
        count: rows.length,
        ok: failures.length === 0,
        rolled_back: rolledBack,
        mutation_count: completedMutations.length,
        ...(Object.keys(refs).length > 0 ? { refs } : {}),
        ...(failures.length > 0 ? { failures } : {}),
        ...(includeDetails ? { calls: rows } : {}),
      },
    };
  }

  private async executeWithReadback(tool: NestedEditorTool, args: Record<string, unknown>, requireReadback: boolean): Promise<NestedToolResult> {
    if (tool.mutates && requireReadback && !tool.readback) {
      throw new Error(`Mutation tool ${tool.name} is missing mandatory readback.`);
    }
    const result = await tool.execute(args);
    if (!tool.mutates) return result;
    if (!tool.readback) return result;
    try {
      const readback = await tool.readback(args, result);
      return { ...result, details: { ...result.details, readback_verified: true, readback } };
    } catch (cause) {
      if (tool.rollback) await tool.rollback(args, result);
      throw cause;
    }
  }

  private async rollback(
    transaction: Awaited<ReturnType<BatchTransactionFactory["begin"]>> | null,
    mutations: Array<{ tool: NestedEditorTool; args: Record<string, unknown>; result: NestedToolResult }>,
    enabled: boolean,
  ): Promise<boolean> {
    if (!enabled) return false;
    if (transaction) {
      await transaction.rollback();
      return true;
    }
    if (mutations.length === 0) return false;
    for (const mutation of [...mutations].reverse()) {
      if (!mutation.tool.rollback) throw new Error(`Cannot rollback ${mutation.tool.name}; no transaction or rollback handler is available.`);
      await mutation.tool.rollback(mutation.args, mutation.result);
    }
    return true;
  }
}

function batchCallSchema(): Record<string, unknown> {
  return {
    type: "object",
    required: ["calls"],
    properties: {
      calls: { type: "array", items: { type: "object", required: ["tool"], properties: { tool: { type: "string" }, args: { type: "object" }, id: { type: "string" } } } },
      stop_on_error: { type: "boolean" },
      rollback_on_error: { type: "boolean" },
      include_call_details: { type: "boolean" },
      require_readback: { type: "boolean" },
      max_text_chars: { type: "integer" },
    },
  };
}

function normalizeArgAliases(params: unknown): unknown {
  if (!isRecord(params)) return params;
  const aliases: Record<string, string> = {
    blockName: "block_name", clientId: "client_id", controlKey: "control_key", controlNames: "control_names",
    elementId: "element_id", elementName: "element_name", elementType: "element_type", includeResponsive: "include_responsive",
    includeSchema: "include_schema", includeText: "include_text", innerBlocks: "inner_blocks", parentId: "parent_id",
    targetParentClientId: "target_parent_client_id", targetParentId: "target_parent_id", widgetType: "widget_type",
  };
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(params)) out[aliases[key] ?? key] ??= value;
  return out;
}

function missingRequired(parameters: unknown, args: Record<string, unknown>): string[] {
  const schema = isRecord(parameters) ? parameters : {};
  const required = Array.isArray(schema.required) ? schema.required.filter((key): key is string => typeof key === "string") : [];
  return required.filter((key) => args[key] === undefined || args[key] === null || args[key] === "");
}

function resolveRefs(value: unknown, refs: Record<string, unknown>): unknown {
  if (typeof value === "string" && value.startsWith("$")) {
    const ref = value.slice(1);
    if (!ref || !Object.hasOwn(refs, ref)) throw new Error(`Unknown batch reference: ${value}`);
    return refs[ref];
  }
  if (Array.isArray(value)) return value.map((item) => resolveRefs(item, refs));
  if (isRecord(value)) return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, resolveRefs(item, refs)]));
  return value;
}

function pickRefValue(result: NestedToolResult): unknown {
  for (const key of ["id", "element_id", "block_id", "client_id", "clientId", "widget_id"]) {
    if (result.details?.[key] !== undefined) return result.details[key];
  }
  return undefined;
}

function firstString(...values: unknown[]): string {
  return values.find((value): value is string => typeof value === "string" && value.trim() !== "") ?? "";
}
function isRecord(value: unknown): value is Record<string, unknown> { return Boolean(value && typeof value === "object" && !Array.isArray(value)); }
function toRecord(value: unknown): Record<string, unknown> { return isRecord(value) ? value : {}; }
function truncate(value: string, max: number): string { return value.length <= max ? value : `${value.slice(0, max - 3)}...`; }
function clamp(value: unknown, min: number, max: number, fallback: number): number {
  return typeof value === "number" && Number.isFinite(value) ? Math.max(min, Math.min(Math.floor(value), max)) : fallback;
}
