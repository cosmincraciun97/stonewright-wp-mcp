import { appendDirectAudit, defaultAuditPath } from "./audit.js";
import { checkLocalWpTarget } from "./elementor-target.js";
import { loadSitesConfig, resolveSite, type ResolvedSite, type SitesConfig } from "./sites-config.js";
import {
  assertWriteAllowed,
  DirectSafetyBlockedError,
  resolveDirectWriteMode,
} from "./writes.js";
import { wpCliBatchReason, wpCliDenyReason } from "../wp-cli-policy.js";
import { runWpCli } from "../wp-cli.js";

/**
 * Gate for the local WP-CLI tools in a Direct session.
 *
 * A read-oriented command runs as before. A command that can change the site is a Direct write: it needs the
 * task-start latch of the Direct site, `confirm: true` in confirm mode, and the local WordPress must be that
 * site. The run leaves an audit row. The commands the shared policy refuses (code, packages, config, the
 * database, users and roles, plugin and theme installs) stay refused, as in Plugin mode.
 */

export type WpCliClass = "read" | "write" | "denied";

export type DirectWpCliTool =
  | "stonewright-wp-cli-run"
  | "stonewright-wp-cli-batch-run"
  | "stonewright-wp-cli-job-start";

export interface DirectWpCliGateInput {
  tool: DirectWpCliTool;
  commands: readonly (readonly string[])[];
  confirm?: boolean | undefined;
  env: NodeJS.ProcessEnv;
  /** Where the run will point WP-CLI (the same inputs the runner receives). */
  target: { cwd?: string | undefined; path?: string | undefined; url?: string | undefined };
  /** Runs the local-install check; defaults to the real runner. */
  runner?: typeof runWpCli | undefined;
  sitesConfig?: SitesConfig | undefined;
}

export interface DirectWpCliGate {
  /** True when the call changes the site and passed the Direct write gate. */
  write: boolean;
  /** Records the outcome of an allowed write. A read records nothing. */
  finish: (outcome: { ok: boolean; code?: string | undefined }) => void;
}

/** read: the batch runner's read-oriented commands. denied: the shared deny-list. Anything else is a write. */
export function classifyWpCliArgv(argv: readonly string[]): WpCliClass {
  if (wpCliDenyReason(argv) !== undefined) return "denied";
  return wpCliBatchReason(argv) === undefined ? "read" : "write";
}

function resourceOf(commands: readonly (readonly string[])[]): string {
  // The command words only: arguments after them can hold values.
  const words = commands.map((argv) =>
    argv
      .filter((token) => !token.startsWith("-"))
      .slice(0, 3)
      .join(" "),
  );
  return `wp-cli:${words.join("; ")}`.slice(0, 200);
}

function blockedRow(
  input: DirectWpCliGateInput,
  site: Pick<ResolvedSite, "alias" | "url"> | null,
  code: string,
  message: string,
): void {
  try {
    appendDirectAudit(
      {
        tool: input.tool,
        site: site?.alias ?? "_global",
        targetIdentity: site?.url ?? "direct-global:_global",
        resource: resourceOf(input.commands),
        status: "blocked",
        code,
        error: message.slice(0, 200),
      },
      defaultAuditPath(input.env),
    );
  } catch {
    // The refusal stands even when the audit row cannot be written.
  }
}

function resolveDirectSite(input: DirectWpCliGateInput): ResolvedSite {
  try {
    const config = input.sitesConfig ?? loadSitesConfig({ env: input.env });
    return resolveSite(config);
  } catch (error) {
    const message = `Direct WP-CLI writes need the configured Direct site (its task-start, confirmation and audit trail), and it could not be resolved: ${error instanceof Error ? error.message.slice(0, 160) : "unknown error"}`;
    blockedRow(input, null, "direct_site_unresolved", message);
    throw new DirectSafetyBlockedError("direct_site_unresolved", message, input.tool);
  }
}

const NOT_A_WRITE: DirectWpCliGate = { write: false, finish: () => undefined };

export async function gateDirectWpCli(input: DirectWpCliGateInput): Promise<DirectWpCliGate> {
  let sawWrite = false;
  for (const argv of input.commands) {
    const kind = classifyWpCliArgv(argv);
    if (kind === "denied") {
      const reason = wpCliDenyReason(argv) ?? "Blocked WP-CLI command";
      blockedRow(input, null, "wp_cli_command_refused", reason);
      throw new Error(reason);
    }
    if (kind === "write") sawWrite = true;
  }
  if (!sawWrite) return NOT_A_WRITE;

  const site = resolveDirectSite(input);
  try {
    assertWriteAllowed({
      mode: resolveDirectWriteMode(input.env, site.url),
      destructive: true,
      ...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
      tool: input.tool,
      env: input.env,
      site,
    });
  } catch (error) {
    if (error instanceof DirectSafetyBlockedError) {
      blockedRow(input, site, error.code, error.message);
    }
    throw error;
  }

  const local = await checkLocalWpTarget(
    input.env,
    { url: site.url },
    {
      ...(input.target.cwd !== undefined ? { cwd: input.target.cwd } : {}),
      ...(input.target.path !== undefined ? { path: input.target.path } : {}),
      ...(input.target.url !== undefined ? { url: input.target.url } : {}),
    },
    input.runner ?? runWpCli,
  );
  if (!local.ok) {
    const message = `WP-CLI was not run: the local WordPress is not confirmed to be the Direct site "${site.alias}". Nothing was changed.`;
    blockedRow(input, site, "wp_cli_target_mismatch", message);
    throw new DirectSafetyBlockedError("wp_cli_target_mismatch", message, input.tool, site.alias);
  }

  let finished = false;
  return {
    write: true,
    finish: (outcome) => {
      if (finished) return;
      finished = true;
      try {
        appendDirectAudit(
          {
            tool: input.tool,
            site: site.alias,
            targetIdentity: site.url,
            resource: resourceOf(input.commands),
            status: outcome.ok ? "ok" : "error",
            ...(outcome.ok ? {} : { code: outcome.code ?? "wp_cli_failed" }),
          },
          defaultAuditPath(input.env),
        );
      } catch {
        // Best-effort audit, as for every Direct write.
      }
    },
  };
}
