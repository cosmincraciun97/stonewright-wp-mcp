// SPDX-License-Identifier: AGPL-3.0-or-later

import type { WorkspaceState } from "./state.js";

/**
 * Editor detection, and the header chip that reports it.
 *
 * Detection walks the candidates in order. An editor that is simply not on the
 * page is skipped. An editor that is on the page but cannot be driven stops the
 * walk: falling through to the next adapter would let a broken Elementor page be
 * edited as if it were Gutenberg, which is exactly the failure this refuses to
 * produce.
 */

export type AdapterKind = "elementor-v3" | "elementor-v4" | "gutenberg";

export interface EditorRegistryLike {
  definitions: () => Array<Record<string, unknown>>;
  call: (tool: string, args?: Record<string, unknown>) => Promise<unknown>;
}

export interface EditorAdapterLike {
  registry: () => EditorRegistryLike;
}

export interface AdapterCandidate {
  kind: AdapterKind;
  detect: () => boolean | Promise<boolean>;
  create: () => EditorAdapterLike | Promise<EditorAdapterLike>;
}

export interface AdapterResolution {
  kind: AdapterKind | null;
  adapter: EditorAdapterLike | null;
  error: string | null;
  attempted: AdapterKind[];
}

const ADAPTER_LABELS: Record<AdapterKind, string> = {
  "elementor-v3": "Elementor V3",
  "elementor-v4": "Elementor V4 Atomic",
  gutenberg: "Gutenberg",
};

export function adapterLabel(kind: AdapterKind | null): string {
  return kind === null ? "No editor" : ADAPTER_LABELS[kind];
}

export async function resolveEditorAdapter(candidates: readonly AdapterCandidate[]): Promise<AdapterResolution> {
  const attempted: AdapterKind[] = [];

  for (const candidate of candidates) {
    attempted.push(candidate.kind);

    let present: boolean;
    try {
      present = await candidate.detect();
    } catch (cause) {
      return { kind: null, adapter: null, error: describeError(candidate.kind, cause), attempted };
    }

    if (!present) {
      continue;
    }

    try {
      const adapter = await candidate.create();
      return { kind: candidate.kind, adapter, error: null, attempted };
    } catch (cause) {
      return { kind: null, adapter: null, error: describeError(candidate.kind, cause), attempted };
    }
  }

  return {
    kind: null,
    adapter: null,
    error: "No supported editor was found on this page.",
    attempted,
  };
}

export interface AdapterStatusModel {
  kind: AdapterKind | null;
  label: string;
  detail: string;
  tone: "ok" | "error" | "idle";
}

export function describeAdapterStatus(resolution: AdapterResolution, state: WorkspaceState): AdapterStatusModel {
  if (resolution.error !== null) {
    return { kind: null, label: adapterLabel(null), detail: resolution.error, tone: "error" };
  }

  if (resolution.kind === null) {
    return { kind: null, label: adapterLabel(null), detail: "Not connected yet.", tone: "idle" };
  }

  return {
    kind: resolution.kind,
    label: adapterLabel(resolution.kind),
    detail: `Connected — ${state.replace(/_/g, " ")}`,
    tone: "ok",
  };
}

export function renderAdapterStatus(doc: Document, model: AdapterStatusModel): HTMLElement {
  const wrapper = doc.createElement("div");
  wrapper.className = `sw-visual-adapter sw-visual-adapter--${model.tone}`;
  wrapper.setAttribute("data-sw-adapter", model.kind ?? "none");

  const label = doc.createElement("span");
  label.className = "sw-visual-adapter__label";
  label.textContent = model.label;

  const detail = doc.createElement("span");
  detail.className = "sw-visual-adapter__detail";
  detail.textContent = model.detail;

  wrapper.append(label, detail);
  return wrapper;
}

function describeError(kind: AdapterKind, cause: unknown): string {
  const message = cause instanceof Error ? cause.message : String(cause);
  return `${ADAPTER_LABELS[kind]} is present but could not be driven: ${message}`;
}
