import http from "node:http";
import https from "node:https";
import { isIP } from "node:net";
import { Readable } from "node:stream";
import { createBrotliDecompress, createGunzip, createInflate } from "node:zlib";

/**
 * The transport of `stonewright-media-upload url`.
 *
 * The caller resolves the host once and checks every address. This request then connects to
 * exactly those addresses: the `lookup` of the connection answers from the validated list and
 * never asks DNS again, so a name that answers differently a second time cannot move the
 * connection to a private address. The URL keeps its host name, so the Host header, the TLS
 * server name (SNI) and the certificate check all use the original name.
 */

export interface PinnedAddress {
  address: string;
  family: number;
}

const NO_BODY_STATUSES = new Set([204, 205, 304]);

function toHeaders(raw: http.IncomingHttpHeaders): Headers {
  const headers = new Headers();
  for (const [name, value] of Object.entries(raw)) {
    if (value === undefined) continue;
    for (const item of Array.isArray(value) ? value : [value]) {
      try {
        headers.append(name, item);
      } catch {
        // A header the Headers class cannot hold is not one this download reads.
      }
    }
  }
  return headers;
}

function decoder(encoding: string | undefined) {
  switch ((encoding ?? "").trim().toLowerCase()) {
    case "gzip":
    case "x-gzip":
      return createGunzip();
    case "deflate":
      return createInflate();
    case "br":
      return createBrotliDecompress();
    default:
      return null;
  }
}

/**
 * One GET to `url`, connected to one of `addresses`. Redirects are returned, not followed.
 * Resolves on the response head; the body streams from the socket.
 */
export function pinnedRequest(
  url: URL,
  addresses: readonly PinnedAddress[],
  signal: AbortSignal,
): Promise<Response> {
  const pinned = addresses.map((a) => ({ address: a.address, family: a.family }));
  const first = pinned[0];
  if (!first) {
    return Promise.reject(new Error("no validated address to connect to"));
  }
  const secure = url.protocol === "https:";
  const hostname = url.hostname.replace(/^\[|\]$/g, "");
  const lookup = (
    _host: string,
    options: { all?: boolean } | undefined,
    callback: (...args: unknown[]) => void,
  ): void => {
    if (options?.all) callback(null, pinned);
    else callback(null, first.address, first.family);
  };
  const options: https.RequestOptions = {
    protocol: url.protocol,
    hostname,
    ...(url.port !== "" ? { port: Number(url.port) } : {}),
    path: `${url.pathname}${url.search}`,
    method: "GET",
    headers: {
      host: url.host,
      accept: "*/*",
      "accept-encoding": "gzip, deflate, br",
    },
    // A fresh connection per request: nothing from an earlier connection is reused.
    agent: false,
    lookup: lookup as never,
    signal,
    ...(secure && isIP(hostname) === 0 ? { servername: hostname } : {}),
  };

  return new Promise<Response>((resolve, reject) => {
    const request = (secure ? https : http).request(options, (incoming) => {
      const status = incoming.statusCode ?? 502;
      const headers = toHeaders(incoming.headers);
      if (NO_BODY_STATUSES.has(status)) {
        incoming.resume();
        resolve(new Response(null, { status, headers }));
        return;
      }
      const unzip = decoder(incoming.headers["content-encoding"]);
      let source: Readable = incoming;
      if (unzip) {
        headers.delete("content-encoding");
        headers.delete("content-length");
        incoming.on("error", (error) => unzip.destroy(error));
        source = incoming.pipe(unzip);
      }
      resolve(
        new Response(Readable.toWeb(source) as unknown as ReadableStream<Uint8Array>, {
          status,
          ...(incoming.statusMessage ? { statusText: incoming.statusMessage } : {}),
          headers,
        }),
      );
    });
    request.on("error", reject);
    request.end();
  });
}
