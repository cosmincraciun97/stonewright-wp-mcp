/**
 * Site credentials loader for Direct mode.
 *
 * Supports:
 *  - schema v2 multi-site registry (preferred)
 *  - legacy schema v1 { default, sites: { alias: { url, username, appPassword } } }
 *  - STONEWRIGHT_WP_* environment single-site fallback
 *
 * Runtime ResolvedSite always exposes username + appPassword for WP REST.
 * v2 secrets are resolved lazily via credential_ref only for the requested alias.
 */
import { existsSync, readFileSync, statSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import {
	type CreateCredentialStoreOptions,
	createCredentialStore,
	resolveCredentialSecret,
} from '../credentials/index.js';
import {
	detectSchemaVersion,
	findSiteByAlias,
	findSiteById,
	normalizeCanonicalUrl,
	parseRegistryV2,
	projectV1AsV2WithoutSecretMove,
	resolveSitePassword,
	type LoadRegistryOptions,
} from '../cli/connect/registry.js';
import type { ConfiguredMode, SiteRecordV2, SitesRegistryV1, SitesRegistryV2 } from '../cli/connect/types.js';

export interface SiteEntry {
	url: string;
	username: string;
	/**
	 * Plaintext Application Password when already resolved (v1 / env).
	 * Empty string for deferred v2 credential_ref resolution.
	 */
	appPassword: string;
	disabledTools?: string[] | undefined;
	/** v2 metadata */
	id?: string | undefined;
	environment?: string | undefined;
	configuredMode?: ConfiguredMode | undefined;
	credentialRef?: string | undefined;
}

export interface SitesConfig {
	default: string;
	sites: Record<string, SiteEntry>;
	source: 'file' | 'env';
	path?: string | undefined;
	permissionWarning?: string | undefined;
	schemaVersion?: 1 | 2 | undefined;
	/** Full v2 registry when loaded from schema v2 (or projected). */
	registry?: SitesRegistryV2 | undefined;
	/**
	 * Lazy secret resolution for v2 sites. Only the alias passed to
	 * resolveSite() triggers a credential-store read.
	 */
	_secretContext?: SecretResolveContext | undefined;
}

interface SecretResolveContext {
	options: LoadSitesConfigOptions;
	path: string;
	raw: unknown;
	/** Cache of alias → resolved password for this process load. */
	cache: Map<string, string>;
}

export interface ResolvedSite {
	alias: string;
	url: string;
	restBase: string;
	username: string;
	appPassword: string;
	disabledTools: string[];
	siteId?: string | undefined;
	environment?: string | undefined;
	configuredMode?: ConfiguredMode | undefined;
}

export interface LoadSitesConfigOptions {
	env?: NodeJS.ProcessEnv | undefined;
	sitesFile?: string | undefined;
	credentials?: CreateCredentialStoreOptions | undefined;
}

function normalizeUrl(raw: string): string {
	return normalizeCanonicalUrl(raw);
}

function restBaseFor(url: string): string {
	return `${url}/wp-json`;
}

function defaultSitesPath(env: NodeJS.ProcessEnv): string {
	const fromEnv = (env['STONEWRIGHT_SITES_FILE'] ?? '').trim();
	if (fromEnv) {
		return fromEnv;
	}
	return join(homedir(), '.stonewright', 'sites.json');
}

function parseSiteEntryV1(alias: string, value: unknown): SiteEntry {
	if (!value || typeof value !== 'object') {
		throw new Error(`sites.${alias} must be an object`);
	}
	const row = value as Record<string, unknown>;
	const url = typeof row.url === 'string' ? row.url : typeof row.URL === 'string' ? row.URL : '';
	const username =
		typeof row.username === 'string'
			? row.username
			: typeof row.USER === 'string'
				? row.USER
				: typeof row.user === 'string'
					? row.user
					: '';
	const appPassword =
		typeof row.appPassword === 'string'
			? row.appPassword
			: typeof row.applicationPassword === 'string'
				? row.applicationPassword
				: typeof row.PASS === 'string'
					? row.PASS
					: typeof row.password === 'string'
						? row.password
						: typeof row.app_password === 'string'
							? row.app_password
							: '';
	if (!url) {
		throw new Error(`sites.${alias}.url is required`);
	}
	if (!username) {
		throw new Error(`sites.${alias}.username is required`);
	}
	if (!appPassword) {
		throw new Error(`sites.${alias}.appPassword is required`);
	}
	normalizeUrl(url);
	const disabledTools = Array.isArray(row.disabledTools)
		? row.disabledTools.filter((item): item is string => typeof item === 'string')
		: undefined;
	return {
		url: normalizeUrl(url),
		username,
		appPassword,
		...(disabledTools ? { disabledTools } : {}),
	};
}

function permissionWarningFor(path: string): string | undefined {
	if (process.platform === 'win32') return undefined;
	try {
		const mode = statSync(path).mode & 0o777;
		if (mode & 0o077) {
			return `Sites file ${path} permissions are ${mode.toString(8)}; recommended 0600`;
		}
	} catch {
		// ignore
	}
	return undefined;
}

function resolveV2Password(
	site: SiteRecordV2,
	ctx: SecretResolveContext,
): string {
	const cached = ctx.cache.get(site.alias);
	if (cached !== undefined) {
		return cached;
	}
	try {
		const password = resolveSitePassword(site, {
			sitesFile: ctx.path,
			...(ctx.options.credentials ? { credentials: ctx.options.credentials } : {}),
			legacyV1: detectSchemaVersion(ctx.raw) === 1 ? (ctx.raw as SitesRegistryV1) : null,
		});
		ctx.cache.set(site.alias, password);
		return password;
	} catch {
		// Fall through to env for this alias
		const env = ctx.options.env ?? process.env;
		const envPass = (
			env['STONEWRIGHT_WP_APP_PASSWORD'] ??
			env['STONEWRIGHT_WP_PASSWORD'] ??
			env['WP_APP_PASSWORD'] ??
			''
		).trim();
		if (envPass) {
			ctx.cache.set(site.alias, envPass);
			return envPass;
		}
		throw new Error(
			`Unable to resolve credentials for site "${site.alias}" (credential_ref). Run stonewright connect migrate or connect add.`,
		);
	}
}

/**
 * Build SitesConfig metadata for v2 without resolving any OS secrets.
 * Passwords are loaded only when resolveSite() is called for a specific alias.
 */
function registryToSitesConfig(
	registry: SitesRegistryV2,
	path: string,
	options: LoadSitesConfigOptions,
	raw: unknown,
	schemaVersion: 1 | 2,
): SitesConfig {
	const sites: Record<string, SiteEntry> = {};
	for (const site of registry.sites) {
		const entry: SiteEntry = {
			url: site.canonical_url,
			username: site.username_hint,
			// Deferred — resolveSite() loads only the selected alias.
			appPassword: '',
			id: site.id,
			environment: site.environment,
			configuredMode: site.configured_mode,
			credentialRef: site.credential_ref,
		};
		if (site.disabled_tools) {
			entry.disabledTools = site.disabled_tools;
		}
		sites[site.alias] = entry;
	}
	if (Object.keys(sites).length === 0) {
		throw new Error(`Invalid sites config in ${path}: at least one site is required`);
	}
	const defaultSite =
		(registry.default_site_id ? findSiteById(registry, registry.default_site_id) : undefined) ??
		registry.sites[0];
	const permissionWarning = permissionWarningFor(path);
	return {
		default: defaultSite?.alias ?? Object.keys(sites)[0],
		sites,
		source: 'file',
		path,
		schemaVersion,
		registry,
		_secretContext: {
			options,
			path,
			raw,
			cache: new Map(),
		},
		...(permissionWarning ? { permissionWarning } : {}),
	};
}

function loadFromFile(path: string, options: LoadSitesConfigOptions): SitesConfig {
	let rawText: string;
	try {
		rawText = readFileSync(path, 'utf8');
	} catch (err) {
		throw new Error(`Unable to read sites file ${path}: ${err instanceof Error ? err.message : String(err)}`);
	}

	let parsed: unknown;
	try {
		parsed = JSON.parse(rawText) as unknown;
	} catch (err) {
		throw new Error(`Invalid JSON in ${path}: ${err instanceof Error ? err.message : String(err)}`);
	}

	if (!parsed || typeof parsed !== 'object') {
		throw new Error(`Invalid sites config in ${path}: root must be an object`);
	}

	const version = detectSchemaVersion(parsed);

	if (version === 2) {
		const registry = parseRegistryV2(parsed);
		return registryToSitesConfig(registry, path, options, parsed, 2);
	}

	if (version === 1) {
		// Prefer resolving secrets from plaintext v1 entries directly (runtime BC).
		const root = parsed as Record<string, unknown>;
		const sitesRaw = root.sites;
		if (!sitesRaw || typeof sitesRaw !== 'object') {
			throw new Error(`Invalid sites config in ${path}: "sites" object is required`);
		}
		const sites: Record<string, SiteEntry> = {};
		for (const [alias, value] of Object.entries(sitesRaw as Record<string, unknown>)) {
			sites[alias] = parseSiteEntryV1(alias, value);
		}
		if (Object.keys(sites).length === 0) {
			throw new Error(`Invalid sites config in ${path}: at least one site is required`);
		}
		const defaultAlias =
			typeof root.default === 'string' && root.default in sites
				? root.default
				: Object.keys(sites)[0];
		const permissionWarning = permissionWarningFor(path);
		const projected = projectV1AsV2WithoutSecretMove(parsed as SitesRegistryV1);
		return {
			default: defaultAlias,
			sites,
			source: 'file',
			path,
			schemaVersion: 1,
			registry: projected,
			...(permissionWarning ? { permissionWarning } : {}),
		};
	}

	throw new Error(`Invalid sites config in ${path}: unrecognized schema`);
}

function loadFromEnv(env: NodeJS.ProcessEnv): SitesConfig {
	const url = (env['STONEWRIGHT_WP_URL'] ?? env['WP_API_URL'] ?? '').trim();
	const username = (env['STONEWRIGHT_WP_USERNAME'] ?? env['WP_USERNAME'] ?? '').trim();
	const appPassword = (
		env['STONEWRIGHT_WP_APP_PASSWORD'] ??
		env['STONEWRIGHT_WP_PASSWORD'] ??
		env['WP_APP_PASSWORD'] ??
		''
	).trim();

	if (!url || !username || !appPassword) {
		throw new Error(
			'Direct mode credentials missing. Provide ~/.stonewright/sites.json or STONEWRIGHT_WP_URL + STONEWRIGHT_WP_USERNAME + STONEWRIGHT_WP_APP_PASSWORD.',
		);
	}

	const entry = parseSiteEntryV1('default', { url, username, appPassword });
	return {
		default: 'default',
		sites: { default: entry },
		source: 'env',
	};
}

export function loadSitesConfig(options: LoadSitesConfigOptions = {}): SitesConfig {
	const env = options.env ?? process.env;
	const path = options.sitesFile ?? defaultSitesPath(env);

	if (existsSync(path)) {
		return loadFromFile(path, options);
	}

	return loadFromEnv(env);
}

export function resolveSite(config: SitesConfig, alias?: string): ResolvedSite {
	const key = (alias ?? config.default).trim() || config.default;
	// Case-insensitive alias match for v2 ergonomics
	let entry = config.sites[key];
	let resolvedAlias = key;
	if (!entry) {
		const lower = key.toLowerCase();
		const found = Object.entries(config.sites).find(([a]) => a.toLowerCase() === lower);
		if (found) {
			resolvedAlias = found[0];
			entry = found[1];
		}
	}
	if (!entry) {
		// Try registry default id
		if (config.registry) {
			const byAlias = findSiteByAlias(config.registry, key);
			if (byAlias && config.sites[byAlias.alias]) {
				entry = config.sites[byAlias.alias];
				resolvedAlias = byAlias.alias;
			}
		}
	}
	if (!entry) {
		throw new Error(`Unknown site alias "${key}". Known: ${Object.keys(config.sites).join(', ')}`);
	}

	let appPassword = entry.appPassword;
	if (!appPassword) {
		const ctx = config._secretContext;
		const record = config.registry
			? findSiteByAlias(config.registry, resolvedAlias) ??
				(entry.id ? findSiteById(config.registry, entry.id) : undefined)
			: undefined;
		if (ctx && record) {
			appPassword = resolveV2Password(record, ctx);
			// Cache on the entry for subsequent resolveSite calls in this process.
			entry.appPassword = appPassword;
		} else if (entry.credentialRef && ctx) {
			// Fallback without full SiteRecordV2
			const synthetic: SiteRecordV2 = {
				id: entry.id ?? resolvedAlias,
				alias: resolvedAlias,
				environment: (entry.environment as SiteRecordV2['environment']) ?? 'other',
				canonical_url: entry.url,
				url_fingerprint: '',
				username_hint: entry.username,
				credential_ref: entry.credentialRef,
				auth_method: 'application-password',
				configured_mode: entry.configuredMode ?? 'auto',
				preferred_active_mode: 'plugin',
				fallback_policy: 'direct-when-plugin-unavailable',
				companion_profile: 'essential-static',
				clients: {},
			};
			appPassword = resolveV2Password(synthetic, ctx);
			entry.appPassword = appPassword;
		}
	}
	if (!appPassword) {
		throw new Error(
			`Unable to resolve credentials for site "${resolvedAlias}". Run stonewright connect migrate or connect add.`,
		);
	}

	return {
		alias: resolvedAlias,
		url: entry.url,
		restBase: restBaseFor(entry.url),
		username: entry.username,
		appPassword,
		disabledTools: entry.disabledTools ?? [],
		siteId: entry.id,
		environment: entry.environment,
		configuredMode: entry.configuredMode,
	};
}

export type { LoadRegistryOptions, SiteRecordV2, SitesRegistryV2, ConfiguredMode };
export { createCredentialStore, resolveCredentialSecret };
