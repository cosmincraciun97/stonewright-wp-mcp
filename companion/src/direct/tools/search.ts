import { assertToolEnabled } from "../writes.js";
import type { DirectToolContext } from "./types.js";

type WpSearchResult = {
  id: number;
  title?: string | undefined;
  url?: string | undefined;
  type?: string | undefined;
  subtype?: string | undefined;
};

export async function siteSearch(
  ctx: DirectToolContext,
  input: {
    search: string;
    type?: string | undefined;
    subtype?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-search");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const items = await ctx.client.get<WpSearchResult[]>("/wp/v2/search", {
    query: {
      search: input.search,
      type: input.type,
      subtype: input.subtype,
      per_page: perPage,
      page,
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map((row) => ({
      id: row.id,
      title: row.title ?? "",
      url: row.url ?? "",
      type: row.type ?? "",
      subtype: row.subtype ?? "",
    })),
    total: list.length,
    page,
    per_page: perPage,
    next_page: list.length === perPage ? page + 1 : undefined,
  };
}

export async function oembed(
  ctx: DirectToolContext,
  input: {
    url: string;
    maxwidth?: number | undefined;
    proxy?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-oembed");
  const path = input.proxy ? "/oembed/1.0/proxy" : "/oembed/1.0/embed";
  return ctx.client.get(path, {
    query: {
      url: input.url,
      maxwidth: input.maxwidth,
    },
  });
}

export async function urlDetails(
  ctx: DirectToolContext,
  input: { url: string },
) {
  assertToolEnabled(ctx.site, "stonewright-url-details");
  try {
    return await ctx.client.get("/wp-block-editor/v1/url-details", {
      query: { url: input.url },
    });
  } catch (err) {
    const status =
      err && typeof err === "object" && "status" in err
        ? Number((err as { status: number }).status)
        : 0;
    if (status === 404) {
      return { supported: false };
    }
    throw err;
  }
}

export async function blockDirectorySearch(
  ctx: DirectToolContext,
  input: {
    term: string;
    page?: number | undefined;
    per_page?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-block-directory-search");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const items = await ctx.client.get<unknown[]>(
    "/wp/v2/block-directory/search",
    {
      query: { term: input.term, page, per_page: perPage },
    },
  );
  return { items: Array.isArray(items) ? items : [], page, per_page: perPage };
}

export async function patternDirectorySearch(
  ctx: DirectToolContext,
  input: {
    search?: string | undefined;
    category?: string | undefined;
    per_page?: number | undefined;
    include_content?: boolean | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-pattern-directory-search");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const items = await ctx.client.get<Array<Record<string, unknown>>>(
    "/wp/v2/pattern-directory/patterns",
    {
      query: {
        search: input.search,
        category: input.category,
        per_page: perPage,
      },
    },
  );
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map((row) => {
      const out: Record<string, unknown> = {
        id: row.id,
        title: row.title,
        categories: row.categories,
        keywords: row.keywords,
      };
      if (input.include_content) {
        out.content = row.content;
      }
      return out;
    }),
    total: list.length,
  };
}
