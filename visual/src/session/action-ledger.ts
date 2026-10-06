// SPDX-License-Identifier: GPL-2.0-or-later
import type { ConfirmationAction, WorkspaceActionView } from "../types.js";
import { canonical, deepFreeze, WorkspaceFailure } from "./protocol.js";
import { issueApplyingPermit, type ApplyingPermit } from "./applying-permit.js";
export interface ActionProposal { action: ConfirmationAction; title: string; args: Record<string, unknown>; session: string; execute: (args: Record<string, unknown>, permit: ApplyingPermit) => Promise<unknown>; fresh: () => Promise<boolean>; current?: () => Promise<boolean>; details?: Record<string, unknown>; }
export interface DecisionAuthority { recordedDecision(proposal: Readonly<ActionProposal>, decision: "allow_once" | "deny"): Promise<boolean>; }
export class ActionLedger {
  private readonly actions = new Map<string, { view: WorkspaceActionView; proposal: ActionProposal; expires: number; promise?: Promise<WorkspaceActionView> }>();
  private readonly replays = new Map<string, { args: string; id: string }>();
  private readonly now: () => number;
  private readonly authority: DecisionAuthority;
  constructor(options: { clock?: { now(): number }; decisionAuthority?: DecisionAuthority } = {}) { this.now = options.clock ? options.clock.now.bind(options.clock) : Date.now; this.authority = options.decisionAuthority ?? { recordedDecision: async () => false }; }
  propose(input: ActionProposal): WorkspaceActionView {
    const text = canonical(input.args); const key = typeof input.args.idempotency_key === "string" ? `${input.session}:${input.action}:${input.args.idempotency_key}` : null;
    const previous = key ? this.replays.get(key) : undefined;
    if (previous) { if (previous.args !== text) throw new WorkspaceFailure("workspace_idempotency_conflict", "Idempotency key was used with different arguments."); return this.status(previous.id); }
    if (this.actions.size >= 256) throw new WorkspaceFailure("workspace_action_limit", "Session action limit reached; reconnect after checking outstanding outcomes.");
    const actionId = globalThis.crypto.randomUUID();
    const proposal = deepFreeze({ ...input, args: JSON.parse(text), ...(input.details ? { details: JSON.parse(canonical(input.details)) } : {}) });
    const view: WorkspaceActionView = { actionId, action: input.action, title: input.title, status: "waiting_for_confirmation", terminal: false, requiresUserConfirmation: true, result: proposal.details ?? null, error: null };
    this.actions.set(actionId, { view, proposal, expires: this.now() + 300000 }); if (key) this.replays.set(key, { args: text, id: actionId }); return this.status(actionId);
  }
  pending(): WorkspaceActionView[] { return [...this.actions.values()].filter(({ view }) => !view.terminal).map(({ view }) => structuredClone(view)); }
  status(id: string): WorkspaceActionView { const entry = this.actions.get(id); if (!entry) throw new WorkspaceFailure("workspace_action_unknown", "Action is not part of this session."); return structuredClone(entry.view); }
  async decide(id: string, decision: "allow_once" | "deny"): Promise<WorkspaceActionView> {
    const entry = this.actions.get(id); if (!entry) throw new WorkspaceFailure("workspace_action_unknown", "Unknown action.");
    if (!["allow_once", "deny"].includes(decision)) throw new WorkspaceFailure("workspace_decision_invalid", "Only one-action approval or denial is accepted.");
    if (entry.promise) return entry.promise;
    if (entry.view.terminal) return this.status(id);
    entry.promise = (async () => {
      try {
        if (decision === "deny") { entry.view.status = "denied"; entry.view.terminal = true; entry.view.requiresUserConfirmation = false; return this.status(id); }
        if (this.now() > entry.expires || !await entry.proposal.fresh()) throw new WorkspaceFailure("workspace_proposal_stale", "Proposal expired or its target changed; read and preview again.");
        if (!await this.authority.recordedDecision(entry.proposal, decision)) throw new WorkspaceFailure("workspace_confirmation_unproven", "The host has not recorded an explicit user approval.");
        if (this.now() > entry.expires || !await entry.proposal.fresh()) throw new WorkspaceFailure("workspace_proposal_stale", "Target changed while approval was pending.");
        entry.view.status = "running";
        const result = await entry.proposal.execute(JSON.parse(canonical(entry.proposal.args)), issueApplyingPermit(id));
        if (entry.proposal.current && !await entry.proposal.current()) throw new WorkspaceFailure("workspace_session_changed", "Late action completion belongs to a closed or replaced session.", { verification_status: "unverified" });
        entry.view.result = result; entry.view.status = "succeeded";
      } catch (cause) { entry.view.status = "failed"; entry.view.error = cause instanceof Error ? cause.message : "Action failed."; entry.view.result = { failure: { code: cause instanceof WorkspaceFailure ? cause.code : "workspace_action_failed", retryable: false, ...(cause instanceof WorkspaceFailure ? cause.details : {}) } }; }
      finally { entry.view.terminal = true; entry.view.requiresUserConfirmation = false; }
      return this.status(id);
    })(); return entry.promise;
  }
  async wait(id: string, timeoutMs = 10000): Promise<WorkspaceActionView> {
    if (!Number.isInteger(timeoutMs) || timeoutMs < 0 || timeoutMs > 60000) throw new WorkspaceFailure("workspace_wait_invalid", "Wait must be between 0 and 60000 ms.");
    const entry = this.actions.get(id); if (!entry) return this.status(id);
    if (entry.promise && !entry.view.terminal && timeoutMs) {
      let timer: ReturnType<typeof setTimeout> | undefined;
      try { await Promise.race([entry.promise, new Promise<void>((resolve) => { timer = setTimeout(resolve, timeoutMs); })]); } finally { if (timer) clearTimeout(timer); }
    } return this.status(id);
  }
}
