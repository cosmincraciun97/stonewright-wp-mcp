import type { ResolvedSite } from "./sites-config.js";

export type DirectWriteMode = "on" | "off" | "confirm";

/** Match plugin context-token TTL. */
export const TASK_START_TTL_MS = 30 * 60_000;

const DEFAULT_SITE_KEY = "_default";

/** Per-site timestamp of last successful stonewright-task-start. */
let taskStartSeenAt: Record<string, number> = {};

function siteKey(site?: string): string {
  const trimmed = (site ?? "").trim();
  return trimmed !== "" ? trimmed : DEFAULT_SITE_KEY;
}

/**
 * Record that task-start ran for a site. Optional `now` is for tests.
 */
export function markTaskStartSeen(
  site?: string,
  now: number = Date.now(),
): void {
  taskStartSeenAt[siteKey(site)] = now;
}

export function resetTaskStartSeenForTests(): void {
  taskStartSeenAt = {};
}

/**
 * True when task-start was seen for the site within the 30-minute TTL.
 * When `site` is omitted, only the default (unscoped) latch is checked —
 * never "any site" — so multi-site clients must pass the resolved alias.
 */
export function hasTaskStartSeen(
  site?: string,
  now: number = Date.now(),
): boolean {
  const seenAt = taskStartSeenAt[siteKey(site)];
  return seenAt !== undefined && now - seenAt <= TASK_START_TTL_MS;
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
  /** Site alias; when omitted only the unscoped default latch unlocks. */
  site?: string;
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
    throw new Error(
      "Call stonewright-task-start before write tools (it loads this site's skills, memory, and recurring errors). Then retry this call. It also re-arms 30 minutes after the last task-start.",
    );
  }
  if (args.mode === "off") {
    throw new Error(
      `Direct writes are disabled (STONEWRIGHT_DIRECT_WRITES=off). Tool: ${args.tool}`,
    );
  }
  if (args.mode === "confirm" && args.destructive && args.confirm !== true) {
    throw new Error(
      `Destructive Direct tool "${args.tool}" requires confirm:true when STONEWRIGHT_DIRECT_WRITES=confirm (or remote sites).`,
    );
  }
}

export function assertToolEnabled(site: ResolvedSite, tool: string): void {
  if (site.disabledTools.includes(tool)) {
    throw new Error(
      `Tool "${tool}" is disabled for site "${site.alias}" via sites.json disabledTools.`,
    );
  }
}
