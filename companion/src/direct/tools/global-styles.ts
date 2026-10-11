import { assertToolEnabled, assertWriteAllowed } from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import { refuseCustomCode, scanGlobalStyles } from "../custom-code-guard.js";
import { storePreImage } from "../preimage.js";
import { attachVerification, verifyWrite } from "../verify.js";
import type { DirectToolContext } from "./types.js";

type WpGlobalStyles = {
  id: string;
  title?: { raw?: string; rendered?: string } | string;
  settings?: Record<string, unknown> | undefined;
  styles?: Record<string, unknown> | undefined;
};

function titleOf(item: WpGlobalStyles): string {
  if (typeof item.title === "string") return item.title;
  return item.title?.raw ?? item.title?.rendered ?? "";
}

export async function globalStylesGet(
  ctx: DirectToolContext,
  input: { id?: string | undefined } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-global-styles-get");
  const id = (input.id ?? "themes").trim() || "themes";
  const styles = await ctx.client.get<WpGlobalStyles>(
    `/wp/v2/global-styles/${encodeURIComponent(id)}`,
    {
      query: { context: "edit" },
    },
  );
  return {
    id: styles.id,
    title: titleOf(styles),
    settings: styles.settings ?? {},
    styles: styles.styles ?? {},
  };
}

export async function globalStylesUpdate(
  ctx: DirectToolContext,
  input: {
    id?: string | undefined;
    settings?: Record<string, unknown> | undefined;
    styles?: Record<string, unknown> | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-global-styles-update");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-global-styles-update",
  });
  const id = (input.id ?? "themes").trim() || "themes";
  const refusal = refuseCustomCode(
    ctx.site,
    "stonewright-global-styles-update",
    `global-styles/${id}`,
    scanGlobalStyles({ settings: input.settings, styles: input.styles }),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  const restPath = `/wp/v2/global-styles/${encodeURIComponent(id)}`;
  const preImage = await storePreImage({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-global-styles-update",
    restPath,
    resourceId: id,
  });
  const body: Record<string, unknown> = {};
  if (input.settings !== undefined) body.settings = input.settings;
  if (input.styles !== undefined) body.styles = input.styles;
  let styles: WpGlobalStyles;
  try {
    styles = await ctx.client.post<WpGlobalStyles>(restPath, { body });
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-global-styles-update",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: `global-styles/${id}`,
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-global-styles-update",
    resource: `global-styles/${id}`,
    resourceType: "global-styles",
    readPath: restPath,
    sent: body,
    beforeSha256: preImage.before_sha256,
    backupId: preImage.backup_id,
  });
  return attachVerification(
    {
      id: styles.id,
      title: titleOf(styles),
      settings: styles.settings ?? {},
      styles: styles.styles ?? {},
      ...preImage,
    },
    verification,
    { backupId: preImage.backup_id },
  );
}

export async function globalStylesTheme(
  ctx: DirectToolContext,
  input: {
    stylesheet?: string | undefined;
    variations?: boolean | undefined;
  } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-global-styles-theme");
  let stylesheet = input.stylesheet?.trim() ?? "";
  if (!stylesheet) {
    const themes = await ctx.client.get<Array<{ stylesheet?: string }>>(
      "/wp/v2/themes",
      {
        query: { status: "active" },
      },
    );
    const active = Array.isArray(themes) ? themes[0] : undefined;
    stylesheet = active?.stylesheet ?? "";
    if (!stylesheet) {
      throw new Error("Could not resolve active theme stylesheet");
    }
  }
  const path = input.variations
    ? `/wp/v2/global-styles/themes/${encodeURIComponent(stylesheet)}/variations`
    : `/wp/v2/global-styles/themes/${encodeURIComponent(stylesheet)}`;
  return ctx.client.get(path);
}

export async function globalStylesRevisions(
  ctx: DirectToolContext,
  input: { parent: string; revision_id?: number | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-global-styles-revisions");
  const base = `/wp/v2/global-styles/${encodeURIComponent(input.parent)}/revisions`;
  if (input.revision_id !== undefined) {
    return ctx.client.get(`${base}/${input.revision_id}`);
  }
  return ctx.client.get(base);
}
