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

export type SelfImproveContext = {
  env: NodeJS.ProcessEnv;
  baseDir?: string;
  /** Injected at registration time to avoid circular imports with registry.ts */
  directToolCount?: number;
};

function stateDir(ctx: SelfImproveContext): string {
  return ctx.baseDir ?? defaultStonewrightDir(ctx.env);
}

export function resolveSelfImproveScope(
  ctx: SelfImproveContext,
  site?: string,
): { scope: string; siteAlias: string | null; baseDir: string } {
  const baseDir = stateDir(ctx);
  try {
    const config = loadSitesConfig({ env: ctx.env });
    const resolved = resolveSite(config, site);
    return { scope: resolved.alias, siteAlias: resolved.alias, baseDir };
  } catch {
    return { scope: "_global", siteAlias: null, baseDir };
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
  const { scope, baseDir, siteAlias } = resolveSelfImproveScope(
    ctx,
    input.site,
  );
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
      site: siteAlias ?? "_global",
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
    site: siteAlias ?? "_global",
    status: "ok",
  });

  const canonicalScope =
    input.scope === "user" || input.scope === "project"
      ? input.scope
      : "project";

  return {
    stored: true,
    backend: "direct" as const,
    scope: canonicalScope,
    memory_id: entry.id,
    storage_ref: memoryStorageRef(scope, entry.id),
    verified: true,
    ok: true,
    memory_key: entry.id,
    memory: entry,
    skill,
  };
}

export function taskStart(
  ctx: SelfImproveContext,
  input: { task: string; surface?: string; intent?: string; site?: string },
) {
  const { scope, baseDir, siteAlias } = resolveSelfImproveScope(
    ctx,
    input.site,
  );
  seedBuiltinSkills(baseDir, ctx.env);
  ensureStonewrightAgentsMd(baseDir, ctx.env);
  markTaskStartSeen(siteAlias ?? scope);

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

function joinStateAgents(baseDir: string): string {
  return `${baseDir.replace(/\/$/, "")}/AGENTS.md`;
}
