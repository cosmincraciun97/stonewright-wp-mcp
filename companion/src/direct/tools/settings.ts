import {
  assertToolEnabled,
  assertWriteAllowed,
  DirectSafetyBlockedError,
} from "../writes.js";
import { appendDirectAudit } from "../audit.js";
import { refuseCustomCode, scanTextFields } from "../custom-code-guard.js";
import { attachVerification, verifyWrite } from "../verify.js";
import type { DirectToolContext } from "./types.js";

const TOOL = "stonewright-settings-update";

/**
 * Settings Direct writes. The list is the plugin's settings allowlist, under the names core REST uses
 * (title, description, timezone) and under the option names (blogname, blogdescription, timezone_string).
 * Anything else, the site address and the administrator email included, is refused.
 */
const ALLOWED_SETTINGS = new Set([
  "title",
  "description",
  "blogname",
  "blogdescription",
  "site_icon",
  "timezone",
  "timezone_string",
  "date_format",
  "time_format",
  "start_of_week",
  "posts_per_page",
  "default_comment_status",
  "default_ping_status",
  "users_can_register",
  "default_role",
  "show_on_front",
  "page_on_front",
  "page_for_posts",
]);

/**
 * Roles for `default_role`: the core roles that hold none of the capabilities that administer the site.
 * Direct cannot read a custom role's capabilities over REST, so every other role is refused.
 */
const SAFE_DEFAULT_ROLES = new Set(["subscriber", "contributor", "author", "editor"]);

function refuse(code: string, message: string, site: string): DirectSafetyBlockedError {
  return new DirectSafetyBlockedError(code, message, TOOL, site);
}

function assertSettingsAllowed(settings: Record<string, unknown>, site: string): void {
  const refused = Object.keys(settings).filter((key) => !ALLOWED_SETTINGS.has(key));
  if (refused.length > 0) {
    throw refuse(
      "settings_key_not_allowed",
      `Direct does not write these settings: ${refused.join(", ")}. Allowed: ${[...ALLOWED_SETTINGS].join(", ")}. Nothing was changed.`,
      site,
    );
  }
  for (const [key, value] of Object.entries(settings)) {
    if (value !== null && typeof value === "object") {
      throw refuse(
        "settings_value_not_allowed",
        `Setting "${key}" must be a string, number, boolean or null. Nothing was changed.`,
        site,
      );
    }
  }
  if ("default_role" in settings) {
    const role = settings["default_role"];
    if (typeof role !== "string" || !SAFE_DEFAULT_ROLES.has(role.trim().toLowerCase())) {
      throw refuse(
        "settings_value_not_allowed",
        `default_role must be one of ${[...SAFE_DEFAULT_ROLES].join(", ")}: a role that can administer the site is never the default for new accounts, and Direct cannot read a custom role's capabilities. Nothing was changed.`,
        site,
      );
    }
  }
}

export async function settingsGet(ctx: DirectToolContext) {
  assertToolEnabled(ctx.site, "stonewright-settings-get");
  const settings =
    await ctx.client.get<Record<string, unknown>>("/wp/v2/settings");
  return { settings: settings ?? {} };
}

export async function settingsUpdate(
  ctx: DirectToolContext,
  input: { settings: Record<string, unknown>; confirm?: boolean | undefined },
) {
  assertToolEnabled(ctx.site, TOOL);
  assertWriteAllowed({
    site: ctx.site,
    mode: ctx.writeMode,
    destructive: true,
    ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
    tool: TOOL,
  });
  if (
    !input.settings ||
    typeof input.settings !== "object" ||
    Array.isArray(input.settings)
  ) {
    throw new Error("settings must be an object of option keys to update");
  }
  assertSettingsAllowed(input.settings, ctx.site.alias);
  const refusal = refuseCustomCode(
    ctx.site,
    TOOL,
    "settings",
    scanTextFields(input.settings),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  let settings: Record<string, unknown>;
  try {
    settings = await ctx.client.post<Record<string, unknown>>(
      "/wp/v2/settings",
      {
        body: input.settings,
      },
    );
  } catch (err) {
    appendDirectAudit({
      tool: TOOL,
      site: ctx.site.alias,
      targetIdentity: ctx.site.url,
      resource: "settings",
      status: "error",
    });
    throw err;
  }
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: TOOL,
    resource: "settings",
    resourceType: "settings",
    readPath: "/wp/v2/settings",
    readQuery: {},
    sent: input.settings,
  });
  return attachVerification({ settings: settings ?? {} }, verification);
}
