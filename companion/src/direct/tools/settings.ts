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

/**
 * The plugin names three settings after their option (blogname, blogdescription, timezone_string); core REST
 * names them title, description and timezone. Direct keeps the REST names and accepts both.
 */
const OPTION_TO_REST: Readonly<Record<string, string>> = {
  blogname: "title",
  blogdescription: "description",
  timezone_string: "timezone",
};
const REST_TO_OPTION: Readonly<Record<string, string>> = Object.fromEntries(
  Object.entries(OPTION_TO_REST).map(([option, rest]) => [rest, option]),
);

/** Moves the option names to the REST names. The same setting under both names with two values is refused. */
function normalizeSettingNames(
  settings: Record<string, unknown>,
  site: string,
): Record<string, unknown> {
  const out: Record<string, unknown> = { ...settings };
  for (const [option, rest] of Object.entries(OPTION_TO_REST)) {
    if (!(option in out)) continue;
    const value = out[option];
    delete out[option];
    if (rest in out && JSON.stringify(out[rest]) !== JSON.stringify(value)) {
      throw refuse(
        "argument_alias_conflict",
        `"${option}" (the Plugin name) and "${rest}" were both given with different values. Send one of them. Nothing was changed.`,
        site,
      );
    }
    out[rest] = value;
  }
  return out;
}

/** The settings as core returns them, plus the plugin's option names beside the REST names. */
function withOptionNames(settings: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = { ...settings };
  for (const [rest, option] of Object.entries(REST_TO_OPTION)) {
    if (rest in settings && !(option in out)) out[option] = settings[rest];
  }
  return out;
}

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

/**
 * Settings the plugin reads that core REST does not expose (no `show_in_rest`). Direct cannot read them; it can
 * still write them, and the answer to a write says when the site kept a key.
 */
const NOT_READABLE_IN_DIRECT = ["default_role", "users_can_register"] as const;

const NOT_AVAILABLE_NOTE =
  "Core REST does not expose these settings, so Direct cannot read them. Read them in wp-admin (Settings > General) or in Plugin mode.";

export async function settingsGet(
  ctx: DirectToolContext,
  input: { keys?: string[] | undefined } = {},
): Promise<{
  settings: Record<string, unknown>;
  unknown_keys?: string[];
  not_available_in_direct?: string[];
  not_available_note?: string;
}> {
  assertToolEnabled(ctx.site, "stonewright-settings-get");
  const settings = withOptionNames(
    (await ctx.client.get<Record<string, unknown>>("/wp/v2/settings")) ?? {},
  );
  const keys = (input.keys ?? []).map((key) => key.trim()).filter(Boolean);
  const notReadable = (names: readonly string[]): string[] =>
    names.filter((name) => (NOT_READABLE_IN_DIRECT as readonly string[]).includes(name) && !(name in settings));
  const notAvailable = (names: readonly string[]) => {
    const list = notReadable(names);
    return list.length > 0 ? { not_available_in_direct: list, not_available_note: NOT_AVAILABLE_NOTE } : {};
  };
  if (keys.length === 0) return { settings, ...notAvailable(NOT_READABLE_IN_DIRECT) };
  const narrowed: Record<string, unknown> = {};
  const unknown: string[] = [];
  const unavailable = new Set(notReadable(keys));
  for (const key of keys) {
    if (key in settings) narrowed[key] = settings[key];
    else if (!unavailable.has(key)) unknown.push(key);
  }
  return {
    settings: narrowed,
    ...(unknown.length > 0 ? { unknown_keys: unknown } : {}),
    ...notAvailable(keys),
  };
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
  const requested = normalizeSettingNames(input.settings, ctx.site.alias);
  assertSettingsAllowed(requested, ctx.site.alias);
  const refusal = refuseCustomCode(
    ctx.site,
    TOOL,
    "settings",
    scanTextFields(requested),
    ctx.env ?? process.env,
  );
  if (refusal) return refusal;
  let settings: Record<string, unknown>;
  try {
    settings = await ctx.client.post<Record<string, unknown>>(
      "/wp/v2/settings",
      {
        body: requested,
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
  // Core REST exposes only some settings; a key it did not return was not stored. Those are reported as
  // ignored_keys, and the readback compares only the keys core can return (under the REST names sent).
  const returned = settings ?? {};
  const ignored = Object.keys(requested).filter((key) => !(key in returned));
  const stored = Object.fromEntries(Object.entries(requested).filter(([key]) => key in returned));
  const verification = await verifyWrite({
    client: ctx.client,
    site: ctx.site,
    env: ctx.env,
    tool: TOOL,
    resource: "settings",
    resourceType: "settings",
    readPath: "/wp/v2/settings",
    readQuery: {},
    sent: stored,
  });
  return attachVerification(
    {
      settings: withOptionNames(returned),
      ...(ignored.length > 0 ? { ignored_keys: ignored } : {}),
    },
    verification,
  );
}
