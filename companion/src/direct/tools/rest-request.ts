import { assertToolEnabled } from "../writes.js";
import type { DirectToolContext } from "./types.js";

/**
 * Read-only REST passthrough for discovered plugin namespaces.
 * Writes must use typed Direct tools or WP-CLI — never arbitrary POST/PUT/PATCH/DELETE here.
 */
export async function restRequest(
  ctx: DirectToolContext,
  input: {
    method?: "GET";
    path: string;
    query?: Record<string, string | number | boolean> | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-rest-request");
  if (
    !input.path.startsWith("/") ||
    input.path.includes("..") ||
    /^https?:/i.test(input.path)
  ) {
    throw new Error(
      'path must be a REST route starting with "/", e.g. /custom-plugin/v1/items',
    );
  }
  const method = input.method ?? "GET";
  if (method !== "GET") {
    throw new Error(
      "stonewright-rest-request is read-only (GET only). Use typed Direct tools or WP-CLI for writes.",
    );
  }
  const opts: {
    query?: Record<string, string | number | boolean | null | undefined>;
  } = {};
  if (input.query !== undefined) {
    opts.query = input.query;
  }
  return ctx.client.get(input.path, opts);
}
