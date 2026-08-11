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
 * `STONEWRIGHT_WP_APP_PASSWORD` into `env` (overwriting empty values; leaving
 * non-empty explicit env credentials in place when already complete unless
 * `force` is true).
 */
export function applySiteAliasToEnv(
	env: NodeJS.ProcessEnv = process.env,
	options: {
		sitesFile?: string | undefined;
		homeDir?: string | undefined;
		credentials?: CreateCredentialStoreOptions | undefined;
		/** Overwrite existing STONEWRIGHT_WP_* even when already set. Default false. */
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

	const force = options.force === true;
	const existingUrl = (env['STONEWRIGHT_WP_URL'] ?? env['WP_API_URL'] ?? '').trim();
	const existingUser = (env['STONEWRIGHT_WP_USERNAME'] ?? env['WP_API_USERNAME'] ?? '').trim();
	const existingPass = (
		env['STONEWRIGHT_WP_APP_PASSWORD'] ??
		env['STONEWRIGHT_WP_PASSWORD'] ??
		env['WP_API_PASSWORD'] ??
		''
	).trim();

	// When force is off and all three are already present, skip secret resolution.
	if (!force && existingUrl && existingUser && existingPass) {
		return { applied: true, alias: site.alias, injected: false };
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
		// Still inject URL/username so probe/diagnostics can name the site.
		if (force || !existingUrl) {
			env['STONEWRIGHT_WP_URL'] = site.canonical_url;
		}
		if (force || !existingUser) {
			env['STONEWRIGHT_WP_USERNAME'] = site.username_hint;
		}
		return { applied: true, alias: site.alias, injected: false, error };
	}

	if (force || !existingUrl) {
		env['STONEWRIGHT_WP_URL'] = site.canonical_url;
	}
	if (force || !existingUser) {
		env['STONEWRIGHT_WP_USERNAME'] = site.username_hint;
	}
	if (force || !existingPass) {
		env['STONEWRIGHT_WP_APP_PASSWORD'] = password;
	}

	log.info('Applied STONEWRIGHT_SITE_ALIAS to runtime env', {
		alias: site.alias,
		url: site.canonical_url,
	});

	return { applied: true, alias: site.alias, injected: true };
}

export type { LoadRegistryOptions };
