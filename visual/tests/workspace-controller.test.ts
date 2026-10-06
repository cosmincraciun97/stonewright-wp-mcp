// SPDX-License-Identifier: GPL-2.0-or-later
import { describe, expect, it, vi } from "vitest";
import { DeclaredToolSet } from "../src/editor-tools/declared-tool-set.js";
import type { AdapterCandidate } from "../src/workspace-ui/adapter-status.js";
import type { EvidenceEntry } from "../src/workspace-ui/evidence-panel.js";
import {
  createWorkspaceController,
  mountStonewrightWorkspace,
  type WorkspaceController,
  type WorkspaceOptions,
  type WorkspaceSnapshot,
} from "../src/workspace-ui/workspace.js";
import { SyntheticDocument, asElement, button, find, type SyntheticNode } from "./synthetic-dom.js";

const PASSING: EvidenceEntry[] = [{ label: "Contrast", rule: "contrast", source: "quality-check", status: "pass", checked: true, evidenceType: "quality-rule" }];
const FAILING: EvidenceEntry[] = [{ label: "Overflow", rule: "overflow", source: "quality-check", status: "fail", checked: true, evidenceType: "quality-rule" }];

/**
 * A page editor with one read tool and one declared, readback-verified write.
 * It records every write and whether the controller allowed dispatch at that moment.
 */
function pageEditor() {
  let titles = ["Welcome"];
  let reads = 0;
  const writes: Array<Record<string, unknown>> = [];
  const dispatchAllowed: boolean[] = [];
  const watch: { controller: WorkspaceController | null } = { controller: null };
  const tools = new DeclaredToolSet([
    { name: "get_page_structure", parameters: { type: "object", additionalProperties: false, properties: {} }, execute: async () => { reads++; return { content: [], details: { titles: [...titles] } }; } },
    {
      name: "rename",
      parameters: { type: "object", additionalProperties: false, properties: { title: { type: "string", minLength: 1 } }, required: ["title"] },
      mutates: true,
      execute: async (args) => { writes.push(args); dispatchAllowed.push(watch.controller?.canDispatchWrite() ?? false); titles = [...titles, String(args.title)]; return { content: [] }; },
      readback: async (args) => { if (titles[titles.length - 1] !== args.title) throw new Error("Rename did not read back."); },
      rollback: async () => { titles = titles.slice(0, -1); },
    },
  ], undefined, {}, async () => [...titles]);
  return { tools, writes, dispatchAllowed, watch, reads: () => reads };
}

function thrownBy(run: () => unknown): unknown {
  try { run(); } catch (cause) { return cause; }
  return undefined;
}

const settle = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

function blockEditorCandidate(editor: ReturnType<typeof pageEditor>): AdapterCandidate {
  return { kind: "gutenberg", detect: () => true, create: () => ({ registry: () => editor.tools }) };
}

function controllerFor(editor: ReturnType<typeof pageEditor>, options: Partial<WorkspaceOptions> = {}): WorkspaceController {
  const controller = createWorkspaceController({ postId: 42, nonce: "synthetic-nonce", restBase: "https://example.test/wp-json/stonewright/v1", adapters: [blockEditorCandidate(editor)], verify: async () => PASSING, ...options });
  editor.watch.controller = controller;
  return controller;
}

const rename = (title: string) => ({ tool: "rename", target: "Hero heading", summary: `Rename to ${title}`, after: title, args: { title } });

describe("workspace controller", () => {
  it("fails visibly when no supported editor is present", async () => {
    const controller = createWorkspaceController({ postId: 42, nonce: "n", restBase: "https://example.test", adapters: [{ kind: "gutenberg", detect: () => false, create: () => { throw new Error("must not build"); } }] });
    await controller.connect();
    expect(controller.getState()).toBe("failed");
    expect(controller.snapshot()).toMatchObject({ error: "No supported editor was found on this page.", adapter: { kind: null, tone: "error" } });
  });

  it("considers only the editor kind the host asked for", async () => {
    const editor = pageEditor();
    const atomic = vi.fn(() => true);
    const controller = controllerFor(editor, { editorKind: "gutenberg", adapters: [{ kind: "elementor-v4", detect: atomic, create: () => ({ registry: () => editor.tools }) }, blockEditorCandidate(editor)] });
    await controller.connect();
    expect(controller.snapshot().adapter).toMatchObject({ kind: "gutenberg", tone: "ok" });
    expect(atomic).not.toHaveBeenCalled();
  });

  it("enforces connect, read, preview, and confirmation order", async () => {
    const editor = pageEditor();
    const controller = controllerFor(editor);
    await expect(controller.read()).rejects.toThrow(/must be connected/);
    expect(() => controller.preview([rename("Hello")])).toThrow(/must read the page/);
    expect(() => controller.requestConfirmation()).toThrow(/nothing to confirm/);
    await expect(controller.decide("allow")).rejects.toThrow(/No confirmation is pending/);
    await controller.connect();
    expect(() => controller.preview([rename("Hello")])).toThrow(/must read the page/);
    expect(editor.writes).toEqual([]);
  });

  it("refuses a preview without exact arguments or with arguments the declared schema rejects", async () => {
    const editor = pageEditor();
    const controller = controllerFor(editor);
    await controller.connect();
    await controller.read();
    expect(() => controller.preview([{ tool: "rename", target: "Hero heading", summary: "Rename" }])).toThrow(/exact typed editor arguments/);
    expect(thrownBy(() => controller.preview([rename("")]))).toMatchObject({ code: "workspace_args_invalid" });
    expect(controller.getState()).toBe("reading");
    expect(editor.reads()).toBe(1);
    expect(editor.writes).toEqual([]);
  });

  it("cannot confirm a blocked preview", async () => {
    const editor = pageEditor();
    const controller = controllerFor(editor);
    await controller.connect();
    await controller.read();
    controller.preview([rename("Hello")], ["The live schema has no such control."]);
    expect(controller.snapshot().confirmation).toMatchObject({ canConfirm: false, blocked: ["The live schema has no such control."] });
    expect(() => controller.requestConfirmation()).toThrow("1 operation(s) cannot be written by the live editor and must be resolved first.");
    expect(controller.getState()).toBe("previewing");
  });

  it("returns to connected without writing when the user denies", async () => {
    const editor = pageEditor();
    const controller = controllerFor(editor);
    await controller.connect();
    await controller.read();
    controller.preview([rename("Hello")]);
    controller.requestConfirmation();
    await controller.decide("deny");
    expect(controller.getState()).toBe("connected");
    expect(controller.snapshot().confirmation).toBeNull();
    expect(editor.writes).toEqual([]);
  });

  it("discards a preview, blocked or not, that is denied before confirmation is requested", async () => {
    const editor = pageEditor();
    const controller = controllerFor(editor);
    await controller.connect();
    for (const blocked of [[], ["The live schema has no such control."]]) {
      await controller.read();
      controller.preview([rename("Hello")], blocked);
      await controller.decide("deny");
      expect(controller.getState()).toBe("connected");
      expect(controller.snapshot().confirmation).toBeNull();
      expect(() => controller.requestConfirmation()).toThrow(/nothing to confirm/);
      await expect(controller.decide("allow")).rejects.toThrow(/No confirmation is pending/);
    }
    expect(editor.writes).toEqual([]);
  });

  it("applies several staged operations as one ordered batch, dispatching only while applying", async () => {
    const editor = pageEditor();
    const controller = controllerFor(editor);
    await controller.connect();
    await controller.read();
    controller.preview([rename("First"), rename("Second")]);
    expect(editor.writes).toEqual([]);
    controller.requestConfirmation();
    expect(controller.canDispatchWrite()).toBe(false);
    await controller.decide("allow");
    expect(controller.getState()).toBe("complete");
    expect(editor.writes).toEqual([{ title: "First" }, { title: "Second" }]);
    expect(editor.dispatchAllowed).toEqual([true, true]);
    expect(controller.canDispatchWrite()).toBe(false);
    expect(controller.snapshot()).toMatchObject({ confirmation: null, error: null, evidence: { total: 1, verified: true } });
  });

  it.each([
    ["failing evidence", { verify: async () => FAILING }, "Verification failed: 1 failing and 0 unchecked rule(s)."],
    ["an unreadable report", { verify: async () => { throw new Error("Quality reports could not be read (HTTP 503)."); } }, "Quality reports could not be read (HTTP 503)."],
    ["no verifier at all", { verify: undefined }, "Verification produced no evidence, so the change is unverified."],
  ] as Array<[string, Partial<WorkspaceOptions>, string]>)("reports a landed write with %s as failed", async (_case, options, message) => {
    const editor = pageEditor();
    const controller = controllerFor(editor, options);
    await controller.connect();
    await controller.read();
    controller.preview([rename("Hello")]);
    controller.requestConfirmation();
    await controller.decide("allow");
    expect(editor.writes).toEqual([{ title: "Hello" }]);
    expect(controller.getState()).toBe("failed");
    expect(controller.snapshot().error).toBe(message);
  });

  it("names the direction in force on the confirmation", async () => {
    const editor = pageEditor();
    const directed = controllerFor(editor, { direction: { id: "7", title: "Calm", revision: 3, hash: "direction-hash" } });
    await directed.connect();
    await directed.read();
    directed.preview([rename("Hello")]);
    expect(directed.snapshot()).toMatchObject({ direction: { id: "7", revision: 3 }, confirmation: { directionLabel: "Calm rev 3" } });
    const undirected = controllerFor(pageEditor());
    await undirected.connect();
    await undirected.read();
    undirected.preview([rename("Hello")]);
    expect(undirected.snapshot().confirmation?.directionLabel).toBe("none active");
  });

  it("notifies subscribers of transitions and recorded evidence until they unsubscribe", async () => {
    const editor = pageEditor();
    const controller = controllerFor(editor);
    const seen: WorkspaceSnapshot[] = [];
    const stop = controller.subscribe((snapshot) => seen.push(snapshot));
    await controller.connect();
    controller.recordEvidence(PASSING);
    expect(seen.map((snapshot) => [snapshot.state, snapshot.evidence.total])).toEqual([["connected", 0], ["connected", 1]]);
    stop();
    await controller.read();
    expect(seen).toHaveLength(2);
  });

  it("keeps the viewport on a declared size", () => {
    const controller = controllerFor(pageEditor());
    expect(controller.snapshot().viewport).toEqual({ id: "desktop", label: "Desktop", width: 1440 });
    controller.setViewport("mobile");
    expect(controller.snapshot().viewport.id).toBe("mobile");
    controller.setViewport("watch");
    expect(controller.snapshot().viewport.id).toBe("mobile");
  });

  it("cannot be reconnected after it is destroyed", async () => {
    const controller = controllerFor(pageEditor());
    controller.destroy();
    await expect(controller.connect()).rejects.toThrow("This workspace has been destroyed.");
  });
});

describe("mounted workspace", () => {
  function mount(options: Partial<WorkspaceOptions> = {}) {
    const doc = new SyntheticDocument();
    const root = doc.createElement("div");
    doc.body.append(root);
    const editor = pageEditor();
    const controller = mountStonewrightWorkspace(asElement(root), { postId: 42, nonce: "synthetic-nonce", restBase: "https://example.test/wp-json/stonewright/v1", adapters: [blockEditorCandidate(editor)], verify: async () => PASSING, ...options });
    editor.watch.controller = controller;
    return { doc, root, editor, controller };
  }

  const pressed = (root: SyntheticNode): Record<string, string | null> => Object.fromEntries(root.querySelectorAll("button[data-sw-viewport]").map((control) => [control.getAttribute("data-sw-viewport") ?? "", control.getAttribute("aria-pressed")]));

  it("builds the header, canvas, and inspector and paints the starting state", () => {
    const { root } = mount();
    expect(root.classList.contains("sw-visual")).toBe(true);
    expect(root.children.map((region) => region.localName)).toEqual(["header", "section", "aside"]);
    expect(root.getAttribute("data-sw-state")).toBe("booting");
    expect(find(root, "h2").textContent).toBe("Post 42");
    expect(find(root, "[data-sw-adapter]").getAttribute("data-sw-adapter")).toBe("none");
    expect(find(root, "[data-sw-evidence]").getAttribute("data-sw-evidence")).toBe("unverified");
    const status = find(root, "[role=\"status\"]");
    expect([status.textContent, status.getAttribute("aria-live")]).toEqual(["booting", "polite"]);
    expect(find(root, ".sw-visual__direction").textContent).toBe("Direction: none active");
    expect(pressed(root)).toEqual({ mobile: "false", tablet: "false", desktop: "true" });
  });

  it("switches the pressed viewport from its toolbar", () => {
    const { root } = mount();
    expect(find(root, ".sw-visual__viewports").getAttribute("role")).toBe("group");
    button(root, "Mobile").click();
    expect(root.getAttribute("data-sw-viewport")).toBe("mobile");
    expect(pressed(root)).toEqual({ mobile: "true", tablet: "false", desktop: "false" });
  });

  it("repaints through the write ladder and drops the panel once the change completes", async () => {
    const { root, controller, editor } = mount();
    await controller.connect();
    expect(find(root, "[data-sw-adapter]").getAttribute("data-sw-adapter")).toBe("gutenberg");
    expect(find(root, "[role=\"status\"]").textContent).toBe("connected");
    await controller.read();
    controller.preview([rename("Hello")]);
    expect(find(root, ".sw-visual-confirm").getAttribute("aria-label")).toBe("Apply 1 change(s) to this page?");
    controller.requestConfirmation();
    expect(root.getAttribute("data-sw-state")).toBe("awaiting_confirmation");
    button(root, "Apply changes").click();
    await vi.waitFor(() => expect(controller.getState()).toBe("complete"));
    expect(editor.writes).toEqual([{ title: "Hello" }]);
    expect(root.querySelector(".sw-visual-confirm")).toBeNull();
    expect(find(root, "[role=\"status\"]").textContent).toBe("complete");
    expect(find(root, ".sw-visual-evidence__heading").textContent).toBe("Evidence — 1/1 passed");
  });

  it("cancels a staged change from the panel", async () => {
    const { root, controller, editor } = mount();
    await controller.connect();
    await controller.read();
    controller.preview([rename("Hello")]);
    controller.requestConfirmation();
    button(root, "Cancel").click();
    await vi.waitFor(() => expect(controller.getState()).toBe("connected"));
    expect(root.querySelector(".sw-visual-confirm")).toBeNull();
    expect(editor.writes).toEqual([]);
  });

  it("keeps Apply disabled until confirmation is requested and ignores early or repeated clicks", async () => {
    const rejections: unknown[] = [];
    const onRejection = (reason: unknown): void => { rejections.push(reason); };
    process.on("unhandledRejection", onRejection);
    try {
      const { root, controller, editor } = mount();
      await controller.connect();
      await controller.read();
      controller.preview([rename("Hello")]);
      const early = button(root, "Apply changes");
      expect(early.disabled).toBe(true);
      early.dispatch("click");
      await settle();
      expect(controller.getState()).toBe("previewing");
      controller.requestConfirmation();
      const apply = button(root, "Apply changes");
      expect(apply.disabled).toBe(false);
      apply.click();
      apply.click();
      await vi.waitFor(() => expect(controller.getState()).toBe("complete"));
      await settle();
      expect(editor.writes).toEqual([{ title: "Hello" }]);
      expect(rejections).toEqual([]);
    } finally {
      process.off("unhandledRejection", onRejection);
    }
  });

  it("discards a previewed change from Cancel without applying or calling out, and ignores a later Apply", async () => {
    const verify = vi.fn(async () => PASSING);
    const request = vi.fn(async () => null);
    const { root, controller, editor } = mount({ verify, request });
    await controller.connect();
    await controller.read();
    controller.preview([rename("Hello")]);
    const staleApply = button(root, "Apply changes");
    const reads = editor.reads();
    button(root, "Cancel").click();
    await vi.waitFor(() => expect(controller.getState()).toBe("connected"));
    expect(root.querySelector(".sw-visual-confirm")).toBeNull();
    expect(find(root, "[role=\"status\"]").textContent).toBe("connected");
    staleApply.dispatch("click");
    await settle();
    expect(controller.getState()).toBe("connected");
    expect(() => controller.requestConfirmation()).toThrow(/nothing to confirm/);
    expect(editor.writes).toEqual([]);
    expect(editor.reads()).toBe(reads);
    expect(verify).not.toHaveBeenCalled();
    expect(request).not.toHaveBeenCalled();
  });

  it("marks the evidence panel with the controller's verified state", () => {
    const verified = mount();
    verified.controller.recordEvidence(PASSING);
    expect(verified.controller.snapshot().evidence.verified).toBe(true);
    expect(find(verified.root, "[data-sw-evidence]").getAttribute("data-sw-evidence")).toBe("verified");
    const failing = mount();
    failing.controller.recordEvidence(FAILING);
    expect(find(failing.root, "[data-sw-evidence]").getAttribute("data-sw-evidence")).toBe("unverified");
  });

  it("shows the failure reason in the status line", async () => {
    const { root, controller } = mount({ adapters: [] });
    await controller.connect();
    expect(root.getAttribute("data-sw-state")).toBe("failed");
    expect(find(root, "[role=\"status\"]").textContent).toBe("No supported editor was found on this page.");
    expect(find(root, "[data-sw-adapter]").classList.contains("sw-visual-adapter--error")).toBe(true);
  });

  it("fills host-provided regions and leaves the host markup around them", () => {
    const doc = new SyntheticDocument();
    const root = doc.createElement("div");
    const heading = doc.createElement("h1");
    heading.textContent = "Host heading";
    const header = doc.createElement("div");
    header.textContent = "Loading…";
    const canvas = doc.createElement("div");
    const inspector = doc.createElement("div");
    root.append(heading, header, canvas, inspector);
    mountStonewrightWorkspace(asElement(root), { postId: 42, nonce: "n", restBase: "https://example.test", adapters: [], regions: { header: asElement(header), canvas: asElement(canvas), inspector: asElement(inspector) } });
    expect(root.children).toHaveLength(4);
    [heading, header, canvas, inspector].forEach((region, index) => expect(root.children[index]).toBe(region));
    expect(heading.textContent).toBe("Host heading");
    expect(header.textContent).not.toContain("Loading");
    expect(header.querySelector("[data-sw-adapter]")).not.toBeNull();
    expect(canvas.querySelectorAll("button[data-sw-viewport]")).toHaveLength(3);
    expect(canvas.querySelector("[data-sw-evidence]")).not.toBeNull();
    expect(inspector.querySelector("[role=\"status\"]")).not.toBeNull();
    expect(root.querySelector("header")).toBeNull();
  });
});
