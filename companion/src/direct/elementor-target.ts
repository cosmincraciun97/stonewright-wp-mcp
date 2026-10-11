import type { runWpCli } from "../wp-cli.js";

/**
 * A configured alias points at one WordPress site, but WP-CLI runs against
 * whichever install the working directory or `STONEWRIGHT_WP_ROOT` selects.
 * Before Direct reads or writes Elementor data through WP-CLI for an alias, it
 * asks that install for its `home` (then `siteurl`) and compares it with the
 * alias URL. A different or unreadable address means WP-CLI is not used.
 */

export type LocalTargetCheck = { ok: true } | { ok: false; message: string };

export const LOCAL_TARGET_MISMATCH =
  "WP-CLI was not used: the local WordPress is not the target site. Direct used core REST for this alias instead.";
export const LOCAL_TARGET_UNCONFIRMED =
  "WP-CLI was not used: the local WordPress could not be confirmed as the target site. Direct used core REST for this alias instead.";

type CliRunner = typeof runWpCli;

// One answer per alias URL and local install, for the life of the process. Only
// a definite answer is cached: a failed read is retried on the next call.
const cache = new Map<string, LocalTargetCheck>();

export function resetLocalTargetCacheForTests(): void {
  cache.clear();
}

/** host[:port]/path, scheme ignored, default ports dropped, trailing slash dropped. */
function siteKey(value: string): string | null {
  try {
    const url = new URL(value.trim());
    if (url.protocol !== "http:" && url.protocol !== "https:") return null;
    const path = url.pathname.replace(/\/+$/, "");
    const port =
      url.port !== "" && url.port !== "80" && url.port !== "443"
        ? `:${url.port}`
        : "";
    return `${url.hostname.toLowerCase()}${port}${path}`;
  } catch {
    return null;
  }
}

async function readOption(
  name: "home" | "siteurl",
  base: { cwd?: string; path?: string; url?: string },
  cli: CliRunner,
  env: NodeJS.ProcessEnv,
): Promise<string | null> {
  const out = await cli(
    { command: ["option", "get", name], ...base },
    undefined,
    env,
  );
  if (out.available === false || !out.ok) return null;
  const line = String((out as { stdout?: string }).stdout ?? "")
    .split(/\r?\n/)
    .map((l) => l.trim())
    .find((l) => /^https?:\/\//i.test(l));
  return line ?? null;
}

export async function checkLocalWpTarget(
  env: NodeJS.ProcessEnv,
  target: { url: string },
  base: { cwd?: string; path?: string; url?: string },
  cli: CliRunner,
): Promise<LocalTargetCheck> {
  const cacheKey = [
    target.url,
    base.cwd ?? "",
    base.path ?? "",
    base.url ?? "",
    env["STONEWRIGHT_WP_ROOT"] ?? "",
  ].join("|");
  const cached = cache.get(cacheKey);
  if (cached) return cached;

  const wanted = siteKey(target.url);
  if (wanted === null) return { ok: false, message: LOCAL_TARGET_UNCONFIRMED };

  const home = await readOption("home", base, cli, env);
  if (home === null) return { ok: false, message: LOCAL_TARGET_UNCONFIRMED };
  let answer: LocalTargetCheck = siteKey(home) === wanted
    ? { ok: true }
    : { ok: false, message: LOCAL_TARGET_MISMATCH };

  if (!answer.ok) {
    const siteurl = await readOption("siteurl", base, cli, env);
    if (siteurl !== null && siteKey(siteurl) === wanted) {
      answer = { ok: true };
    }
  }
  cache.set(cacheKey, answer);
  return answer;
}
