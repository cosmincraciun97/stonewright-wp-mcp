import { readFileSync } from "node:fs";
import { basename } from "node:path";
import type { WpRestClient } from "../wp-rest-client.js";
import {
  assertToolEnabled,
  assertWriteAllowed,
  type DirectWriteMode,
} from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import { refuseCustomCode, scanTextFields } from "../custom-code-guard.js";
import type { ResolvedSite } from "../sites-config.js";
import {
  assertMediaContent,
  chooseMediaFilename,
  mediaMaxBytes,
  mediaTooLarge,
  mediaTypeFor,
} from "../media-limits.js";
import {
  downloadMediaUrl,
  resolveLocalMediaPath,
  type MediaLookup,
} from "../media-source.js";

export interface MediaToolContext {
  client: WpRestClient;
  site: ResolvedSite;
  writeMode: DirectWriteMode;
  fetchImpl?: typeof fetch | undefined;
  env?: NodeJS.ProcessEnv | undefined;
  /** Working directory for relative upload paths; defaults to the process cwd. */
  cwd?: string | undefined;
  /** DNS lookup for url uploads; defaults to the system resolver. */
  lookupImpl?: MediaLookup | undefined;
}

type WpMedia = {
  id: number;
  slug?: string | undefined;
  source_url?: string | undefined;
  mime_type?: string | undefined;
  alt_text?: string | undefined;
  title?: { rendered?: string; raw?: string } | string;
  caption?: { rendered?: string; raw?: string } | string;
  media_details?: Record<string, unknown> | undefined;
};

function titleOf(item: WpMedia): string {
  if (typeof item.title === "string") return item.title;
  return item.title?.raw ?? item.title?.rendered ?? "";
}

function compactMedia(item: WpMedia) {
  return {
    id: item.id,
    title: titleOf(item),
    slug: item.slug ?? "",
    source_url: item.source_url ?? "",
    mime_type: item.mime_type ?? "",
    alt_text: item.alt_text ?? "",
  };
}

export async function mediaList(
  ctx: MediaToolContext,
  input: {
    search?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-media-list");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const items = await ctx.client.get<WpMedia[]>("/wp/v2/media", {
    query: {
      search: input.search,
      per_page: perPage,
      page,
      _fields: "id,title,slug,source_url,mime_type,alt_text",
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map(compactMedia),
    total: list.length,
    page,
    per_page: perPage,
    next_page: list.length === perPage ? page + 1 : undefined,
  };
}

export async function mediaGet(ctx: MediaToolContext, input: { id: number }) {
  assertToolEnabled(ctx.site, "stonewright-media-get");
  const item = await ctx.client.get<WpMedia>(`/wp/v2/media/${input.id}`);
  return {
    ...compactMedia(item),
    caption:
      typeof item.caption === "string"
        ? item.caption
        : (item.caption?.raw ?? item.caption?.rendered ?? ""),
    media_details: item.media_details ?? {},
  };
}

function decodedBasename(pathname: string): string {
  const last = basename(pathname);
  try {
    return decodeURIComponent(last);
  } catch {
    return last;
  }
}

export async function mediaUpload(
  ctx: MediaToolContext,
  input: {
    path?: string | undefined;
    url?: string | undefined;
    filename?: string | undefined;
    title?: string | undefined;
    alt_text?: string | undefined;
    caption?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-media-upload");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: false,
    tool: "stonewright-media-upload",
  });

  const env = ctx.env ?? process.env;
  // The text goes first: a refused upload downloads and reads nothing.
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-media-upload",
    "media",
    scanTextFields({
      title: input.title,
      alt_text: input.alt_text,
      caption: input.caption,
    }),
    env,
  );
  if (refusal) return refusal;
  const maxBytes = mediaMaxBytes(env);
  let bytes: Buffer;
  let chosen: { filename: string; extension: string };

  if (input.path) {
    const real = resolveLocalMediaPath(input.path, {
      env,
      cwd: ctx.cwd ?? process.cwd(),
      confirm: input.confirm,
      maxBytes,
    });
    // The type is decided from the name before any byte is read.
    chosen = chooseMediaFilename({
      supplied: input.filename,
      source: basename(real),
    });
    bytes = readFileSync(real);
    if (bytes.length > maxBytes) throw mediaTooLarge(maxBytes);
  } else if (input.url) {
    const downloaded = await downloadMediaUrl(input.url, {
      fetchImpl: ctx.fetchImpl,
      lookupImpl: ctx.lookupImpl,
      maxBytes,
    });
    bytes = downloaded.bytes;
    chosen = chooseMediaFilename({
      supplied: input.filename,
      source: decodedBasename(downloaded.finalUrl.pathname),
      contentType: downloaded.contentType,
    });
  } else {
    throw new Error("media-upload requires path or url");
  }
  assertMediaContent(chosen.extension, bytes);

  const form = new FormData();
  form.append(
    "file",
    new Blob([new Uint8Array(bytes)], {
      type: mediaTypeFor(chosen.extension) ?? "",
    }),
    chosen.filename,
  );
  if (input.title) form.append("title", input.title);
  if (input.alt_text) form.append("alt_text", input.alt_text);
  if (input.caption) form.append("caption", input.caption);

  try {
    const item = await ctx.client.post<WpMedia>("/wp/v2/media", {
      body: form,
      rawBody: true,
    });
    appendDirectAudit({
      tool: "stonewright-media-upload",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: `media/${item.id}`,
      status: "ok",
    });
    const compact = compactMedia(item);
    return {
      ...compact,
      alt_text_recommended: !compact.alt_text,
    };
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-media-upload",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: "media",
      status: "error",
    });
    throw err;
  }
}

export async function mediaUpdate(
  ctx: MediaToolContext,
  input: {
    id: number;
    title?: string | undefined;
    alt_text?: string | undefined;
    caption?: string | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-media-update");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: false,
    tool: "stonewright-media-update",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-media-update",
    `media/${input.id}`,
    scanTextFields({
      title: input.title,
      alt_text: input.alt_text,
      caption: input.caption,
    }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const body: Record<string, unknown> = {};
  if (input.title !== undefined) body.title = input.title;
  if (input.alt_text !== undefined) body.alt_text = input.alt_text;
  if (input.caption !== undefined) body.caption = input.caption;

  try {
    const item = await ctx.client.put<WpMedia>(`/wp/v2/media/${input.id}`, {
      body,
    });
    appendDirectAudit({
      tool: "stonewright-media-update",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: `media/${input.id}`,
      status: "ok",
    });
    return compactMedia(item);
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-media-update",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: `media/${input.id}`,
      status: "error",
    });
    throw err;
  }
}

export async function mediaDelete(
  ctx: MediaToolContext,
  input: {
    id: number;
    force?: boolean | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-media-delete");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-media-delete",
  });
  const result = await ctx.client.del(`/wp/v2/media/${input.id}`, {
    query: { force: input.force === true },
  });
  appendDirectAudit({
    tool: "stonewright-media-delete",
    site: ctx.site.alias,
    targetIdentity: ctx.site.url,
    resource: `media/${input.id}`,
    status: "ok",
  });
  return result ?? { deleted: true, id: input.id };
}
