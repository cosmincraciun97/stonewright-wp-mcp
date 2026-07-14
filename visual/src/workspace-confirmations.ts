// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Derived from novamira-visual/src/workspace-confirmations.ts
// Source SHA-256: 86c20504f8f5e4b255183e3d6ea876ff3f92953f9d55b0fcfd3cad1b1165d5fd

import type {
  ActionStatus,
  ConfirmationAction,
  ConfirmationDecision,
  ConfirmationRequest,
  WorkspaceActionView,
} from "./types.js";

interface ActionRecord {
  id: string;
  action: ConfirmationAction;
  request: ConfirmationRequest;
  status: ActionStatus;
  run: () => Promise<unknown>;
  result?: unknown;
  error?: string;
}

export class WorkspaceConfirmations {
  private nextId = 1;
  private readonly actions = new Map<string, ActionRecord>();
  private readonly waiters = new Map<string, Set<() => void>>();
  private readonly sessionAllowedActions = new Set<ConfirmationAction>();
  private allowAllTools = false;

  isAllowed(action: ConfirmationAction): boolean {
    return this.allowAllTools || this.sessionAllowedActions.has(action);
  }

  enqueue(action: ConfirmationAction, request: ConfirmationRequest, run: () => Promise<unknown>): WorkspaceActionView | Promise<unknown> {
    if (this.isAllowed(action)) return run();
    const id = `action-${this.nextId++}`;
    const record: ActionRecord = { id, action, request, status: "waiting_for_confirmation", run };
    this.actions.set(id, record);
    return this.view(record);
  }

  pending(): WorkspaceActionView[] {
    return Array.from(this.actions.values())
      .filter((action) => action.status === "waiting_for_confirmation")
      .map((action) => this.view(action));
  }

  get(actionId: string): WorkspaceActionView {
    return this.view(this.requireAction(actionId));
  }

  async decide(actionId: string, decision: ConfirmationDecision): Promise<WorkspaceActionView> {
    const action = this.requireAction(actionId);
    if (action.status !== "waiting_for_confirmation") return this.view(action);
    if (decision === "deny") {
      action.status = "denied";
      action.error = `User denied action: ${action.request.title}`;
      this.notify(action.id);
      return this.view(action);
    }
    if (decision === "allow_session") this.sessionAllowedActions.add(action.action);
    if (decision === "allow_all_tools_session") this.allowAllTools = true;
    await this.start(action);
    return this.view(action);
  }

  async wait(actionId: string, timeoutMs = 60_000): Promise<WorkspaceActionView> {
    const action = this.requireAction(actionId);
    if (isTerminal(action.status) || timeoutMs === 0) return this.view(action);
    return new Promise((resolve) => {
      const finish = (): void => {
        clearTimeout(timer);
        this.waiters.get(actionId)?.delete(check);
        resolve(this.view(this.requireAction(actionId)));
      };
      const check = (): void => { if (isTerminal(this.requireAction(actionId).status)) finish(); };
      const timer = setTimeout(finish, Math.max(0, Math.min(timeoutMs, 60_000)));
      const listeners = this.waiters.get(actionId) ?? new Set<() => void>();
      listeners.add(check);
      this.waiters.set(actionId, listeners);
    });
  }

  disableSessionAllowances(): void {
    this.allowAllTools = false;
    this.sessionAllowedActions.clear();
  }

  private async start(action: ActionRecord): Promise<void> {
    action.status = "running";
    this.notify(action.id);
    try {
      action.result = structuredClone(await action.run());
      action.status = "succeeded";
    } catch (cause) {
      action.error = cause instanceof Error ? cause.message : String(cause);
      action.status = "failed";
    }
    this.notify(action.id);
  }

  private requireAction(actionId: string): ActionRecord {
    const action = this.actions.get(actionId);
    if (!action) throw new Error(`Unknown action: ${actionId}`);
    return action;
  }

  private notify(actionId: string): void {
    for (const waiter of this.waiters.get(actionId) ?? []) waiter();
  }

  private view(action: ActionRecord): WorkspaceActionView {
    return {
      actionId: action.id,
      action: action.action,
      title: action.request.title,
      status: action.status,
      terminal: isTerminal(action.status),
      requiresUserConfirmation: action.status === "waiting_for_confirmation",
      result: action.result ?? null,
      error: action.error ?? null,
    };
  }
}

function isTerminal(status: ActionStatus): boolean {
  return status === "succeeded" || status === "failed" || status === "denied";
}
