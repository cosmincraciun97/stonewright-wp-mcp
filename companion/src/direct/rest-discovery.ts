import type { WpRestClient } from './wp-rest-client.js';

/**
 * Cached slug -> rest_base resolution for post types and taxonomies.
 *
 * Core REST exposes registered models at /wp/v2/types and /wp/v2/taxonomies.
 * A CPT registered with a rest_base different from its slug (for example
 * post type `sector` with rest_base `sectors`) would otherwise 404 when the
 * slug is used as the collection path. Discovery only runs for non-builtin
 * names and the result is cached per site for the process lifetime.
 */

export type RestDiscoveryKind = 'types' | 'taxonomies';

type DiscoveredRow = {
	slug?: string | undefined;
	rest_base?: string | undefined;
};

const cache = new Map<string, Map<string, string>>();

function cacheKey(client: WpRestClient, kind: RestDiscoveryKind): string {
	return `${client.restBase}:${kind}`;
}

export function clearRestDiscoveryCache(): void {
	cache.clear();
}

export async function resolveRestBase(
	client: WpRestClient,
	kind: RestDiscoveryKind,
	slug: string,
): Promise<string | null> {
	const key = cacheKey(client, kind);
	let map = cache.get(key);
	if (!map) {
		try {
			const rows = await client.get<Record<string, DiscoveredRow>>(`/wp/v2/${kind}`);
			map = new Map<string, string>();
			for (const row of Object.values(rows ?? {})) {
				const restBase = (row?.rest_base ?? '').trim();
				if (!restBase) {
					continue;
				}
				const rowSlug = (row?.slug ?? '').trim().toLowerCase();
				if (rowSlug) {
					map.set(rowSlug, restBase);
				}
				// Accept a rest_base passed directly as the type/taxonomy input.
				map.set(restBase.toLowerCase(), restBase);
			}
			cache.set(key, map);
		} catch {
			// Discovery endpoint unavailable (auth, host filter); caller falls back
			// to the raw input so slug==rest_base models keep working.
			return null;
		}
	}
	return map.get(slug.toLowerCase()) ?? null;
}
