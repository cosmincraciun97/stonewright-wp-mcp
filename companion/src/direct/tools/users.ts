import { assertToolEnabled, assertWriteAllowed, DirectSafetyBlockedError } from "../writes.js";
import { WpRestError } from "../wp-rest-client.js";
import { attachVerification, verifyWrite } from "../verify.js";
import { refuseCustomCode, scanTextFields } from "../custom-code-guard.js";
import type { DirectToolContext } from "./types.js";

type WpUser = {
  id: number;
  name?: string | undefined;
  slug?: string | undefined;
  url?: string | undefined;
  description?: string | undefined;
  link?: string | undefined;
  username?: string | undefined;
  email?: string | undefined;
  roles?: string[] | undefined;
  capabilities?: Record<string, boolean> | undefined;
};

function compactUser(user: WpUser, includePrivate = false) {
  const base = {
    id: user.id,
    name: user.name ?? "",
    slug: user.slug ?? "",
    url: user.url ?? "",
    description: user.description ?? "",
    link: user.link ?? "",
    roles: user.roles ?? [],
    // The plugin's name for the display name.
    display_name: user.name ?? "",
  };
  if (!includePrivate) return base;
  return {
    ...base,
    username: user.username ?? "",
    email: user.email ?? "",
    // The plugin's name for the sign-in name.
    login: user.username ?? "",
  };
}

export async function userList(
  ctx: DirectToolContext,
  input: {
    search?: string | undefined;
    roles?: string | undefined;
    per_page?: number | undefined;
    page?: number | undefined;
    /** Users to skip; core applies it instead of page. */
    offset?: number | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-user-list");
  const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
  const page = Math.max(input.page ?? 1, 1);
  const query = {
    search: input.search,
    roles: input.roles,
    per_page: perPage,
    page,
    offset: input.offset,
    _fields: "id,name,slug,url,description,link,roles",
  };
  // Core REST returns `roles` only in the edit context, which needs the list_users capability. Without it the
  // list is read in the public view, where no user carries roles, and the answer says so instead of showing [].
  let items: WpUser[];
  let rolesAvailable = true;
  try {
    items = await ctx.client.get<WpUser[]>("/wp/v2/users", {
      query: { ...query, context: "edit" },
    });
  } catch (err) {
    if (!(err instanceof WpRestError) || err.status !== 403) throw err;
    rolesAvailable = false;
    items = await ctx.client.get<WpUser[]>("/wp/v2/users", { query });
  }
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map((row) => {
      const { roles, ...rest } = compactUser(row);
      return rolesAvailable ? { ...rest, roles } : rest;
    }),
    total: list.length,
    page,
    per_page: perPage,
    next_page: list.length === perPage ? page + 1 : undefined,
    ...(rolesAvailable
      ? {}
      : {
          roles_available: false,
          roles_note:
            "Roles are not shown: core REST returns them only to an account that may list users (the list_users capability, as an administrator has). Use an administrator's Application Password, or read one user with stonewright-user-get.",
        }),
  };
}

export async function userGet(ctx: DirectToolContext, input: { id: number }) {
  assertToolEnabled(ctx.site, "stonewright-user-get");
  const user = await ctx.client.get<WpUser>(`/wp/v2/users/${input.id}`, {
    query: {
      context: "edit",
      _fields: "id,name,slug,url,description,link,roles,username,email",
    },
  });
  return compactUser(user, true);
}

export async function userMe(ctx: DirectToolContext) {
  assertToolEnabled(ctx.site, "stonewright-user-me");
  const user = await ctx.client.get<WpUser>("/wp/v2/users/me", {
    query: {
      context: "edit",
      _fields: "id,name,slug,url,description,link,roles,username,email",
    },
  });
  return compactUser(user, true);
}

/** The account the session signs in as: the user behind the Application Password. */
async function sessionUser(ctx: DirectToolContext): Promise<{ id: number }> {
  const me = await ctx.client.get<WpUser>("/wp/v2/users/me", {
    query: { context: "edit", _fields: "id" },
  });
  return { id: me.id };
}

/** Refuses when no other administrator would be left once `id` stops being one. */
async function assertAnotherAdministrator(
  ctx: DirectToolContext,
  id: number,
  tool: string,
): Promise<void> {
  const admins = await ctx.client.get<Array<{ id: number }>>("/wp/v2/users", {
    query: { roles: "administrator", context: "edit", per_page: 100, _fields: "id" },
  });
  const others = (Array.isArray(admins) ? admins : []).filter((row) => row.id !== id);
  if (others.length === 0) {
    throw new DirectSafetyBlockedError(
      "last_administrator",
      `Refusing ${tool}: user ${id} is the last administrator, and the site needs one. Nothing was changed.`,
      tool,
      ctx.site.alias,
    );
  }
}

/**
 * Refuses to remove the session's own account (delete it, or take administrator away from it) and to
 * remove the last administrator. Reads only; the caller writes after it returns.
 */
async function assertAccountMayBeRemoved(
  ctx: DirectToolContext,
  id: number,
  tool: string,
  action: string,
): Promise<void> {
  const me = await sessionUser(ctx);
  if (me.id === id) {
    throw new DirectSafetyBlockedError(
      "self_protection",
      `Refusing ${tool}: user ${id} is the account whose Application Password Stonewright signs in with, so ${action} would lock this session out. Nothing was changed.`,
      tool,
      ctx.site.alias,
    );
  }
  const target = await ctx.client.get<WpUser>(`/wp/v2/users/${id}`, {
    query: { context: "edit", _fields: "id,roles" },
  });
  if ((target.roles ?? []).includes("administrator")) {
    await assertAnotherAdministrator(ctx, id, tool);
  }
}

export async function userCreate(
  ctx: DirectToolContext,
  input: {
    username: string;
    email: string;
    password: string;
    roles?: string[] | undefined;
    name?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-user-create");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-user-create",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-user-create",
    "users",
    scanTextFields({ name: input.name }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const createBody: Record<string, unknown> = {
    username: input.username,
    email: input.email,
    password: input.password,
    ...(input.roles !== undefined ? { roles: input.roles } : {}),
    ...(input.name !== undefined ? { name: input.name } : {}),
  };
  const user = await ctx.client.post<WpUser>("/wp/v2/users", {
    body: createBody,
  });
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-user-create",
    resource: `users/${user.id}`,
    resourceType: "users",
    readPath: `/wp/v2/users/${user.id}`,
    expect: "present",
    sent: createBody,
  });
  return attachVerification(compactUser(user, true), verification);
}

export async function userUpdate(
  ctx: DirectToolContext,
  input: {
    id: number;
    email?: string | undefined;
    name?: string | undefined;
    roles?: string[] | undefined;
    password?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-user-update");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-user-update",
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-user-update",
    `users/${input.id}`,
    scanTextFields({ name: input.name }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  if (input.roles !== undefined && !input.roles.includes("administrator")) {
    await assertAccountMayBeRemoved(
      ctx,
      input.id,
      "stonewright-user-update",
      "removing its administrator role",
    );
  }
  const body: Record<string, unknown> = {};
  if (input.email !== undefined) body.email = input.email;
  if (input.name !== undefined) body.name = input.name;
  if (input.roles !== undefined) body.roles = input.roles;
  if (input.password !== undefined) body.password = input.password;
  const user = await ctx.client.post<WpUser>(`/wp/v2/users/${input.id}`, {
    body,
  });
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-user-update",
    resource: `users/${input.id}`,
    resourceType: "users",
    readPath: `/wp/v2/users/${input.id}`,
    sent: body,
  });
  return attachVerification(compactUser(user, true), verification);
}

export async function userDelete(
  ctx: DirectToolContext,
  input: { id: number; reassign: number; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-user-delete");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-user-delete",
    env: ctx.env ?? process.env,
    // Asks for a confirmation in every write mode.
    alwaysConfirm: true,
  });
  await assertAccountMayBeRemoved(ctx, input.id, "stonewright-user-delete", "deleting it");
  const result = await ctx.client.del(`/wp/v2/users/${input.id}`, {
    query: { force: true, reassign: input.reassign },
  });
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-user-delete",
    resource: `users/${input.id}`,
    resourceType: "users",
    readPath: `/wp/v2/users/${input.id}`,
    expect: "absent",
  });
  return attachVerification(
    (result as object | undefined) ?? { deleted: true, id: input.id },
    verification,
  );
}

type AppPassword = {
  uuid: string;
  name?: string | undefined;
  created?: string | undefined;
  last_used?: string | null | undefined;
  password?: string | undefined;
};

export async function appPasswordList(
  ctx: DirectToolContext,
  input: { user_id: number },
) {
  assertToolEnabled(ctx.site, "stonewright-app-password-list");
  const items = await ctx.client.get<AppPassword[]>(
    `/wp/v2/users/${input.user_id}/application-passwords`,
  );
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map((row) => ({
      uuid: row.uuid,
      name: row.name ?? "",
      created: row.created ?? "",
      last_used: row.last_used ?? null,
    })),
    total: list.length,
  };
}

export async function appPasswordCreate(
  ctx: DirectToolContext,
  input: { user_id: number; name: string; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-app-password-create");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-app-password-create",
    env: ctx.env ?? process.env,
    // Asks for a confirmation in every write mode.
    alwaysConfirm: true,
  });
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-app-password-create",
    `users/${input.user_id}/application-passwords`,
    scanTextFields({ name: input.name }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const created = await ctx.client.post<AppPassword>(
    `/wp/v2/users/${input.user_id}/application-passwords`,
    { body: { name: input.name } },
  );
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-app-password-create",
    resource: `users/${input.user_id}/application-passwords`,
    resourceType: "application-passwords",
    readPath: `/wp/v2/users/${input.user_id}/application-passwords/${encodeURIComponent(created.uuid)}`,
    readQuery: {},
    expect: "present",
    sent: { name: input.name },
  });
  return attachVerification(
    {
      uuid: created.uuid,
      name: created.name ?? input.name,
      password: created.password ?? "",
      note: "Store this now; it cannot be retrieved again.",
    },
    verification,
  );
}

export async function appPasswordRevoke(
  ctx: DirectToolContext,
  input: { user_id: number; uuid: string; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-app-password-revoke");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-app-password-revoke",
    env: ctx.env ?? process.env,
    // Asks for a confirmation in every write mode.
    alwaysConfirm: true,
  });
  const result = await ctx.client.del(
    `/wp/v2/users/${input.user_id}/application-passwords/${encodeURIComponent(input.uuid)}`,
  );
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-app-password-revoke",
    resource: `users/${input.user_id}/application-passwords/${input.uuid}`,
    resourceType: "application-passwords",
    readPath: `/wp/v2/users/${input.user_id}/application-passwords/${encodeURIComponent(input.uuid)}`,
    readQuery: {},
    expect: "absent",
  });
  return attachVerification(
    (result as object | undefined) ?? { deleted: true, uuid: input.uuid },
    verification,
  );
}
