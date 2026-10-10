// SPDX-License-Identifier: GPL-2.0-or-later
import { describe, expect, it } from "vitest";
import { WORKSPACE_TRANSITIONS, WorkspaceStateMachine, isWriteState, type WorkspaceState } from "../src/workspace-ui/state.js";

const STATES = Object.keys(WORKSPACE_TRANSITIONS) as WorkspaceState[];
const LADDER: WorkspaceState[] = ["connected", "reading", "previewing", "awaiting_confirmation", "applying", "verifying", "complete"];

describe("workspace state machine", () => {
  it("starts booting and records every accepted step of the write ladder", () => {
    const machine = new WorkspaceStateMachine();
    for (const next of LADDER) machine.to(next);
    expect(machine.state).toBe("complete");
    expect(machine.history).toEqual(["booting", ...LADDER]);
  });

  it("refuses an undeclared move and stays where it was", () => {
    const machine = new WorkspaceStateMachine("previewing");
    expect(machine.can("applying")).toBe(false);
    expect(() => machine.to("applying")).toThrow("Workspace cannot move from previewing to applying.");
    expect(machine.state).toBe("previewing");
    expect(machine.history).toEqual(["previewing"]);
  });

  it("enters the single write state only from an awaiting confirmation", () => {
    expect(STATES.filter(isWriteState)).toEqual(["applying"]);
    expect(STATES.filter((state) => WORKSPACE_TRANSITIONS[state].includes("applying"))).toEqual(["awaiting_confirmation"]);
  });

  it("leaves the write state only through verification or failure", () => {
    expect([...WORKSPACE_TRANSITIONS.applying].sort()).toEqual(["failed", "verifying"]);
    expect([...WORKSPACE_TRANSITIONS.verifying].sort()).toEqual(["complete", "failed"]);
  });

  it("lets every step before the write be abandoned back to connected or fail", () => {
    for (const state of ["reading", "previewing", "awaiting_confirmation"] as const) {
      expect(WORKSPACE_TRANSITIONS[state]).toEqual(expect.arrayContaining(["connected", "failed"]));
    }
  });

  it("starts a new run from a completed or failed one", () => {
    expect(new WorkspaceStateMachine("complete").can("connected")).toBe(true);
    expect(new WorkspaceStateMachine("failed").can("booting")).toBe(true);
    expect(new WorkspaceStateMachine("failed").can("connected")).toBe(true);
  });

  it("hands out history as a copy and resets to a fresh boot", () => {
    const machine = new WorkspaceStateMachine();
    machine.to("connected");
    (machine.history as WorkspaceState[]).push("complete");
    expect(machine.history).toEqual(["booting", "connected"]);
    machine.reset();
    expect(machine.state).toBe("booting");
    expect(machine.history).toEqual(["booting"]);
  });
});
