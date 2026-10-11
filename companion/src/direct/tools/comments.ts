import { assertToolEnabled, assertWriteAllowed } from "../writes.js";
import { attachVerification, verifyWrite } from "../verify.js";
import { refuseCustomCode, scanTextFields } from "../custom-code-guard.js";
import type { DirectToolContext } from "./types.js";

type WpComment = {
  id: number;
  post?: number | undefined;
  parent?: number | undefined;
  status?: string | undefined;
  author_name?: string | undefined;
  author_email?: string | undefined;
  date?: string | undefined;
  link?: string | undefined;
  content?: { rendered?: string; raw?: string } | undefined;
};

function compactComment(c: WpComment) {
  return {
    id: c.id,
    post: c.post ?? 0,
    parent: c.parent ?? 0,
    status: c.status ?? "",
    author_name: c.author_name ?? "",
    date: c.date ?? "",
    link: c.link ?? "",
    content: c.content?.raw ?? c.content?.rendered ?? "",
  };
}

export async function commentList(
  ctx: DirectToolContext,
  input: {
    post?: number | undefined;
    status?: string | undefined;
    search?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-comment-list");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const items = await ctx.client.get<WpComment[]>("/wp/v2/comments", {
    query: {
      post: input.post,
      status: input.status,
      search: input.search,
      per_page: perPage,
      page,
      context: "edit",
      _fields: "id,post,parent,status,author_name,date,link,content",
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map(compactComment),
    total: list.length,
    page,
    per_page: perPage,
    next_page: list.length === perPage ? page + 1 : undefined,
  };
}

export async function commentGet(
  ctx: DirectToolContext,
  input: { id: number },
) {
  assertToolEnabled(ctx.site, "stonewright-comment-get");
  const c = await ctx.client.get<WpComment>(`/wp/v2/comments/${input.id}`, {
    query: {
      context: "edit",
      _fields:
        "id,post,parent,status,author_name,author_email,date,link,content",
    },
  });
  return compactComment(c);
}

export async function commentCreate(
  ctx: DirectToolContext,
  input: {
    post: number;
    content: string;
    parent?: number | undefined;
    author_name?: string | undefined;
    author_email?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-comment-create");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-comment-create",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-comment-create",
    `post/${input.post}`,
    scanTextFields({
      content: input.content,
      author_name: input.author_name,
    }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const createBody: Record<string, unknown> = {
    post: input.post,
    content: input.content,
    ...(input.parent !== undefined ? { parent: input.parent } : {}),
    ...(input.author_name !== undefined
      ? { author_name: input.author_name }
      : {}),
    ...(input.author_email !== undefined
      ? { author_email: input.author_email }
      : {}),
  };
  const c = await ctx.client.post<WpComment>("/wp/v2/comments", {
    body: createBody,
  });
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-comment-create",
    resource: `comments/${c.id}`,
    resourceType: "comments",
    readPath: `/wp/v2/comments/${c.id}`,
    expect: "present",
    sent: createBody,
  });
  return attachVerification(compactComment(c), verification);
}

export async function commentUpdate(
  ctx: DirectToolContext,
  input: {
    id: number;
    content?: string | undefined;
    status?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-comment-update");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-comment-update",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-comment-update",
    `comments/${input.id}`,
    scanTextFields({ content: input.content }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const body: Record<string, unknown> = {};
  if (input.content !== undefined) body.content = input.content;
  if (input.status !== undefined) body.status = input.status;
  const c = await ctx.client.post<WpComment>(`/wp/v2/comments/${input.id}`, {
    body,
  });
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-comment-update",
    resource: `comments/${input.id}`,
    resourceType: "comments",
    readPath: `/wp/v2/comments/${input.id}`,
    sent: body,
  });
  return attachVerification(compactComment(c), verification);
}

export async function commentDelete(
  ctx: DirectToolContext,
  input: {
    id: number;
    force?: boolean | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-comment-delete");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-comment-delete",
  });
  const result = await ctx.client.del<unknown>(`/wp/v2/comments/${input.id}`, {
    query: { force: input.force === true },
  });
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-comment-delete",
    resource: `comments/${input.id}`,
    resourceType: "comments",
    readPath: `/wp/v2/comments/${input.id}`,
    ...(input.force === true
      ? { expect: "absent" as const }
      : { sent: { status: "trash" } }),
  });
  return attachVerification(
    (result as object | undefined) ?? { deleted: true, id: input.id },
    verification,
  );
}
