import { describe, expect, it, beforeEach, afterEach } from "vitest";
import { maybeAttachTaskStartHint } from "../src/direct/registry.js";
import {
  assertWriteAllowed,
  hasTaskStartSeen,
  markTaskStartSeen,
  resetTaskStartSeenForTests,
  TASK_START_TTL_MS,
} from "../src/direct/writes.js";

describe("direct task-start write gate", () => {
  beforeEach(() => {
    resetTaskStartSeenForTests();
  });
  afterEach(() => {
    resetTaskStartSeenForTests();
  });

  it("blocks writes before task-start by default", () => {
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
      }),
    ).toThrow(/task-start/i);
  });

  it("allows writes after markTaskStartSeen", () => {
    markTaskStartSeen();
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
      }),
    ).not.toThrow();
  });

  it("opt-out via STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off", () => {
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "t",
        env: { STONEWRIGHT_DIRECT_REQUIRE_TASK_START: "off" },
      }),
    ).not.toThrow();
  });

  it("re-requires task-start after 30 minutes", () => {
    resetTaskStartSeenForTests();
    markTaskStartSeen("site-a", 0);
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
        site: "site-a",
        now: 29 * 60_000,
      }),
    ).not.toThrow();
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
        site: "site-a",
        now: 31 * 60_000,
      }),
    ).toThrow(/task-start/);
    expect(TASK_START_TTL_MS).toBe(30 * 60_000);
  });

  it("task-start is per site", () => {
    resetTaskStartSeenForTests();
    markTaskStartSeen("site-a", 0);
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
        site: "site-b",
        now: 1000,
      }),
    ).toThrow(/task-start/);
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
        site: "site-a",
        now: 1000,
      }),
    ).not.toThrow();
  });

  it("site-a latch does not unlock unscoped or other-site writes", () => {
    resetTaskStartSeenForTests();
    markTaskStartSeen("site-a", 0);
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
        now: 1000,
      }),
    ).toThrow(/task-start/);
    expect(() =>
      assertWriteAllowed({
        mode: "on",
        destructive: false,
        tool: "stonewright-content-update",
        site: "site-b",
        now: 1000,
      }),
    ).toThrow(/task-start/);
  });

  it("error message mentions 30-minute re-arm", () => {
    expect(() =>
      assertWriteAllowed({ mode: "on", destructive: false, tool: "t" }),
    ).toThrow(/30 minutes/i);
  });

  it("hasTaskStartSeen reflects TTL", () => {
    markTaskStartSeen("site-a", 0);
    expect(hasTaskStartSeen("site-a", TASK_START_TTL_MS)).toBe(true);
    expect(hasTaskStartSeen("site-a", TASK_START_TTL_MS + 1)).toBe(false);
    expect(hasTaskStartSeen("site-b", 1000)).toBe(false);
  });

  it("read payloads get a non-blocking task-start hint before session start", () => {
    const nudged = maybeAttachTaskStartHint(
      { ok: true, items: [] },
      { tool: "stonewright-content-list", site: "site-a", now: 1000 },
    ) as Record<string, unknown>;
    expect(nudged.task_start_hint).toMatch(/stonewright-task-start/);
  });

  it("task-start hint disappears after markTaskStartSeen", () => {
    markTaskStartSeen("site-a", 0);
    const clean = maybeAttachTaskStartHint(
      { ok: true, items: [] },
      { tool: "stonewright-content-list", site: "site-a", now: 1000 },
    ) as Record<string, unknown>;
    expect(clean.task_start_hint).toBeUndefined();
  });

  it("skips hint on task-start tool itself even without latch", () => {
    const result = maybeAttachTaskStartHint(
      { ok: true, context_token: "x" },
      { tool: "stonewright-task-start", now: 1000 },
    ) as Record<string, unknown>;
    expect(result.task_start_hint).toBeUndefined();
  });
});
