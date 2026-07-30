import { assertToolEnabled, assertWriteAllowed } from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import type { DirectToolContext } from "./types.js";

type WpTemplate = {
  id: string;
  slug?: string | undefined;
  theme?: string | undefined;
  type?: string | undefined;
  source?: string | undefined;
  origin?: string | undefined;
  content?:
    | { raw?: string; rendered?: string; block_version?: number }
    | string;
  title?: { raw?: string; rendered?: string } | string;
  description?: string | undefined;
  status?: string | undefined;
  wp_id?: number | undefined;
  has_theme_file?: boolean | undefined;
  author?: number | undefined;
  area?: string | undefined;
  is_custom?: boolean | undefined;
};

function titleOf(item: WpTemplate): string {
  if (typeof item.title === "string") return item.title;
  return item.title?.raw ?? item.title?.rendered ?? "";
}

function contentOf(item: WpTemplate, raw = false): string {
  if (typeof item.content === "string") return item.content;
  return raw
    ? (item.content?.raw ?? "")
    : (item.content?.rendered ?? item.content?.raw ?? "");
}

function compactTemplate(item: WpTemplate, includeContent = false) {
  const base = {
    id: item.id,
    slug: item.slug ?? "",
    theme: item.theme ?? "",
    type: item.type ?? "",
    source: item.source ?? "",
    status: item.status ?? "",
    title: titleOf(item),
    description: item.description ?? "",
    wp_id: item.wp_id ?? 0,
    area: item.area ?? "",
  };
  if (!includeContent) return base;
  return { ...base, content: contentOf(item, true) };
}

async function listCollection(
  ctx: DirectToolContext,
  tool: string,
  collection: "templates" | "template-parts",
  input: {
    search?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, tool);
  const perPage = Math.min(Math.max(input.per_page ?? 50, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const items = await ctx.client.get<WpTemplate[]>(`/wp/v2/${collection}`, {
    query: {
      search: input.search,
      per_page: perPage,
      page,
      _fields: "id,slug,theme,type,source,status,title,description,wp_id,area",
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map((row) => compactTemplate(row)),
    total: list.length,
    page,
    per_page: perPage,
    next_page: list.length === perPage ? page + 1 : undefined,
  };
}

async function getCollection(
  ctx: DirectToolContext,
  tool: string,
  collection: "templates" | "template-parts",
  id: string,
) {
  assertToolEnabled(ctx.site, tool);
  const item = await ctx.client.get<WpTemplate>(
    `/wp/v2/${collection}/${encodeURIComponent(id)}`,
    {
      query: { context: "edit" },
    },
  );
  return compactTemplate(item, true);
}

async function updateCollection(
  ctx: DirectToolContext,
  tool: string,
  collection: "templates" | "template-parts",
  input: {
    id: string;
    title?: string | undefined;
    content?: string | undefined;
    description?: string | undefined;
  },
) {
  assertToolEnabled(ctx.site, tool);
  assertWriteAllowed({
    site: ctx.site.alias,
    mode: ctx.writeMode,
    destructive: false,
    tool,
  });
  const body: Record<string, unknown> = {};
  if (input.title !== undefined) body.title = input.title;
  if (input.content !== undefined) body.content = input.content;
  if (input.description !== undefined) body.description = input.description;
  try {
    const item = await ctx.client.post<WpTemplate>(
      `/wp/v2/${collection}/${encodeURIComponent(input.id)}`,
      { body },
    );
    appendDirectAudit({
      tool,
      site: ctx.site.alias,
      resource: `${collection}/${input.id}`,
      status: "ok",
    });
    return compactTemplate(item, true);
  } catch (err) {
    appendDirectAudit({
      tool,
      site: ctx.site.alias,
      resource: `${collection}/${input.id}`,
      status: "error",
    });
    throw err;
  }
}

export async function templateList(
  ctx: DirectToolContext,
  input: {
    search?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  } = {},
) {
  return listCollection(ctx, "stonewright-template-list", "templates", input);
}

export async function templateGet(
  ctx: DirectToolContext,
  input: { id: string },
) {
  return getCollection(ctx, "stonewright-template-get", "templates", input.id);
}

export async function templateUpdate(
  ctx: DirectToolContext,
  input: {
    id: string;
    title?: string | undefined;
    content?: string | undefined;
    description?: string | undefined;
  },
) {
  return updateCollection(
    ctx,
    "stonewright-template-update",
    "templates",
    input,
  );
}

export async function templatePartList(
  ctx: DirectToolContext,
  input: {
    search?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  } = {},
) {
  return listCollection(
    ctx,
    "stonewright-template-part-list",
    "template-parts",
    input,
  );
}

export async function templatePartGet(
  ctx: DirectToolContext,
  input: { id: string },
) {
  return getCollection(
    ctx,
    "stonewright-template-part-get",
    "template-parts",
    input.id,
  );
}

export async function templatePartUpdate(
  ctx: DirectToolContext,
  input: {
    id: string;
    title?: string | undefined;
    content?: string | undefined;
    description?: string | undefined;
  },
) {
  return updateCollection(
    ctx,
    "stonewright-template-part-update",
    "template-parts",
    input,
  );
}

export async function templateCreate(
  ctx: DirectToolContext,
  input: {
    slug: string;
    title?: string | undefined;
    content?: string | undefined;
    description?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-template-create");
  assertWriteAllowed({
    site: ctx.site.alias,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-template-create",
  });
  const body: Record<string, unknown> = { slug: input.slug };
  if (input.title !== undefined) body.title = input.title;
  if (input.content !== undefined) body.content = input.content;
  if (input.description !== undefined) body.description = input.description;
  const created = await ctx.client.post<WpTemplate>("/wp/v2/templates", {
    body,
  });
  appendDirectAudit({
    tool: "stonewright-template-create",
    site: ctx.site.alias,
    resource: `templates/${created.id}`,
    status: "ok",
  });
  return compactTemplate(created, true);
}

export async function templateDelete(
  ctx: DirectToolContext,
  input: {
    id: string;
    force?: boolean | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-template-delete");
  assertWriteAllowed({
    site: ctx.site.alias,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-template-delete",
  });
  const result = await ctx.client.del(
    `/wp/v2/templates/${encodeURIComponent(input.id)}`,
    {
      query: { force: input.force === true },
    },
  );
  appendDirectAudit({
    tool: "stonewright-template-delete",
    site: ctx.site.alias,
    resource: `templates/${input.id}`,
    status: "ok",
  });
  return result ?? { deleted: true, id: input.id };
}

export async function templatePartCreate(
  ctx: DirectToolContext,
  input: {
    slug: string;
    title?: string | undefined;
    content?: string | undefined;
    area?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-template-part-create");
  assertWriteAllowed({
    site: ctx.site.alias,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-template-part-create",
  });
  const body: Record<string, unknown> = { slug: input.slug };
  if (input.title !== undefined) body.title = input.title;
  if (input.content !== undefined) body.content = input.content;
  if (input.area !== undefined) body.area = input.area;
  const created = await ctx.client.post<WpTemplate>("/wp/v2/template-parts", {
    body,
  });
  appendDirectAudit({
    tool: "stonewright-template-part-create",
    site: ctx.site.alias,
    resource: `template-parts/${created.id}`,
    status: "ok",
  });
  return compactTemplate(created, true);
}

export async function templatePartDelete(
  ctx: DirectToolContext,
  input: {
    id: string;
    force?: boolean | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-template-part-delete");
  assertWriteAllowed({
    site: ctx.site.alias,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-template-part-delete",
  });
  const result = await ctx.client.del(
    `/wp/v2/template-parts/${encodeURIComponent(input.id)}`,
    {
      query: { force: input.force === true },
    },
  );
  appendDirectAudit({
    tool: "stonewright-template-part-delete",
    site: ctx.site.alias,
    resource: `template-parts/${input.id}`,
    status: "ok",
  });
  return result ?? { deleted: true, id: input.id };
}
