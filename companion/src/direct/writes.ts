import type { ResolvedSite } from "./sites-config.js";
import { createHash } from "node:crypto";

export type DirectWriteMode = "on" | "off" | "confirm";

export class DirectSafetyBlockedError extends Error {
  readonly code: string;
  readonly tool: string;
  readonly site: string;

  constructor(code: string, message: string, tool: string, site = "_global") {
    super(message);
    this.name = "DirectSafetyBlockedError";
    this.code = code;
    this.tool = tool;
    this.site = site;
  }
}

/** Match plugin context-token TTL. */
export const TASK_START_TTL_MS = 30 * 60_000;

const DEFAULT_SITE_KEY = "_default";

type DirectWriteTarget = string | Pick<ResolvedSite, "alias" | "url" | "siteId">;

type TaskStartLatch = {
  seenAt: number;
  targetFingerprint: string | null;
};

/** Per-alias binding of the last successful stonewright-task-start. */
let taskStartLatches: Record<string, TaskStartLatch> = {};

function siteAlias(site?: DirectWriteTarget): string {
  const raw = typeof site === "string" ? site : site?.alias;
  return (raw ?? "").trim();
}

function siteKey(site?: DirectWriteTarget): string {
  const trimmed = siteAlias(site);
  return trimmed !== "" ? trimmed : DEFAULT_SITE_KEY;
}

export function directTargetFingerprint(
  site: Pick<ResolvedSite, "url" | "siteId">,
): string {
  const canonicalUrl = site.url.replace(/\/+$/, "");
  return createHash("sha256")
    .update(`${site.siteId ?? ""}|${canonicalUrl}|1`)
    .digest("hex");
}

/**
 * Record that task-start ran for a site. Optional `now` is for tests.
 */
export function markTaskStartSeen(
  site?: DirectWriteTarget,
  now: number = Date.now(),
): void {
  taskStartLatches[siteKey(site)] = {
    seenAt: now,
    targetFingerprint:
      typeof site === "object" && site !== null
        ? directTargetFingerprint(site)
        : null,
  };
}

export function resetTaskStartSeenForTests(): void {
  taskStartLatches = {};
}

/**
 * True when task-start was seen for the site within the 30-minute TTL.
 * When `site` is omitted, only the default (unscoped) latch is checked —
 * never "any site" — so multi-site clients must pass the resolved alias.
 */
export function hasTaskStartSeen(
  site?: DirectWriteTarget,
  now: number = Date.now(),
): boolean {
  const latch = taskStartLatches[siteKey(site)];
  if (!latch || now - latch.seenAt > TASK_START_TTL_MS) return false;
  if (typeof site !== "object" || site === null) return true;
  return latch.targetFingerprint === directTargetFingerprint(site);
}

export function resolveDirectWriteMode(
  env: NodeJS.ProcessEnv = process.env,
  siteUrl?: string,
): DirectWriteMode {
  const raw = (env["STONEWRIGHT_DIRECT_WRITES"] ?? "").trim().toLowerCase();
  if (raw === "on" || raw === "off" || raw === "confirm") {
    return raw;
  }

  if (siteUrl) {
    try {
      const host = new URL(siteUrl).hostname;
      if (
        host === "localhost" ||
        host === "127.0.0.1" ||
        host.endsWith(".local") ||
        host.endsWith(".test")
      ) {
        return "on";
      }
    } catch {
      // fall through
    }
  }

  return "confirm";
}

export function assertWriteAllowed(args: {
  mode: DirectWriteMode;
  destructive: boolean;
  confirm?: boolean | undefined;
  tool: string;
  env?: NodeJS.ProcessEnv;
  /** Resolved target; writes bind the alias to its canonical URL/site identity. */
  site?: DirectWriteTarget;
  /** Injectable clock for TTL tests. */
  now?: number;
}): void {
  const env = args.env ?? process.env;
  const requireTaskStart =
    (env["STONEWRIGHT_DIRECT_REQUIRE_TASK_START"] ?? "on")
      .trim()
      .toLowerCase() !== "off";
  const now = args.now ?? Date.now();
  if (requireTaskStart && !hasTaskStartSeen(args.site, now)) {
    throw new DirectSafetyBlockedError(
      "task_start_required",
      "Call stonewright-task-start before write tools (it loads this site's skills, memory, and recurring errors). Then retry this call. It also re-arms 30 minutes after the last task-start.",
      args.tool,
      siteAlias(args.site),
    );
  }
  if (args.mode === "off") {
    throw new DirectSafetyBlockedError(
      "direct_writes_disabled",
      `Direct writes are disabled (STONEWRIGHT_DIRECT_WRITES=off). Tool: ${args.tool}`,
      args.tool,
      siteAlias(args.site),
    );
  }
  if (args.mode === "confirm" && args.destructive && args.confirm !== true) {
    throw new DirectSafetyBlockedError(
      "confirmation_required",
      `Destructive Direct tool "${args.tool}" requires confirm:true when STONEWRIGHT_DIRECT_WRITES=confirm (or remote sites).`,
      args.tool,
      siteAlias(args.site),
    );
  }
}

export function assertToolEnabled(site: ResolvedSite, tool: string): void {
  if (site.disabledTools.includes(tool)) {
    throw new DirectSafetyBlockedError(
      "tool_disabled",
      `Tool "${tool}" is disabled for site "${site.alias}" via sites.json disabledTools.`,
      tool,
      site.alias,
    );
  }
}
