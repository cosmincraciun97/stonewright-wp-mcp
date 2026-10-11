import { assertToolEnabled, assertWriteAllowed, DirectSafetyBlockedError } from "../writes.js";
import { attachVerification, verifyWrite } from "../verify.js";
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
    // The plugin's flag for the same fact as status.
    active: (theme.status ?? "") === "active",
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
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-theme-activate",
    env: ctx.env ?? process.env,
  });
  if (input.confirm !== true) {
    throw new DirectSafetyBlockedError(
      "confirmation_required",
      "confirm:true is required for this tool (stonewright-theme-activate)",
      "stonewright-theme-activate",
      ctx.site.alias,
    );
  }
  const result = await ctx.client.post<WpTheme>(
    `/wp/v2/themes/${encodeURIComponent(input.stylesheet)}`,
    { body: { status: "active" } },
  );
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-theme-activate",
    resource: `themes/${input.stylesheet}`,
    resourceType: "themes",
    readPath: `/wp/v2/themes/${encodeURIComponent(input.stylesheet)}`,
    readQuery: {},
    sent: { status: "active" },
  });
  return attachVerification(compactTheme(result), verification);
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
  return {
    supported: false,
    action: "update",
    approval_required: true,
    agent_must_stop: true,
    hint: "Direct mode cannot write custom code because it has no authenticated wp-admin one-time-grant boundary. Use the Stonewright plugin tool stonewright-theme-custom-css: run dry_run, show the human approval URL, exact path, byte counts, and summary, then stop.",
  };
}
