// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from "vitest";
import {
  WORKSPACE_TRANSITIONS,
  WorkspaceStateMachine,
  isWriteState,
  type WorkspaceState,
} from "../src/workspace-ui/state.js";
import { describeAdapterStatus, resolveEditorAdapter, type AdapterCandidate } from "../src/workspace-ui/adapter-status.js";
import { describeEvidencePanel, summarizeEvidence } from "../src/workspace-ui/evidence-panel.js";
import { describeConfirmation } from "../src/workspace-ui/confirmation-panel.js";
import { createWorkspaceController } from "../src/workspace-ui/workspace.js";

/**
 * The workspace controller owns state; the panels own view models. Nothing in
 * this file touches a document, so the state machine stays testable in Node and
 * the DOM assembly is left to the admin browser tests.
 */

interface RegistryCall {
  tool: string;
  args: Record<string, unknown>;
}

function fakeAdapter(calls: RegistryCall[], failOn: string | null = null) {
  return {
    registry() {
      return {
        definitions: () => [
          { name: "get_page_structure", mutates: false },
          { name: "update_settings", mutates: true },
        ],
        async call(tool: string, args: Record<string, unknown> = {}) {
          calls.push({ tool, args });
          if (tool === failOn) throw new Error(`${tool} exploded`);
          return { content: [{ type: "text", text: `${tool} ok` }], details: { tool } };
        },
      };
    },
  };
}

function candidate(kind: AdapterCandidate["kind"], over: Partial<AdapterCandidate> = {}): AdapterCandidate {
  return {
    kind,
    detect: () => true,
    create: () => fakeAdapter([]),
    ...over,
  };
}

function controllerWith(calls: RegistryCall[], over: Record<string, unknown> = {}) {
  return createWorkspaceController({
    restBase: "https://example.test/wp-json/stonewright/v1",
    nonce: "nonce-1",
    postId: 41,
    direction: { id: "quarry", title: "Quarry", revision: 3, hash: "abc123" },
    adapters: [candidate("elementor-v3", { create: () => fakeAdapter(calls) })],
    ...over,
  });
}

const OPERATIONS = [
  { tool: "update_settings", target: "hero-title", summary: "Set desktop font size to 48px", before: "32px", after: "48px", breakpoint: "desktop" },
];

async function walkToConfirmation(controller: ReturnType<typeof controllerWith>) {
  await controller.connect();
  await controller.read();
  controller.preview(OPERATIONS);
  controller.requestConfirmation();
}

/* -------------------------------------------------------------------------- */

describe("workspace state machine", () => {
  it("starts booting and walks the full happy path", () => {
    const machine = new WorkspaceStateMachine();
    expect(machine.state).toBe("booting");

    const path: WorkspaceState[] = [
      "connected",
      "reading",
      "previewing",
      "awaiting_confirmation",
      "applying",
      "verifying",
      "complete",
    ];
    for (const next of path) {
      machine.to(next);
    }

    expect(machine.state).toBe("complete");
    expect(machine.history).toEqual(["booting", ...path]);
  });

  it("refuses a transition that is not declared and keeps the current state", () => {
    const machine = new WorkspaceStateMachine();
    expect(machine.can("applying")).toBe(false);
    expect(() => machine.to("applying")).toThrow(/booting/);
    expect(machine.state).toBe("booting");
  });

  it("lets every state fail and never lets a failed state jump straight into a write", () => {
    for (const state of Object.keys(WORKSPACE_TRANSITIONS) as WorkspaceState[]) {
      if (state === "failed" || state === "complete") {
        continue;
      }
      expect(WORKSPACE_TRANSITIONS[state]).toContain("failed");
    }

    expect(WORKSPACE_TRANSITIONS.failed).not.toContain("applying");
    expect(WORKSPACE_TRANSITIONS.complete).not.toContain("applying");
  });

  it("names exactly one write state", () => {
    const writeStates = (Object.keys(WORKSPACE_TRANSITIONS) as WorkspaceState[]).filter(isWriteState);
    expect(writeStates).toEqual(["applying"]);
  });
});

describe("adapter resolution", () => {
  it("skips an editor that is not present and reports the one that is", async () => {
    const resolution = await resolveEditorAdapter([
      candidate("elementor-v4", { detect: () => false }),
      candidate("elementor-v3"),
    ]);

    expect(resolution.kind).toBe("elementor-v3");
    expect(resolution.adapter).not.toBeNull();
    expect(resolution.error).toBeNull();
    expect(resolution.attempted).toEqual(["elementor-v4", "elementor-v3"]);
  });

  it("stops at an adapter that is present but broken instead of falling through", async () => {
    const later: string[] = [];
    const resolution = await resolveEditorAdapter([
      candidate("elementor-v3", {
        create: () => {
          throw new Error("Elementor runtime is half loaded");
        },
      }),
      candidate("gutenberg", {
        detect: () => {
          later.push("gutenberg");
          return true;
        },
      }),
    ]);

    expect(resolution.kind).toBeNull();
    expect(resolution.adapter).toBeNull();
    expect(resolution.error).toContain("half loaded");
    expect(resolution.attempted).toEqual(["elementor-v3"]);
    expect(later).toEqual([]);
  });

  it("treats a detection that throws as a failure of that editor, not as absence", async () => {
    const resolution = await resolveEditorAdapter([
      candidate("gutenberg", {
        detect: () => {
          throw new Error("editor store is missing");
        },
      }),
      candidate("elementor-v3"),
    ]);

    expect(resolution.kind).toBeNull();
    expect(resolution.error).toContain("editor store is missing");
    expect(resolution.attempted).toEqual(["gutenberg"]);
  });

  it("reports no editor at all rather than inventing one", async () => {
    const resolution = await resolveEditorAdapter([candidate("elementor-v3", { detect: () => false })]);

    expect(resolution.kind).toBeNull();
    expect(resolution.error).toContain("No supported editor");
    expect(describeAdapterStatus(resolution, "failed").tone).toBe("error");
  });

  it("describes a healthy adapter without claiming more than it knows", async () => {
    const resolution = await resolveEditorAdapter([candidate("gutenberg")]);
    const model = describeAdapterStatus(resolution, "connected");

    expect(model.tone).toBe("ok");
    expect(model.kind).toBe("gutenberg");
    expect(model.label.toLowerCase()).toContain("gutenberg");
  });
});

describe("evidence panel", () => {
  it("counts unchecked rules separately and refuses to call them a pass", () => {
    const summary = summarizeEvidence([
      { label: "Body contrast", status: "pass", viewport: "1440" },
      { label: "Heading scale", status: "not_checked", viewport: "390" },
    ]);

    expect(summary.total).toBe(2);
    expect(summary.pass).toBe(1);
    expect(summary.notChecked).toBe(1);
    expect(summary.verified).toBe(false);
  });

  it("never reports verified when nothing was checked", () => {
    const model = describeEvidencePanel([]);

    expect(model.summary.verified).toBe(false);
    expect(model.notice).toMatch(/nothing/i);
  });

  it("reports verified only when every row passed", () => {
    const model = describeEvidencePanel([
      { label: "Body contrast", status: "pass" },
      { label: "Rhythm", status: "pass" },
    ]);

    expect(model.summary.verified).toBe(true);
    expect(model.notice).toBeNull();
    expect(model.rows).toHaveLength(2);
  });

  it("keeps a failure visible even when later rows pass", () => {
    const model = describeEvidencePanel([
      { label: "Body contrast", status: "fail", measured: "2.9:1", viewport: "390" },
      { label: "Rhythm", status: "pass" },
    ]);

    expect(model.summary.verified).toBe(false);
    expect(model.rows[0].status).toBe("fail");
    expect(model.rows[0].measured).toBe("2.9:1");
  });
});

describe("confirmation panel", () => {
  it("lists what would change before anything runs", () => {
    const model = describeConfirmation({ operations: OPERATIONS, directionLabel: "Quarry rev 3" });

    expect(model.canConfirm).toBe(true);
    expect(model.operations).toHaveLength(1);
    expect(model.operations[0].summary).toContain("48px");
    expect(model.title.toLowerCase()).toContain("apply");
  });

  it("cannot be confirmed with nothing to apply", () => {
    const model = describeConfirmation({ operations: [], directionLabel: "Quarry rev 3" });

    expect(model.canConfirm).toBe(false);
    expect(model.warning).toMatch(/nothing/i);
  });

  it("cannot be confirmed while an operation is blocked", () => {
    const model = describeConfirmation({
      operations: OPERATIONS,
      directionLabel: "Quarry rev 3",
      blocked: ["hero-title: the live schema has no font_size control"],
    });

    expect(model.canConfirm).toBe(false);
    expect(model.blocked).toHaveLength(1);
  });
});

describe("workspace controller", () => {
  it("connects, reads, and exposes the resolved adapter", async () => {
    const calls: RegistryCall[] = [];
    const controller = controllerWith(calls);

    expect(controller.getState()).toBe("booting");
    await controller.connect();
    expect(controller.getState()).toBe("connected");

    await controller.read();
    expect(controller.getState()).toBe("reading");
    expect(calls.map((call) => call.tool)).toEqual(["get_page_structure"]);
    expect(controller.snapshot().adapter.kind).toBe("elementor-v3");
  });

  it("dispatches no write before a preview and an explicit confirmation", async () => {
    const calls: RegistryCall[] = [];
    const controller = controllerWith(calls);

    await controller.connect();
    await controller.read();

    expect(controller.canDispatchWrite()).toBe(false);
    await expect(controller.decide("allow")).rejects.toThrow(/confirmation/i);

    controller.preview(OPERATIONS);
    expect(controller.canDispatchWrite()).toBe(false);
    await expect(controller.decide("allow")).rejects.toThrow(/confirmation/i);

    expect(calls.filter((call) => call.tool === "update_settings")).toHaveLength(0);
  });

  it("cannot preview before it has read the page", async () => {
    const controller = controllerWith([]);
    await controller.connect();

    expect(() => controller.preview(OPERATIONS)).toThrow(/read/i);
  });

  it("applies only after the user allows it, then verifies", async () => {
    const calls: RegistryCall[] = [];
    const controller = controllerWith(calls, {
      verify: async () => [{ label: "Body contrast", status: "pass" as const }],
    });

    await walkToConfirmation(controller);
    expect(controller.getState()).toBe("awaiting_confirmation");
    expect(calls.filter((call) => call.tool === "update_settings")).toHaveLength(0);

    await controller.decide("allow");

    expect(calls.filter((call) => call.tool === "update_settings")).toHaveLength(1);
    expect(controller.getState()).toBe("complete");
    expect(controller.snapshot().evidence.verified).toBe(true);
  });

  it("does not report complete when verification found nothing to check", async () => {
    const controller = controllerWith([], { verify: async () => [] });

    await walkToConfirmation(controller);
    await controller.decide("allow");

    expect(controller.getState()).toBe("failed");
    expect(controller.snapshot().error).toMatch(/verif/i);
  });

  it("denying returns to connected and writes nothing", async () => {
    const calls: RegistryCall[] = [];
    const controller = controllerWith(calls);

    await walkToConfirmation(controller);
    await controller.decide("deny");

    expect(controller.getState()).toBe("connected");
    expect(calls.filter((call) => call.tool === "update_settings")).toHaveLength(0);
    expect(controller.snapshot().confirmation).toBeNull();
  });

  it("keeps the evidence it already has when an adapter call fails", async () => {
    const calls: RegistryCall[] = [];
    const controller = createWorkspaceController({
      restBase: "https://example.test/wp-json/stonewright/v1",
      nonce: "nonce-1",
      postId: 41,
      direction: null,
      adapters: [candidate("elementor-v3", { create: () => fakeAdapter(calls, "update_settings") })],
      verify: async () => [{ label: "Body contrast", status: "pass" as const }],
    });

    await walkToConfirmation(controller);
    controller.recordEvidence([{ label: "Baseline capture", status: "pass" }]);
    await controller.decide("allow");

    expect(controller.getState()).toBe("failed");
    expect(controller.snapshot().error).toContain("exploded");
    expect(controller.snapshot().evidence.total).toBe(1);
    expect(controller.snapshot().adapter.kind).toBe("elementor-v3");
  });

  it("passes the exact tool arguments the operation carries", async () => {
    const calls: RegistryCall[] = [];
    const controller = controllerWith(calls, {
      verify: async () => [{ label: "Body contrast", status: "pass" as const }],
    });

    await controller.connect();
    await controller.read();
    controller.preview([
      {
        tool: "update_settings",
        target: "hero-title",
        summary: "Set desktop font size to 48px",
        after: "48px",
        breakpoint: "desktop",
        args: { element_id: "abc123", idempotency_key: "key-1" },
      },
    ]);
    controller.requestConfirmation();
    await controller.decide("allow");

    const write = calls.find((call) => call.tool === "update_settings");
    expect(write?.args).toEqual({
      target: "hero-title",
      element_id: "abc123",
      idempotency_key: "key-1",
      breakpoint: "desktop",
      value: "48px",
    });
  });

  it("tells subscribers when evidence is recorded outside a transition", async () => {
    const controller = controllerWith([]);
    const seen: number[] = [];
    const stop = controller.subscribe((snapshot) => seen.push(snapshot.evidence.total));

    await controller.connect();
    controller.recordEvidence([{ label: "Stored finding", status: "fail" }]);
    stop();

    expect(seen).toEqual([0, 1]);
    expect(controller.getState()).toBe("connected");
  });

  it("fails to connect when no editor answers, and says so", async () => {
    const controller = controllerWith([], {
      adapters: [candidate("elementor-v3", { detect: () => false })],
    });

    await controller.connect();

    expect(controller.getState()).toBe("failed");
    expect(controller.snapshot().error).toContain("No supported editor");
  });

  it("tells subscribers about every state change once", async () => {
    const seen: WorkspaceState[] = [];
    const controller = controllerWith([], { verify: async () => [{ label: "Rhythm", status: "pass" as const }] });
    const stop = controller.subscribe((snapshot) => seen.push(snapshot.state));

    await walkToConfirmation(controller);
    await controller.decide("allow");
    stop();

    expect(seen).toEqual([
      "connected",
      "reading",
      "previewing",
      "awaiting_confirmation",
      "applying",
      "verifying",
      "complete",
    ]);
  });
});
