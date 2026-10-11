import { assertToolEnabled, assertWriteAllowed, DirectSafetyBlockedError } from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import { attachVerification, verifyWrite } from "../verify.js";
import type { DirectToolContext } from "./types.js";

type WpPlugin = {
  plugin: string;
  status?: string | undefined;
  name?: string | undefined;
  plugin_uri?: string | undefined;
  author?: string | undefined;
  author_uri?: string | undefined;
  description?: { raw?: string; rendered?: string } | string;
  version?: string | undefined;
  network_only?: boolean | undefined;
  requires_wp?: string | undefined;
  requires_php?: string | undefined;
  textdomain?: string | undefined;
};

function descriptionOf(plugin: WpPlugin): string {
  if (typeof plugin.description === "string") return plugin.description;
  return plugin.description?.raw ?? plugin.description?.rendered ?? "";
}

function compactPlugin(plugin: WpPlugin) {
  return {
    plugin: plugin.plugin,
    status: plugin.status ?? "",
    name: plugin.name ?? "",
    version: plugin.version ?? "",
    author: plugin.author ?? "",
    description: descriptionOf(plugin),
    network_only: plugin.network_only === true,
  };
}

function encodePlugin(plugin: string): string {
  return plugin.split("/").map(encodeURIComponent).join("/");
}

/**
 * Plugin folders Direct never deactivates or deletes: Stonewright itself, and the MCP adapter that
 * carries the companion's connection. A plugin that only has "stonewright" in its name is another plugin.
 */
const PROTECTED_PLUGIN_FOLDERS = new Set(["stonewright", "mcp-adapter"]);

function protectedPluginFolder(plugin: string): string | null {
  const normalized = plugin
    .trim()
    .replace(/\\/g, "/")
    .replace(/^\/+/, "")
    .toLowerCase()
    .replace(/\.php$/, "");
  const folder = normalized.split("/")[0] ?? "";
  return PROTECTED_PLUGIN_FOLDERS.has(folder) ? folder : null;
}

function selfProtectionError(tool: string, plugin: string, site: string, why: string) {
  return new DirectSafetyBlockedError(
    "self_protection",
    `Refusing ${tool} for "${plugin}": ${why} Nothing was changed. Do this in wp-admin if it is really intended.`,
    tool,
    site,
  );
}

/**
 * Refuses a plugin that is Stonewright or the connection plugin. The path decides first, with no request;
 * then the installed plugin's own header is read, so a renamed Stonewright folder is still recognised.
 */
async function assertPluginNotProtected(
  ctx: DirectToolContext,
  plugin: string,
  tool: string,
): Promise<void> {
  const folder = protectedPluginFolder(plugin);
  if (folder !== null) {
    throw selfProtectionError(
      tool,
      plugin,
      ctx.site.alias,
      folder === "stonewright"
        ? "this is Stonewright itself."
        : "this is the plugin that carries the Stonewright connection (MCP adapter).",
    );
  }
  const installed = await ctx.client.get<WpPlugin>(`/wp/v2/plugins/${encodePlugin(plugin.trim())}`);
  const textdomain = (installed?.textdomain ?? "").trim().toLowerCase();
  const name = (installed?.name ?? "").trim().toLowerCase();
  if (textdomain === "stonewright" || name === "stonewright") {
    throw selfProtectionError(tool, plugin, ctx.site.alias, "this is Stonewright itself.");
  }
}

export async function pluginList(
  ctx: DirectToolContext,
  input: { status?: string | undefined; search?: string | undefined } = {},
) {
  assertToolEnabled(ctx.site, "stonewright-plugin-list");
  const items = await ctx.client.get<WpPlugin[]>("/wp/v2/plugins", {
    query: {
      status: input.status,
      search: input.search,
    },
  });
  const list = Array.isArray(items) ? items : [];
  return {
    items: list.map(compactPlugin),
    total: list.length,
  };
}

export async function pluginActivate(
  ctx: DirectToolContext,
  input: { plugin: string; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-plugin-activate");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-plugin-activate",
  });
  const plugin = input.plugin.trim();
  if (!plugin) throw new Error("plugin is required (e.g. akismet/akismet)");
  let result: WpPlugin;
  try {
    result = await ctx.client.put<WpPlugin>(
      `/wp/v2/plugins/${encodePlugin(plugin)}`,
      {
        body: { status: "active" },
      },
    );
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-plugin-activate",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: plugin,
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-plugin-activate",
    resource: plugin,
    resourceType: "plugins",
    readPath: `/wp/v2/plugins/${encodePlugin(plugin)}`,
    readQuery: {},
    sent: { status: "active" },
  });
  return attachVerification(compactPlugin(result), verification);
}

export async function pluginDeactivate(
  ctx: DirectToolContext,
  input: { plugin: string; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-plugin-deactivate");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-plugin-deactivate",
  });
  const plugin = input.plugin.trim();
  if (!plugin) throw new Error("plugin is required (e.g. akismet/akismet)");
  await assertPluginNotProtected(ctx, plugin, "stonewright-plugin-deactivate");
  let result: WpPlugin;
  try {
    result = await ctx.client.put<WpPlugin>(
      `/wp/v2/plugins/${encodePlugin(plugin)}`,
      {
        body: { status: "inactive" },
      },
    );
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-plugin-deactivate",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: plugin,
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-plugin-deactivate",
    resource: plugin,
    resourceType: "plugins",
    readPath: `/wp/v2/plugins/${encodePlugin(plugin)}`,
    readQuery: {},
    sent: { status: "inactive" },
  });
  return attachVerification(compactPlugin(result), verification);
}

export async function pluginInstall(
  ctx: DirectToolContext,
  input: {
    slug: string;
    status?: "active" | "inactive" | undefined;
    confirm?: boolean | undefined;
  },
) {
  assertToolEnabled(ctx.site, "stonewright-plugin-install");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-plugin-install",
  });
  const slug = input.slug.trim();
  if (!slug) throw new Error("slug is required (WordPress.org plugin slug)");
  let result: WpPlugin;
  try {
    result = await ctx.client.post<WpPlugin>("/wp/v2/plugins", {
      body: {
        slug,
        status: input.status ?? "inactive",
      },
    });
  } catch (err) {
    appendDirectAudit({
      tool: "stonewright-plugin-install",
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: slug,
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-plugin-install",
    resource: slug,
    resourceType: "plugins",
    readPath: `/wp/v2/plugins/${encodePlugin(result.plugin ?? slug)}`,
    readQuery: {},
    sent: { status: input.status ?? "inactive" },
  });
  return attachVerification(compactPlugin(result), verification);
}

export async function pluginGet(
  ctx: DirectToolContext,
  input: { plugin: string },
) {
  assertToolEnabled(ctx.site, "stonewright-plugin-get");
  const plugin = input.plugin.trim();
  if (!plugin) throw new Error("plugin is required (e.g. akismet/akismet)");
  const result = await ctx.client.get<WpPlugin>(
    `/wp/v2/plugins/${encodePlugin(plugin)}`,
  );
  return compactPlugin(result);
}

export async function pluginDelete(
  ctx: DirectToolContext,
  input: { plugin: string; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, "stonewright-plugin-delete");
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: "stonewright-plugin-delete",
    env: ctx.env ?? process.env,
    // Asks for a confirmation in every write mode.
    alwaysConfirm: true,
  });
  const plugin = input.plugin.trim();
  if (!plugin) throw new Error("plugin is required (e.g. akismet/akismet)");
  await assertPluginNotProtected(ctx, plugin, "stonewright-plugin-delete");
  const result = await ctx.client.del(`/wp/v2/plugins/${encodePlugin(plugin)}`);
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: "stonewright-plugin-delete",
    resource: plugin,
    resourceType: "plugins",
    readPath: `/wp/v2/plugins/${encodePlugin(plugin)}`,
    readQuery: {},
    expect: "absent",
  });
  return attachVerification(
    (result as object | undefined) ?? {
      deleted: true,
      plugin,
      note: "Plugin must be inactive first.",
    },
    verification,
  );
}
