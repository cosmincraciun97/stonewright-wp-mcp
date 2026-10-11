import { lookup as dnsLookup } from "node:dns/promises";
import { realpathSync, statSync } from "node:fs";
import { isIP } from "node:net";
import { isAbsolute, join, relative, resolve, sep } from "node:path";
import { defaultStateDir } from "./audit.js";
import { mediaBlocked, mediaTooLarge } from "./media-limits.js";

/**
 * Where `stonewright-media-upload` may read its bytes from.
 *
 * - A local `path` must resolve (symlinks and `..` included) to a file inside
 *   the working directory, the companion media folder
 *   (`<state dir>/media`) or a root listed in `STONEWRIGHT_DIRECT_MEDIA_ROOTS`.
 *   Any other file needs an explicit `confirm: true`.
 * - A `url` must be http or https and must resolve only to public addresses.
 *   Redirects are followed by hand so each hop is checked the same way.
 */

export const MEDIA_ROOTS_ENV = "STONEWRIGHT_DIRECT_MEDIA_ROOTS";
const MAX_REDIRECTS = 5;
const REDIRECT_STATUSES = new Set([301, 302, 303, 307, 308]);
const DOWNLOAD_TIMEOUT_MS = 60_000;

export type MediaLookup = (
  hostname: string,
) => Promise<Array<{ address: string; family: number }>>;

const blocked = mediaBlocked;

// --- local paths -----------------------------------------------------------

function realOrResolved(path: string): string {
  const absolute = resolve(path);
  try {
    return realpathSync(absolute);
  } catch {
    return absolute;
  }
}

export function allowedMediaRoots(
  env: NodeJS.ProcessEnv,
  cwd: string,
): string[] {
  const configured = (env[MEDIA_ROOTS_ENV] ?? "")
    .split(/[;,]/)
    .map((item) => item.trim())
    .filter(Boolean);
  return [cwd, join(defaultStateDir(env), "media"), ...configured].map(
    realOrResolved,
  );
}

function isInside(candidate: string, root: string): boolean {
  const rel = relative(root, candidate);
  if (rel === "") return true;
  if (rel === ".." || rel.startsWith(`..${sep}`) || isAbsolute(rel)) return false;
  return true;
}

/**
 * Returns the real path to read. Throws unless the file is allowed, is a file
 * and is no larger than `maxBytes` (measured, not read).
 */
export function resolveLocalMediaPath(
  inputPath: string,
  opts: {
    env: NodeJS.ProcessEnv;
    cwd: string;
    confirm?: boolean | undefined;
    maxBytes?: number | undefined;
  },
): string {
  const requested = resolve(opts.cwd, inputPath);
  let real: string;
  let exists = true;
  try {
    real = realpathSync(requested);
  } catch {
    real = requested;
    exists = false;
  }
  const inside = allowedMediaRoots(opts.env, opts.cwd).some((root) =>
    isInside(real, root),
  );
  if (!inside && opts.confirm !== true) {
    throw blocked(
      `media-upload path is outside the allowed folders (the working directory, the companion media folder, and ${MEDIA_ROOTS_ENV}). Move the file into one of them, or pass confirm:true when the user approved this file.`,
      "media_path_outside_allowed_roots",
    );
  }
  if (!exists) {
    throw blocked("media-upload path does not exist.", "media_path_not_found");
  }
  const stats = statSync(real);
  if (!stats.isFile()) {
    throw blocked("media-upload path is not a file.", "media_path_not_a_file");
  }
  if (opts.maxBytes !== undefined && stats.size > opts.maxBytes) {
    throw mediaTooLarge(opts.maxBytes);
  }
  return real;
}

// --- addresses -------------------------------------------------------------

function parseIpv4(value: string): number[] | null {
  const parts = value.split(".");
  if (parts.length !== 4) return null;
  const octets = parts.map((p) => (/^\d{1,3}$/.test(p) ? Number(p) : NaN));
  return octets.every((o) => o >= 0 && o <= 255) ? octets : null;
}

function parseIpv6(value: string): number[] | null {
  let text = value.split("%")[0] ?? "";
  if (text.includes(".")) {
    const cut = text.lastIndexOf(":");
    const v4 = parseIpv4(text.slice(cut + 1));
    if (!v4) return null;
    text = `${text.slice(0, cut + 1)}${(((v4[0] ?? 0) << 8) | (v4[1] ?? 0)).toString(16)}:${(((v4[2] ?? 0) << 8) | (v4[3] ?? 0)).toString(16)}`;
  }
  const halves = text.split("::");
  if (halves.length > 2) return null;
  const head = halves[0] ? halves[0].split(":") : [];
  const tail = halves.length === 2 && halves[1] ? halves[1].split(":") : [];
  let groups: string[];
  if (halves.length === 1) {
    if (head.length !== 8) return null;
    groups = head;
  } else {
    const fill = 8 - head.length - tail.length;
    if (fill < 1) return null;
    groups = [...head, ...Array<string>(fill).fill("0"), ...tail];
  }
  const numbers = groups.map((g) => (/^[0-9a-f]{1,4}$/i.test(g) ? parseInt(g, 16) : NaN));
  return numbers.every((n) => Number.isInteger(n)) ? numbers : null;
}

function publicIpv4(o: number[]): boolean {
  const [a = 0, b = 0, c = 0] = o;
  if (a === 0 || a === 10 || a === 127) return false;
  if (a === 100 && b >= 64 && b <= 127) return false; // carrier-grade NAT
  if (a === 169 && b === 254) return false; // link-local, cloud metadata
  if (a === 172 && b >= 16 && b <= 31) return false;
  if (a === 192 && b === 0 && c === 0) return false;
  if (a === 192 && b === 168) return false;
  if (a === 198 && (b === 18 || b === 19)) return false; // benchmarking
  if (a >= 224) return false; // multicast, reserved, broadcast
  return true;
}

function publicIpv6(g: number[]): boolean {
  const [g0 = 0, g1 = 0, g2 = 0, g3 = 0, g4 = 0, g5 = 0, g6 = 0, g7 = 0] = g;
  const embedded = (hi: number, lo: number): number[] => [hi >> 8, hi & 255, lo >> 8, lo & 255];
  if (g0 === 0 && g1 === 0 && g2 === 0 && g3 === 0 && g4 === 0) {
    // ::, ::1, IPv4-compatible (::a.b.c.d) and IPv4-mapped (::ffff:a.b.c.d)
    if (g5 === 0 || g5 === 0xffff) return publicIpv4(embedded(g6, g7));
  }
  if (g0 === 0x64 && g1 === 0xff9b && g2 === 0 && g3 === 0 && g4 === 0 && g5 === 0) {
    return publicIpv4(embedded(g6, g7)); // NAT64
  }
  if (g0 === 0x2002) return publicIpv4(embedded(g1, g2)); // 6to4
  if ((g0 & 0xffc0) === 0xfe80) return false; // link-local
  if ((g0 & 0xffc0) === 0xfec0) return false; // site-local (deprecated)
  if ((g0 & 0xfe00) === 0xfc00) return false; // unique local
  if ((g0 >> 8) === 0xff) return false; // multicast
  if (g0 === 0x2001 && (g1 === 0 || g1 === 0xdb8)) return false; // Teredo, documentation
  if (g0 === 0x100 && g1 === 0 && g2 === 0 && g3 === 0) return false; // discard-only
  return true;
}

/** True only for an address that is routable on the public internet. */
export function isPublicAddress(address: string): boolean {
  const family = isIP(address);
  if (family === 4) {
    const octets = parseIpv4(address);
    return octets !== null && publicIpv4(octets);
  }
  if (family === 6) {
    const groups = parseIpv6(address);
    return groups !== null && publicIpv6(groups);
  }
  return false;
}

const defaultLookup: MediaLookup = async (hostname) =>
  dnsLookup(hostname, { all: true, verbatim: true });

async function assertPublicUrl(
  url: URL,
  lookup: MediaLookup,
): Promise<void> {
  if (url.protocol !== "http:" && url.protocol !== "https:") {
    throw blocked(
      "media-upload url must use http or https.",
      "media_url_scheme_not_allowed",
    );
  }
  const notPublic = (): Error =>
    blocked(
      "media-upload url does not point at a public internet address (private, loopback, link-local and multicast addresses are refused).",
      "media_url_address_not_public",
    );
  const host = url.hostname.replace(/^\[|\]$/g, "");
  if (isIP(host) !== 0) {
    if (!isPublicAddress(host)) throw notPublic();
    return;
  }
  let answers: Array<{ address: string; family: number }>;
  try {
    answers = await lookup(host);
  } catch {
    throw blocked("media-upload url host did not resolve.", "media_url_unresolved");
  }
  if (answers.length === 0) {
    throw blocked("media-upload url host did not resolve.", "media_url_unresolved");
  }
  if (!answers.every((a) => isPublicAddress(a.address))) throw notPublic();
}

// --- download --------------------------------------------------------------

function parseMediaUrl(raw: string, base?: string): URL {
  try {
    return new URL(raw, base);
  } catch {
    throw blocked("media-upload url is not a valid URL.", "media_url_invalid");
  }
}

/** Keeps the tool's own refusals and turns a timeout or reset into one stable code. */
function downloadFailure(error: unknown): Error {
  if (error instanceof Error && /code=media_/.test(error.message)) return error;
  const name = error instanceof Error ? error.name : "";
  if (name === "TimeoutError" || name === "AbortError") {
    return blocked(
      "media-upload url download timed out.",
      "media_url_timeout",
    );
  }
  return new Error(
    `Failed to download media from URL (${error instanceof Error ? error.message.slice(0, 120) : "network error"})`,
  );
}

/** Reads the body, stopping as soon as it passes `maxBytes`. */
async function readBounded(response: Response, maxBytes: number): Promise<Buffer> {
  if (!response.body) {
    const all = Buffer.from(await response.arrayBuffer());
    if (all.length > maxBytes) throw mediaTooLarge(maxBytes);
    return all;
  }
  const body = response.body as ReadableStream<Uint8Array>;
  const reader = body.getReader();
  const chunks: Buffer[] = [];
  let total = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > maxBytes) {
      await reader.cancel().catch(() => undefined);
      throw mediaTooLarge(maxBytes);
    }
    chunks.push(Buffer.from(value.buffer, value.byteOffset, value.byteLength));
  }
  return Buffer.concat(chunks, total);
}

export async function downloadMediaUrl(
  rawUrl: string,
  opts: {
    fetchImpl?: typeof fetch | undefined;
    lookupImpl?: MediaLookup | undefined;
    maxBytes?: number | undefined;
  } = {},
): Promise<{ bytes: Buffer; finalUrl: URL; contentType: string }> {
  const fetchImpl = opts.fetchImpl ?? fetch;
  const lookup = opts.lookupImpl ?? defaultLookup;
  const maxBytes = opts.maxBytes ?? Number.MAX_SAFE_INTEGER;
  // One deadline for every hop and the body.
  const signal = AbortSignal.timeout(DOWNLOAD_TIMEOUT_MS);
  let current = parseMediaUrl(rawUrl);
  for (let hop = 0; hop <= MAX_REDIRECTS; hop += 1) {
    await assertPublicUrl(current, lookup);
    let response: Response;
    try {
      response = await fetchImpl(current.toString(), {
        redirect: "manual",
        signal,
      });
    } catch (error) {
      throw downloadFailure(error);
    }
    const location = response.headers.get("location");
    if (REDIRECT_STATUSES.has(response.status) && location) {
      await response.body?.cancel().catch(() => undefined);
      current = parseMediaUrl(location, current.toString());
      continue;
    }
    if (!response.ok) {
      await response.body?.cancel().catch(() => undefined);
      throw new Error(
        `Failed to download media from URL (HTTP ${response.status})`,
      );
    }
    const declared = Number(response.headers.get("content-length"));
    if (Number.isFinite(declared) && declared > maxBytes) {
      await response.body?.cancel().catch(() => undefined);
      throw mediaTooLarge(maxBytes);
    }
    let bytes: Buffer;
    try {
      bytes = await readBounded(response, maxBytes);
    } catch (error) {
      throw downloadFailure(error);
    }
    return {
      bytes,
      finalUrl: current,
      contentType: response.headers.get("content-type") ?? "",
    };
  }
  throw blocked(
    "media-upload url redirected too many times.",
    "media_url_too_many_redirects",
  );
}
