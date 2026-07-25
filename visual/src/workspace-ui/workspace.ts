// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  describeAdapterStatus,
  renderAdapterStatus,
  resolveEditorAdapter,
  type AdapterCandidate,
  type AdapterKind,
  type AdapterResolution,
  type AdapterStatusModel,
  type EditorRegistryLike,
} from "./adapter-status.js";
import {
  describeConfirmation,
  renderConfirmationPanel,
  type ConfirmationModel,
  type ProposedOperation,
} from "./confirmation-panel.js";
import {
  describeEvidencePanel,
  renderEvidencePanel,
  summarizeEvidence,
  type EvidenceEntry,
  type EvidenceSummary,
} from "./evidence-panel.js";
import { WorkspaceStateMachine, isWriteState, type WorkspaceState } from "./state.js";

/**
 * The workspace controller.
 *
 * It owns the state machine, the resolved adapter, the evidence it has
 * collected, and the pending confirmation. It does not own markup: the panels
 * turn its snapshot into DOM. Every editor write goes through one private
 * method that refuses to run outside the single write state.
 */

export interface DirectionSummary {
  id: string;
  title: string;
  revision: number;
  hash: string;
}

export interface WorkspaceOptions {
  restBase: string;
  nonce: string;
  postId: number;
  direction?: DirectionSummary | null;
  editorKind?: AdapterKind | "auto";
  adapters: readonly AdapterCandidate[];
  /** Tool the read step calls on the resolved adapter. */
  readTool?: string;
  /** Collects evidence after a write. Injected so the controller stays network-free. */
  verify?: (context: VerifyContext) => Promise<EvidenceEntry[]>;
  /** Network access, injected. Nothing in this module calls fetch directly. */
  request?: (path: string, init?: RequestInit) => Promise<unknown>;
  viewports?: readonly WorkspaceViewport[];
}

export interface VerifyContext {
  postId: number;
  operations: readonly ProposedOperation[];
  direction: DirectionSummary | null;
}

export interface WorkspaceViewport {
  id: string;
  label: string;
  width: number;
}

export const DEFAULT_VIEWPORTS: readonly WorkspaceViewport[] = [
  { id: "mobile", label: "Mobile", width: 375 },
  { id: "tablet", label: "Tablet", width: 768 },
  { id: "desktop", label: "Desktop", width: 1440 },
];

export interface WorkspaceSnapshot {
  state: WorkspaceState;
  adapter: AdapterStatusModel;
  evidence: EvidenceSummary;
  confirmation: ConfirmationModel | null;
  direction: DirectionSummary | null;
  viewport: WorkspaceViewport;
  error: string | null;
}

export interface WorkspaceController {
  getState: () => WorkspaceState;
  snapshot: () => WorkspaceSnapshot;
  subscribe: (listener: (snapshot: WorkspaceSnapshot) => void) => () => void;
  connect: () => Promise<void>;
  read: () => Promise<void>;
  preview: (operations: readonly ProposedOperation[], blocked?: readonly string[]) => void;
  requestConfirmation: () => void;
  decide: (decision: "allow" | "deny") => Promise<void>;
  recordEvidence: (entries: readonly EvidenceEntry[]) => void;
  setViewport: (id: string) => void;
  canDispatchWrite: () => boolean;
  destroy: () => void;
}

const EMPTY_RESOLUTION: AdapterResolution = { kind: null, adapter: null, error: null, attempted: [] };

export function createWorkspaceController(options: WorkspaceOptions): WorkspaceController {
  const machine = new WorkspaceStateMachine();
  const listeners = new Set<(snapshot: WorkspaceSnapshot) => void>();
  const viewports = options.viewports ?? DEFAULT_VIEWPORTS;

  let resolution: AdapterResolution = EMPTY_RESOLUTION;
  let evidence: EvidenceEntry[] = [];
  let confirmation: ConfirmationModel | null = null;
  let operations: ProposedOperation[] = [];
  let viewport: WorkspaceViewport = viewports[viewports.length - 1];
  let error: string | null = null;
  let destroyed = false;

  const snapshot = (): WorkspaceSnapshot => ({
    state: machine.state,
    adapter: describeAdapterStatus(resolution, machine.state),
    evidence: summarizeEvidence(evidence),
    confirmation,
    direction: options.direction ?? null,
    viewport,
    error,
  });

  const move = (next: WorkspaceState): void => {
    machine.to(next);
    for (const listener of listeners) {
      listener(snapshot());
    }
  };

  const fail = (reason: string): void => {
    error = reason;
    if (machine.can("failed")) {
      move("failed");
    }
  };

  const registry = (): EditorRegistryLike => {
    if (resolution.adapter === null) {
      throw new Error("No editor adapter is connected.");
    }
    return resolution.adapter.registry();
  };

  /** The single place a mutating editor tool may be called. */
  const dispatchWrite = async (operation: ProposedOperation): Promise<void> => {
    if (!isWriteState(machine.state)) {
      throw new Error(`Refusing to write while the workspace is ${machine.state}.`);
    }
    await registry().call(operation.tool, { target: operation.target, ...operationArgs(operation) });
  };

  return {
    getState: () => machine.state,
    snapshot,

    subscribe(listener) {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },

    async connect() {
      if (destroyed) {
        throw new Error("This workspace has been destroyed.");
      }
      resolution = await resolveEditorAdapter(candidatesFor(options));
      if (resolution.error !== null || resolution.adapter === null) {
        fail(resolution.error ?? "No supported editor was found on this page.");
        return;
      }
      error = null;
      move("connected");
    },

    async read() {
      if (machine.state !== "connected") {
        throw new Error(`The workspace must be connected before it can read; it is ${machine.state}.`);
      }
      move("reading");
      try {
        await registry().call(options.readTool ?? "get_page_structure", {});
      } catch (cause) {
        fail(messageOf(cause));
      }
    },

    preview(next, blocked = []) {
      if (machine.state !== "reading") {
        throw new Error(`The workspace must read the page before it can preview; it is ${machine.state}.`);
      }
      operations = [...next];
      confirmation = describeConfirmation({
        operations,
        directionLabel: directionLabel(options.direction ?? null),
        blocked,
      });
      move("previewing");
    },

    requestConfirmation() {
      if (machine.state !== "previewing") {
        throw new Error(`There is nothing to confirm while the workspace is ${machine.state}.`);
      }
      if (confirmation === null || !confirmation.canConfirm) {
        throw new Error(confirmation?.warning ?? "This change cannot be confirmed.");
      }
      move("awaiting_confirmation");
    },

    async decide(decision) {
      if (machine.state !== "awaiting_confirmation") {
        throw new Error(`No confirmation is pending; the workspace is ${machine.state}.`);
      }

      if (decision === "deny") {
        confirmation = null;
        operations = [];
        move("connected");
        return;
      }

      move("applying");
      try {
        for (const operation of operations) {
          await dispatchWrite(operation);
        }
      } catch (cause) {
        fail(messageOf(cause));
        return;
      }

      move("verifying");
      let collected: EvidenceEntry[] = [];
      try {
        collected = options.verify
          ? await options.verify({ postId: options.postId, operations, direction: options.direction ?? null })
          : [];
      } catch (cause) {
        fail(messageOf(cause));
        return;
      }

      evidence = [...evidence, ...collected];
      const summary = summarizeEvidence(evidence);
      if (!summary.verified) {
        fail(
          summary.total === 0
            ? "Verification produced no evidence, so the change is unverified."
            : `Verification failed: ${summary.fail} failing and ${summary.notChecked} unchecked rule(s).`,
        );
        return;
      }

      confirmation = null;
      move("complete");
    },

    recordEvidence(entries) {
      evidence = [...evidence, ...entries];
    },

    setViewport(id) {
      const match = viewports.find((candidate) => candidate.id === id);
      if (match) {
        viewport = match;
      }
    },

    canDispatchWrite: () => isWriteState(machine.state),

    destroy() {
      destroyed = true;
      listeners.clear();
    },
  };
}

/**
 * Build the admin regions and keep them in step with the controller.
 *
 * Three regions: a header that states page, editor, and adapter status; an
 * evidence canvas with the viewport controls; and an inspector holding the
 * direction, the proposed diff, and the verification result.
 */
export function mountStonewrightWorkspace(root: HTMLElement, options: WorkspaceOptions): WorkspaceController {
  const doc = root.ownerDocument;
  const controller = createWorkspaceController(options);
  const viewports = options.viewports ?? DEFAULT_VIEWPORTS;

  root.textContent = "";
  root.classList.add("sw-visual");

  const header = doc.createElement("header");
  header.className = "sw-visual__header";

  const title = doc.createElement("h2");
  title.className = "sw-visual__title";
  title.textContent = `Post ${options.postId}`;

  const adapterSlot = doc.createElement("div");
  adapterSlot.className = "sw-visual__adapter-slot";

  header.append(title, adapterSlot);

  const canvas = doc.createElement("section");
  canvas.className = "sw-visual__canvas";
  canvas.setAttribute("aria-label", "Evidence canvas");

  const viewportControls = doc.createElement("div");
  viewportControls.className = "sw-visual__viewports";
  viewportControls.setAttribute("role", "group");
  viewportControls.setAttribute("aria-label", "Viewport");

  for (const candidate of viewports) {
    const button = doc.createElement("button");
    button.type = "button";
    button.className = "sw-visual__viewport";
    button.textContent = candidate.label;
    button.setAttribute("data-sw-viewport", candidate.id);
    button.addEventListener("click", () => {
      controller.setViewport(candidate.id);
      paint();
    });
    viewportControls.append(button);
  }

  const evidenceSlot = doc.createElement("div");
  evidenceSlot.className = "sw-visual__evidence-slot";
  canvas.append(viewportControls, evidenceSlot);

  const inspector = doc.createElement("aside");
  inspector.className = "sw-visual__inspector";
  inspector.setAttribute("aria-label", "Inspector");

  const directionLine = doc.createElement("p");
  directionLine.className = "sw-visual__direction";

  const confirmSlot = doc.createElement("div");
  confirmSlot.className = "sw-visual__confirm-slot";

  const status = doc.createElement("p");
  status.className = "sw-visual__status";
  status.setAttribute("role", "status");
  status.setAttribute("aria-live", "polite");

  inspector.append(directionLine, confirmSlot, status);
  root.append(header, canvas, inspector);

  function paint(): void {
    const current = controller.snapshot();

    root.setAttribute("data-sw-state", current.state);
    root.setAttribute("data-sw-viewport", current.viewport.id);

    adapterSlot.textContent = "";
    adapterSlot.append(renderAdapterStatus(doc, current.adapter));

    for (const button of Array.from(viewportControls.querySelectorAll("button"))) {
      const active = button.getAttribute("data-sw-viewport") === current.viewport.id;
      button.setAttribute("aria-pressed", active ? "true" : "false");
    }

    evidenceSlot.textContent = "";
    evidenceSlot.append(renderEvidencePanel(doc, describeEvidencePanel(evidenceRows(current))));

    directionLine.textContent = `Direction: ${directionLabel(current.direction)}`;

    confirmSlot.textContent = "";
    if (current.confirmation !== null) {
      confirmSlot.append(
        renderConfirmationPanel(doc, current.confirmation, {
          onAllow: () => void controller.decide("allow"),
          onDeny: () => void controller.decide("deny"),
        }),
      );
    }

    status.textContent = current.error ?? current.state.replace(/_/g, " ");
  }

  controller.subscribe(paint);
  paint();

  return controller;
}

function evidenceRows(snapshot: WorkspaceSnapshot): EvidenceEntry[] {
  // The snapshot carries counts, not rows; the panel needs something to list.
  const rows: EvidenceEntry[] = [];
  for (let index = 0; index < snapshot.evidence.pass; index += 1) {
    rows.push({ label: "Passed rule", status: "pass" });
  }
  for (let index = 0; index < snapshot.evidence.fail; index += 1) {
    rows.push({ label: "Failed rule", status: "fail" });
  }
  for (let index = 0; index < snapshot.evidence.warn; index += 1) {
    rows.push({ label: "Warning", status: "warn" });
  }
  for (let index = 0; index < snapshot.evidence.notChecked; index += 1) {
    rows.push({ label: "Unchecked rule", status: "not_checked" });
  }
  return rows;
}

function candidatesFor(options: WorkspaceOptions): readonly AdapterCandidate[] {
  const requested = options.editorKind ?? "auto";
  if (requested === "auto") {
    return options.adapters;
  }
  return options.adapters.filter((candidate) => candidate.kind === requested);
}

function directionLabel(direction: DirectionSummary | null): string {
  return direction === null ? "none active" : `${direction.title} rev ${direction.revision}`;
}

function operationArgs(operation: ProposedOperation): Record<string, unknown> {
  const args: Record<string, unknown> = {};
  if (operation.breakpoint !== undefined) {
    args.breakpoint = operation.breakpoint;
  }
  if (operation.after !== undefined) {
    args.value = operation.after;
  }
  return args;
}

function messageOf(cause: unknown): string {
  return cause instanceof Error ? cause.message : String(cause);
}
