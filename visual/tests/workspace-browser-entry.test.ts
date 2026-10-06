// SPDX-License-Identifier: AGPL-3.0-or-later
import { afterEach, describe, expect, it, vi } from "vitest";
import {
  createQualityVerifier,
  defaultAdapterCandidates,
  directionFromPayload,
  evidenceFromReport,
  mountFromConfig,
  type WorkspaceBootConfig,
} from "../src/workspace-browser.js";
import { resolveEditorAdapter } from "../src/workspace-ui/adapter-status.js";
import { summarizeEvidence } from "../src/workspace-ui/evidence-panel.js";
import type { WorkspaceController } from "../src/workspace-ui/workspace.js";
import { SyntheticDocument, find, type SyntheticNode } from "./synthetic-dom.js";

type EditorWindowArg = NonNullable<Parameters<typeof defaultAdapterCandidates>[0]>;

interface HostWindow {
  stonewrightVisualWorkspace?: WorkspaceBootConfig;
  stonewrightVisual?: WorkspaceController;
  stonewrightVisualConnect?: (editorWindow: EditorWindowArg) => Promise<WorkspaceController | null>;
}

const CONFIG: WorkspaceBootConfig = { restBase: "https://example.test/wp-json/stonewright/v1/", nonce: "synthetic-nonce", postId: 42 };
const QUALITY_URL = "https://example.test/wp-json/stonewright/v1/design-studio/quality?post_id=42&limit=1";

afterEach(() => {
  vi.unstubAllGlobals();
});

/** A page whose V3 runtime is ready: an active document, a command bus, and a widget cache. */
function v3EditorWindow(): EditorWindowArg {
  return {
    elementor: { config: { version: "synthetic" }, widgetsCache: { heading: { title: "Heading", controls: { title: { type: "text" } } } }, getContainer: () => null, documents: { getCurrent: () => ({ id: 42, container: { id: "document", children: [] } }) } },
    $e: { run: async () => undefined },
  } as unknown as EditorWindowArg;
}

/** The same ready runtime, with one Atomic type in its widget cache. */
function v4EditorWindow(): EditorWindowArg {
  const target = v3EditorWindow() as { elementor: { widgetsCache: Record<string, unknown> } };
  target.elementor.widgetsCache = { heading: { atomic: true, title: "Heading", version: "1.0", atomic_props_schema: { title: { properties: { $$type: { const: "string" } } } } } };
  return target as unknown as EditorWindowArg;
}

/** A page with the block registry and every editor store the block tools call. */
function blockEditorWindow(): EditorWindowArg {
  const noop = (): undefined => undefined;
  const stores: Record<string, Record<string, unknown>> = {
    "core/block-editor": { getBlocks: () => [], getBlock: () => null, getBlockRootClientId: () => null, getBlockIndex: () => -1, insertBlocks: noop, updateBlockAttributes: noop, moveBlocksToPosition: noop, removeBlocks: noop },
    "core/editor": { getCurrentPostId: () => 42, isEditedPostDirty: () => false, undo: noop, redo: noop, savePost: noop },
  };
  return { wp: { blocks: { getBlockTypes: () => [], createBlock: noop, serialize: () => "" }, data: { select: (name: string) => stores[name], dispatch: (name: string) => stores[name] } } } as unknown as EditorWindowArg;
}

/** The stored-report route, answering with an empty report list. A held call waits until it is released. */
function qualityRoute(response: { ok: boolean; status: number; body: unknown } = { ok: true, status: 200, body: { reports: [] } }) {
  const held: Array<() => void> = [];
  let holdNext = false;
  const fetch = vi.fn(async (_url: string, _init?: RequestInit) => {
    if (holdNext) {
      holdNext = false;
      await new Promise<void>((resolve) => held.push(resolve));
    }
    return { ok: response.ok, status: response.status, json: async () => response.body };
  });
  vi.stubGlobal("fetch", fetch);
  return { fetch, hold: () => { holdNext = true; }, release: () => { for (const resolve of held.splice(0)) resolve(); } };
}

/** A host document holding one workspace root under the given attribute. */
function hostDocument(attribute = "data-sw-visual-workspace"): { doc: SyntheticDocument; root: SyntheticNode } {
  const doc = new SyntheticDocument();
  const root = doc.createElement("div");
  root.setAttribute(attribute, "");
  doc.body.append(root);
  return { doc, root };
}

async function bootEntry(win: HostWindow, doc: SyntheticDocument): Promise<void> {
  vi.stubGlobal("window", win);
  vi.stubGlobal("document", doc);
  vi.resetModules();
  await import("../src/workspace-browser.js");
}

const settle = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

describe("stored quality report evidence", () => {
  it("turns a missing or empty report into no evidence", () => {
    for (const payload of [null, undefined, "reports", {}, { reports: [] }, { reports: "none" }]) expect(evidenceFromReport(payload)).toEqual([]);
    expect(summarizeEvidence(evidenceFromReport({ reports: [] })).verified).toBe(false);
  });

  it("reads only the newest report and keeps each finding's rule, viewport, and element", () => {
    const rows = evidenceFromReport({ reports: [
      { findings: [{ rule_id: "text-contrast", severity: "error", viewport: "mobile", element_ref: "#hero-title" }] },
      { findings: [{ rule_id: "older-report", severity: "error" }] },
    ] });
    expect(rows).toEqual([{ label: "text-contrast", rule: "text-contrast", status: "fail", viewport: "mobile", measured: "#hero-title", source: "design-quality-check", checked: true, evidenceType: "quality-rule" }]);
    expect(summarizeEvidence(rows).verified).toBe(false);
  });

  it("lists rules that could not run as unchecked rows", () => {
    const rows = evidenceFromReport({ reports: [{ findings: [], coverage: { checked: 3, not_checked: 2, not_checked_rules: ["focus-visible", "target-size"] } }] });
    expect(rows).toEqual([
      { label: "focus-visible", rule: "focus-visible", status: "not_checked", source: "design-quality-check", checked: false, evidenceType: "quality-rule" },
      { label: "target-size", rule: "target-size", status: "not_checked", source: "design-quality-check", checked: false, evidenceType: "quality-rule" },
    ]);
    expect(summarizeEvidence(rows).verified).toBe(false);
  });

  it("never counts a finding without a rule id or a known severity as checked", () => {
    const rows = evidenceFromReport({ reports: [{ findings: [{ severity: "error" }, { rule_id: "spacing", severity: "made-up" }] }] });
    expect(rows.map(({ label, checked }) => ({ label, checked }))).toEqual([{ label: "rule", checked: false }, { label: "spacing", checked: false }]);
  });

  it("treats the plugin's advisory warning as checked evidence that does not block verification", () => {
    const rows = evidenceFromReport({ reports: [{ status: "warn", findings: [{ rule_id: "token.spacing", severity: "warning", viewport: "desktop", element_ref: "hero-title", waived: false, waiver_reason: "" }] }] });
    expect(rows).toEqual([{ label: "token.spacing", rule: "token.spacing", status: "warn", viewport: "desktop", measured: "hero-title", source: "design-quality-check", checked: true, evidenceType: "quality-rule" }]);
    expect(summarizeEvidence(rows)).toMatchObject({ warn: 1, verified: true });
  });

  it("counts a waived finding as an accepted pass, never as a checked warning", () => {
    const rows = evidenceFromReport({ reports: [{ status: "pass", findings: [{ rule_id: "contrast.text", severity: "info", waived: true, waiver_reason: "Decorative legal note." }] }] });
    expect(rows).toMatchObject([{ rule: "contrast.text", status: "pass", checked: true }]);
    expect(summarizeEvidence(rows)).toMatchObject({ pass: 1, warn: 0, verified: true });
  });

  it("still refuses to verify an error or an unchecked rule reported next to warnings", () => {
    const failing = evidenceFromReport({ reports: [{ findings: [{ rule_id: "contrast.text", severity: "error" }, { rule_id: "token.spacing", severity: "warning" }] }] });
    expect(summarizeEvidence(failing).verified).toBe(false);
    const unchecked = evidenceFromReport({ reports: [{ findings: [{ rule_id: "token.spacing", severity: "warning" }], coverage: { checked: 4, not_checked: 1, not_checked_rules: ["state.focus_visible"] } }] });
    expect(summarizeEvidence(unchecked).verified).toBe(false);
  });

  it("never verifies a severity outside the plugin's error, warning, and info", () => {
    for (const severity of ["critical", "warn", "pass", "fail", "not_checked", "", 3, null]) {
      const rows = evidenceFromReport({ reports: [{ findings: [{ rule_id: "token.spacing", severity }] }] });
      expect(rows).toMatchObject([{ rule: "token.spacing", checked: false }]);
      expect(summarizeEvidence(rows).verified).toBe(false);
    }
  });
});

describe("direction summary from the stored row", () => {
  it("states identity, revision, and contract hash without carrying the contract", () => {
    expect(directionFromPayload({ id: 7, name: "Calm", revision: "3", contract_hash: "c0ffee", contract: { rules: ["spacing"] } })).toEqual({ id: "7", title: "Calm", revision: 3, hash: "c0ffee" });
  });

  it("falls back to the title, the slug, or a numbered name, and to revision zero", () => {
    expect(directionFromPayload({ id: 9, title: "Bold", revision: "draft", hash: "beef" })).toEqual({ id: "9", title: "Bold", revision: 0, hash: "beef" });
    expect(directionFromPayload({ id: "9", slug: "warm" })).toEqual({ id: "9", title: "warm", revision: 0, hash: "" });
    expect(directionFromPayload({ id: 5, name: " " })).toEqual({ id: "5", title: "Direction 5", revision: 0, hash: "" });
  });

  it("has no direction without an identity", () => {
    for (const row of [null, undefined, [], {}, { id: 0 }, { id: "0" }, { id: "" }, { id: true }]) expect(directionFromPayload(row)).toBeNull();
  });
});

describe("editor detection on a connected window", () => {
  it("offers the candidates in Atomic, V3, then block editor order", () => {
    expect(defaultAdapterCandidates({}).map((candidate) => candidate.kind)).toEqual(["elementor-v4", "elementor-v3", "gutenberg"]);
  });

  it("finds no editor on a page without an editor runtime", async () => {
    expect(await resolveEditorAdapter(defaultAdapterCandidates({}))).toMatchObject({ kind: null, error: "No supported editor was found on this page.", attempted: ["elementor-v4", "elementor-v3", "gutenberg"] });
  });

  it("does not mistake block scripts without editor stores for an editor", async () => {
    const scriptsOnly = { wp: { blocks: { getBlockTypes: () => [] }, data: { select: () => undefined } } } as unknown as EditorWindowArg;
    const missingStore = { wp: { blocks: { getBlockTypes: () => [] }, data: { select: () => { throw new Error("store is not registered"); } } } } as unknown as EditorWindowArg;
    for (const target of [scriptsOnly, missingStore]) expect(await resolveEditorAdapter(defaultAdapterCandidates(target))).toMatchObject({ kind: null, error: "No supported editor was found on this page." });
  });

  it("connects a ready V3 editor and builds its strict catalog", async () => {
    const resolution = await resolveEditorAdapter(defaultAdapterCandidates(v3EditorWindow()));
    expect(resolution).toMatchObject({ kind: "elementor-v3", error: null, attempted: ["elementor-v4", "elementor-v3"] });
    const tools = resolution.adapter!.registry();
    expect(tools.definitions().map((tool) => tool.name)).toEqual(expect.arrayContaining(["get_page_structure", "get_evidence_ledger", "create_element", "undo", "redo", "save"]));
    expect(await tools.call("get_page_structure", {})).toMatchObject({ details: { document_id: "42", count: 0 } });
  });

  it("connects a ready Atomic editor ahead of V3 and builds its strict catalog", async () => {
    const resolution = await resolveEditorAdapter(defaultAdapterCandidates(v4EditorWindow()));
    expect(resolution).toMatchObject({ kind: "elementor-v4", error: null, attempted: ["elementor-v4"] });
    const tools = resolution.adapter!.registry();
    expect(tools.definitions().map((tool) => tool.name)).toEqual(expect.arrayContaining(["get_page_structure", "create_element", "update_settings", "save"]));
    expect(await tools.call("get_page_structure", {})).toMatchObject({ details: { document_id: "42", architecture: "v4", tree: [] } });
  });

  it("connects a ready block editor and builds its catalog", async () => {
    const resolution = await resolveEditorAdapter(defaultAdapterCandidates(blockEditorWindow()));
    expect(resolution).toMatchObject({ kind: "gutenberg", error: null });
    expect(resolution.adapter!.registry().definitions().map((tool) => tool.name)).toEqual(expect.arrayContaining(["get_page_structure", "insert_block", "save"]));
  });

  it("stops at an editor that is present but not ready instead of falling through to the block editor", async () => {
    const notReady = { ...(blockEditorWindow() as object), elementor: { widgetsCache: { heading: { atomic: true } } }, $e: { run: async () => undefined } } as unknown as EditorWindowArg;
    const resolution = await resolveEditorAdapter(defaultAdapterCandidates(notReady));
    expect(resolution).toMatchObject({ kind: null, adapter: null, attempted: ["elementor-v4"] });
    expect(resolution.error).toMatch(/present but could not be driven/);
  });
});

describe("quality verifier", () => {
  it("reads the newest stored report with the nonce in a header, never in the address", async () => {
    const route = qualityRoute({ ok: true, status: 200, body: { reports: [{ findings: [{ rule_id: "overflow", severity: "error", viewport: "mobile" }] }] } });
    const rows = await createQualityVerifier(CONFIG)({ postId: 42, operations: [], direction: null });
    expect(rows).toMatchObject([{ rule: "overflow", status: "fail", viewport: "mobile" }]);
    expect(route.fetch).toHaveBeenCalledTimes(1);
    const [url, init] = route.fetch.mock.calls[0];
    expect(url).toBe(QUALITY_URL);
    expect(url).not.toContain("synthetic-nonce");
    expect(init).toEqual({ credentials: "same-origin", headers: { "X-WP-Nonce": "synthetic-nonce" } });
  });

  it("fails instead of reporting evidence when the report cannot be read", async () => {
    qualityRoute({ ok: false, status: 503, body: {} });
    await expect(createQualityVerifier(CONFIG)({ postId: 42, operations: [], direction: null })).rejects.toThrow("Quality reports could not be read (HTTP 503).");
  });
});

describe("mounting from the boot configuration", () => {
  it("mounts nothing when the page has no workspace root", () => {
    vi.stubGlobal("document", new SyntheticDocument());
    expect(mountFromConfig(CONFIG, {})).toBeNull();
  });

  it("mounts into the workspace root and shows the configured direction", () => {
    const { doc, root } = hostDocument();
    vi.stubGlobal("document", doc);
    const controller = mountFromConfig({ ...CONFIG, direction: { id: 7, name: "Calm", revision: 3, contract_hash: "c0ffee" } }, {});
    expect(controller?.getState()).toBe("booting");
    expect(root.classList.contains("sw-visual")).toBe(true);
    expect(root.children.map((region) => region.localName)).toEqual(["header", "section", "aside"]);
    expect(find(root, ".sw-visual__direction").textContent).toBe("Direction: Calm rev 3");
  });

  it("fills the host's own regions under a custom selector when all three are present", () => {
    const { doc, root } = hostDocument("data-host-workspace");
    const slots = ["data-sw-visual-adapter", "data-sw-visual-workspace-canvas", "data-sw-visual-workspace-inspector"].map((attribute) => {
      const slot = doc.createElement("div");
      slot.setAttribute(attribute, "");
      root.append(slot);
      return slot;
    });
    vi.stubGlobal("document", doc);
    expect(mountFromConfig({ ...CONFIG, mountSelector: "[data-host-workspace]" }, {})).not.toBeNull();
    expect(root.children).toHaveLength(3);
    slots.forEach((slot, index) => expect(root.children[index]).toBe(slot));
    expect(slots[0].querySelector("[data-sw-adapter]")).not.toBeNull();
    expect(slots[1].querySelectorAll("button[data-sw-viewport]")).toHaveLength(3);
    expect(slots[2].querySelector("[role=\"status\"]")).not.toBeNull();
  });

  it("builds its own regions when a host region is missing", () => {
    const { doc, root } = hostDocument();
    for (const attribute of ["data-sw-visual-adapter", "data-sw-visual-workspace-canvas"]) {
      const slot = doc.createElement("div");
      slot.setAttribute(attribute, "");
      root.append(slot);
    }
    vi.stubGlobal("document", doc);
    mountFromConfig(CONFIG, {});
    expect(root.children.map((region) => region.localName)).toEqual(["header", "section", "aside"]);
  });

  it("connects only the editor kind the configuration names", async () => {
    const both = { ...(v3EditorWindow() as object), ...(blockEditorWindow() as object) } as unknown as EditorWindowArg;
    vi.stubGlobal("document", hostDocument().doc);
    const automatic = mountFromConfig(CONFIG, both)!;
    await automatic.connect();
    expect(automatic.snapshot().adapter.kind).toBe("elementor-v3");
    vi.stubGlobal("document", hostDocument().doc);
    const pinned = mountFromConfig({ ...CONFIG, editorKind: "gutenberg" }, both)!;
    await pinned.connect();
    expect(pinned.snapshot().adapter.kind).toBe("gutenberg");
  });
});

describe("browser boot", () => {
  it("publishes the mounted controller and a connect hook that replaces it", async () => {
    qualityRoute();
    const { doc, root } = hostDocument();
    const win: HostWindow = { stonewrightVisualWorkspace: CONFIG };
    await bootEntry(win, doc);
    await vi.waitFor(() => expect(win.stonewrightVisual).toBeDefined());
    const initial = win.stonewrightVisual!;
    expect(initial.getState()).toBe("failed");
    expect(initial.snapshot().error).toBe("No supported editor was found on this page.");

    const connected = await win.stonewrightVisualConnect!(v3EditorWindow());
    expect(connected?.snapshot()).toMatchObject({ state: "connected", adapter: { kind: "elementor-v3" } });
    expect(win.stonewrightVisual).toBe(connected);
    expect(root.getAttribute("data-sw-state")).toBe("connected");
    await expect(initial.connect()).rejects.toThrow("This workspace has been destroyed.");
  });

  it("does nothing on a page without boot configuration", async () => {
    qualityRoute();
    const { doc, root } = hostDocument();
    const win: HostWindow = {};
    await bootEntry(win, doc);
    await settle();
    expect(win.stonewrightVisualConnect).toBeUndefined();
    expect(root.classList.contains("sw-visual")).toBe(false);
  });

  it("waits for the document to finish loading before it mounts", async () => {
    qualityRoute();
    const { doc, root } = hostDocument();
    doc.readyState = "loading";
    const win: HostWindow = { stonewrightVisualWorkspace: CONFIG };
    await bootEntry(win, doc);
    await settle();
    expect(win.stonewrightVisualConnect).toBeUndefined();
    expect(root.classList.contains("sw-visual")).toBe(false);
    doc.readyState = "interactive";
    doc.fire("DOMContentLoaded");
    await vi.waitFor(() => expect(win.stonewrightVisual).toBeDefined());
    expect(root.classList.contains("sw-visual")).toBe(true);
  });

  it("does not let a late initial boot replace or repaint an explicit connection", async () => {
    const route = qualityRoute();
    route.hold();
    const { doc, root } = hostDocument();
    const win: HostWindow = { stonewrightVisualWorkspace: CONFIG };
    await bootEntry(win, doc);
    await vi.waitFor(() => expect(route.fetch).toHaveBeenCalledTimes(1));
    const connected = await win.stonewrightVisualConnect!(v3EditorWindow());
    expect(win.stonewrightVisual).toBe(connected);
    route.release();
    await settle();
    expect(win.stonewrightVisual).toBe(connected);
    expect(root.getAttribute("data-sw-state")).toBe("connected");
  });

  it("discards a superseded connection without letting it repaint the root", async () => {
    const route = qualityRoute();
    const { doc, root } = hostDocument();
    const win: HostWindow = { stonewrightVisualWorkspace: CONFIG };
    await bootEntry(win, doc);
    await vi.waitFor(() => expect(win.stonewrightVisual).toBeDefined());
    route.hold();
    const first = win.stonewrightVisualConnect!({});
    await vi.waitFor(() => expect(route.fetch).toHaveBeenCalledTimes(2));
    const second = await win.stonewrightVisualConnect!(blockEditorWindow());
    expect(second?.snapshot().adapter.kind).toBe("gutenberg");
    route.release();
    expect(await first).toBeNull();
    await settle();
    expect(win.stonewrightVisual).toBe(second);
    expect(root.getAttribute("data-sw-state")).toBe("connected");
  });
});
