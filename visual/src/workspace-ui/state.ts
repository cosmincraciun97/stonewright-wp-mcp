// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The workspace state machine.
 *
 * A visual edit walks one path: boot, connect to an editor, read the page,
 * preview what would change, ask, apply, verify. Every state can fail, and
 * exactly one state is allowed to dispatch a write. Keeping that rule in a
 * declared transition table rather than in scattered `if` statements is what
 * makes "no write before confirmation" checkable instead of hopeful.
 */

export type WorkspaceState =
  | "booting"
  | "connected"
  | "reading"
  | "previewing"
  | "awaiting_confirmation"
  | "applying"
  | "verifying"
  | "complete"
  | "failed";

/** The only state in which the controller may call a mutating editor tool. */
const WRITE_STATE: WorkspaceState = "applying";

export const WORKSPACE_TRANSITIONS: Record<WorkspaceState, readonly WorkspaceState[]> = {
  booting: ["connected", "failed"],
  connected: ["reading", "failed"],
  reading: ["previewing", "connected", "failed"],
  previewing: ["awaiting_confirmation", "connected", "failed"],
  awaiting_confirmation: ["applying", "connected", "failed"],
  applying: ["verifying", "failed"],
  verifying: ["complete", "failed"],
  complete: ["connected"],
  failed: ["booting", "connected"],
};

export function isWriteState(state: WorkspaceState): boolean {
  return state === WRITE_STATE;
}

export class WorkspaceStateMachine {
  private current: WorkspaceState;
  private readonly seen: WorkspaceState[];

  constructor(initial: WorkspaceState = "booting") {
    this.current = initial;
    this.seen = [initial];
  }

  get state(): WorkspaceState {
    return this.current;
  }

  get history(): readonly WorkspaceState[] {
    return this.seen.slice();
  }

  can(next: WorkspaceState): boolean {
    return WORKSPACE_TRANSITIONS[this.current].includes(next);
  }

  to(next: WorkspaceState): void {
    if (!this.can(next)) {
      throw new Error(`Workspace cannot move from ${this.current} to ${next}.`);
    }
    this.current = next;
    this.seen.push(next);
  }

  reset(): void {
    this.current = "booting";
    this.seen.length = 0;
    this.seen.push("booting");
  }
}
