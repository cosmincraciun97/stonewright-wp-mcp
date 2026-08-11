/**
 * Versioned multi-site registry (schema v2) with atomic writes and v1 migration.
 */
import {
	chmodSync,
	closeSync,
	copyFileSync,
	existsSync,
	fsyncSync,
	mkdirSync,
	openSync,
	readFileSync,
	renameSync,
	statSync,
	unlinkSync,
	writeSync,
} from 'node:fs';
import { createHash, randomBytes } from 'node:crypto';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import {
	type CreateCredentialStoreOptions,
	CredentialError,
	createCredentialStore,
	makeCredentialRef,
	storeSiteSecret,
} from '../../credentials/index.js';
import {
	ConnectError,
	type ConfiguredMode,
	type SiteEnvironment,
	type SiteRecordV2,
	type SitesRegistryV1,
	type SitesRegistryV2,
} from './types.js';
import { aliasKey, sanitizeAlias } from './server-name.js';
import {
	defaultFallbackPolicy,
	defaultPreferredActiveMode,
	envModeToConfigured,
} from './mode-policy.js';

export interface RegistryPaths {
	sitesFile: string;
	homeDir: string;
}

export interface LoadRegistryOptions {
	sitesFile?: string | undefined;
	env?: NodeJS.ProcessEnv | undefined;
	homeDir?: string | undefined;
	/** When true, auto-migrate v1 → v2 in memory (does not write unless save is called). */
	autoMigrate?: boolean | undefined;
	credentials?: CreateCredentialStoreOptions | undefined;
	allowEnvRef?: boolean | undefined;
}

export function defaultSitesPath(env: NodeJS.ProcessEnv = process.env, home = homedir()): string {
	const fromEnv = (env['STONEWRIGHT_SITES_FILE'] ?? '').trim();
	if (fromEnv) return fromEnv;
	return join(home, '.stonewright', 'sites.json');
}

export function normalizeCanonicalUrl(raw: string): string {
	const trimmed = raw.trim().replace(/\/+$/, '');
	let parsed: URL;
	try {
		parsed = new URL(trimmed);
	} catch {
		throw new ConnectError('invalid_url', `Invalid site URL: ${raw}`);
	}
	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		throw new ConnectError('invalid_url', `Site URL must be http(s): ${raw}`);
	}
	const path = parsed.pathname === '/' ? '' : parsed.pathname.replace(/\/+$/, '');
	return `${parsed.protocol}//${parsed.host}${path}`;
}

export function urlFingerprint(canonicalUrl: string, environment: SiteEnvironment): string {
	const material = `${canonicalUrl.toLowerCase()}|${environment}`;
	const hash = createHash('sha256').update(material).digest('hex');
	return `sha256:${hash}`;
}

export function newSiteId(): string {
	// Time-sortable-ish id without external ULID dependency.
	const time = Date.now().toString(36);
	const rand = randomBytes(8).toString('hex');
	return `01${time}${rand}`.toUpperCase();
}

export function emptyRegistry(): SitesRegistryV2 {
	return {
		schema_version: 2,
		default_site_id: null,
		sites: [],
	};
}

export function detectSchemaVersion(raw: unknown): 1 | 2 | 0 {
	if (!raw || typeof raw !== 'object') return 0;
	const root = raw as Record<string, unknown>;
	if (root.schema_version === 2 && Array.isArray(root.sites)) return 2;
	if (root.sites && typeof root.sites === 'object' && !Array.isArray(root.sites)) return 1;
	// Empty-ish object with no sites
	if (Array.isArray(root.sites) && root.schema_version === undefined) return 0;
	return 0;
}

function sleepSync(ms: number): void {
	const end = Date.now() + ms;
	while (Date.now() < end) {
		// spin
	}
}

/**
 * Advisory lock via exclusive lockfile create, then run fn, then release.
 */
export function withRegistryLock<T>(sitesFile: string, fn: () => T, timeoutMs = 10_000): T {
	const lockPath = `${sitesFile}.lock`;
	const dir = dirname(sitesFile);
	mkdirSync(dir, { recursive: true, mode: 0o700 });
	const start = Date.now();
	while (Date.now() - start <= timeoutMs) {
		try {
			const fd = openSync(lockPath, 'wx', 0o600);
			try {
				writeSync(fd, `${process.pid}\n`);
				return fn();
			} finally {
				closeSync(fd);
				try {
					unlinkSync(lockPath);
				} catch {
					// ignore
				}
			}
		} catch (err) {
			const code = (err as NodeJS.ErrnoException).code;
			if (code !== 'EEXIST') throw err;
			sleepSync(40);
		}
	}
	throw new ConnectError(
		'registry_lock_timeout',
		`Timed out waiting for registry lock at ${lockPath}`,
	);
}

export interface AtomicWriteOptions {
	/**
	 * When true, keep a timestamped `.bak.*` next to the file after success.
	 * Default true for normal v2 saves. Migration from plaintext v1 should pass
	 * false so secrets are not left on disk after secrets move into the store.
	 */
	persistBackup?: boolean;
}

/**
 * Best-effort secure delete: overwrite with zeros then unlink.
 * Used to remove plaintext v1 migration rollback copies after success.
 */
export function secureUnlink(path: string): void {
	if (!existsSync(path)) return;
	try {
		const stat = statSync(path);
		const size = Math.max(stat.size, 1);
		const fd = openSync(path, 'r+');
		try {
			writeSync(fd, Buffer.alloc(size, 0));
			fsyncSync(fd);
		} finally {
			closeSync(fd);
		}
	} catch {
		// Fall through to unlink even if overwrite fails.
	}
	try {
		unlinkSync(path);
	} catch {
		// ignore
	}
}

/**
 * Atomic write: optional timestamped backup + temp + fsync + rename. Mode 0600.
 * Returns the backup path when a backup was created (caller may secureUnlink it).
 */
export function atomicWriteRegistry(
	sitesFile: string,
	registry: SitesRegistryV2,
	options: AtomicWriteOptions = {},
): string | null {
	const persistBackup = options.persistBackup !== false;
	const dir = dirname(sitesFile);
	mkdirSync(dir, { recursive: true, mode: 0o700 });
	if (process.platform !== 'win32') {
		try {
			chmodSync(dir, 0o700);
		} catch {
			// ignore
		}
	}

	let backupPath: string | null = null;
	if (existsSync(sitesFile)) {
		// Always stage a rollback copy while rewriting; delete on success when
		// persistBackup is false (v1→v2 migration must not leave plaintext).
		backupPath = `${sitesFile}.bak.${new Date().toISOString().replace(/[:.]/g, '-')}`;
		copyFileSync(sitesFile, backupPath);
		if (process.platform !== 'win32') {
			try {
				chmodSync(backupPath, 0o600);
			} catch {
				// ignore
			}
		}
	}

	const body = `${JSON.stringify(registry, null, 2)}\n`;
	const tmp = `${sitesFile}.${process.pid}.${Date.now()}.tmp`;
	const fd = openSync(tmp, 'w', 0o600);
	try {
		writeSync(fd, body);
		fsyncSync(fd);
	} finally {
		closeSync(fd);
	}
	if (process.platform !== 'win32') {
		chmodSync(tmp, 0o600);
	}
	try {
		renameSync(tmp, sitesFile);
	} catch (err) {
		// Restore from staged backup if rename failed.
		if (backupPath && existsSync(backupPath) && !existsSync(sitesFile)) {
			try {
				copyFileSync(backupPath, sitesFile);
			} catch {
				// ignore secondary failure
			}
		}
		throw err;
	}
	if (process.platform !== 'win32') {
		try {
			chmodSync(sitesFile, 0o600);
		} catch {
			// ignore
		}
	}

	if (backupPath && !persistBackup) {
		secureUnlink(backupPath);
		return null;
	}
	return backupPath;
}

export function readRawSitesFile(sitesFile: string): unknown {
	if (!existsSync(sitesFile)) return null;
	const text = readFileSync(sitesFile, 'utf8');
	try {
		return JSON.parse(text) as unknown;
	} catch (err) {
		throw new ConnectError(
			'invalid_json',
			`Invalid JSON in ${sitesFile}: ${err instanceof Error ? err.message : String(err)}`,
		);
	}
}

function parseSiteRecordV2(row: unknown, index: number): SiteRecordV2 {
	if (!row || typeof row !== 'object') {
		throw new ConnectError('invalid_site', `sites[${index}] must be an object`);
	}
	const r = row as Record<string, unknown>;
	const id = typeof r.id === 'string' ? r.id : '';
	const alias = typeof r.alias === 'string' ? r.alias : '';
	const canonical_url = typeof r.canonical_url === 'string' ? r.canonical_url : '';
	const username_hint = typeof r.username_hint === 'string' ? r.username_hint : '';
	const credential_ref = typeof r.credential_ref === 'string' ? r.credential_ref : '';
	if (!id || !alias || !canonical_url || !credential_ref) {
		throw new ConnectError(
			'invalid_site',
			`sites[${index}] requires id, alias, canonical_url, credential_ref`,
		);
	}
	const environment = (typeof r.environment === 'string' ? r.environment : 'other') as SiteEnvironment;
	const configured_mode = (typeof r.configured_mode === 'string'
		? r.configured_mode
		: 'auto') as ConfiguredMode;
	const clients =
		r.clients && typeof r.clients === 'object' && !Array.isArray(r.clients)
			? (r.clients as SiteRecordV2['clients'])
			: {};
	const disabled_tools = Array.isArray(r.disabled_tools)
		? r.disabled_tools.filter((x): x is string => typeof x === 'string')
		: undefined;

	const record: SiteRecordV2 = {
		id,
		alias,
		environment,
		canonical_url: normalizeCanonicalUrl(canonical_url),
		url_fingerprint:
			typeof r.url_fingerprint === 'string' && r.url_fingerprint
				? r.url_fingerprint
				: urlFingerprint(normalizeCanonicalUrl(canonical_url), environment),
		username_hint,
		credential_ref,
		auth_method: (typeof r.auth_method === 'string' ? r.auth_method : 'application-password') as SiteRecordV2['auth_method'],
		configured_mode:
			configured_mode === 'direct-only' || configured_mode === 'plugin-only' || configured_mode === 'auto'
				? configured_mode
				: 'auto',
		preferred_active_mode:
			r.preferred_active_mode === 'direct' || r.preferred_active_mode === 'plugin'
				? r.preferred_active_mode
				: defaultPreferredActiveMode(configured_mode),
		fallback_policy:
			r.fallback_policy === 'never' ||
			r.fallback_policy === 'always-direct' ||
			r.fallback_policy === 'direct-when-plugin-unavailable'
				? r.fallback_policy
				: defaultFallbackPolicy(configured_mode),
		companion_profile:
			typeof r.companion_profile === 'string' && r.companion_profile
				? r.companion_profile
				: 'essential-static',
		clients,
	};
	if (r.plugin_expectations && typeof r.plugin_expectations === 'object') {
		record.plugin_expectations = r.plugin_expectations as SiteRecordV2['plugin_expectations'];
	}
	if (r.last_verification && typeof r.last_verification === 'object') {
		record.last_verification = r.last_verification as SiteRecordV2['last_verification'];
	}
	if (disabled_tools) {
		record.disabled_tools = disabled_tools;
	}
	if (typeof r.created_at === 'string') {
		record.created_at = r.created_at;
	}
	if (typeof r.updated_at === 'string') {
		record.updated_at = r.updated_at;
	}
	return record;
}

export function parseRegistryV2(raw: unknown): SitesRegistryV2 {
	if (!raw || typeof raw !== 'object') {
		throw new ConnectError('invalid_registry', 'Registry root must be an object');
	}
	const root = raw as Record<string, unknown>;
	if (root.schema_version !== 2) {
		throw new ConnectError('invalid_registry', 'Expected schema_version 2');
	}
	if (!Array.isArray(root.sites)) {
		throw new ConnectError('invalid_registry', 'sites must be an array in schema v2');
	}
	const sites = root.sites.map((row, i) => parseSiteRecordV2(row, i));
	const default_site_id =
		typeof root.default_site_id === 'string' && sites.some((s) => s.id === root.default_site_id)
			? root.default_site_id
			: sites[0]?.id ?? null;
	return { schema_version: 2, default_site_id, sites };
}

/**
 * Extract plaintext password from a v1 site row (all known key aliases).
 */
function v1Password(row: SitesRegistryV1['sites'][string]): string {
	return (
		row.appPassword ??
		row.applicationPassword ??
		row.password ??
		row.PASS ??
		row.app_password ??
		''
	).trim();
}

function v1Username(row: SitesRegistryV1['sites'][string]): string {
	return (row.username ?? row.user ?? row.USER ?? '').trim();
}

function v1Url(row: SitesRegistryV1['sites'][string]): string {
	return (row.url ?? row.URL ?? '').trim();
}

/**
 * Lossless v1 → v2 migration.
 * Moves plaintext secrets into the credential store BEFORE writing v2.
 * On secure-store failure: throws and leaves the original file untouched.
 */
export function migrateV1ToV2(
	v1: SitesRegistryV1,
	options: {
		credentials?: CreateCredentialStoreOptions | undefined;
		allowEnvRef?: boolean | undefined;
		environment?: SiteEnvironment | undefined;
	} = {},
): SitesRegistryV2 {
	const sites: SiteRecordV2[] = [];
	const now = new Date().toISOString();
	const aliases = Object.keys(v1.sites ?? {});

	for (const alias of aliases) {
		const row = v1.sites[alias];
		if (!row) continue;
		const url = v1Url(row);
		const username = v1Username(row);
		const password = v1Password(row);
		if (!url || !username) {
			throw new ConnectError(
				'migration_incomplete',
				`v1 site "${alias}" is missing url or username; cannot migrate`,
			);
		}
		if (!password) {
			throw new ConnectError(
				'migration_incomplete',
				`v1 site "${alias}" has no password; cannot migrate securely`,
			);
		}

		let credential_ref: string;
		try {
			credential_ref = storeSiteSecret({
				alias,
				secret: password,
				purpose: 'app-password',
				...(options.credentials ? { options: options.credentials } : {}),
				allowEnvRef: options.allowEnvRef ?? false,
			});
		} catch (err) {
			if (err instanceof CredentialError) {
				throw new ConnectError(
					'secure_migration_failed',
					`Secure migration failed for "${alias}": ${err.message}. Original sites.json left untouched.`,
					{ alias, credential_code: err.code },
				);
			}
			throw err;
		}

		const environment = options.environment ?? 'other';
		const canonical_url = normalizeCanonicalUrl(url);
		const configured_mode: ConfiguredMode = 'auto';
		const id = newSiteId();
		const disabled_tools = Array.isArray(row.disabledTools)
			? row.disabledTools.filter((x): x is string => typeof x === 'string')
			: undefined;
		sites.push({
			id,
			alias: sanitizeAlias(alias) === aliasKey(alias) ? sanitizeAlias(alias) : alias,
			environment,
			canonical_url,
			url_fingerprint: urlFingerprint(canonical_url, environment),
			username_hint: username,
			credential_ref,
			auth_method: 'application-password',
			configured_mode,
			preferred_active_mode: defaultPreferredActiveMode(configured_mode),
			fallback_policy: defaultFallbackPolicy(configured_mode),
			companion_profile: 'essential-static',
			clients: {},
			...(disabled_tools ? { disabled_tools } : {}),
			created_at: now,
			updated_at: now,
		});
	}

	if (sites.length === 0) {
		return emptyRegistry();
	}

	const defaultAlias =
		typeof v1.default === 'string' && sites.some((s) => s.alias === v1.default || aliasKey(s.alias) === aliasKey(v1.default!))
			? v1.default
			: sites[0].alias;
	const defaultSite =
		sites.find((s) => s.alias === defaultAlias || aliasKey(s.alias) === aliasKey(defaultAlias)) ??
		sites[0];

	return {
		schema_version: 2,
		default_site_id: defaultSite.id,
		sites,
	};
}

export function loadRegistry(options: LoadRegistryOptions = {}): {
	registry: SitesRegistryV2;
	path: string;
	source: 'file' | 'empty';
	schema_was: 0 | 1 | 2;
	permissionWarning?: string;
} {
	const env = options.env ?? process.env;
	const home = options.homeDir ?? homedir();
	const path = options.sitesFile ?? defaultSitesPath(env, home);

	if (!existsSync(path)) {
		return { registry: emptyRegistry(), path, source: 'empty', schema_was: 0 };
	}

	const raw = readRawSitesFile(path);
	const version = detectSchemaVersion(raw);

	let permissionWarning: string | undefined;
	if (process.platform !== 'win32') {
		try {
			const mode = statSync(path).mode & 0o777;
			if (mode & 0o077) {
				permissionWarning = `Sites file ${path} permissions are ${mode.toString(8)}; recommended 0600`;
			}
		} catch {
			// ignore
		}
	}

	if (version === 2) {
		return {
			registry: parseRegistryV2(raw),
			path,
			source: 'file',
			schema_was: 2,
			...(permissionWarning ? { permissionWarning } : {}),
		};
	}

	if (version === 1) {
		if (options.autoMigrate) {
			const migrated = migrateV1ToV2(raw as SitesRegistryV1, {
				...(options.credentials ? { credentials: options.credentials } : {}),
			});
			return {
				registry: migrated,
				path,
				source: 'file',
				schema_was: 1,
				...(permissionWarning ? { permissionWarning } : {}),
			};
		}
		// Return an in-memory projection without moving secrets (runtime load path uses sites-config).
		const projected = projectV1AsV2WithoutSecretMove(raw as SitesRegistryV1);
		return {
			registry: projected,
			path,
			source: 'file',
			schema_was: 1,
			...(permissionWarning ? { permissionWarning } : {}),
		};
	}

	throw new ConnectError('invalid_registry', `Unrecognized sites registry schema in ${path}`);
}

/**
 * Project v1 into v2 shape for read-only tooling without touching the credential store.
 * credential_ref is a sentinel `legacy-plaintext://alias` resolved only by sites-config.
 */
export function projectV1AsV2WithoutSecretMove(v1: SitesRegistryV1): SitesRegistryV2 {
	const sites: SiteRecordV2[] = [];
	for (const [alias, row] of Object.entries(v1.sites ?? {})) {
		const url = v1Url(row);
		const username = v1Username(row);
		if (!url || !username) continue;
		const environment: SiteEnvironment = 'other';
		const canonical_url = normalizeCanonicalUrl(url);
		const id = `legacy-${aliasKey(alias)}`;
		const disabled_tools = Array.isArray(row.disabledTools)
			? row.disabledTools.filter((x): x is string => typeof x === 'string')
			: undefined;
		sites.push({
			id,
			alias,
			environment,
			canonical_url,
			url_fingerprint: urlFingerprint(canonical_url, environment),
			username_hint: username,
			credential_ref: `legacy-plaintext://${alias}`,
			auth_method: 'application-password',
			configured_mode: 'auto',
			preferred_active_mode: 'plugin',
			fallback_policy: 'direct-when-plugin-unavailable',
			companion_profile: 'essential-static',
			clients: {},
			...(disabled_tools ? { disabled_tools } : {}),
		});
	}
	const defaultAlias =
		typeof v1.default === 'string' && sites.some((s) => s.alias === v1.default)
			? v1.default
			: sites[0]?.alias;
	const defaultSite = sites.find((s) => s.alias === defaultAlias) ?? sites[0];
	return {
		schema_version: 2,
		default_site_id: defaultSite?.id ?? null,
		sites,
	};
}

export function saveRegistry(
	registry: SitesRegistryV2,
	options: {
		sitesFile?: string | undefined;
		env?: NodeJS.ProcessEnv | undefined;
		homeDir?: string | undefined;
	} = {},
): { path: string; backupPath: string | null } {
	const env = options.env ?? process.env;
	const home = options.homeDir ?? homedir();
	const path = options.sitesFile ?? defaultSitesPath(env, home);
	const backupPath = withRegistryLock(path, () => atomicWriteRegistry(path, registry));
	return { path, backupPath };
}

/**
 * Persist a v1→v2 migration to disk. Idempotent when already v2.
 * On credential failure, original file is not modified.
 */
export function migrateSitesFile(options: LoadRegistryOptions = {}): {
	path: string;
	migrated: boolean;
	site_count: number;
	backupPath: string | null;
} {
	const env = options.env ?? process.env;
	const home = options.homeDir ?? homedir();
	const path = options.sitesFile ?? defaultSitesPath(env, home);

	if (!existsSync(path)) {
		const empty = emptyRegistry();
		const { backupPath } = saveRegistry(empty, { sitesFile: path, env, homeDir: home });
		return { path, migrated: true, site_count: 0, backupPath };
	}

	return withRegistryLock(path, () => {
		const raw = readRawSitesFile(path);
		const version = detectSchemaVersion(raw);
		if (version === 2) {
			const reg = parseRegistryV2(raw);
			return { path, migrated: false, site_count: reg.sites.length, backupPath: null };
		}
		if (version !== 1) {
			throw new ConnectError('invalid_registry', `Cannot migrate unrecognized schema in ${path}`);
		}
		// Secrets move first (inside migrateV1ToV2). Only then write.
		// Do not persist a plaintext v1 .bak after success — secrets already left the file.
		const migrated = migrateV1ToV2(raw as SitesRegistryV1, {
			...(options.credentials ? { credentials: options.credentials } : {}),
			...(options.allowEnvRef !== undefined ? { allowEnvRef: options.allowEnvRef } : {}),
		});
		const backupPath = atomicWriteRegistry(path, migrated, { persistBackup: false });
		return { path, migrated: true, site_count: migrated.sites.length, backupPath };
	});
}

export function findSiteByAlias(registry: SitesRegistryV2, alias: string): SiteRecordV2 | undefined {
	const key = aliasKey(alias);
	return registry.sites.find((s) => aliasKey(s.alias) === key);
}

export function findSiteById(registry: SitesRegistryV2, id: string): SiteRecordV2 | undefined {
	return registry.sites.find((s) => s.id === id);
}

export function findDuplicateEndpoint(
	registry: SitesRegistryV2,
	canonicalUrl: string,
	environment: SiteEnvironment,
	excludeId?: string,
): SiteRecordV2 | undefined {
	const fp = urlFingerprint(normalizeCanonicalUrl(canonicalUrl), environment);
	return registry.sites.find((s) => s.url_fingerprint === fp && s.id !== excludeId);
}

export function assertAliasAvailable(
	registry: SitesRegistryV2,
	alias: string,
	opts: { replace?: boolean | undefined; excludeId?: string | undefined } = {},
): void {
	const existing = findSiteByAlias(registry, alias);
	if (!existing) return;
	if (opts.excludeId && existing.id === opts.excludeId) return;
	if (opts.replace) return;
	throw new ConnectError(
		'alias_exists',
		`Alias "${sanitizeAlias(alias)}" already exists (site id ${existing.id}). Pass --replace to overwrite.`,
		{
			existing_alias: existing.alias,
			existing_id: existing.id,
			clients: Object.keys(existing.clients),
		},
	);
}

export function upsertSite(
	registry: SitesRegistryV2,
	site: SiteRecordV2,
	opts: { replace?: boolean | undefined; makeDefault?: boolean | undefined } = {},
): SitesRegistryV2 {
	assertAliasAvailable(registry, site.alias, {
		...(opts.replace !== undefined ? { replace: opts.replace } : {}),
		excludeId: site.id,
	});
	const dup = findDuplicateEndpoint(registry, site.canonical_url, site.environment, site.id);
	if (dup) {
		throw new ConnectError(
			'duplicate_site',
			`Canonical URL + environment already registered as alias "${dup.alias}". Refusing to clone.`,
			{ existing_alias: dup.alias, existing_id: dup.id, environment: dup.environment },
		);
	}

	const sites = registry.sites.filter((s) => s.id !== site.id && aliasKey(s.alias) !== aliasKey(site.alias));
	// If replace by alias, drop the previous alias row.
	const nextSites = [...sites.filter((s) => aliasKey(s.alias) !== aliasKey(site.alias)), site];
	const default_site_id =
		opts.makeDefault || !registry.default_site_id
			? site.id
			: registry.default_site_id && nextSites.some((s) => s.id === registry.default_site_id)
				? registry.default_site_id
				: site.id;

	return {
		schema_version: 2,
		default_site_id,
		sites: nextSites,
	};
}

export function removeSite(registry: SitesRegistryV2, alias: string): {
	registry: SitesRegistryV2;
	removed: SiteRecordV2;
} {
	const existing = findSiteByAlias(registry, alias);
	if (!existing) {
		throw new ConnectError('unknown_alias', `No site with alias "${alias}"`);
	}
	const sites = registry.sites.filter((s) => s.id !== existing.id);
	const default_site_id =
		registry.default_site_id === existing.id ? sites[0]?.id ?? null : registry.default_site_id;
	return {
		registry: { schema_version: 2, default_site_id, sites },
		removed: existing,
	};
}

export function setDefaultSite(registry: SitesRegistryV2, alias: string): SitesRegistryV2 {
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		throw new ConnectError('unknown_alias', `No site with alias "${alias}"`);
	}
	return { ...registry, default_site_id: site.id };
}

export function buildSiteRecord(input: {
	alias: string;
	url: string;
	username: string;
	credential_ref: string;
	environment?: SiteEnvironment | undefined;
	configured_mode?: string | undefined;
	companion_profile?: string | undefined;
	id?: string | undefined;
	disabled_tools?: string[] | undefined;
	clients?: SiteRecordV2['clients'] | undefined;
}): SiteRecordV2 {
	const environment = input.environment ?? 'other';
	const canonical_url = normalizeCanonicalUrl(input.url);
	const configured_mode = envModeToConfigured(
		typeof input.configured_mode === 'string' ? input.configured_mode : 'auto',
	);
	const now = new Date().toISOString();
	return {
		id: input.id ?? newSiteId(),
		alias: sanitizeAlias(input.alias),
		environment,
		canonical_url,
		url_fingerprint: urlFingerprint(canonical_url, environment),
		username_hint: input.username,
		credential_ref: input.credential_ref,
		auth_method: 'application-password',
		configured_mode,
		preferred_active_mode: defaultPreferredActiveMode(configured_mode),
		fallback_policy: defaultFallbackPolicy(configured_mode),
		companion_profile: input.companion_profile ?? 'essential-static',
		clients: input.clients ?? {},
		...(input.disabled_tools ? { disabled_tools: input.disabled_tools } : {}),
		created_at: now,
		updated_at: now,
	};
}

/** Resolve plaintext password for a v1 legacy sentinel or real credential_ref. */
export function resolveSitePassword(
	site: SiteRecordV2,
	options: {
		sitesFile?: string | undefined;
		credentials?: CreateCredentialStoreOptions | undefined;
		/** Raw v1 file content for legacy-plaintext:// resolution */
		legacyV1?: SitesRegistryV1 | null | undefined;
	} = {},
): string {
	if (site.credential_ref.startsWith('legacy-plaintext://')) {
		const alias = site.credential_ref.slice('legacy-plaintext://'.length);
		const v1 = options.legacyV1;
		if (v1?.sites?.[alias]) {
			const pw = v1Password(v1.sites[alias]);
			if (pw) return pw;
		}
		// Re-read file if needed
		if (options.sitesFile && existsSync(options.sitesFile)) {
			const raw = readRawSitesFile(options.sitesFile);
			if (detectSchemaVersion(raw) === 1) {
				const row = (raw as SitesRegistryV1).sites?.[alias];
				if (row) {
					const pw = v1Password(row);
					if (pw) return pw;
				}
			}
		}
		throw new ConnectError(
			'credential_missing',
			`Legacy plaintext credential for "${alias}" not found`,
		);
	}

	const store = createCredentialStore(options.credentials);
	const secret = store.get(site.credential_ref);
	if (secret) return secret;

	// env:// may need EnvCredentialStore even when platform store is selected
	if (site.credential_ref.startsWith('env://') || site.credential_ref.startsWith('env:')) {
		const envStore = createCredentialStore({
			...options.credentials,
			prefer: 'env',
		});
		const fromEnv = envStore.get(site.credential_ref);
		if (fromEnv) return fromEnv;
	}

	// Try memory if injected
	if (options.credentials?.store) {
		const fromInjected = options.credentials.store.get(site.credential_ref);
		if (fromInjected) return fromInjected;
	}

	throw new ConnectError(
		'credential_missing',
		`Could not resolve credential_ref for site "${site.alias}". Re-run connect add or migrate.`,
	);
}

export function makeEnvCredentialRef(envVar: string): string {
	if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(envVar)) {
		throw new ConnectError('invalid_env_var', `Invalid credential env var: ${envVar}`);
	}
	return `env://${envVar}`;
}

export { makeCredentialRef, storeSiteSecret, createCredentialStore };
