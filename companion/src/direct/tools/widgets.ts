import { assertToolEnabled, assertWriteAllowed } from "../writes.js";
import { attachVerification, verifyWrite } from "../verify.js";
import { refuseCustomCode, scanWidget } from "../custom-code-guard.js";
import type { DirectToolContext } from "./types.js";

type WpSidebar = {
  id: string;
  name?: string | undefined;
  description?: string | undefined;
  status?: string | undefined;
  widgets?: string[] | undefined;
};

type WpWidget = {
  id: string;
  id_base?: string | undefined;
  sidebar?: string | undefined;
  instance?: Record<string, unknown> | undefined;
};

function compactSidebar(s: WpSidebar) {
  return {
    id: s.id,
    name: s.name ?? "",
    status: s.status ?? "",
    widgets: Array.isArray(s.widgets) ? s.widgets : [],
  };
}

function compactWidget(w: WpWidget) {
  return {
    id: w.id,
    id_base: w.id_base ?? "",
    sidebar: w.sidebar ?? "",
    instance: w.instance ?? {},
  };
}

export async function sidebarList(
  ctx: DirectToolContext,
  input: { id?: string | undefined } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-sidebar-list");
  if (input.id) {
    const one = await ctx.client.get<WpSidebar>(
      `/wp/v2/sidebars/${encodeURIComponent(input.id)}`,
    );
    return { items: [compactSidebar(one)] };
  }
  const items = await ctx.client.get<WpSidebar[]>("/wp/v2/sidebars");
  const list = Array.isArray(items) ? items : [];
  return { items: list.map(compactSidebar), total: list.length };
}

export async function widgetList(
  ctx: DirectToolContext,
  input: { sidebar?: string | undefined } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-widget-list");
  const items = await ctx.client.get<WpWidget[]>("/wp/v2/widgets", {
    query: {
      context: "edit",
      ...(input.sidebar ? { sidebar: input.sidebar } : {}),
    },
  });
  const list = Array.isArray(items) ? items : [];
  return { items: list.map(compactWidget), total: list.length };
}

export async function widgetManage(
  ctx: DirectToolContext,
  input: {
    action: "create" | "update" | "delete";
    id?: string | undefined;
    id_base?: string | undefined;
    sidebar?: string | undefined;
    instance?: Record<string, unknown> | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-widget-manage");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-widget-manage",
  });
  if (input.action !== "delete") {
    const refusal = refuseCustomCode(
      ctx.site,
      "stonewright-widget-manage",
      `widgets/${input.id ?? input.id_base ?? ""}`,
      scanWidget({ id_base: input.id_base, instance: input.instance }),
      ctx.env ?? process.env,
    );
    if (refusal) return refusal;
  }

  if (input.action === "create") {
    if (!input.id_base || !input.sidebar) {
      throw new Error("create requires id_base and sidebar");
    }
    const createBody: Record<string, unknown> = {
      id_base: input.id_base,
      sidebar: input.sidebar,
      ...(input.instance !== undefined ? { instance: input.instance } : {}),
    };
    const w = await ctx.client.post<WpWidget>("/wp/v2/widgets", {
      body: createBody,
    });
    const verification = await verifyWrite({
      client: ctx.client,
      site: ctx.site,
      env: ctx.env,
      tool: "stonewright-widget-manage",
      resource: `widgets/${w.id}`,
      resourceType: "widgets",
      readPath: `/wp/v2/widgets/${encodeURIComponent(w.id)}`,
      expect: "present",
      sent: createBody,
    });
    return attachVerification(
      { action: "create", widget: compactWidget(w) },
      verification,
    );
  }

  if (input.action === "update") {
    if (!input.id) {
      throw new Error("update requires id");
    }
    const body: Record<string, unknown> = {};
    if (input.sidebar !== undefined) body.sidebar = input.sidebar;
    if (input.instance !== undefined) body.instance = input.instance;
    const w = await ctx.client.post<WpWidget>(
      `/wp/v2/widgets/${encodeURIComponent(input.id)}`,
      { body },
    );
    const verification = await verifyWrite({
      client: ctx.client,
      site: ctx.site,
      env: ctx.env,
      tool: "stonewright-widget-manage",
      resource: `widgets/${input.id}`,
      resourceType: "widgets",
      readPath: `/wp/v2/widgets/${encodeURIComponent(input.id)}`,
      sent: body,
    });
    return attachVerification(
      { action: "update", widget: compactWidget(w) },
      verification,
    );
  }

  if (!input.id) {
    throw new Error("delete requires id");
  }
  await ctx.client.del(`/wp/v2/widgets/${encodeURIComponent(input.id)}`, {
    query: { force: true },
  });
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-widget-manage",
    resource: `widgets/${input.id}`,
    resourceType: "widgets",
    readPath: `/wp/v2/widgets/${encodeURIComponent(input.id)}`,
    expect: "absent",
  });
  return attachVerification(
    { action: "delete", id: input.id, deleted: true },
    verification,
  );
}
