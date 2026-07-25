// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from "vitest";
import {
  defaultAdapterCandidates,
  directionFromPayload,
  evidenceFromReport,
} from "../src/workspace-browser.js";

/**
 * The browser entry, tested without a browser.
 *
 * Importing this module is safe in Node: the boot block is guarded on `window`
 * and `document`, so only the pure translation helpers run here. Detection is
 * exercised against fabricated editor globals, which is the same shape the
 * adapters see in wp-admin.
 */

type EditorGlobals = Parameters<typeof defaultAdapterCandidates>[0];

function detectionsFor(target: EditorGlobals): Record<string, boolean> {
  const out: Record<string, boolean> = {};
  for (const candidate of defaultAdapterCandidates(target)) {
    out[candidate.kind] = candidate.detect() === true;
  }
  return out;
}

describe("direction payload", () => {
  it("maps the WordPress direction row onto the workspace summary", () => {
    const summary = directionFromPayload({
      id: 11,
      slug: "quarry",
      name: "Quarry",
      revision: 4,
      contract_hash: "sha256:9f2c",
      active: true,
    });

    expect(summary).toEqual({
      id: "11",
      title: "Quarry",
      revision: 4,
      hash: "sha256:9f2c",
    });
  });

  it("keeps the hash and never invents a contract", () => {
    const summary = directionFromPayload({
      id: 11,
      name: "Quarry",
      revision: 4,
      contract_hash: "sha256:9f2c",
    });

    expect(Object.keys(summary ?? {}).sort()).toEqual(["hash", "id", "revision", "title"]);
  });

  it("reports no direction when the row is absent, empty, or unsaved", () => {
    expect(directionFromPayload(null)).toBeNull();
    expect(directionFromPayload(undefined)).toBeNull();
    expect(directionFromPayload({})).toBeNull();
    expect(directionFromPayload({ id: 0, name: "Draft" })).toBeNull();
  });

  it("accepts an already normalised summary", () => {
    const summary = directionFromPayload({ id: "7", title: "Basalt", revision: 2, hash: "sha256:aa" });

    expect(summary).toEqual({ id: "7", title: "Basalt", revision: 2, hash: "sha256:aa" });
  });

  it("falls back to the identifier when the row carries no name", () => {
    expect(directionFromPayload({ id: 9 })?.title).toBe("Direction 9");
  });

  it("coerces a string revision and tolerates a missing hash", () => {
    const summary = directionFromPayload({ id: 3, name: "Slate", revision: "6" });

    expect(summary?.revision).toBe(6);
    expect(summary?.hash).toBe("");
  });
});

describe("adapter detection", () => {
  it("finds nothing on a page with no editor runtime", () => {
    expect(detectionsFor({})).toEqual({
      "elementor-v4": false,
      "elementor-v3": false,
      gutenberg: false,
    });
  });

  it("reads a classic Elementor page as V3 and not V4", () => {
    const detections = detectionsFor({ elementor: { widgetsCache: { heading: {} } }, $e: {} });

    expect(detections["elementor-v3"]).toBe(true);
    expect(detections["elementor-v4"]).toBe(false);
  });

  it("reads an atomic Elementor page as V4", () => {
    const detections = detectionsFor({
      elementor: { widgetsCache: { "e-paragraph": { atomic: true } } },
      $e: {},
    });

    expect(detections["elementor-v4"]).toBe(true);
    // V3 still matches; resolution order decides, and V4 is attempted first.
    expect(detections["elementor-v3"]).toBe(true);
  });

  it("requires both halves of the block editor before claiming Gutenberg", () => {
    const editorStores = (name: string) =>
      name === "core/block-editor"
        ? { getBlocks: () => [] }
        : { getCurrentPostId: () => 1 };

    expect(detectionsFor({ wp: { blocks: {} } }).gutenberg).toBe(false);
    expect(
      detectionsFor({ wp: { blocks: { getBlockTypes: () => [] }, data: { select: editorStores } } })
        .gutenberg,
    ).toBe(true);
  });

  it("does not read a plain admin screen that merely loaded wp.data as an editor", () => {
    // The workspace host page enqueues the block libraries without ever
    // registering an editor store. Claiming Gutenberg here would report a
    // connected editor with nothing behind it.
    expect(detectionsFor({ wp: { blocks: {}, data: {} } }).gutenberg).toBe(false);
    expect(
      detectionsFor({
        wp: { blocks: { getBlockTypes: () => [] }, data: { select: () => undefined } },
      }).gutenberg,
    ).toBe(false);
    expect(
      detectionsFor({
        wp: {
          blocks: { getBlockTypes: () => [] },
          data: {
            select: (name: string) => (name === "core/block-editor" ? { getBlocks: () => [] } : undefined),
          },
        },
      }).gutenberg,
    ).toBe(false);
  });
});

describe("quality report translation", () => {
  it("turns findings and unchecked rules into evidence rows", () => {
    const entries = evidenceFromReport({
      reports: [
        {
          findings: [
            { rule_id: "contrast.text", severity: "error", viewport: "mobile", element_ref: "hero > h1" },
            { rule_id: "token.spacing", severity: "warning", viewport: "desktop" },
          ],
          coverage: { not_checked_rules: ["state.focus"] },
        },
      ],
    });

    expect(entries).toHaveLength(3);
    expect(entries[0]).toMatchObject({ rule: "contrast.text", status: "fail", viewport: "mobile" });
    expect(entries[1]).toMatchObject({ rule: "token.spacing", status: "warn" });
    expect(entries[2]).toMatchObject({ rule: "state.focus", status: "not_checked" });
  });

  it("produces no evidence when no report was stored", () => {
    expect(evidenceFromReport({ reports: [] })).toEqual([]);
    expect(evidenceFromReport(null)).toEqual([]);
  });
});
