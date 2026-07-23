import { appendDirectAudit, recentRecurringErrors } from "../audit.js";
import {
  getMemory,
  listMemory,
  memoryStorageRef,
  recordMemory,
  type MemoryKind,
} from "../memory-store.js";
import { loadSitesConfig, resolveSite } from "../sites-config.js";
import {
  deleteSkill,
  getSkill,
  listSkills,
  matchSkills,
  saveSkill,
  defaultStonewrightDir,
  seedBuiltinSkills,
  type SkillMeta,
} from "../skills-store.js";
import { PLUGIN_ONLY_CAPABILITIES } from "./site-discover.js";
import { markTaskStartSeen, resolveDirectWriteMode } from "../writes.js";
import { ensureStonewrightAgentsMd, pointerInstalled } from "../agents-md.js";
import { permanentRulesGuidance } from "../permanent-rules.js";
import { createHash, randomBytes } from "node:crypto";
import type { SitesConfig, ResolvedSite } from "../sites-config.js";
import { WpRestClient, WpRestError } from "../wp-rest-client.js";

export type SelfImproveContext = {
  env: NodeJS.ProcessEnv;
  baseDir?: string;
  fetchImpl?: typeof fetch;
  timeoutMs?: number;
  sitesConfig?: SitesConfig;
  /** Injected at registration time to avoid circular imports with registry.ts */
  directToolCount?: number;
};

type TargetBinding = {
  backend: "plugin" | "direct-site-local" | "direct-global";
  siteAlias: string | null;
  normalizedUrl: string | null;
  siteFingerprint: string;
  contextToken: string;
  expiresAt: string;
};

const targetBindings = new Map<string, TargetBinding>();

function stateDir(ctx: SelfImproveContext): string {
  return ctx.baseDir ?? defaultStonewrightDir(ctx.env);
}

function bindingKey(ctx: SelfImproveContext, siteAlias: string | null): string {
  return `${stateDir(ctx)}\0${siteAlias ?? "_global"}`;
}

function configuredSite(
  ctx: SelfImproveContext,
  alias?: string,
): ResolvedSite | null {
  try {
    const config = ctx.sitesConfig ?? loadSitesConfig({ env: ctx.env });
    return resolveSite(config, alias);
  } catch (err) {
    if ((alias ?? "").trim()) {
      throw err;
    }
    return null;
  }
}

function fingerprint(url: string): string {
  return createHash("sha256").update(`${url.replace(/\/+$/, "")}|1`).digest("hex");
}

export type ResolveScopeOptions = {
  /** When true, missing site config falls back to _global (reads/skills only). */
  allowGlobalFallback?: boolean;
  /** Explicit global intent (writes to _global.jsonl). */
  global?: boolean;
  /** Require a resolved non-global site (learning project/user writes). */
  requireSite?: boolean;
};

/**
 * Resolve memory/skills scope. Never silently maps unknown aliases to _global.
 * Unknown explicit site aliases throw with code=site_alias_unresolved.
 */
export function resolveSelfImproveScope(
  ctx: SelfImproveContext,
  site?: string,
  options: ResolveScopeOptions = {},
): { scope: string; siteAlias: string | null; baseDir: string } {
  const baseDir = stateDir(ctx);
  const allowGlobalFallback = options.allowGlobalFallback !== false;
  const explicitSite = (site ?? "").trim();

  if (options.global === true || explicitSite === "_global") {
    return { scope: "_global", siteAlias: null, baseDir };
  }

  if (explicitSite) {
    try {
      const config = ctx.sitesConfig ?? loadSitesConfig({ env: ctx.env });
      const resolved = resolveSite(config, explicitSite);
      return { scope: resolved.alias, siteAlias: resolved.alias, baseDir };
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err);
      throw new Error(
        `Unknown or unresolvable site alias "${explicitSite}". ${msg} code=site_alias_unresolved`,
      );
    }
  }

  try {
    const config = ctx.sitesConfig ?? loadSitesConfig({ env: ctx.env });
    const resolved = resolveSite(config, undefined);
    return { scope: resolved.alias, siteAlias: resolved.alias, baseDir };
  } catch {
    if (options.requireSite) {
      throw new Error(
        "No site bound for this operation. Pass site alias, bind task-start to a site, or set global:true for global memory. code=site_required",
      );
    }
    if (allowGlobalFallback) {
      return { scope: "_global", siteAlias: null, baseDir };
    }
    throw new Error(
      "No site bound and global fallback disabled. code=site_required",
    );
  }
}

function mergeIndex(scopes: string[], baseDir: string): SkillMeta[] {
  const seen = new Set<string>();
  const out: SkillMeta[] = [];
  for (const scope of scopes) {
    for (const item of listSkills({ baseDir, scope }).items) {
      const key = `${scope}:${item.slug}`;
      if (seen.has(item.slug) || seen.has(key)) {
        continue;
      }
      seen.add(item.slug);
      out.push(item);
    }
  }
  return out;
}

function skillScopes(scope: string): string[] {
  return scope === "_global"
    ? ["_global", "_builtin"]
    : [scope, "_global", "_builtin"];
}

export function skillList(
  ctx: SelfImproveContext,
  input: { site?: string } = {},
) {
  const { scope, baseDir } = resolveSelfImproveScope(ctx, input.site);
  seedBuiltinSkills(baseDir, ctx.env);
  return { scope, items: mergeIndex(skillScopes(scope), baseDir) };
}

export function skillGet(
  ctx: SelfImproveContext,
  input: { slug: string; site?: string },
) {
  const { scope, baseDir } = resolveSelfImproveScope(ctx, input.site);
  seedBuiltinSkills(baseDir, ctx.env);
  for (const s of skillScopes(scope)) {
    try {
      return getSkill({ baseDir, scope: s, slug: input.slug });
    } catch {
      // try next
    }
  }
  throw new Error(`Skill not found: ${input.slug}`);
}

export function skillSave(
  ctx: SelfImproveContext,
  input: {
    slug: string;
    name: string;
    description: string;
    triggers: string[];
    body: string;
    enabled?: boolean;
    global?: boolean;
    site?: string;
  },
) {
  const resolved = resolveSelfImproveScope(ctx, input.site);
  const scope = input.global ? "_global" : resolved.scope;
  const meta = saveSkill({
    baseDir: resolved.baseDir,
    scope,
    slug: input.slug,
    name: input.name,
    description: input.description,
    triggers: input.triggers,
    body: input.body,
    ...(input.enabled !== undefined ? { enabled: input.enabled } : {}),
  });
  appendDirectAudit({
    tool: "stonewright-skill-save",
    site: resolved.siteAlias ?? "_global",
    resource: `${scope}/${input.slug}`,
    status: "ok",
  });
  return meta;
}

export function skillDelete(
  ctx: SelfImproveContext,
  input: { slug: string; confirm?: boolean; global?: boolean; site?: string },
) {
  if (input.confirm !== true) {
    throw new Error("stonewright-skill-delete requires confirm:true");
  }
  const resolved = resolveSelfImproveScope(ctx, input.site);
  const scope = input.global ? "_global" : resolved.scope;
  const result = deleteSkill({
    baseDir: resolved.baseDir,
    scope,
    slug: input.slug,
  });
  appendDirectAudit({
    tool: "stonewright-skill-delete",
    site: resolved.siteAlias ?? "_global",
    resource: `${scope}/${input.slug}`,
    status: "ok",
  });
  return result;
}

export function memoryList(
  ctx: SelfImproveContext,
  input: { limit?: number; site?: string } = {},
) {
  const { scope, baseDir } = resolveSelfImproveScope(ctx, input.site);
  const scopes = scope === "_global" ? ["_global"] : [scope, "_global"];
  const items = scopes.flatMap(
    (s) => listMemory({ baseDir, scope: s, limit: input.limit ?? 20 }).items,
  );
  items.sort((a, b) => b.ts.localeCompare(a.ts));
  return { scope, items: items.slice(0, input.limit ?? 20) };
}

export type LearningRecordInput = {
  /** Canonical fields (preferred). */
  topic?: string;
  correction?: string;
  scope?: string;
  source?: string;
  evidence?: string;
  /** Explicit global memory intent — required to write _global.jsonl. */
  global?: boolean;
  /** Legacy Direct free-text. */
  text?: string;
  kind?: MemoryKind;
  tags?: string[];
  draft_skill?: {
    slug: string;
    name: string;
    description: string;
    triggers: string[];
    body: string;
  };
  site?: string;
};

function normalizeLearningInput(input: LearningRecordInput): {
  topic: string;
  correction: string;
  kind: MemoryKind;
  source: string;
  tags: string[];
} {
  const correction = (input.correction ?? input.text ?? "").trim();
  let topic = (input.topic ?? "").trim();
  if (!correction) {
    throw new Error(
      "Provide topic+correction, or text (Direct legacy). code=learning_record_invalid",
    );
  }
  if (!topic) {
    topic = correction.split("\n")[0]?.slice(0, 80) || "correction";
  }
  const kind: MemoryKind =
    input.kind &&
    (["correction", "lesson", "preference", "fact"] as const).includes(
      input.kind,
    )
      ? input.kind
      : "correction";
  const source =
    input.source?.trim() ||
    (input.kind ? "explicit-user-request" : "explicit-user-request");
  return {
    topic,
    correction,
    kind,
    source,
    tags: input.tags ?? ["user-authored"],
  };
}

export function learningRecord(ctx: SelfImproveContext, input: LearningRecordInput) {
  const wantGlobal = input.global === true || input.scope === "global";
  const semanticScope =
    input.scope === "user" || input.scope === "project"
      ? input.scope
      : wantGlobal
        ? "global"
        : "project";
  const storeGlobally = wantGlobal || semanticScope === "user";

  // Explicit site alias: resolve or throw — never silent _global fallback.
  // Global and user memories write _global intentionally so every site reads them.
  // No site: use default site if configured, else pluginless local store.
  let scope: string;
  let siteAlias: string | null;
  let baseDir: string;
  if (storeGlobally) {
    ({ scope, siteAlias, baseDir } = resolveSelfImproveScope(ctx, "_global", {
      global: true,
    }));
  } else if ((input.site ?? "").trim()) {
    ({ scope, siteAlias, baseDir } = resolveSelfImproveScope(ctx, input.site, {
      allowGlobalFallback: false,
      requireSite: true,
    }));
  } else {
    ({ scope, siteAlias, baseDir } = resolveSelfImproveScope(ctx, undefined, {
      allowGlobalFallback: true,
    }));
  }

  if (!storeGlobally && (input.site ?? "").trim() && scope === "_global") {
    throw new Error(
      `Refusing to write project/user learning to _global for site "${input.site}". code=site_alias_unresolved`,
    );
  }

  const normalized = normalizeLearningInput(input);
  const entry = recordMemory({
    baseDir,
    scope,
    text: normalized.correction,
    kind: normalized.kind,
    tags: normalized.tags,
    topic: normalized.topic,
    source: normalized.source,
    dedupe: true,
  });

  const readback = getMemory({ baseDir, scope, id: entry.id });
  if (!readback || readback.text.trim() !== entry.text.trim()) {
    appendDirectAudit({
      tool: "stonewright-learning-record",
      site: siteAlias ?? scope,
      status: "error",
    });
    throw new Error(
      "Learning readback mismatch. code=memory_readback_mismatch",
    );
  }

  let skill: SkillMeta | null = null;
  if (input.draft_skill) {
    skill = saveSkill({
      baseDir,
      scope,
      slug: input.draft_skill.slug,
      name: input.draft_skill.name,
      description: input.draft_skill.description,
      triggers: input.draft_skill.triggers,
      body: input.draft_skill.body,
      enabled: false,
    });
  }
  appendDirectAudit({
    tool: "stonewright-learning-record",
    site: siteAlias ?? scope,
    status: "ok",
  });

  const memoryBackend =
    scope === "_global" ? "direct-global" : "direct-site-local";
  const visibility =
    "local-only (not visible in WordPress Stonewright Memory UI; companion ~/.stonewright/memory only)";

  return {
    stored: true,
    backend: "direct" as const,
    memory_backend: memoryBackend,
    scope: semanticScope,
    storage_scope: scope,
    site_alias: siteAlias,
    visibility,
    memory_type: normalized.kind,
    memory_id: entry.id,
    storage_ref: memoryStorageRef(scope, entry.id),
    verified: true,
    ok: true,
    memory_key: entry.id,
    memory: entry,
    skill,
    note:
      "Direct-local memory is machine-local. wp-admin cannot read this file; use export/import for cross-host sync.",
  };
}

export function taskStart(
  ctx: SelfImproveContext,
  input: { task: string; surface?: string; intent?: string; site?: string },
) {
  const { scope, baseDir, siteAlias } = resolveSelfImproveScope(
    ctx,
    input.site,
    { allowGlobalFallback: true },
  );
  seedBuiltinSkills(baseDir, ctx.env);
  ensureStonewrightAgentsMd(baseDir, ctx.env);
  markTaskStartSeen(siteAlias ?? scope);
  const memoryBackend =
    scope === "_global" ? "direct-global" : "direct-site-local";
  const memoryVisibility =
    "local-only (not visible in WordPress Stonewright Memory UI)";
  const resolvedSite = siteAlias ? configuredSite(ctx, siteAlias) : null;
  const normalizedUrl = resolvedSite?.url.replace(/\/+$/, "") ?? null;
  const contextToken = `swdctx_${randomBytes(24).toString("hex")}`;
  const expiresAt = new Date(Date.now() + 30 * 60_000).toISOString();
  const siteFingerprint = fingerprint(normalizedUrl ?? `_global:${scope}`);
  const binding: TargetBinding = {
    backend: scope === "_global" ? "direct-global" : "direct-site-local",
    siteAlias,
    normalizedUrl,
    siteFingerprint,
    contextToken,
    expiresAt,
  };
  targetBindings.set(bindingKey(ctx, siteAlias), binding);

  const taskText = [input.task, input.surface ?? "", input.intent ?? ""]
    .join(" ")
    .trim();
  const matched: SkillMeta[] = [];
  const seen = new Set<string>();
  for (const s of skillScopes(scope)) {
    for (const hit of matchSkills({
      baseDir,
      scope: s,
      task: taskText,
      limit: 5,
    })) {
      if (seen.has(hit.slug)) {
        continue;
      }
      seen.add(hit.slug);
      matched.push(hit);
    }
  }
  const memory = memoryList(ctx, {
    limit: 5,
    ...(input.site !== undefined ? { site: input.site } : {}),
  }).items;
  const writeMode = resolveDirectWriteMode(ctx.env, undefined);
  const recurring = recentRecurringErrors(baseDir, 3);
  const pointerOk = pointerInstalled(ctx.env);
  const agentsPath = joinStateAgents(baseDir);

  const guidance = [
    "Direct mode: core WordPress REST via Application Passwords; no plugin required.",
    "Destructive tools require confirm:true; writes honor STONEWRIGHT_DIRECT_WRITES.",
    ...permanentRulesGuidance(),
    'Content model: Direct mode fully edits EXISTING registered models — any CPT content via stonewright-content-* (type param), any taxonomy terms via stonewright-taxonomy-terms, ACF field values via stonewright-acf-fields-* (needs ACF "Show in REST"). Registering NEW post types, taxonomies, or field groups requires PHP running on the server: use the Stonewright plugin or theme/plugin code. No REST-only client can register models — do not build ad hoc plugins as a workaround; tell the user instead.',
    "If the user explicitly asks to remember a correction, call stonewright-learning-record and only claim success when the response has verified:true; report memory_id and scope.",
    "Load a matched skill body with stonewright-skill-get before acting on its topic.",
    "Never guess WordPress/Elementor/Gutenberg schemas — read first, research official docs when unknown, verify after writes.",
  ];
  if (recurring.length > 0) {
    guidance.unshift(
      "Fix recurring_errors first: read last_error, correct the cause, then record the fix with stonewright-learning-record.",
    );
  }
  if (!pointerOk) {
    guidance.push(
      "One-time setup available: call stonewright-agents-md-sync and offer the user the global pointer.",
    );
  }

  return {
    mode: "direct" as const,
    site: siteAlias,
    write_mode: writeMode,
    target_context: {
      backend: "direct",
      site_alias: siteAlias,
      normalized_url: normalizedUrl,
      site_fingerprint: siteFingerprint,
      environment_type:
        normalizedUrl && /(?:localhost|127\.0\.0\.1|\.local|\.test)(?::|$)/i.test(new URL(normalizedUrl).hostname)
          ? "local"
          : normalizedUrl
            ? "production"
            : "pluginless",
      stonewright_mode: writeMode,
      memory_backend: memoryBackend,
      memory_visibility: memoryVisibility,
      storage_scope: scope,
      tool_profile: "direct",
      expires_at: expiresAt,
      context_token: contextToken,
    },
    matched_skills: matched.map((s) => ({
      slug: s.slug,
      description: s.description,
      next: "call stonewright-skill-get with this slug and follow the playbook",
    })),
    memory_highlights: memory.slice(0, 5).map((m) => ({
      ts: m.ts,
      kind: m.kind,
      text: m.text,
    })),
    recurring_errors: recurring,
    setup: {
      agents_md: agentsPath,
      pointer_installed: pointerOk,
    },
    capabilities: {
      direct_tools: ctx.directToolCount ?? 0,
      plugin_only: PLUGIN_ONLY_CAPABILITIES.map((c) => c.id),
      content_model: {
        existing_models:
          "Full CRUD for any registered CPT (stonewright-content-* with type), taxonomy terms (stonewright-taxonomy-terms), and ACF field values (stonewright-acf-fields-*).",
        registration:
          "Plugin-only: registering new post types/taxonomies/field groups needs server-side PHP; core REST has no registration endpoint.",
      },
    },
    guidance,
  };
}

/**
 * Prefer the authoritative plugin site store when the typed bridge is
 * available. A 404 rest_no_route proves pluginless operation; auth, transport,
 * and server failures never silently fall back to local memory.
 */
export async function taskStartAuthoritative(
  ctx: SelfImproveContext,
  input: { task: string; surface?: string; intent?: string; site?: string },
) {
  const site = configuredSite(ctx, input.site);
  if (!site) {
    return taskStart(ctx, input);
  }

  const client = new WpRestClient(site, {
    fetchImpl: ctx.fetchImpl,
    timeoutMs: ctx.timeoutMs,
  });
  try {
    const plugin = await client.post<Record<string, unknown>>(
      "/stonewright/v1/direct/task-start",
      { body: input },
    );
    const target =
      plugin["target_context"] && typeof plugin["target_context"] === "object"
        ? (plugin["target_context"] as Record<string, unknown>)
        : {};
    const contextToken = String(plugin["context_token"] ?? "");
    const expiresAt = String(plugin["expires_at"] ?? "");
    const binding: TargetBinding = {
      backend: "plugin",
      siteAlias: site.alias,
      normalizedUrl: site.url,
      siteFingerprint: String(target["site_fingerprint"] ?? fingerprint(site.url)),
      contextToken,
      expiresAt,
    };
    targetBindings.set(bindingKey(ctx, site.alias), binding);
    markTaskStartSeen(site.alias);

    return {
      ...plugin,
      mode: "direct" as const,
      plugin_mode: String(plugin["mode"] ?? ""),
      site: site.alias,
      target_context: {
        ...target,
        backend: "plugin",
        site_alias: site.alias,
        normalized_url: site.url,
        memory_backend: "plugin-site",
        memory_visibility: "site-admin (Stonewright Memory UI)",
        context_token: contextToken,
        expires_at: expiresAt,
      },
    };
  } catch (err) {
    if (
      err instanceof WpRestError &&
      err.status === 404 &&
      err.code.includes("rest_no_route")
    ) {
      return taskStart(ctx, input);
    }
    throw err;
  }
}

export async function learningRecordAuthoritative(
  ctx: SelfImproveContext,
  input: LearningRecordInput,
) {
  if (input.global === true || input.scope === "global") {
    return learningRecord(ctx, input);
  }
  const site = configuredSite(ctx, input.site);
  if (!site) {
    return learningRecord(ctx, input);
  }
  const binding = targetBindings.get(bindingKey(ctx, site.alias));
  if (!binding) {
    throw new Error(
      `Call stonewright-task-start for site "${site.alias}" before learning-record. code=target_context_required`,
    );
  }
  if (Date.parse(binding.expiresAt) <= Date.now()) {
    targetBindings.delete(bindingKey(ctx, site.alias));
    throw new Error(
      `Target context expired for site "${site.alias}". Call stonewright-task-start again. code=target_context_expired`,
    );
  }
  if (binding.normalizedUrl !== site.url) {
    targetBindings.delete(bindingKey(ctx, site.alias));
    throw new Error(
      `Target URL changed for site "${site.alias}" after task-start. Call stonewright-task-start again. code=target_context_changed`,
    );
  }
  if (binding.backend !== "plugin") {
    return learningRecord(ctx, input);
  }

  const client = new WpRestClient(site, {
    fetchImpl: ctx.fetchImpl,
    timeoutMs: ctx.timeoutMs,
  });
  return client.post<Record<string, unknown>>(
    "/stonewright/v1/direct/learning-record",
    {
      body: {
        ...input,
        stonewright_context_token: binding.contextToken,
      },
    },
  );
}

function joinStateAgents(baseDir: string): string {
  return `${baseDir.replace(/\/$/, "")}/AGENTS.md`;
}
