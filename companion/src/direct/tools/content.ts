import type { WpRestClient } from "../wp-rest-client.js";
import {
  assertToolEnabled,
  assertWriteAllowed,
  type DirectWriteMode,
} from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import {
  refuseCustomCode,
  scanMarkupFields,
  scanMeta,
} from "../custom-code-guard.js";
import { storePreImage } from "../preimage.js";
import { resolveRestBase } from "../rest-discovery.js";
import { attachVerification, verifyWrite } from "../verify.js";
import { probeAfterWrite, type ProbeOptions } from "../probe.js";
import type { ResolvedSite } from "../sites-config.js";

export interface ContentToolContext {
  client: WpRestClient;
  site: ResolvedSite;
  writeMode: DirectWriteMode;
  env?: NodeJS.ProcessEnv | undefined;
  fetchImpl?: typeof fetch | undefined;
  lookupImpl?: ((hostname: string) => Promise<Array<{ address: string; family: number }>>) | undefined;
}

/** Custom code a content write would store: markup in title or content, and code or CSS carried in meta. */
function contentCodeFindings(input: {
  title?: string | undefined;
  content?: string | undefined;
  excerpt?: string | undefined;
  meta?: Record<string, unknown> | undefined;
}) {
  return [
    ...scanMarkupFields({
      title: input.title,
      content: input.content,
      excerpt: input.excerpt,
    }),
    ...scanMeta(input.meta),
  ];
}

type WpPost = {
  id: number;
  slug?: string | undefined;
  status?: string | undefined;
  modified?: string | undefined;
  link?: string | undefined;
  type?: string | undefined;
  title?: { rendered?: string; raw?: string } | string;
  content?: { rendered?: string; raw?: string } | string;
  excerpt?: { rendered?: string; raw?: string } | string;
  parent?: number | undefined;
  template?: string | undefined;
  meta?: Record<string, unknown> | undefined;
};

function titleOf(post: WpPost): string {
  if (typeof post.title === "string") {
    return post.title;
  }
  return post.title?.raw ?? post.title?.rendered ?? "";
}

function compactPost(post: WpPost) {
  return {
    id: post.id,
    title: titleOf(post),
    slug: post.slug ?? "",
    status: post.status ?? "",
    modified: post.modified ?? "",
    link: post.link ?? "",
    type: post.type ?? "",
  };
}

async function collectionFor(
  ctx: ContentToolContext,
  type: string,
): Promise<string> {
  const normalized = type.trim().toLowerCase() || "pages";
  if (normalized === "page" || normalized === "pages") {
    return "pages";
  }
  if (normalized === "post" || normalized === "posts") {
    return "posts";
  }
  // CPT: resolve the real rest_base from /wp/v2/types (cached); a CPT whose
  // rest_base differs from its slug would otherwise 404.
  const discovered = await resolveRestBase(ctx.client, "types", normalized);
  return discovered ?? normalized;
}

export async function contentList(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    search?: string | undefined;
    status?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-content-list");
  const collection = await collectionFor(ctx, input.type ?? "pages");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const items = await ctx.client.get<WpPost[]>(`/wp/v2/${collection}`, {
    query: {
      search: input.search,
      status: input.status,
      per_page: perPage,
      page,
      _fields: "id,title,slug,status,modified,link,type",
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map(compactPost),
    total: list.length,
    page,
    per_page: perPage,
    next_page: list.length === perPage ? page + 1 : undefined,
  };
}

export async function contentGet(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    id: number;
    fields?: "raw" | "rendered" | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-content-get");
  const collection = await collectionFor(ctx, input.type ?? "pages");
  const context = input.fields === "raw" ? "edit" : "view";
  const post = await ctx.client.get<WpPost>(
    `/wp/v2/${collection}/${input.id}`,
    {
      query: { context },
    },
  );
  const content =
    typeof post.content === "string"
      ? post.content
      : input.fields === "raw"
        ? (post.content?.raw ?? "")
        : (post.content?.rendered ?? "");
  return {
    ...compactPost(post),
    content,
    parent: post.parent ?? 0,
    template: post.template ?? "",
    meta: post.meta ?? {},
  };
}

export async function contentCreate(
  ctx: ContentToolContext,
  input: {
    kind?: "page" | "post" | undefined;
    type?: string | undefined;
    title: string;
    content?: string | undefined;
    excerpt?: string | undefined;
    slug?: string | undefined;
    /** Publication date; with status "future" it schedules the item. */
    date?: string | undefined;
    categories?: number[] | undefined;
    status?: string | undefined;
    parent?: number | undefined;
    template?: string | undefined;
    meta?: Record<string, unknown> | undefined;
  },
) {
  const tool =
    input.kind === "page"
      ? "stonewright-content-create-page"
      : input.kind === "post"
        ? "stonewright-content-create-post"
        : "stonewright-content-create";
  assertToolEnabled(ctx.site, tool);
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: false,
    tool,
  });
  const refusal = refuseCustomCode(
    ctx.site,
    tool,
    input.kind ?? input.type ?? "page",
    contentCodeFindings(input),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const collection = await collectionFor(
    ctx,
    input.kind ?? input.type ?? "page",
  );
  const body: Record<string, unknown> = {
    title: input.title,
    content: input.content,
    excerpt: input.excerpt,
    slug: input.slug,
    date: input.date,
    categories: input.categories,
    status: input.status ?? "draft",
    parent: input.parent,
    template: input.template,
    meta: input.meta,
  };
  let post: WpPost;
  try {
    post = await ctx.client.post<WpPost>(`/wp/v2/${collection}`, { body });
  } catch (err) {
    appendDirectAudit({
      tool,
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: collection,
      status: "error",
      code: err instanceof Error ? err.name : "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool,
    resource: `${collection}/${post.id}`,
    resourceType: collection,
    readPath: `/wp/v2/${collection}/${post.id}`,
    sent: body,
  });
  return probeAfterWrite(
    ctx,
    {
      tool,
      resource: `${collection}/${post.id}`,
      input: {},
      target: { kind: "page", status: post.status, link: post.link },
      sentText: input.content,
    },
    attachVerification(compactPost(post), verification),
  );
}

export async function contentUpdate(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    id: number;
    title?: string | undefined;
    content?: string | undefined;
    status?: string | undefined;
    parent?: number | undefined;
    template?: string | undefined;
    meta?: Record<string, unknown> | undefined;
  } & ProbeOptions,
) {
  assertToolEnabled(ctx.site, "stonewright-content-update");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: false,
    tool: "stonewright-content-update",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-content-update",
    `${input.type ?? "pages"}/${input.id}`,
    contentCodeFindings(input),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const collection = await collectionFor(ctx, input.type ?? "pages");
  const preImage = await storePreImage({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-content-update",
    restPath: `/wp/v2/${collection}/${input.id}`,
    resourceId: `${collection}-${input.id}`,
  });
  const body: Record<string, unknown> = {};
  if (input.title !== undefined) body.title = input.title;
  if (input.content !== undefined) body.content = input.content;
  if (input.status !== undefined) body.status = input.status;
  if (input.parent !== undefined) body.parent = input.parent;
  if (input.template !== undefined) body.template = input.template;
  if (input.meta !== undefined) body.meta = input.meta;

  let post: WpPost;
  try {
    post = await ctx.client.put<WpPost>(`/wp/v2/${collection}/${input.id}`, {
      body,
    });
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-content-update",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: `${collection}/${input.id}`,
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-content-update",
    resource: `${collection}/${input.id}`,
    resourceType: collection,
    readPath: `/wp/v2/${collection}/${input.id}`,
    sent: body,
    beforeSha256: preImage.before_sha256,
    backupId: preImage.backup_id,
  });
  return probeAfterWrite(
    ctx,
    {
      tool: "stonewright-content-update",
      resource: `${collection}/${input.id}`,
      input,
      target: { kind: "page", status: post.status, link: post.link },
      sentText: input.content,
      backupId: preImage.backup_id,
    },
    attachVerification({ ...compactPost(post), ...preImage }, verification, {
      backupId: preImage.backup_id,
    }),
  );
}

export async function contentDelete(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    id: number;
    force?: boolean | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-content-delete");
  const force = input.force === true;
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: force,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-content-delete",
  });
  const collection = await collectionFor(ctx, input.type ?? "pages");
  let result: unknown;
  try {
    result = await ctx.client.del<unknown>(
      `/wp/v2/${collection}/${input.id}`,
      {
        query: { force },
      },
    );
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-content-delete",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: `${collection}/${input.id}`,
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-content-delete",
    resource: `${collection}/${input.id}`,
    resourceType: collection,
    readPath: `/wp/v2/${collection}/${input.id}`,
    ...(force ? { expect: "absent" as const } : { sent: { status: "trash" } }),
  });
  return attachVerification({ deleted: true, force, result }, verification);
}

export async function contentRevisions(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    id: number;
    per_page?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-content-revisions");
  const collection = await collectionFor(ctx, input.type ?? "pages");
  const perPage = Math.min(Math.max(input.per_page ?? 10, 1), 50);
  const revisions = await ctx.client.get<WpPost[]>(
    `/wp/v2/${collection}/${input.id}/revisions`,
    {
      query: {
        per_page: perPage,
        _fields: "id,title,slug,status,modified,author",
      },
    },
  );
  const list = Array.isArray(revisions) ? revisions : [];
  return {
    items: list.map((row) => ({
      id: row.id,
      title: titleOf(row),
      modified: row.modified ?? "",
    })),
    total: list.length,
  };
}

export async function contentRevisionGet(
  ctx: ContentToolContext,
  input: { type?: string | undefined; id: number; revision_id: number },
) {
  assertToolEnabled(ctx.site, "stonewright-content-revision-get");
  const collection = await collectionFor(ctx, input.type ?? "pages");
  const rev = await ctx.client.get<WpPost>(
    `/wp/v2/${collection}/${input.id}/revisions/${input.revision_id}`,
    { query: { _fields: "id,title,slug,status,modified,content,author" } },
  );
  return {
    id: rev.id,
    title: titleOf(rev),
    modified: rev.modified ?? "",
    content:
      typeof rev.content === "string"
        ? rev.content
        : (rev.content?.raw ?? rev.content?.rendered ?? ""),
  };
}

export async function contentRevisionDelete(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    id: number;
    revision_id: number;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-content-revision-delete");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-content-revision-delete",
  });
  const collection = await collectionFor(ctx, input.type ?? "pages");
  const result = await ctx.client.del(
    `/wp/v2/${collection}/${input.id}/revisions/${input.revision_id}`,
    { query: { force: true } },
  );
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-content-revision-delete",
    resource: `${collection}/${input.id}/revisions/${input.revision_id}`,
    resourceType: "revisions",
    readPath: `/wp/v2/${collection}/${input.id}/revisions/${input.revision_id}`,
    expect: "absent",
  });
  return attachVerification(
    (result as object | undefined) ?? { deleted: true, revision_id: input.revision_id },
    verification,
  );
}

export async function contentAutosaves(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    id: number;
    autosave_id?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-content-autosaves");
  const collection = await collectionFor(ctx, input.type ?? "pages");
  if (input.autosave_id !== undefined) {
    const one = await ctx.client.get<WpPost>(
      `/wp/v2/${collection}/${input.id}/autosaves/${input.autosave_id}`,
      { query: { _fields: "id,title,modified,author" } },
    );
    return {
      items: [
        { id: one.id, title: titleOf(one), modified: one.modified ?? "" },
      ],
    };
  }
  const items = await ctx.client.get<WpPost[]>(
    `/wp/v2/${collection}/${input.id}/autosaves`,
    {
      query: { _fields: "id,title,modified,author" },
    },
  );
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map((row) => ({
      id: row.id,
      title: titleOf(row),
      modified: row.modified ?? "",
    })),
    total: list.length,
  };
}

export async function contentAutosaveCreate(
  ctx: ContentToolContext,
  input: {
    type?: string | undefined;
    id: number;
    title?: string | undefined;
    content?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-content-autosave-create");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: false,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-content-autosave-create",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-content-autosave-create",
    `${input.type ?? "pages"}/${input.id}/autosaves`,
    contentCodeFindings(input),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const collection = await collectionFor(ctx, input.type ?? "pages");
  const body: Record<string, unknown> = {};
  if (input.title !== undefined) body.title = input.title;
  if (input.content !== undefined) body.content = input.content;
  const saved = await ctx.client.post<WpPost>(
    `/wp/v2/${collection}/${input.id}/autosaves`,
    { body },
  );
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-content-autosave-create",
    resource: `${collection}/${input.id}/autosaves`,
    resourceType: "autosaves",
    readPath: `/wp/v2/${collection}/${input.id}/autosaves/${saved.id}`,
    sent: body,
  });
  return attachVerification(
    {
      id: saved.id,
      title: titleOf(saved),
      modified: saved.modified ?? "",
    },
    verification,
  );
}
