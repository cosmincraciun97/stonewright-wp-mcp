import type { ResolvedSite } from "./sites-config.js";
import { createHash } from "node:crypto";
import { cachedPluginDecision, currentDirectCall, noteOverrideUse } from "./plugin-presence.js";
import {
  CONFIRMATION_TTL_MS,
  consumeConfirmation,
  describeConfirmation,
  issueConfirmation,
} from "./confirm-token.js";

export type DirectWriteMode = "on" | "off" | "confirm";

export class DirectSafetyBlockedError extends Error {
  readonly code: string;
  readonly tool: string;
  readonly site: string;
  /** Extra fields for the tool result (for example the confirmation_id). Never written to the audit trail. */
  readonly details: Record<string, unknown>;

  constructor(
    code: string,
    message: string,
    tool: string,
    site = "_global",
    details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = "DirectSafetyBlockedError";
    this.code = code;
    this.tool = tool;
    this.site = site;
    this.details = details;
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

/** A local development address: localhost, 127.0.0.1, *.local, *.test. */
function isLocalDevUrl(siteUrl: string | undefined): boolean {
  if (!siteUrl) return false;
  try {
    const host = new URL(siteUrl).hostname;
    return (
      host === "localhost" ||
      host === "127.0.0.1" ||
      host.endsWith(".local") ||
      host.endsWith(".test")
    );
  } catch {
    return false;
  }
}

export function resolveDirectWriteMode(
  env: NodeJS.ProcessEnv = process.env,
  siteUrl?: string,
): DirectWriteMode {
  const raw = (env["STONEWRIGHT_DIRECT_WRITES"] ?? "").trim().toLowerCase();
  if (raw === "on" || raw === "off" || raw === "confirm") {
    return raw;
  }

  return isLocalDevUrl(siteUrl) ? "on" : "confirm";
}

/**
 * Whether a plain `confirm:true` counts as a confirmation for a site. It does on a local development address
 * and where STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on; STONEWRIGHT_DIRECT_CONFIRM_LEGACY=off refuses it everywhere.
 * Anywhere else the call must carry the confirmation_id that a refused call returns.
 */
export function legacyConfirmAccepted(
  env: NodeJS.ProcessEnv = process.env,
  siteUrl?: string,
): boolean {
  const raw = (env["STONEWRIGHT_DIRECT_CONFIRM_LEGACY"] ?? "").trim().toLowerCase();
  if (raw === "on" || raw === "1" || raw === "true") return true;
  if (raw === "off" || raw === "0" || raw === "false") return false;
  return isLocalDevUrl(siteUrl);
}

function confirmationBinding(site: DirectWriteTarget | undefined, tool: string) {
  return {
    tool,
    site:
      typeof site === "object" && site !== null
        ? directTargetFingerprint(site)
        : siteAlias(site),
    args: currentDirectCall()?.args ?? {},
  };
}

/** Refuse the call unless it carries a valid confirmation; a refusal hands out the id to repeat it with. */
function requireConfirmation(args: {
  tool: string;
  site?: DirectWriteTarget | undefined;
  confirm?: boolean | undefined;
  env: NodeJS.ProcessEnv;
  now: number;
  allowBareConfirm?: boolean | undefined;
  /** The site's address when `site` is only an alias. */
  siteUrl?: string | undefined;
}): void {
  const siteUrl =
    typeof args.site === "object" && args.site !== null ? args.site.url : args.siteUrl;
  const alias = siteAlias(args.site);
  const binding = confirmationBinding(args.site, args.tool);
  const presented = currentDirectCall()?.confirmationId;
  let invalid: string | undefined;
  if (presented) {
    const used = consumeConfirmation(presented, binding, args.now);
    if (used.ok) return;
    invalid = used.reason;
  }
  const bareAccepted =
    args.allowBareConfirm === true ||
    legacyConfirmAccepted(currentDirectCall()?.env ?? args.env, siteUrl);
  if (args.confirm === true && bareAccepted) return;

  const issued = issueConfirmation(binding, args.now);
  const details = {
    confirmation_id: issued.id,
    expires_in_seconds: CONFIRMATION_TTL_MS / 1000,
    summary: describeConfirmation(binding, alias === "" ? "_global" : alias),
  };
  const repeat = "Repeat the same call, unchanged, with this confirmation_id (valid for 10 minutes, one use).";
  if (invalid !== undefined) {
    const why =
      invalid === "expired"
        ? "has expired"
        : invalid === "different_call"
          ? "belongs to a different call (other arguments, site or tool)"
          : "is unknown or was already used";
    throw new DirectSafetyBlockedError(
      "confirmation_invalid",
      `The confirmation_id ${why}, so "${args.tool}" was not run. ${repeat}`,
      args.tool,
      alias,
      { ...details, reason: invalid },
    );
  }
  const bareNote =
    args.confirm === true
      ? " A plain confirm:true is no longer accepted on this site; set STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on to restore it."
      : "";
  throw new DirectSafetyBlockedError(
    "confirmation_required",
    `Destructive Direct tool "${args.tool}" needs a confirmation bound to this exact call (confirm:true on a local development site).${bareNote} ${repeat}`,
    args.tool,
    alias,
    details,
  );
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
  /** The write touches only local state (for example a local skill file), so the site's plugin is irrelevant. */
  localOnly?: boolean;
  /** Ask for a confirmation in every mode, not only in confirm mode (tools that always need one). */
  alwaysConfirm?: boolean;
  /** The caller already holds the confirmation (an internal restore that the agent approved). */
  preConfirmed?: boolean;
  /** A plain confirm:true counts on any site (a local WP-CLI run has no confirmation_id argument). */
  allowBareConfirm?: boolean;
  /** The site's address when `site` is only an alias; a plain confirm:true is judged by it. */
  siteUrl?: string;
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
  // A fresh "plugin is active" answer refuses the write before any tool work
  // starts. The REST client repeats the check and also fails closed when the
  // answer is unknown (see plugin-guard.ts).
  if (
    args.localOnly !== true &&
    typeof args.site === "object" &&
    args.site !== null &&
    args.site.url
  ) {
    const decision = cachedPluginDecision(args.site.url, env, now);
    if (decision.action === "block") {
      throw new DirectSafetyBlockedError(
        decision.code,
        decision.message,
        args.tool,
        siteAlias(args.site),
      );
    }
    if (decision.action === "override") {
      noteOverrideUse({
        tool: args.tool,
        alias: siteAlias(args.site),
        url: args.site.url,
        presence: decision.presence,
        env,
      });
    }
  }
  if (
    args.destructive &&
    (args.mode === "confirm" || args.alwaysConfirm === true) &&
    args.preConfirmed !== true
  ) {
    requireConfirmation({
      tool: args.tool,
      site: args.site,
      confirm: args.confirm,
      env,
      now,
      allowBareConfirm: args.allowBareConfirm,
      siteUrl: args.siteUrl,
    });
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
