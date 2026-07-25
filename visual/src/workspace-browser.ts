// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Browser entry for the Stonewright Visual workspace.
 *
 * This file is the only place that touches `window`, `document`, and `fetch`.
 * It builds the adapter candidates from the live editor runtimes, wires
 * verification to the read-only quality route, and mounts the workspace. It
 * imports nothing from Node, so the bundle stays loadable in wp-admin.
 */

import { ElementorV3EditorAdapter } from "./elementor-v3/editor-adapter.js";
import { createWindowElementorV3Runtime } from "./elementor-v3/window-runtime.js";
import { ElementorV4EditorAdapter } from "./elementor-v4/editor-adapter.js";
import { createWindowElementorV4Runtime } from "./elementor-v4/window-runtime.js";
import { GutenbergEditorAdapter } from "./gutenberg/editor-adapter.js";
import { createWindowGutenbergRuntime } from "./gutenberg/window-runtime.js";
import type { AdapterCandidate, AdapterKind } from "./workspace-ui/adapter-status.js";
import type { EvidenceEntry, EvidenceStatus } from "./workspace-ui/evidence-panel.js";
import {
  mountStonewrightWorkspace,
  type DirectionSummary,
  type WorkspaceController,
  type WorkspaceOptions,
} from "./workspace-ui/workspace.js";

export * from "./workspace-ui/adapter-status.js";
export * from "./workspace-ui/confirmation-panel.js";
export * from "./workspace-ui/evidence-panel.js";
export * from "./workspace-ui/state.js";
export * from "./workspace-ui/workspace.js";

export interface WorkspaceBootConfig {
  restBase: string;
  nonce: string;
  postId: number;
  editorKind?: AdapterKind | "auto";
  /**
   * The WordPress direction row, or an already-normalised summary. The row
   * carries the contract hash and never the contract itself.
   */
  direction?: DirectionSummary | Record<string, unknown> | null;
  mountSelector?: string;
}

interface EditorWindow {
  elementor?: { widgetsCache?: Record<string, { atomic?: boolean }>; config?: { elements?: Record<string, { atomic?: boolean }> } };
  $e?: unknown;
  wp?: { blocks?: unknown; data?: { select?: unknown; dispatch?: unknown } };
  stonewrightVisualWorkspace?: WorkspaceBootConfig;
  /** The mounted controller, so a host script can drive the workspace. */
  stonewrightVisual?: WorkspaceController;
}

const MOUNT_SELECTOR = "[data-sw-visual-workspace]";

export function defaultAdapterCandidates(target: EditorWindow = window as unknown as EditorWindow): AdapterCandidate[] {
  return [
    {
      kind: "elementor-v4",
      detect: () => hasElementor(target) && hasAtomicTypes(target),
      create: () => new ElementorV4EditorAdapter(createWindowElementorV4Runtime(target as never)),
    },
    {
      kind: "elementor-v3",
      detect: () => hasElementor(target),
      create: () => new ElementorV3EditorAdapter(createWindowElementorV3Runtime(target as never)),
    },
    {
      kind: "gutenberg",
      detect: () => hasBlockEditor(target),
      create: () => new GutenbergEditorAdapter(createWindowGutenbergRuntime(target as never)),
    },
  ];
}

/**
 * Reads the most recent stored quality report for this post and turns it into
 * evidence rows. No report means no evidence: the controller then reports the
 * change as unverified instead of inventing a pass.
 */
export function createQualityVerifier(config: WorkspaceBootConfig): NonNullable<WorkspaceOptions["verify"]> {
  return async () => {
    const url = `${config.restBase.replace(/\/$/, "")}/design-studio/quality?post_id=${config.postId}&limit=1`;
    const response = await fetch(url, {
      credentials: "same-origin",
      headers: { "X-WP-Nonce": config.nonce },
    });

    if (!response.ok) {
      throw new Error(`Quality reports could not be read (HTTP ${response.status}).`);
    }

    const payload: unknown = await response.json();
    return evidenceFromReport(payload);
  };
}

export function evidenceFromReport(payload: unknown): EvidenceEntry[] {
  const report = firstReport(payload);
  if (report === null) {
    return [];
  }

  const entries: EvidenceEntry[] = [];

  for (const finding of asArray(report.findings)) {
    const row = asRecord(finding);
    entries.push({
      label: text(row.rule_id, "rule"),
      rule: text(row.rule_id, "rule"),
      status: severityToStatus(text(row.severity, "warn")),
      viewport: optionalText(row.viewport),
      measured: optionalText(row.element_ref),
      source: "design-quality-check",
    });
  }

  for (const rule of asArray(asRecord(report.coverage).not_checked_rules)) {
    entries.push({
      label: text(rule, "rule"),
      rule: text(rule, "rule"),
      status: "not_checked",
      source: "design-quality-check",
    });
  }

  return entries;
}

/**
 * Normalises the WordPress direction row into the summary the workspace shows.
 *
 * The row is `DirectionSummary::row()` from the plugin: identity, revision, and
 * `contract_hash`. There is no contract in it, and none is reconstructed here —
 * the workspace states which direction is in force, it does not re-derive its
 * rules in the browser.
 */
export function directionFromPayload(value: unknown): DirectionSummary | null {
  const row = asRecord(value);
  const id = typeof row.id === "number" || typeof row.id === "string" ? String(row.id) : "";
  if (id === "" || id === "0") {
    return null;
  }

  return {
    id,
    title: text(row.name ?? row.title ?? row.slug, `Direction ${id}`),
    revision: typeof row.revision === "number" ? row.revision : Number(row.revision ?? 0) || 0,
    hash: text(row.contract_hash ?? row.hash, ""),
  };
}

/**
 * Region slots a host page may provide. All three must be present, otherwise
 * the workspace builds its own regions inside the mount root.
 */
function hostRegions(root: HTMLElement): { header: HTMLElement; canvas: HTMLElement; inspector: HTMLElement } | undefined {
  const header = root.querySelector<HTMLElement>("[data-sw-visual-adapter]");
  const canvas = root.querySelector<HTMLElement>("[data-sw-visual-workspace-canvas]");
  const inspector = root.querySelector<HTMLElement>("[data-sw-visual-workspace-inspector]");

  if (header === null || canvas === null || inspector === null) {
    return undefined;
  }

  return { header, canvas, inspector };
}

export function mountFromConfig(
  config: WorkspaceBootConfig,
  target: EditorWindow = window as unknown as EditorWindow,
): WorkspaceController | null {
  const selector = config.mountSelector ?? MOUNT_SELECTOR;
  const root = document.querySelector<HTMLElement>(selector);
  if (root === null) {
    return null;
  }

  const options: WorkspaceOptions = {
    restBase: config.restBase,
    nonce: config.nonce,
    postId: config.postId,
    editorKind: config.editorKind ?? "auto",
    direction: directionFromPayload(config.direction),
    adapters: defaultAdapterCandidates(target),
    verify: createQualityVerifier(config),
  };

  const regions = hostRegions(root);
  if (regions !== undefined) {
    options.regions = regions;
  }

  return mountStonewrightWorkspace(root, options);
}

/**
 * Mounts, connects, and shows what is already known about the post.
 *
 * Connecting can legitimately fail: the workspace host page is not an editor,
 * so there is often no editor runtime to attach to. That is reported in the
 * status line rather than hidden. Either way the stored quality report is read
 * and shown, so the page still says what the last verification observed.
 */
async function start(config: WorkspaceBootConfig, target: EditorWindow): Promise<void> {
  const controller = mountFromConfig(config, target);
  if (controller === null) {
    return;
  }

  target.stonewrightVisual = controller;
  await controller.connect();

  try {
    const stored = await createQualityVerifier(config)({
      postId: config.postId,
      operations: [],
      direction: directionFromPayload(config.direction),
    });
    controller.recordEvidence(stored);
  } catch {
    // No readable report is not an error state of its own: the evidence panel
    // stays empty, which is exactly what "nothing was verified" looks like.
  }
}

function boot(): void {
  const target = window as unknown as EditorWindow;
  const config = target.stonewrightVisualWorkspace;
  if (!config) {
    return;
  }
  void start(config, target);
}

if (typeof window !== "undefined" && typeof document !== "undefined") {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
}

function hasElementor(target: EditorWindow): boolean {
  return Boolean(target.elementor && target.$e);
}

/**
 * True when a block editor is really attached to this page.
 *
 * `wp.blocks` and `wp.data` are enqueued on plenty of admin screens that hold
 * no editor at all — the workspace host page is one of them. Their presence
 * proves nothing, so detection asks for the stores the Gutenberg runtime
 * actually calls. Without this the workspace reports "connected" against a
 * page that has no blocks to read and no post to save.
 */
function hasBlockEditor(target: EditorWindow): boolean {
  const blocks = target.wp?.blocks as { getBlockTypes?: unknown } | undefined;
  const select = target.wp?.data?.select;

  if (typeof blocks?.getBlockTypes !== "function" || typeof select !== "function") {
    return false;
  }

  try {
    const store = (select as (name: string) => unknown)("core/block-editor") as { getBlocks?: unknown } | undefined;
    const editor = (select as (name: string) => unknown)("core/editor") as { getCurrentPostId?: unknown } | undefined;

    return typeof store?.getBlocks === "function" && typeof editor?.getCurrentPostId === "function";
  } catch {
    // An unregistered store is a missing editor, not a crash.
    return false;
  }
}

function hasAtomicTypes(target: EditorWindow): boolean {
  const cache = target.elementor?.widgetsCache ?? {};
  const elements = target.elementor?.config?.elements ?? {};
  return [...Object.values(cache), ...Object.values(elements)].some((entry) => entry?.atomic === true);
}

function severityToStatus(severity: string): EvidenceStatus {
  switch (severity) {
    case "error":
    case "fail":
      return "fail";
    case "not_checked":
      return "not_checked";
    case "pass":
      return "pass";
    default:
      return "warn";
  }
}

function firstReport(payload: unknown): Record<string, unknown> | null {
  const reports = asArray(asRecord(payload).reports);
  return reports.length === 0 ? null : asRecord(reports[0]);
}

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === "object" && !Array.isArray(value) ? (value as Record<string, unknown>) : {};
}

function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}

function text(value: unknown, fallback: string): string {
  return typeof value === "string" && value.trim() !== "" ? value : fallback;
}

function optionalText(value: unknown): string | undefined {
  return typeof value === "string" && value.trim() !== "" ? value : undefined;
}
