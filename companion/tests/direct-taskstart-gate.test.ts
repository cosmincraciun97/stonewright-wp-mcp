import { describe, expect, it, beforeEach, afterEach, vi } from "vitest";
import { maybeAttachTaskStartHint } from "../src/direct/registry.js";
import { taskStart, type SelfImproveContext } from "../src/direct/tools/self-improve.js";
import { appPasswordCreate } from "../src/direct/tools/users.js";
import { contentUpdate } from "../src/direct/tools/content.js";
import { WpRestClient } from "../src/direct/wp-rest-client.js";
import type { ResolvedSite, SitesConfig } from "../src/direct/sites-config.js";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import {
  assertWriteAllowed,
  hasTaskStartSeen,
  markTaskStartSeen,
  resetTaskStartSeenForTests,
  TASK_START_TTL_MS,
} from "../src/direct/writes.js";

describe("direct task-start write gate", () => {
  const tempDirs: string[] = [];

  beforeEach(() => {
    resetTaskStartSeenForTests();
  });
  afterEach(() => {
    resetTaskStartSeenForTests();
    for (const dir of tempDirs.splice(0)) rmSync(dir, { recursive: true, force: true });
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

  it("blocks an application-password write when the task-start alias is repointed within the TTL", async () => {
    const baseDir = mkdtempSync(join(tmpdir(), "stonewright-task-start-retarget-"));
    tempDirs.push(baseDir);
    const originalSite: ResolvedSite = {
      alias: "site-a",
      url: "https://old.example.test",
      restBase: "https://old.example.test/wp-json",
      username: "admin",
      appPassword: "[redacted]",
      disabledTools: [],
      siteId: "site-id-a",
    };
    const repointedSite: ResolvedSite = {
      ...originalSite,
      url: "https://new.example.test",
      restBase: "https://new.example.test/wp-json",
      appPassword: "[redacted]",
    };
    const sitesConfig: SitesConfig = {
      default: "site-a",
      source: "env",
      sites: {
        "site-a": {
          id: originalSite.siteId,
          url: originalSite.url,
          username: originalSite.username,
          appPassword: originalSite.appPassword,
        },
      },
    };
    const startContext: SelfImproveContext = {
      env: { STONEWRIGHT_DIRECT_WRITES: "on" },
      baseDir,
      sitesConfig,
    };
    taskStart(startContext, { task: "rotate application password", site: "site-a" });
    const fetchImpl = vi.fn(() => Promise.resolve(new Response(JSON.stringify({
      uuid: "synthetic-uuid",
      name: "synthetic-client",
      password: "[redacted]",
    }), { status: 201, headers: { "content-type": "application/json" } })));

    await expect(appPasswordCreate({
      client: new WpRestClient(repointedSite, { fetchImpl }),
      site: repointedSite,
      writeMode: "on",
      env: { STONEWRIGHT_DIRECT_WRITES: "on" },
    }, {
      user_id: 9,
      name: "synthetic-client",
      confirm: true,
    })).rejects.toMatchObject({ code: "task_start_required" });
    expect(fetchImpl).not.toHaveBeenCalled();
  });

  it("blocks an ordinary write when the task-start alias is repointed within the TTL", async () => {
    const originalSite: ResolvedSite = {
      alias: "site-a",
      url: "https://old.example.test",
      restBase: "https://old.example.test/wp-json",
      username: "admin",
      appPassword: "[redacted]",
      disabledTools: [],
      siteId: "site-id-a",
    };
    const repointedSite: ResolvedSite = {
      ...originalSite,
      url: "https://new.example.test",
      restBase: "https://new.example.test/wp-json",
      appPassword: "[redacted]",
    };
    markTaskStartSeen(originalSite);
    const fetchImpl = vi.fn(() => Promise.resolve(new Response(JSON.stringify({
      id: 42,
      title: { raw: "Changed" },
    }), { status: 200, headers: { "content-type": "application/json" } })));

    await expect(contentUpdate({
      client: new WpRestClient(repointedSite, { fetchImpl }),
      site: repointedSite,
      writeMode: "on",
    }, {
      id: 42,
      title: "Changed",
    })).rejects.toMatchObject({ code: "task_start_required" });
    expect(fetchImpl).not.toHaveBeenCalled();
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
