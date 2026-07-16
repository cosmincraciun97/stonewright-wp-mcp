import { appendDirectAudit } from '../audit.js';
import { listMemory, recordMemory, type MemoryKind } from '../memory-store.js';
import { loadSitesConfig, resolveSite } from '../sites-config.js';
import {
	deleteSkill,
	getSkill,
	listSkills,
	matchSkills,
	saveSkill,
	defaultStonewrightDir,
	type SkillMeta,
} from '../skills-store.js';
import { PLUGIN_ONLY_CAPABILITIES } from './site-discover.js';
import { resolveDirectWriteMode } from '../writes.js';

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
		return { scope: '_global', siteAlias: null, baseDir };
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

export function skillList(ctx: SelfImproveContext, input: { site?: string } = {}) {
	const { scope, baseDir } = resolveSelfImproveScope(ctx, input.site);
	const scopes = scope === '_global' ? ['_global'] : [scope, '_global'];
	return { scope, items: mergeIndex(scopes, baseDir) };
}

export function skillGet(ctx: SelfImproveContext, input: { slug: string; site?: string }) {
	const { scope, baseDir } = resolveSelfImproveScope(ctx, input.site);
	const scopes = scope === '_global' ? ['_global'] : [scope, '_global'];
	for (const s of scopes) {
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
	const scope = input.global ? '_global' : resolved.scope;
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
		tool: 'stonewright-skill-save',
		site: resolved.siteAlias ?? '_global',
		resource: `${scope}/${input.slug}`,
		status: 'ok',
	});
	return meta;
}

export function skillDelete(
	ctx: SelfImproveContext,
	input: { slug: string; confirm?: boolean; global?: boolean; site?: string },
) {
	if (input.confirm !== true) {
		throw new Error('stonewright-skill-delete requires confirm:true');
	}
	const resolved = resolveSelfImproveScope(ctx, input.site);
	const scope = input.global ? '_global' : resolved.scope;
	const result = deleteSkill({ baseDir: resolved.baseDir, scope, slug: input.slug });
	appendDirectAudit({
		tool: 'stonewright-skill-delete',
		site: resolved.siteAlias ?? '_global',
		resource: `${scope}/${input.slug}`,
		status: 'ok',
	});
	return result;
}

export function memoryList(ctx: SelfImproveContext, input: { limit?: number; site?: string } = {}) {
	const { scope, baseDir } = resolveSelfImproveScope(ctx, input.site);
	const scopes = scope === '_global' ? ['_global'] : [scope, '_global'];
	const items = scopes.flatMap((s) => listMemory({ baseDir, scope: s, limit: input.limit ?? 20 }).items);
	items.sort((a, b) => b.ts.localeCompare(a.ts));
	return { scope, items: items.slice(0, input.limit ?? 20) };
}

export function learningRecord(
	ctx: SelfImproveContext,
	input: {
		text: string;
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
	},
) {
	const { scope, baseDir, siteAlias } = resolveSelfImproveScope(ctx, input.site);
	const entry = recordMemory({
		baseDir,
		scope,
		text: input.text,
		...(input.kind !== undefined ? { kind: input.kind } : {}),
		...(input.tags !== undefined ? { tags: input.tags } : {}),
	});
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
		tool: 'stonewright-learning-record',
		site: siteAlias ?? '_global',
		status: 'ok',
	});
	return { ok: true, memory: entry, skill };
}

export function taskStart(
	ctx: SelfImproveContext,
	input: { task: string; surface?: string; intent?: string; site?: string },
) {
	const { scope, baseDir, siteAlias } = resolveSelfImproveScope(ctx, input.site);
	const taskText = [input.task, input.surface ?? '', input.intent ?? ''].join(' ').trim();
	const scopes = scope === '_global' ? ['_global'] : [scope, '_global'];
	const matched: SkillMeta[] = [];
	const seen = new Set<string>();
	for (const s of scopes) {
		for (const hit of matchSkills({ baseDir, scope: s, task: taskText, limit: 5 })) {
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

	return {
		mode: 'direct' as const,
		site: siteAlias,
		write_mode: writeMode,
		matched_skills: matched.map((s) => ({
			slug: s.slug,
			description: s.description,
			next: 'call stonewright-skill-get with this slug and follow the playbook',
		})),
		memory_highlights: memory.slice(0, 5).map((m) => ({
			ts: m.ts,
			kind: m.kind,
			text: m.text,
		})),
		capabilities: {
			direct_tools: ctx.directToolCount ?? 0,
			plugin_only: PLUGIN_ONLY_CAPABILITIES.map((c) => c.id),
		},
		guidance: [
			'Direct mode: core WordPress REST via Application Passwords; no plugin required.',
			'Destructive tools require confirm:true; writes honor STONEWRIGHT_DIRECT_WRITES.',
			'If the user corrects a repeatable mistake, call stonewright-learning-record.',
			'Load a matched skill body with stonewright-skill-get before acting on its topic.',
		],
	};
}
