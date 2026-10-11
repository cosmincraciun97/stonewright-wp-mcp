/**
 * The block types a site has registered, read from core REST (`/wp/v2/block-types`).
 *
 * The list needs a signed-in user who can edit posts. When it cannot be read (no permission, an older
 * WordPress, a proxy that hides it) the answer is `unavailable` and nothing that needs the registry is
 * refused: a block name is checked only where the site says what it knows. A readable list is kept for
 * ten minutes per site, because every validation and every block write asks for it.
 */

import { normalizeBlockName } from './block-markup.js';

export type BlockRegistry =
	| { status: 'checked'; names: ReadonlySet<string> }
	| { status: 'unavailable'; reason: string };

export interface BlockRegistryClient {
	get: <T>(path: string, opts?: { query?: Record<string, string | number | boolean | null | undefined> }) => Promise<T>;
}

const CACHE_MS = 10 * 60 * 1000;
const cache = new Map<string, { at: number; names: ReadonlySet<string> }>();

export function resetBlockRegistryCacheForTests(): void {
	cache.clear();
}

/** Names that are always valid in a document: the classic block and the placeholder for a missing one. */
const ALWAYS_KNOWN = ['core/freeform', 'core/missing'];

export async function readBlockRegistry(client: BlockRegistryClient, siteKey: string): Promise<BlockRegistry> {
	const key = siteKey.replace(/\/+$/, '');
	const hit = cache.get(key);
	if (hit !== undefined && Date.now() - hit.at < CACHE_MS) return { status: 'checked', names: hit.names };
	let rows: unknown;
	try {
		rows = await client.get<unknown>('/wp/v2/block-types', { query: { _fields: 'name' } });
	} catch (error) {
		const reason = error instanceof Error ? error.message : String(error);
		return { status: 'unavailable', reason: reason.replace(/\s+/g, ' ').slice(0, 160) };
	}
	if (!Array.isArray(rows)) return { status: 'unavailable', reason: 'unexpected answer' };
	const names = new Set<string>(ALWAYS_KNOWN);
	for (const row of rows) {
		const name = row !== null && typeof row === 'object' ? (row as { name?: unknown }).name : undefined;
		if (typeof name === 'string' && name !== '') names.add(name);
	}
	// An empty list says nothing about the site; treat it as unreadable rather than refusing every block.
	if (names.size <= ALWAYS_KNOWN.length) return { status: 'unavailable', reason: 'the site returned no block types' };
	cache.set(key, { at: Date.now(), names });
	return { status: 'checked', names };
}

export function isKnownBlock(registry: BlockRegistry, name: string): boolean {
	return registry.status !== 'checked' || registry.names.has(normalizeBlockName(name));
}
