// SPDX-License-Identifier: GPL-2.0-or-later
import { describe, expect, it } from "vitest";
import {
  adapterLabel,
  describeAdapterStatus,
  renderAdapterStatus,
  resolveEditorAdapter,
  type AdapterCandidate,
  type AdapterKind,
} from "../src/workspace-ui/adapter-status.js";
import { describeConfirmation, renderConfirmationPanel, type ProposedOperation } from "../src/workspace-ui/confirmation-panel.js";
import { describeEvidencePanel, renderEvidencePanel, summarizeEvidence, type EvidenceEntry } from "../src/workspace-ui/evidence-panel.js";
import { SyntheticDocument, asDocument, button, find, synthetic } from "./synthetic-dom.js";

const emptyRegistry = () => ({ definitions: () => [], call: async () => null });

/** A detection candidate that counts how often it was probed and built. */
function candidate(kind: AdapterKind, present: boolean | Error, built: "ok" | Error = "ok") {
  const seen = { detect: 0, create: 0 };
  const row: AdapterCandidate = {
    kind,
    detect: () => { seen.detect++; if (present instanceof Error) throw present; return present; },
    create: async () => { seen.create++; if (built instanceof Error) throw built; return { registry: emptyRegistry }; },
  };
  return { row, seen };
}

/** One checked evidence row with full provenance. */
function checkedRow(status: EvidenceEntry["status"], rule: string, extra: Partial<EvidenceEntry> = {}): EvidenceEntry {
  return { label: rule, rule, status, source: "quality-check", checked: true, evidenceType: "quality-rule", ...extra };
}

const RENAME: ProposedOperation = { tool: "update_settings", target: "Hero heading", summary: "Shorter title", before: "Welcome to our site", after: "Welcome", breakpoint: "mobile", args: { element_id: "c1", settings: { title: "Welcome" } } };

describe("editor detection", () => {
  it("walks the candidates in order and connects the first editor that is present", async () => {
    const atomic = candidate("elementor-v4", false);
    const classic = candidate("elementor-v3", true);
    const blocks = candidate("gutenberg", true);
    const resolution = await resolveEditorAdapter([atomic.row, classic.row, blocks.row]);
    expect(resolution).toMatchObject({ kind: "elementor-v3", error: null, attempted: ["elementor-v4", "elementor-v3"] });
    expect(resolution.adapter?.registry().definitions()).toEqual([]);
    expect(blocks.seen).toEqual({ detect: 0, create: 0 });
  });

  it("stops at a present editor that cannot be driven instead of falling through", async () => {
    const atomic = candidate("elementor-v4", true, new Error("runtime is missing"));
    const blocks = candidate("gutenberg", true);
    const resolution = await resolveEditorAdapter([atomic.row, blocks.row]);
    expect(resolution).toMatchObject({ kind: null, adapter: null, attempted: ["elementor-v4"] });
    expect(resolution.error).toMatch(/ is present but could not be driven: runtime is missing$/);
    expect(blocks.seen).toEqual({ detect: 0, create: 0 });
  });

  it("treats a failing detection probe as an error rather than an absent editor", async () => {
    const atomic = candidate("elementor-v4", new Error("window is closed"));
    const blocks = candidate("gutenberg", true);
    const resolution = await resolveEditorAdapter([atomic.row, blocks.row]);
    expect(resolution).toMatchObject({ kind: null, adapter: null, attempted: ["elementor-v4"] });
    expect(resolution.error).toMatch(/could not be driven: window is closed$/);
    expect(blocks.seen.detect).toBe(0);
  });

  it("reports that no supported editor was found once every candidate is absent", async () => {
    const rows = (["elementor-v4", "elementor-v3", "gutenberg"] as const).map((kind) => candidate(kind, false));
    const resolution = await resolveEditorAdapter(rows.map(({ row }) => row));
    expect(resolution).toEqual({ kind: null, adapter: null, error: "No supported editor was found on this page.", attempted: ["elementor-v4", "elementor-v3", "gutenberg"] });
    expect(rows.every(({ seen }) => seen.create === 0)).toBe(true);
  });
});

describe("adapter status chip", () => {
  it("describes idle, connected, and failed resolutions", () => {
    expect(describeAdapterStatus({ kind: null, adapter: null, error: null, attempted: [] }, "booting")).toEqual({ kind: null, label: "No editor", detail: "Not connected yet.", tone: "idle" });
    expect(describeAdapterStatus({ kind: "gutenberg", adapter: { registry: emptyRegistry }, error: null, attempted: ["gutenberg"] }, "awaiting_confirmation")).toEqual({ kind: "gutenberg", label: adapterLabel("gutenberg"), detail: "Connected — awaiting confirmation", tone: "ok" });
    expect(describeAdapterStatus({ kind: null, adapter: null, error: "Editor window closed.", attempted: ["elementor-v4"] }, "failed")).toEqual({ kind: null, label: "No editor", detail: "Editor window closed.", tone: "error" });
  });

  it("gives every editor kind its own label", () => {
    const labels = (["elementor-v3", "elementor-v4", "gutenberg"] as const).map(adapterLabel);
    expect(new Set(labels).size).toBe(3);
    expect(labels).not.toContain(adapterLabel(null));
  });

  it("renders the tone, the kind marker, and the label and detail as text", () => {
    const doc = new SyntheticDocument();
    const failed = synthetic(renderAdapterStatus(asDocument(doc), { kind: null, label: "No editor", detail: "<b>closed</b>", tone: "error" }));
    expect(failed.classList.contains("sw-visual-adapter--error")).toBe(true);
    expect(failed.getAttribute("data-sw-adapter")).toBe("none");
    expect(find(failed, ".sw-visual-adapter__detail").textContent).toBe("<b>closed</b>");
    const connected = synthetic(renderAdapterStatus(asDocument(doc), describeAdapterStatus({ kind: "elementor-v3", adapter: { registry: emptyRegistry }, error: null, attempted: ["elementor-v3"] }, "connected")));
    expect(connected.getAttribute("data-sw-adapter")).toBe("elementor-v3");
    expect(connected.textContent).toBe(`${adapterLabel("elementor-v3")}Connected — connected`);
  });
});

describe("evidence summary", () => {
  it("never verifies an empty report", () => {
    expect(summarizeEvidence([])).toEqual({ total: 0, pass: 0, fail: 0, warn: 0, notChecked: 0, verified: false });
    expect(describeEvidencePanel([]).notice).toBe("Nothing was checked, so nothing passed.");
  });

  it("verifies checked passes alongside advisory warnings", () => {
    const panel = describeEvidencePanel([checkedRow("pass", "contrast"), checkedRow("warn", "spacing-scale")]);
    expect(panel.summary).toEqual({ total: 2, pass: 1, fail: 0, warn: 1, notChecked: 0, verified: true });
    expect(panel.notice).toBeNull();
  });

  it("does not verify a failed or an unchecked rule", () => {
    const failed = describeEvidencePanel([checkedRow("pass", "contrast"), checkedRow("fail", "overflow")]);
    expect(failed.summary).toMatchObject({ fail: 1, verified: false });
    expect(failed.notice).toBe("1 rule(s) failed against the captured render.");
    const unchecked = describeEvidencePanel([checkedRow("pass", "contrast"), { label: "focus", rule: "focus", status: "not_checked", source: "quality-check", checked: false, evidenceType: "quality-rule" }]);
    expect(unchecked.summary).toMatchObject({ notChecked: 1, verified: false });
    expect(unchecked.notice).toBe("1 rule(s) had no evidence to run against and stay unverified.");
  });

  it("does not count a pass without checked provenance", () => {
    const unproven: EvidenceEntry[] = [
      { label: "contrast", status: "pass" },
      checkedRow("pass", "contrast", { checked: false }),
      checkedRow("pass", "contrast", { rule: "" }),
      checkedRow("pass", "contrast", { source: undefined }),
      checkedRow("pass", "contrast", { evidenceType: undefined }),
    ];
    for (const entry of unproven) expect(summarizeEvidence([checkedRow("pass", "overflow"), entry]).verified).toBe(false);
  });

  it("fills a missing viewport and measurement with a placeholder", () => {
    const [row] = describeEvidencePanel([checkedRow("pass", "contrast")]).rows;
    expect(row).toMatchObject({ viewport: "—", measured: "—", rule: "contrast" });
    const [measured] = describeEvidencePanel([checkedRow("fail", "overflow", { viewport: "mobile", measured: "412px > 375px" })]).rows;
    expect(measured).toMatchObject({ viewport: "mobile", measured: "412px > 375px" });
  });
});

describe("evidence panel rendering", () => {
  it("marks the panel verified and lists one row per entry with its status", () => {
    const doc = new SyntheticDocument();
    const panel = synthetic(renderEvidencePanel(asDocument(doc), describeEvidencePanel([checkedRow("pass", "contrast", { viewport: "desktop", measured: "7.1:1" }), checkedRow("warn", "spacing-scale")])));
    expect(panel.getAttribute("data-sw-evidence")).toBe("verified");
    expect(find(panel, "h3").textContent).toBe("Evidence — 1/2 passed");
    expect(panel.querySelector(".sw-visual-evidence__notice")).toBeNull();
    expect(panel.querySelectorAll("li").map((row) => row.getAttribute("data-sw-evidence-status"))).toEqual(["pass", "warn"]);
    expect(find(panel, ".sw-visual-evidence__measured").textContent).toBe("desktop · 7.1:1");
  });

  it("states why unverified evidence cannot pass", () => {
    const doc = new SyntheticDocument();
    const panel = synthetic(renderEvidencePanel(asDocument(doc), describeEvidencePanel([{ label: "<script>focus</script>", rule: "focus", status: "not_checked", checked: false }])));
    expect(panel.getAttribute("data-sw-evidence")).toBe("unverified");
    expect(find(panel, ".sw-visual-evidence__notice").textContent).toBe("1 rule(s) had no evidence to run against and stay unverified.");
    expect(find(panel, ".sw-visual-evidence__label").textContent).toBe("<script>focus</script>");
  });
});

describe("confirmation model", () => {
  it("states every operation and fills missing before, after, and breakpoint", () => {
    const model = describeConfirmation({ operations: [RENAME, { tool: "delete_element", target: "Old banner", summary: "Remove banner" }], directionLabel: "Calm rev 3" });
    expect(model).toMatchObject({ title: "Apply 2 change(s) to this page?", directionLabel: "Calm rev 3", blocked: [], canConfirm: true, warning: null });
    expect(model.operations[0]).toEqual({ ...RENAME });
    expect(model.operations[1]).toMatchObject({ before: "—", after: "—", breakpoint: "all" });
  });

  it("offers no confirmation when there is nothing to apply", () => {
    expect(describeConfirmation({ operations: [], directionLabel: "none active" })).toMatchObject({ canConfirm: false, warning: "There is nothing to apply." });
  });

  it("offers no confirmation while any operation is blocked", () => {
    const model = describeConfirmation({ operations: [RENAME], directionLabel: "none active", blocked: ["The live schema has no control named title_mobile."] });
    expect(model).toMatchObject({ canConfirm: false, blocked: ["The live schema has no control named title_mobile."], warning: "1 operation(s) cannot be written by the live editor and must be resolved first." });
  });
});

describe("confirmation panel rendering", () => {
  it("labels the group with its title and shows target, breakpoint, change, and summary as text", () => {
    const doc = new SyntheticDocument();
    const model = describeConfirmation({ operations: [{ ...RENAME, summary: "<img src=x onerror=alert(1)>" }], directionLabel: "Calm rev 3" });
    const panel = synthetic(renderConfirmationPanel(asDocument(doc), model, { onAllow: () => undefined, onDeny: () => undefined }));
    expect(panel.getAttribute("role")).toBe("group");
    expect(panel.getAttribute("aria-label")).toBe("Apply 1 change(s) to this page?");
    expect(find(panel, ".sw-visual-confirm__direction").textContent).toBe("Direction: Calm rev 3");
    expect(find(panel, ".sw-visual-confirm__target").textContent).toBe("Hero heading · mobile");
    expect(find(panel, ".sw-visual-confirm__change").textContent).toBe("Welcome to our site → Welcome");
    expect(find(panel, ".sw-visual-confirm__summary").textContent).toBe("<img src=x onerror=alert(1)>");
    expect(panel.querySelector(".sw-visual-confirm__warning")).toBeNull();
  });

  it("runs the allow and deny handlers from typed buttons", () => {
    const doc = new SyntheticDocument();
    const calls: string[] = [];
    const panel = synthetic(renderConfirmationPanel(asDocument(doc), describeConfirmation({ operations: [RENAME], directionLabel: "none active" }), { onAllow: () => calls.push("allow"), onDeny: () => calls.push("deny") }));
    const apply = button(panel, "Apply changes");
    expect(apply.type).toBe("button");
    expect(apply.disabled).toBe(false);
    apply.click();
    button(panel, "Cancel").click();
    expect(calls).toEqual(["allow", "deny"]);
  });

  it("disables Apply for a blocked change while still letting the user cancel", () => {
    const doc = new SyntheticDocument();
    const calls: string[] = [];
    const model = describeConfirmation({ operations: [RENAME], directionLabel: "none active", blocked: ["Unsupported responsive control."] });
    const panel = synthetic(renderConfirmationPanel(asDocument(doc), model, { onAllow: () => calls.push("allow"), onDeny: () => calls.push("deny") }));
    expect(find(panel, ".sw-visual-confirm__warning").textContent).toBe(model.warning);
    expect(find(panel, ".sw-visual-confirm__blocked").textContent).toBe("Unsupported responsive control.");
    const apply = button(panel, "Apply changes");
    expect(apply.disabled).toBe(true);
    apply.click();
    button(panel, "Cancel").click();
    expect(calls).toEqual(["deny"]);
  });
});
