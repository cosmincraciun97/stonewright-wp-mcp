/**
 * Which memory file a site's lessons live in.
 *
 * Memory used to be keyed by the registry alias, so pointing an alias at another site handed that site the
 * old site's memory, and two aliases for one site kept two memories. The file is now keyed by the site's
 * identity, its canonical URL: `<host>-<10 hex of the URL's fingerprint>`.
 *
 * Existing `memory/<alias>.jsonl` files are adopted once, carefully:
 *  - the first time an alias with such a file is seen, its entries are copied (ids and timestamps kept) into
 *    the file of the site the alias points at, and the pairing is recorded in `memory/_site-bindings.json`;
 *  - the old file is never moved, rewritten or deleted;
 *  - while the alias still points at the same site, its old file is still read (see legacyScope), so a
 *    lesson an older companion writes there is not lost;
 *  - once the alias points at another site, the old file is no longer read for it. Its lessons stay with
 *    the site they were adopted for.
 */
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { directIncidentFingerprint } from './incidents.js';
import { defaultStonewrightDir } from './skills-store.js';
import { importMemoryEntries, listMemory } from './memory-store.js';

const BINDINGS_FILE = '_site-bindings.json';
const SCOPE_RE = /^[a-z0-9_][a-z0-9_.-]{0,63}$/;

type BindingFile = {
	version: 1;
	aliases: Record<string, { site_key: string; adopted_at: string }>;
};

/** The memory scope of a site: its host and a short fingerprint of the canonical URL. */
export function siteMemoryKey(siteUrl: string): string {
	const fingerprint = directIncidentFingerprint(siteUrl).slice(0, 10);
	let host = 'site';
	try {
		host = new URL(siteUrl.trim()).hostname.toLowerCase();
	} catch {
		// directIncidentFingerprint already refused a value that is not a URL.
	}
	const label = host.replace(/[^a-z0-9.-]+/g, '-').replace(/^[^a-z0-9]+/, '').slice(0, 40) || 'site';
	return `${label}-${fingerprint}`;
}

function bindingsPath(baseDir: string): string {
	return join(baseDir, 'memory', BINDINGS_FILE);
}

function readBindings(baseDir: string): BindingFile {
	const path = bindingsPath(baseDir);
	if (!existsSync(path)) return { version: 1, aliases: {} };
	try {
		const parsed = JSON.parse(readFileSync(path, 'utf8')) as Partial<BindingFile>;
		if (parsed && typeof parsed === 'object' && parsed.aliases && typeof parsed.aliases === 'object') {
			return { version: 1, aliases: parsed.aliases };
		}
	} catch {
		// An unreadable file is treated as empty; the old memory files are untouched either way.
	}
	return { version: 1, aliases: {} };
}

function writeBindings(baseDir: string, bindings: BindingFile): void {
	const dir = join(baseDir, 'memory');
	mkdirSync(dir, { recursive: true, mode: 0o700 });
	const path = bindingsPath(baseDir);
	const temp = `${path}.${randomUUID()}.tmp`;
	writeFileSync(temp, `${JSON.stringify(bindings, null, 2)}\n`, { encoding: 'utf8', mode: 0o600 });
	renameSync(temp, path);
}

export type SiteMemoryScope = {
	/** The file key lessons are written to and read from. */
	memoryScope: string;
	/** The alias-keyed file that is still read for this site, or null. */
	legacyScope: string | null;
};

/**
 * The memory scope for a site, adopting the alias's old memory file the first time it is seen.
 * `alias` and `siteUrl` come from the resolved site.
 */
export function resolveSiteMemoryScope(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	alias: string;
	siteUrl: string;
}): SiteMemoryScope {
	const baseDir = input.baseDir ?? defaultStonewrightDir(input.env);
	const memoryScope = siteMemoryKey(input.siteUrl);
	const alias = input.alias;
	if (!SCOPE_RE.test(alias) || alias === memoryScope) return { memoryScope, legacyScope: null };
	const legacyFile = join(baseDir, 'memory', `${alias}.jsonl`);
	if (!existsSync(legacyFile)) return { memoryScope, legacyScope: null };

	const bindings = readBindings(baseDir);
	const bound = bindings.aliases[alias];
	if (bound === undefined) {
		// First sight of this alias with an old file: adopt it for the site the alias points at now.
		const legacy = listMemory({ baseDir, scope: alias, limit: Number.MAX_SAFE_INTEGER }).items;
		importMemoryEntries({ baseDir, scope: memoryScope, entries: legacy.slice().reverse() });
		bindings.aliases[alias] = { site_key: memoryScope, adopted_at: new Date().toISOString() };
		writeBindings(baseDir, bindings);
		return { memoryScope, legacyScope: alias };
	}
	return { memoryScope, legacyScope: bound.site_key === memoryScope ? alias : null };
}
