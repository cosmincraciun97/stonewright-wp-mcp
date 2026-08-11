/**
 * Resolve STONEWRIGHT_SITE_ALIAS against the multi-site registry and inject
 * STONEWRIGHT_WP_* credentials into the process env used by the MCP server.
 *
 * Only the selected alias's secret is loaded — never every site in the registry.
 */
import { existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import {
	detectSchemaVersion,
	findSiteByAlias,
	findSiteById,
	parseRegistryV2,
	projectV1AsV2WithoutSecretMove,
	readRawSitesFile,
	resolveSitePassword,
	type LoadRegistryOptions,
} from '../cli/connect/registry.js';
import type { SitesRegistryV1, SitesRegistryV2 } from '../cli/connect/types.js';
import type { CreateCredentialStoreOptions } from '../credentials/index.js';
import { log } from '../lib/log.js';

export interface ApplySiteAliasResult {
	applied: boolean;
	alias: string | null;
	/** True when URL/username/password were written into env. */
	injected: boolean;
	error?: string | undefined;
}

function defaultSitesPath(env: NodeJS.ProcessEnv, home: string): string {
	const fromEnv = (env['STONEWRIGHT_SITES_FILE'] ?? '').trim();
	if (fromEnv) return fromEnv;
	return join(home, '.stonewright', 'sites.json');
}

/**
 * When `STONEWRIGHT_SITE_ALIAS` is set, load only that site from the registry
 * and inject `STONEWRIGHT_WP_URL`, `STONEWRIGHT_WP_USERNAME`, and
 * `STONEWRIGHT_WP_APP_PASSWORD` into `env`.
 *
 * An explicit alias is authoritative. It always replaces inherited or stale
 * WordPress environment values so one named client entry cannot start against
 * a different site's credentials.
 */
export function applySiteAliasToEnv(
	env: NodeJS.ProcessEnv = process.env,
	options: {
		sitesFile?: string | undefined;
		homeDir?: string | undefined;
		credentials?: CreateCredentialStoreOptions | undefined;
		/** @deprecated Alias selection is always authoritative. */
		force?: boolean | undefined;
	} = {},
): ApplySiteAliasResult {
	const aliasRaw = (env['STONEWRIGHT_SITE_ALIAS'] ?? '').trim();
	if (!aliasRaw) {
		return { applied: false, alias: null, injected: false };
	}

	const home = options.homeDir ?? homedir();
	const path = options.sitesFile ?? defaultSitesPath(env, home);

	if (!existsSync(path)) {
		const error = `STONEWRIGHT_SITE_ALIAS=${aliasRaw} set but sites file not found at ${path}`;
		log.warn(error);
		return { applied: false, alias: aliasRaw, injected: false, error };
	}

	let raw: unknown;
	try {
		raw = readRawSitesFile(path);
	} catch (err) {
		const error = err instanceof Error ? err.message : String(err);
		log.warn('Failed to read sites registry for site alias', { alias: aliasRaw, error });
		return { applied: false, alias: aliasRaw, injected: false, error };
	}

	const version = detectSchemaVersion(raw);
	let registry: SitesRegistryV2;
	if (version === 2) {
		registry = parseRegistryV2(raw);
	} else if (version === 1) {
		registry = projectV1AsV2WithoutSecretMove(raw as SitesRegistryV1);
	} else {
		const error = `Unrecognized sites registry schema at ${path}`;
		log.warn(error);
		return { applied: false, alias: aliasRaw, injected: false, error };
	}

	const site =
		findSiteByAlias(registry, aliasRaw) ??
		// Allow default_site_id when alias matches nothing but equals "default"
		(aliasRaw === 'default' && registry.default_site_id
			? findSiteById(registry, registry.default_site_id)
			: undefined);

	if (!site) {
		const known = registry.sites.map((s) => s.alias).join(', ') || '(none)';
		const error = `Unknown STONEWRIGHT_SITE_ALIAS "${aliasRaw}". Known: ${known}`;
		log.warn(error);
		return { applied: false, alias: aliasRaw, injected: false, error };
	}

	let password: string;
	try {
		const resolveOpts: {
			sitesFile: string;
			credentials?: CreateCredentialStoreOptions;
			legacyV1: SitesRegistryV1 | null;
		} = {
			sitesFile: path,
			legacyV1: version === 1 ? (raw as SitesRegistryV1) : null,
		};
		if (options.credentials) {
			resolveOpts.credentials = options.credentials;
		}
		password = resolveSitePassword(site, resolveOpts);
	} catch (err) {
		const error = err instanceof Error ? err.message : String(err);
		log.warn('Failed to resolve credential for site alias', { alias: site.alias, error });
		// Name the selected site, but fail closed instead of pairing it with a
		// password inherited from another MCP entry.
		env['STONEWRIGHT_WP_URL'] = site.canonical_url;
		env['STONEWRIGHT_WP_USERNAME'] = site.username_hint;
		env['STONEWRIGHT_WP_APP_PASSWORD'] = '';
		return { applied: true, alias: site.alias, injected: false, error };
	}

	env['STONEWRIGHT_WP_URL'] = site.canonical_url;
	env['STONEWRIGHT_WP_USERNAME'] = site.username_hint;
	env['STONEWRIGHT_WP_APP_PASSWORD'] = password;

	log.info('Applied STONEWRIGHT_SITE_ALIAS to runtime env', {
		alias: site.alias,
		url: site.canonical_url,
	});

	return { applied: true, alias: site.alias, injected: true };
}

export type { LoadRegistryOptions };
