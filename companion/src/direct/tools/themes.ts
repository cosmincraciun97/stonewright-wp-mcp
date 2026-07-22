import { assertToolEnabled, assertWriteAllowed } from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import type { DirectToolContext } from "./types.js";

type WpTheme = {
  stylesheet: string;
  template?: string | undefined;
  name?: { raw?: string; rendered?: string } | string;
  status?: string | undefined;
  version?: string | undefined;
  author?: { raw?: string; rendered?: string } | string;
  description?: { raw?: string; rendered?: string } | string;
  theme_supports?: Record<string, unknown> | undefined;
};

function textOf(
  value: { raw?: string; rendered?: string } | string | undefined,
): string {
  if (typeof value === "string") return value;
  return value?.raw ?? value?.rendered ?? "";
}

function compactTheme(theme: WpTheme) {
  return {
    stylesheet: theme.stylesheet,
    template: theme.template ?? "",
    name: textOf(theme.name),
    status: theme.status ?? "",
    version: theme.version ?? "",
    author: textOf(theme.author),
    description: textOf(theme.description),
  };
}

export async function themeList(
  ctx: DirectToolContext,
  input: { status?: string | undefined } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-theme-list");
  const items = await ctx.client.get<WpTheme[]>("/wp/v2/themes", {
    query: {
      status: input.status,
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map(compactTheme),
    total: list.length,
  };
}

export async function themeActivate(
  ctx: DirectToolContext,
  input: { stylesheet: string; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-theme-activate");
  if (input.confirm !== true) {
    throw new Error(
      "confirm:true is required for this tool (stonewright-theme-activate)",
    );
  }
  const result = await ctx.client.post<WpTheme>(
    `/wp/v2/themes/${encodeURIComponent(input.stylesheet)}`,
    { body: { status: "active" } },
  );
  appendDirectAudit({
    tool: "stonewright-theme-activate",
    site: ctx.site.alias,
    resource: `themes/${input.stylesheet}`,
    status: "ok",
  });
  return compactTheme(result);
}

export async function customCss(
  ctx: DirectToolContext,
  input: {
    action: "get" | "update";
    css?: string | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-custom-css");
  if (input.action === "get") {
    const settings =
      await ctx.client.get<Record<string, unknown>>("/wp/v2/settings");
    // Core REST does not always expose custom CSS; probe known keys.
    const keys = ["custom_css", "custom_css_post_id"];
    const found = keys.find((k) => k in settings);
    if (!found) {
      return {
        supported: false,
        hint: "Custom CSS requires the Stonewright plugin on this site.",
      };
    }
    return {
      supported: true,
      action: "get",
      settings: { [found]: settings[found] },
    };
  }
  assertWriteAllowed({
    site: ctx.site.alias,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-custom-css",
  });
  // Best-effort: many installs reject unknown settings keys.
  try {
    const updated = await ctx.client.post<Record<string, unknown>>(
      "/wp/v2/settings",
      {
        body: { custom_css: input.css ?? "" },
      },
    );
    appendDirectAudit({
      tool: "stonewright-custom-css",
      site: ctx.site.alias,
      resource: "settings/custom_css",
      status: "ok",
    });
    return { supported: true, action: "update", settings: updated };
  } catch {
    return {
      supported: false,
      hint: "Custom CSS requires the Stonewright plugin on this site.",
    };
  }
}
