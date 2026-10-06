// SPDX-License-Identifier: AGPL-3.0-or-later
import type { NestedEditorTool, NestedToolResult, BatchTransactionFactory } from "../types.js";
import { canonical, deepFreeze, digest, object, WorkspaceFailure } from "../session/protocol.js";
import { consumeApplyingPermit, type ApplyingPermit } from "../session/applying-permit.js";
import { checkSchema, summarizeSchema, validateArgs, type CheckedSchema } from "./schema-guard.js";
import { compileBatch, resolveCallArgs, type ResultContract } from "./reference-plan.js";
export class DeclaredToolSet {
  private readonly tools = new Map<string, { tool: NestedEditorTool; schema: CheckedSchema }>();
  constructor(tools: NestedEditorTool[], private readonly transactionFactory?: BatchTransactionFactory, private readonly contracts: Record<string, ResultContract> = {}, private readonly readState?: () => Promise<unknown>) {
    this.contracts = deepFreeze(JSON.parse(canonical(contracts)));
    for (const contract of Object.values(this.contracts)) for (const field of Object.values(contract.publicResultFields)) checkSchema(field);
    if (transactionFactory) this.transactionFactory = Object.freeze({ begin: transactionFactory.begin.bind(transactionFactory) });
    if (tools.length > 128) throw new WorkspaceFailure("workspace_catalog_limit", "Too many tool declarations.");
    for (const tool of tools) {
      if (!/^[\w-]{1,64}$/.test(tool.name) || tool.name === "batch_call" || this.tools.has(tool.name)) throw new WorkspaceFailure("workspace_tool_duplicate", "Tool names must be unique declared names.");
      if (!tool.parameters || typeof tool.execute !== "function" || tool.mutates && typeof tool.readback !== "function") throw new WorkspaceFailure("workspace_tool_invalid", "Tools require a schema and mutations require readback.");
      const checked = checkSchema(tool.parameters);
      this.tools.set(tool.name, { tool: Object.freeze({ ...tool, parameters: deepFreeze(JSON.parse(checked.canonical)) }), schema: deepFreeze(checked) });
    }
  }
  definitions(): Array<Record<string, unknown>> { return [...this.tools.values()].map(({ tool, schema }) => ({ name: tool.name, ...(tool.label === undefined ? {} : { label: tool.label }), ...(tool.description === undefined ? {} : { description: tool.description }), parameters: summarizeSchema(schema), mutates: tool.mutates === true, batchable: tool.batchable !== false })); }
  async call(name: string, args: Record<string, unknown> = {}): Promise<NestedToolResult> {
    if (name === "batch_call") {
      const calls = this.preflight(args);
      if (calls.some((call) => this.tools.get(call.tool)?.tool.mutates)) throw new WorkspaceFailure("workspace_applying_required", "A mutating batch requires approved application.");
      return this.batch(args);
    }
    const entry = this.tools.get(name); if (!entry) throw new WorkspaceFailure("workspace_tool_unknown", "Tool is not declared.");
    if (entry.tool.mutates) throw new WorkspaceFailure("workspace_applying_required", "A mutation requires approved application.");
    return this.execute(name, args);
  }
  validate(name: string, args: Record<string, unknown>): void { if (name === "batch_call") { this.preflight(args); return; } const entry = this.tools.get(name); if (!entry) throw new WorkspaceFailure("workspace_tool_unknown", "Tool is not declared."); validateArgs(entry.schema, args); }
  async apply(name: string, args: Record<string, unknown>, permit: ApplyingPermit): Promise<NestedToolResult> { consumeApplyingPermit(permit); this.validate(name, args); return name === "batch_call" ? this.batch(args) : this.execute(name, args); }
  private async execute(name: string, args: Record<string, unknown>): Promise<NestedToolResult> {
    const entry = this.tools.get(name)!;
    validateArgs(entry.schema, args);
    const result = await entry.tool.execute(args);
    if (entry.tool.mutates) await entry.tool.readback!(args, result);
    return result;
  }
  private preflight(input: Record<string, unknown>) {
    const calls = compileBatch(input, (name) => { const entry = this.tools.get(name); return !!entry && entry.tool.batchable !== false && !["undo", "redo", "save"].includes(name) && (!entry.tool.mutates || !!this.readState && (!!this.transactionFactory || typeof entry.tool.rollback === "function")); });
    const placeholders = new Map<string, { value: Record<string, unknown>; contract: ResultContract }>();
    const witness = (schema: Record<string, unknown>): unknown => { if (schema.const !== undefined) return schema.const; if (Array.isArray(schema.enum)) return schema.enum[0]; const type = Array.isArray(schema.type) ? schema.type[0] : schema.type; if (type === "string") return "x".repeat(Math.max(1, Number(schema.minLength ?? 1))); if (type === "integer" || type === "number") return Number(schema.minimum ?? 0); if (type === "boolean") return false; if (type === "array") return []; if (type === "null") return null; return {}; };
    for (const call of calls) {
      validateArgs(this.tools.get(call.tool)!.schema, resolveCallArgs(call.args, placeholders));
      if (call.alias) { const contract = this.contracts[call.tool]; if (!contract) throw new WorkspaceFailure("workspace_reference_invalid", "Alias source has no declared result contract."); placeholders.set(call.alias, { value: Object.fromEntries(Object.entries(contract.publicResultFields).map(([field, schema]) => [field, witness(schema)])), contract }); }
    }
    return calls;
  }
  private async batch(input: Record<string, unknown>): Promise<NestedToolResult> {
    const calls = this.preflight(input);
    const aliases = new Map<string, { value: Record<string, unknown>; contract: ResultContract }>();
    for (const call of calls) if (call.alias && !this.contracts[call.tool]) throw new WorkspaceFailure("workspace_reference_invalid", "Alias source has no declared result contract.");
    const beforeHash = this.readState ? await digest(await this.readState()) : null;
    const transaction = await this.transactionFactory?.begin();
    const attempted: Array<{ tool: NestedEditorTool; args: Record<string, unknown>; result: NestedToolResult }> = [];
    const receipts: Array<Record<string, unknown>> = [];
    try {
      for (const call of calls) {
        const entry = this.tools.get(call.tool)!; const args = object(resolveCallArgs(call.args, aliases)); validateArgs(entry.schema, args);
        const attempt = { tool: entry.tool, args, result: { content: [] } as NestedToolResult }; if (entry.tool.mutates) attempted.push(attempt);
        attempt.result = await entry.tool.execute(args);
        if (entry.tool.mutates) await entry.tool.readback!(args, attempt.result);
        if (call.alias) {
          const contract = this.contracts[call.tool]; const raw = attempt.result.details ?? {};
          const projected: Record<string, unknown> = {};
          for (const [field, schema] of Object.entries(contract.publicResultFields)) { if (!Object.hasOwn(raw, field)) throw new WorkspaceFailure("workspace_result_invalid", "Required public result field is missing."); validateArgs(checkSchema(schema), raw[field]); projected[field] = raw[field]; }
          aliases.set(call.alias, { value: projected, contract });
        }
        receipts.push({ tool: call.tool, verified: entry.tool.mutates === true, alias: call.alias ?? null });
      }
      await transaction?.commit(); return { content: [{ type: "text", text: `${calls.length} calls completed.` }], details: { ok: true, calls: receipts, verification_status: "verified", rollback: "not_needed" } };
    } catch (cause) {
      let rollback = attempted.length ? "unavailable" : "not_needed";
      try { if (transaction) await transaction.rollback(); else for (const attempt of attempted.reverse()) await attempt.tool.rollback!(attempt.args, attempt.result); if (attempted.length) rollback = beforeHash !== null && this.readState && await digest(await this.readState()) === beforeHash ? "restored" : "failed"; } catch (recovery) { rollback = recovery instanceof WorkspaceFailure && recovery.code.includes("fenced") ? "fenced" : "failed"; }
      throw new WorkspaceFailure("workspace_batch_failed", "Batch failed; inspect recovery before retrying.", { completed: receipts.length, rollback, cause: cause instanceof WorkspaceFailure ? cause.code : "workspace_tool_failed", retry: rollback === "restored" ? "fresh_read_required" : "manual_recovery" });
    }
  }
}
