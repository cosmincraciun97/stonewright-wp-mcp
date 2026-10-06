// SPDX-License-Identifier: GPL-2.0-or-later
import type { BackendTransport } from "../types.js";
import { ActionLedger, type DecisionAuthority } from "./action-ledger.js";
import { BackendPolicy, type BackendCapability } from "./backend-policy.js";
import type { WorkspaceHostPort } from "./host-port.js";
import { object, REQUEST_METHODS, WorkspaceFailure } from "./protocol.js";
export class WorkspaceRouter {
  readonly actions: ActionLedger;
  private readonly backend?: BackendPolicy;
  private readonly reads = new Map<string, { generation: number; tree: string; schema: string; direction: string }>();
  constructor(private readonly options: { host?: WorkspaceHostPort; backend?: BackendTransport; backendPolicy?: readonly BackendCapability[]; decisionAuthority?: DecisionAuthority; clock?: { now(): number } } = {}) { this.actions = new ActionLedger(options); if (options.backend) this.backend = new BackendPolicy(options.backend, options.backendPolicy); }
  async dispatch(method: string, input: unknown = {}): Promise<unknown> {
    if (!(REQUEST_METHODS as readonly string[]).includes(method)) throw new WorkspaceFailure("workspace_method_unknown", "Only declared workspace methods are accepted.");
    const params = object(input); const allowed: Record<string, string[]> = { workspace_status: [], workspace_list_pages: [], workspace_pending_confirmations: [], workspace_open_page: ["url", "editor_kind"], workspace_close_page: ["page_id"], workspace_focus_page: ["page_id"], workspace_reload_page: ["page_id"], workspace_list_page_tools: ["page_id"], workspace_discover_backend_tools: ["category", "search"], workspace_call_backend_tool: ["tool", "args"], workspace_call_page_tool: ["page_id", "tool", "args"], workspace_decide_confirmation: ["action_id", "decision"], workspace_get_action_status: ["action_id"], workspace_wait_action: ["action_id", "timeout_ms"] };
    if (Object.keys(params).some((key) => !allowed[method].includes(key))) throw new WorkspaceFailure("workspace_args_invalid", "Unknown method argument.");
    const text = (key: string): string => { const value = params[key]; if (typeof value !== "string" || !value || value.length > 2048) throw new WorkspaceFailure("workspace_args_invalid", `A bounded ${key} is required.`); return value; };
    if (method === "workspace_pending_confirmations") return this.actions.pending();
    if (method === "workspace_get_action_status") return this.actions.status(text("action_id"));
    if (method === "workspace_wait_action") { if (params.timeout_ms !== undefined && typeof params.timeout_ms !== "number") throw new WorkspaceFailure("workspace_args_invalid", "Wait duration must be a number."); return this.actions.wait(text("action_id"), params.timeout_ms as number | undefined); }
    if (method === "workspace_decide_confirmation") return this.actions.decide(text("action_id"), text("decision") as "allow_once" | "deny");
    if (method === "workspace_status") return { connected: !!this.options.host, backend_available: !!this.backend, pending_actions: this.actions.pending().length };
    if (method.startsWith("workspace_discover_backend") || method === "workspace_call_backend_tool") {
      if (!this.backend) throw new WorkspaceFailure("workspace_backend_unavailable", "No Visual-safe backend host port is configured.");
      return method === "workspace_discover_backend_tools" ? this.backend.discover(params) : this.backend.read(text("tool"), object(params.args ?? {}));
    }
    const host = this.options.host; if (!host) throw new WorkspaceFailure("workspace_host_unavailable", "No supported workspace host is configured.");
    if (method === "workspace_list_pages") return host.listPages();
    if (method === "workspace_open_page") return host.openPage({ url: text("url"), ...(params.editor_kind === undefined ? {} : { editorKind: text("editor_kind") }) });
    const pageId = text("page_id");
    if (method === "workspace_close_page" || method === "workspace_reload_page") { this.reads.delete(pageId); return method === "workspace_close_page" ? host.closePage(pageId) : host.reloadPage(pageId); }
    if (method === "workspace_focus_page") return host.focusPage(pageId);
    const binding = await host.resolveEditor(pageId);
    if (binding.closed()) throw new WorkspaceFailure("workspace_session_closed", "Editor session is closed.");
    if (method === "workspace_list_page_tools") return binding.tools.definitions();
    const name = text("tool"); const args = object(params.args ?? {}); const definition = binding.tools.definitions().find((tool) => tool.name === name);
    if (!definition && name !== "batch_call") throw new WorkspaceFailure("workspace_tool_unknown", "Tool is not declared.");
    if (name !== "batch_call" && !definition?.mutates) {
      const result = await binding.tools.call(name, args);
      if (name === "get_page_structure") this.reads.set(pageId, { generation: binding.generation, tree: await binding.treeHash(), schema: await binding.schemaHash(), direction: await binding.directionHash() });
      return result;
    }
    const read = this.reads.get(pageId); if (!read) throw new WorkspaceFailure("workspace_read_required", "Read the page before previewing a mutation.");
    binding.tools.validate(name, args);
    const current = async (): Promise<boolean> => { const live = await host.resolveEditor(pageId); return !live.closed() && live.id === binding.id && live.generation === binding.generation; };
    return this.actions.propose({ action: `page_tool:${name}`, title: String(definition?.label ?? name), args, session: `${binding.id}:${binding.generation}`, details: { target: pageId, breakpoint: args.breakpoint ?? "declared", before_hash: read.tree, planned_after: args, direction_hash: read.direction, exact_args: args }, current, fresh: async () => await current() && read.generation === binding.generation && read.tree === await binding.treeHash() && read.schema === await binding.schemaHash() && read.direction === await binding.directionHash(), execute: async (approved, permit) => {
      const result = await binding.tools.apply(name, approved, permit); const evidence = await binding.verify?.();
      const required = binding.requiredEvidence;
      if (!required?.length || !evidence?.length || evidence.some((row) => typeof row.rule !== "string" || !row.rule || typeof row.evidenceType !== "string" || !row.evidenceType || row.checked !== true || row.status !== "pass") || new Set(evidence.map((row) => row.rule)).size !== evidence.length || required.some((type) => !evidence.some((row) => row.evidenceType === type))) throw new WorkspaceFailure("workspace_verification_unavailable", "The write landed but complete quality evidence is unavailable.", { verification_status: "unverified", write_landed: true });
      return result;
    } });
  }
}
