import { existsSync, readFileSync, statSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

export interface SiteEntry {
	url: string;
	username: string;
	appPassword: string;
	disabledTools?: string[];
}

export interface SitesConfig {
	default: string;
	sites: Record<string, SiteEntry>;
	source: 'file' | 'env';
	path?: string;
	permissionWarning?: string;
}

export interface ResolvedSite {
	alias: string;
	url: string;
	restBase: string;
	username: string;
	appPassword: string;
	disabledTools: string[];
}

export interface LoadSitesConfigOptions {
	env?: NodeJS.ProcessEnv;
	sitesFile?: string;
}

function normalizeUrl(raw: string): string {
	const trimmed = raw.trim().replace(/\/+$/, '');
	let parsed: URL;
	try {
		parsed = new URL(trimmed);
	} catch {
		throw new Error(`Invalid site URL: ${raw}`);
	}
	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		throw new Error(`Site URL must be http(s): ${raw}`);
	}
	return `${parsed.protocol}//${parsed.host}${parsed.pathname === '/' ? '' : parsed.pathname.replace(/\/+$/, '')}`;
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

function parseSiteEntry(alias: string, value: unknown): SiteEntry {
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

function loadFromFile(path: string): SitesConfig {
	let raw: string;
	try {
		raw = readFileSync(path, 'utf8');
	} catch (err) {
		throw new Error(`Unable to read sites file ${path}: ${err instanceof Error ? err.message : String(err)}`);
	}

	let parsed: unknown;
	try {
		parsed = JSON.parse(raw) as unknown;
	} catch (err) {
		throw new Error(`Invalid JSON in ${path}: ${err instanceof Error ? err.message : String(err)}`);
	}

	if (!parsed || typeof parsed !== 'object') {
		throw new Error(`Invalid sites config in ${path}: root must be an object`);
	}

	const root = parsed as Record<string, unknown>;
	const sitesRaw = root.sites;
	if (!sitesRaw || typeof sitesRaw !== 'object') {
		throw new Error(`Invalid sites config in ${path}: "sites" object is required`);
	}

	const sites: Record<string, SiteEntry> = {};
	for (const [alias, value] of Object.entries(sitesRaw as Record<string, unknown>)) {
		sites[alias] = parseSiteEntry(alias, value);
	}
	if (Object.keys(sites).length === 0) {
		throw new Error(`Invalid sites config in ${path}: at least one site is required`);
	}

	const defaultAlias =
		typeof root.default === 'string' && root.default in sites
			? root.default
			: Object.keys(sites)[0];

	let permissionWarning: string | undefined;
	if (process.platform !== 'win32') {
		try {
			const mode = statSync(path).mode & 0o777;
			if (mode & 0o077) {
				permissionWarning = `Sites file ${path} permissions are ${mode.toString(8)}; recommended 0600`;
			}
		} catch {
			// ignore permission probe failures
		}
	}

	return {
		default: defaultAlias,
		sites,
		source: 'file',
		path,
		...(permissionWarning ? { permissionWarning } : {}),
	};
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

	const entry = parseSiteEntry('default', { url, username, appPassword });
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
		return loadFromFile(path);
	}

	return loadFromEnv(env);
}

export function resolveSite(config: SitesConfig, alias?: string): ResolvedSite {
	const key = (alias ?? config.default).trim() || config.default;
	const entry = config.sites[key];
	if (!entry) {
		throw new Error(`Unknown site alias "${key}". Known: ${Object.keys(config.sites).join(', ')}`);
	}
	return {
		alias: key,
		url: entry.url,
		restBase: restBaseFor(entry.url),
		username: entry.username,
		appPassword: entry.appPassword,
		disabledTools: entry.disabledTools ?? [],
	};
}
