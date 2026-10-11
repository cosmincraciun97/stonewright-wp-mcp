import type { WpRestClient } from "../wp-rest-client.js";
import {
  assertToolEnabled,
  assertWriteAllowed,
  type DirectWriteMode,
} from "../writes.js";
import { attachVerification, verifyWrite } from "../verify.js";
import { appendDirectAudit } from "../audit.js";
import { refuseCustomCode, scanTextFields } from "../custom-code-guard.js";
import { resolveRestBase } from "../rest-discovery.js";
import type { ResolvedSite } from "../sites-config.js";

export interface TaxonomyToolContext {
  client: WpRestClient;
  site: ResolvedSite;
  writeMode: DirectWriteMode;
  env?: NodeJS.ProcessEnv | undefined;
}

type WpTerm = {
  id: number;
  name?: string | undefined;
  slug?: string | undefined;
  description?: string | undefined;
  count?: number | undefined;
  parent?: number | undefined;
};

async function collectionFor(
  ctx: TaxonomyToolContext,
  taxonomy: string,
): Promise<string> {
  const normalized = taxonomy.trim().toLowerCase() || "categories";
  if (normalized === "category" || normalized === "categories")
    return "categories";
  if (
    normalized === "tag" ||
    normalized === "post_tag" ||
    normalized === "tags"
  )
    return "tags";
  // Custom taxonomy: resolve the real rest_base from /wp/v2/taxonomies (cached).
  const discovered = await resolveRestBase(
    ctx.client,
    "taxonomies",
    normalized,
  );
  return discovered ?? normalized;
}

function compactTerm(term: WpTerm) {
  return {
    id: term.id,
    name: term.name ?? "",
    slug: term.slug ?? "",
    description: term.description ?? "",
    count: term.count ?? 0,
    parent: term.parent ?? 0,
  };
}

export async function taxonomyList(
  ctx: TaxonomyToolContext,
  input: {
    taxonomy?: string | undefined;
    search?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-taxonomy-terms");
  const collection = await collectionFor(ctx, input.taxonomy ?? "categories");
  const perPage = Math.min(Math.max(input.per_page ?? 50, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const items = await ctx.client.get<WpTerm[]>(`/wp/v2/${collection}`, {
    query: {
      search: input.search,
      per_page: perPage,
      page,
      _fields: "id,name,slug,description,count,parent",
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    taxonomy: collection,
    items: list.map(compactTerm),
    total: list.length,
    page,
    per_page: perPage,
    next_page: list.length === perPage ? page + 1 : undefined,
  };
}

export async function taxonomyCreate(
  ctx: TaxonomyToolContext,
  input: {
    taxonomy?: string | undefined;
    name: string;
    slug?: string | undefined;
    description?: string | undefined;
    parent?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-taxonomy-terms");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: false,
    tool: "stonewright-taxonomy-terms",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-taxonomy-terms",
    input.taxonomy ?? "categories",
    scanTextFields({
      name: input.name,
      description: input.description,
    }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const collection = await collectionFor(ctx, input.taxonomy ?? "categories");
  const body: Record<string, unknown> = {
    name: input.name,
    slug: input.slug,
    description: input.description,
    parent: input.parent,
  };
  let term: WpTerm;
  try {
    term = await ctx.client.post<WpTerm>(`/wp/v2/${collection}`, { body });
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-taxonomy-terms",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: collection,
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-taxonomy-terms",
    resource: `${collection}/${term.id}`,
    resourceType: collection,
    readPath: `/wp/v2/${collection}/${term.id}`,
    expect: "present",
    sent: body,
  });
  return attachVerification(compactTerm(term), verification);
}

export async function taxonomyUpdate(
  ctx: TaxonomyToolContext,
  input: {
    taxonomy?: string | undefined;
    id: number;
    name?: string | undefined;
    slug?: string | undefined;
    description?: string | undefined;
    parent?: number | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-taxonomy-terms");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: false,
    tool: "stonewright-taxonomy-terms",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-taxonomy-terms",
    `${input.taxonomy ?? "categories"}/${input.id}`,
    scanTextFields({
      name: input.name,
      description: input.description,
    }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const collection = await collectionFor(ctx, input.taxonomy ?? "categories");
  const body: Record<string, unknown> = {};
  if (input.name !== undefined) body.name = input.name;
  if (input.slug !== undefined) body.slug = input.slug;
  if (input.description !== undefined) body.description = input.description;
  if (input.parent !== undefined) body.parent = input.parent;

  let term: WpTerm;
  try {
    term = await ctx.client.put<WpTerm>(`/wp/v2/${collection}/${input.id}`, {
      body,
    });
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-taxonomy-terms",
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
    tool: "stonewright-taxonomy-terms",
    resource: `${collection}/${input.id}`,
    resourceType: collection,
    readPath: `/wp/v2/${collection}/${input.id}`,
    sent: body,
  });
  return attachVerification(compactTerm(term), verification);
}

export async function taxonomyDelete(
  ctx: TaxonomyToolContext,
  input: {
    taxonomy?: string | undefined;
    id: number;
    force?: boolean | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-taxonomy-terms");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-taxonomy-terms",
  });
  const collection = await collectionFor(ctx, input.taxonomy ?? "categories");
  let result: unknown;
  try {
    result = await ctx.client.del<unknown>(`/wp/v2/${collection}/${input.id}`, {
      query: { force: input.force ?? true },
    });
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-taxonomy-terms",
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
    tool: "stonewright-taxonomy-terms",
    resource: `${collection}/${input.id}`,
    resourceType: collection,
    readPath: `/wp/v2/${collection}/${input.id}`,
    expect: "absent",
  });
  return attachVerification({ deleted: true, result }, verification);
}
