import {
	copyFileSync,
	existsSync,
	mkdirSync,
	readFileSync,
	readdirSync,
	renameSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SLUG_RE = /^[a-z0-9][a-z0-9-]{0,63}$/;
const SCOPE_RE = /^[a-z0-9_][a-z0-9_.-]{0,63}$/;
const MAX_BODY = 64_000;

export type SkillMeta = {
	slug: string;
	name: string;
	description: string;
	triggers: string[];
	enabled: boolean;
	updated_at: string;
};

export type Skill = SkillMeta & { body: string };

export function defaultStonewrightDir(env: NodeJS.ProcessEnv = process.env): string {
	const override = (env['STONEWRIGHT_STATE_DIR'] ?? '').trim();
	if (override) {
		return resolve(override);
	}
	return join(homedir(), '.stonewright');
}

function root(baseDir?: string, env?: NodeJS.ProcessEnv): string {
	return baseDir ?? defaultStonewrightDir(env);
}

function assertSlug(slug: string): void {
	if (!SLUG_RE.test(slug)) {
		throw new Error(`Invalid skill slug: ${slug}`);
	}
}

function assertScope(scope: string): void {
	if (!SCOPE_RE.test(scope)) {
		throw new Error(`Invalid skill scope: ${scope}`);
	}
}

function under(base: string, candidate: string): boolean {
	const b = resolve(base);
	const c = resolve(candidate);
	return c === b || c.startsWith(`${b}/`) || c.startsWith(`${b}\\`);
}

function skillsDir(baseDir: string | undefined, scope: string, env?: NodeJS.ProcessEnv): string {
	assertScope(scope);
	const base = resolve(join(root(baseDir, env), 'skills'));
	const dir = resolve(join(base, scope));
	if (!under(base, dir)) {
		throw new Error('Scope escapes skills dir');
	}
	return dir;
}

function skillPath(baseDir: string | undefined, scope: string, slug: string, env?: NodeJS.ProcessEnv): string {
	assertSlug(slug);
	const dir = skillsDir(baseDir, scope, env);
	const file = resolve(join(dir, `${slug}.md`));
	if (!under(dir, file)) {
		throw new Error('Slug escapes skills dir');
	}
	return file;
}

function serialize(meta: SkillMeta, body: string): string {
	const fm = {
		name: meta.name,
		description: meta.description,
		triggers: meta.triggers,
		enabled: meta.enabled,
		updated_at: meta.updated_at,
	};
	return `---\n${JSON.stringify(fm, null, 2)}\n---\n${body}`;
}

function parse(raw: string, slug: string): Skill {
	const m = raw.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n?([\s\S]*)$/);
	if (!m) {
		throw new Error(`Skill file missing frontmatter: ${slug}`);
	}
	const fm = JSON.parse(m[1]!) as {
		name?: string;
		description?: string;
		triggers?: string[];
		enabled?: boolean;
		updated_at?: string;
	};
	return {
		slug,
		name: String(fm.name ?? slug),
		description: String(fm.description ?? ''),
		triggers: Array.isArray(fm.triggers) ? fm.triggers.map(String) : [],
		enabled: fm.enabled !== false,
		updated_at: String(fm.updated_at ?? ''),
		body: m[2] ?? '',
	};
}

export function saveSkill(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	slug: string;
	name: string;
	description: string;
	triggers: string[];
	body: string;
	enabled?: boolean;
}): SkillMeta {
	assertSlug(input.slug);
	assertScope(input.scope);
	if (input.body.length > MAX_BODY) {
		throw new Error(`Skill body exceeds ${MAX_BODY} bytes`);
	}
	const dir = skillsDir(input.baseDir, input.scope, input.env);
	mkdirSync(dir, { recursive: true, mode: 0o700 });
	const meta: SkillMeta = {
		slug: input.slug,
		name: input.name,
		description: input.description,
		triggers: input.triggers,
		enabled: input.enabled !== false,
		updated_at: new Date().toISOString(),
	};
	const file = skillPath(input.baseDir, input.scope, input.slug, input.env);
	const tmp = `${file}.${process.pid}.tmp`;
	writeFileSync(tmp, serialize(meta, input.body), { encoding: 'utf8', mode: 0o600 });
	renameSync(tmp, file);
	return meta;
}

export function listSkills(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
}): { items: SkillMeta[] } {
	const dir = skillsDir(input.baseDir, input.scope, input.env);
	if (!existsSync(dir)) {
		return { items: [] };
	}
	const items: SkillMeta[] = [];
	for (const name of readdirSync(dir)) {
		if (!name.endsWith('.md')) {
			continue;
		}
		const slug = name.slice(0, -3);
		if (!SLUG_RE.test(slug)) {
			continue;
		}
		try {
			const skill = parse(readFileSync(join(dir, name), 'utf8'), slug);
			const { body: _body, ...meta } = skill;
			items.push(meta);
		} catch {
			// skip corrupt
		}
	}
	items.sort((a, b) => b.updated_at.localeCompare(a.updated_at));
	return { items };
}

export function getSkill(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	slug: string;
}): Skill {
	const file = skillPath(input.baseDir, input.scope, input.slug, input.env);
	if (!existsSync(file)) {
		throw new Error(`Skill not found: ${input.slug}`);
	}
	return parse(readFileSync(file, 'utf8'), input.slug);
}

export function deleteSkill(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	slug: string;
}): { deleted: true } {
	const file = skillPath(input.baseDir, input.scope, input.slug, input.env);
	if (existsSync(file)) {
		rmSync(file);
	}
	return { deleted: true };
}

export function matchSkills(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	task: string;
	limit?: number;
}): SkillMeta[] {
	const task = input.task.toLowerCase();
	const { items } = listSkills(input);
	const hits = items
		.filter((s) => s.enabled)
		.filter((s) => {
			const terms = [...s.triggers, s.name, s.slug].map((t) => t.toLowerCase()).filter(Boolean);
			return terms.some((t) => t.length > 0 && task.includes(t));
		})
		.sort((a, b) => {
			const la = Math.max(0, ...a.triggers.map((t) => t.length));
			const lb = Math.max(0, ...b.triggers.map((t) => t.length));
			return lb - la;
		});
	return hits.slice(0, input.limit ?? 5);
}

/**
 * Copy packaged built-in skills into `<state>/skills/_builtin/` only when missing.
 * User-edited files are never overwritten; deleted files are restored on next seed.
 */
export function seedBuiltinSkills(baseDir?: string, env: NodeJS.ProcessEnv = process.env): {
	seeded: string[];
	skipped: string[];
} {
	const rootDir = root(baseDir, env);
	const destDir = join(rootDir, 'skills', '_builtin');
	mkdirSync(destDir, { recursive: true, mode: 0o700 });

	const candidates = [
		// Packaged next to companion package root (npm pack includes skills-builtin/)
		join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'skills-builtin'),
		join(process.cwd(), 'skills-builtin'),
		join(process.cwd(), 'companion', 'skills-builtin'),
	];
	const srcDir = candidates.find((d) => existsSync(d));
	const seeded: string[] = [];
	const skipped: string[] = [];
	if (!srcDir) {
		return { seeded, skipped };
	}
	for (const name of readdirSync(srcDir)) {
		if (!name.endsWith('.md')) continue;
		const dest = join(destDir, name);
		if (existsSync(dest)) {
			skipped.push(name);
			continue;
		}
		copyFileSync(join(srcDir, name), dest);
		seeded.push(name);
	}
	return { seeded, skipped };
}
