import { createHmac, randomBytes, timingSafeEqual } from "node:crypto";
import { stableJson } from "./verify.js";

/**
 * Confirmations bound to the exact call, local to the companion.
 *
 * A destructive Direct call that carries no valid confirmation is refused with a `confirmation_id`. The id is an
 * HMAC (a secret that exists only in this process) over the tool name, the site and the canonical arguments. It is
 * kept in memory for ten minutes and works once, for that call only: another argument, another site or another tool
 * does not match it, and a restart of the companion drops every id.
 */

export const CONFIRMATION_TTL_MS = 10 * 60_000;

/** Ids waiting to be used. The oldest are dropped first when the table is full. */
const MAX_PENDING = 200;

/** Keys that are not part of what is being confirmed. */
const UNBOUND_KEYS = new Set(["confirm", "confirmation_id", "site"]);

export type ConfirmationBinding = {
  tool: string;
  /** A fingerprint of the resolved site, or its alias when no site was resolved. */
  site: string;
  args: Record<string, unknown>;
};

type Pending = { tool: string; site: string; argsHash: string; expiresAt: number };

export type ConsumeResult =
  | { ok: true }
  | { ok: false; reason: "unknown" | "expired" | "different_call" };

const secret = randomBytes(32);
const pending = new Map<string, Pending>();

export function canonicalConfirmationArgs(args: Record<string, unknown>): string {
  const bound: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(args)) {
    if (!UNBOUND_KEYS.has(key)) bound[key] = value;
  }
  return stableJson(bound);
}

function sign(binding: ConfirmationBinding, argsHash: string): string {
  return createHmac("sha256", secret)
    .update(stableJson([binding.tool, binding.site, argsHash]))
    .digest("hex")
    .slice(0, 32);
}

function prune(now: number): void {
  for (const [id, entry] of pending) {
    if (now > entry.expiresAt) pending.delete(id);
  }
  while (pending.size >= MAX_PENDING) {
    const oldest = pending.keys().next().value;
    if (oldest === undefined) break;
    pending.delete(oldest);
  }
}

export function issueConfirmation(
  binding: ConfirmationBinding,
  now: number = Date.now(),
): { id: string; expiresAt: number } {
  const argsHash = canonicalConfirmationArgs(binding.args);
  const id = sign(binding, argsHash);
  prune(now);
  const expiresAt = now + CONFIRMATION_TTL_MS;
  pending.delete(id);
  pending.set(id, { tool: binding.tool, site: binding.site, argsHash, expiresAt });
  return { id, expiresAt };
}

function sameId(a: string, b: string): boolean {
  const left = Buffer.from(a);
  const right = Buffer.from(b);
  return left.length === right.length && timingSafeEqual(left, right);
}

/**
 * Check an id against the call that presents it. It is used up only when it matches: a wrong attempt leaves the
 * id valid for the call it was issued for.
 */
export function consumeConfirmation(
  id: string,
  binding: ConfirmationBinding,
  now: number = Date.now(),
): ConsumeResult {
  const entry = pending.get(id);
  if (!entry) return { ok: false, reason: "unknown" };
  if (now > entry.expiresAt) {
    pending.delete(id);
    return { ok: false, reason: "expired" };
  }
  const argsHash = canonicalConfirmationArgs(binding.args);
  if (
    entry.tool !== binding.tool ||
    entry.site !== binding.site ||
    entry.argsHash !== argsHash ||
    !sameId(sign(binding, argsHash), id)
  ) {
    return { ok: false, reason: "different_call" };
  }
  pending.delete(id);
  return { ok: true };
}

function brief(value: unknown): string {
  if (typeof value === "string") {
    const flat = value.replace(/\s+/g, " ").trim();
    return JSON.stringify(flat.length > 60 ? `${flat.slice(0, 57)}...` : flat);
  }
  if (Array.isArray(value)) return `[${value.length} item${value.length === 1 ? "" : "s"}]`;
  if (value !== null && typeof value === "object") {
    const keys = Object.keys(value as Record<string, unknown>).length;
    return `{${keys} key${keys === 1 ? "" : "s"}}`;
  }
  return String(value);
}

/** Argument names whose value is never repeated in the summary. */
const HIDDEN_VALUE_KEY = /pass|secret|token|key|auth|cookie/i;

/** A plain sentence about what the repeated call will do. Values are shortened and never listed in full. */
export function describeConfirmation(binding: ConfirmationBinding, siteLabel: string): string {
  const entries = Object.entries(binding.args)
    .filter(([key, value]) => !UNBOUND_KEYS.has(key) && value !== undefined)
    .slice(0, 12)
    .map(([key, value]) => `${key}=${HIDDEN_VALUE_KEY.test(key) ? '[hidden]' : brief(value)}`);
  const withArgs = entries.length > 0 ? ` with ${entries.join(", ")}` : "";
  return `This will run ${binding.tool} on site "${siteLabel}"${withArgs}. It changes or removes content on that site.`;
}

export function resetConfirmationsForTests(): void {
  pending.clear();
}
