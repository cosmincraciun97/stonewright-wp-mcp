/**
 * Connect CLI command implementations (add/list/use/verify/repair/remove/migrate/detect-client).
 */
import { homedir } from 'node:os';
import {
	buildStdioServerEntry,
	detectClients,
	getClientAdapter,
	listClientCatalog,
	type ClientAdapter,
} from '../clients/index.js';
import {
	type CreateCredentialStoreOptions,
	MemoryCredentialStore,
	storeSiteSecret,
} from '../../credentials/index.js';
import { APP_VERSION } from '../../version.js';
import { configuredModeToEnv, mayProbePlugin, resolveActiveMode } from './mode-policy.js';
import {
	buildSiteRecord,
	findSiteByAlias,
	findSiteById,
	loadRegistry,
	makeEnvCredentialRef,
	migrateSitesFile,
	removeSite as registryRemoveSite,
	resolveSitePassword,
	saveRegistry,
	setDefaultSite,
	upsertSite,
	type LoadRegistryOptions,
} from './registry.js';
import { mcpServerName } from './server-name.js';
import {
	ConnectError,
	type ConnectReceipt,
	type SiteEnvironment,
	type SiteRecordV2,
	type SitesRegistryV2,
} from './types.js';

export interface ConnectContext {
	sitesFile?: string | undefined;
	homeDir?: string | undefined;
	env?: NodeJS.ProcessEnv | undefined;
	credentials?: CreateCredentialStoreOptions | undefined;
	/** Skip live HTTP auth (tests). */
	skipAuth?: boolean | undefined;
	fetchImpl?: typeof fetch | undefined;
	packageSpec?: string | undefined;
	/** Override client config path for add/repair (tests and advanced use). */
	clientConfigPath?: string | undefined;
}

function ctxPaths(ctx: ConnectContext): LoadRegistryOptions {
	const out: LoadRegistryOptions = {};
	if (ctx.sitesFile !== undefined) out.sitesFile = ctx.sitesFile;
	if (ctx.homeDir !== undefined) out.homeDir = ctx.homeDir;
	if (ctx.env !== undefined) out.env = ctx.env;
	if (ctx.credentials !== undefined) out.credentials = ctx.credentials;
	return out;
}

function companionPackageSpec(version = APP_VERSION): string {
	return `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v${version}/stonewright-companion-${version}.tgz`;
}

function writeOut(msg: string): void {
	process.stdout.write(`${msg}\n`);
}

function writeErr(msg: string): void {
	process.stderr.write(`${msg}\n`);
}

function receiptLines(r: ConnectReceipt): string {
	const parts = [
		`ok=${r.ok}`,
		`site_id=${r.site_id}`,
		`site_alias=${r.site_alias}`,
		`environment=${r.environment}`,
		`configured_mode=${r.configured_mode}`,
	];
	if (r.active_mode) parts.push(`active_mode=${r.active_mode}`);
	parts.push(r.message);
	return parts.join(' | ');
}

async function verifyAuth(
	url: string,
	username: string,
	password: string,
	fetchImpl: typeof fetch,
): Promise<{ ok: boolean; detail: string }> {
	const base = url.replace(/\/+$/, '');
	const auth = Buffer.from(`${username}:${password.replace(/\s+/g, '')}`).toString('base64');
	try {
		const res = await fetchImpl(`${base}/wp-json/wp/v2/users/me`, {
			headers: { Authorization: `Basic ${auth}`, Accept: 'application/json' },
		});
		if (res.ok) {
			return { ok: true, detail: `Authenticated (HTTP ${res.status})` };
		}
		return { ok: false, detail: `Auth failed HTTP ${res.status}` };
	} catch (err) {
		return { ok: false, detail: err instanceof Error ? err.message : String(err) };
	}
}

function takenServerNames(registry: SitesRegistryV2, excludeSiteId?: string): Set<string> {
	const taken = new Set<string>();
	for (const site of registry.sites) {
		if (excludeSiteId && site.id === excludeSiteId) continue;
		for (const binding of Object.values(site.clients)) {
			if (binding.server_name) taken.add(binding.server_name);
		}
	}
	return taken;
}

export function connectDetectClient(ctx: ConnectContext = {}): number {
	const home = ctx.homeDir ?? homedir();
	const detected = detectClients(home);
	const catalog = listClientCatalog();
	writeOut(JSON.stringify({ detected, catalog }, null, 2));
	return 0;
}

export interface ConnectAddInput {
	alias: string;
	url: string;
	username: string;
	password?: string | undefined;
	environment?: SiteEnvironment | undefined;
	mode?: string | undefined;
	client?: string | undefined;
	clientConfigPath?: string | undefined;
	replace?: boolean | undefined;
	makeDefault?: boolean | undefined;
	/** Use env://VAR instead of OS store. */
	credentialEnv?: string | undefined;
	companionProfile?: string | undefined;
	/** When set, skip interactive prompts. */
	yes?: boolean | undefined;
}

export async function connectAdd(input: ConnectAddInput, ctx: ConnectContext = {}): Promise<number> {
	const loaded = loadRegistry(ctxPaths(ctx));
	let registry = loaded.registry;

	// If file is still v1 on disk, migrate first (secure) so we don't mix schemas.
	if (loaded.schema_was === 1 && loaded.source === 'file') {
		try {
			migrateSitesFile({ ...ctxPaths(ctx), allowEnvRef: Boolean(input.credentialEnv) });
			registry = loadRegistry(ctxPaths(ctx)).registry;
		} catch (err) {
			if (err instanceof ConnectError && err.code === 'secure_migration_failed') {
				writeErr(err.message);
				return 1;
			}
			// If migration fails because secure store unavailable and user gave credentialEnv, retry
			if (input.credentialEnv) {
				migrateSitesFile({ ...ctxPaths(ctx), allowEnvRef: true });
				registry = loadRegistry(ctxPaths(ctx)).registry;
			} else {
				throw err;
			}
		}
	}

	const existing = findSiteByAlias(registry, input.alias);
	if (existing && !input.replace) {
		writeErr(
			`Alias "${input.alias}" already exists (id=${existing.id}). Clients: ${Object.keys(existing.clients).join(', ') || '(none)'}. Pass --replace to overwrite.`,
		);
		return 1;
	}
	if (existing && input.replace) {
		writeOut(
			`Replacing alias "${existing.alias}" (id=${existing.id}). Affected clients: ${Object.keys(existing.clients).join(', ') || '(none)'}.`,
		);
	}

	const password = (input.password ?? '').trim();
	if (!password && !input.credentialEnv) {
		writeErr('Application Password is required (or pass --credential-env VAR).');
		return 1;
	}

	if (!ctx.skipAuth && password) {
		const auth = await verifyAuth(input.url, input.username, password, ctx.fetchImpl ?? fetch);
		if (!auth.ok) {
			writeErr(`Auth failed: ${auth.detail}`);
			return 1;
		}
		writeOut(auth.detail);
	}

	let credential_ref: string;
	try {
		if (input.credentialEnv) {
			credential_ref = makeEnvCredentialRef(input.credentialEnv);
			if (password) {
				// Seed process env for this session / tests
				const env = ctx.env ?? process.env;
				env[input.credentialEnv] = password;
				if (ctx.credentials?.store) {
					ctx.credentials.store.set(credential_ref, password);
				}
			}
		} else {
			credential_ref = storeSiteSecret({
				alias: input.alias,
				secret: password,
				purpose: 'app-password',
				options: ctx.credentials ?? { allowMemoryFallback: false },
				allowEnvRef: false,
			});
		}
	} catch (err) {
		const msg = err instanceof Error ? err.message : String(err);
		writeErr(`Secure credential store failed: ${msg}`);
		writeErr('Registry left unchanged. Use --credential-env VAR for headless environments.');
		return 1;
	}

	const site = buildSiteRecord({
		alias: input.alias,
		url: input.url,
		username: input.username,
		credential_ref,
		environment: input.environment ?? 'other',
		configured_mode: input.mode ?? 'auto',
		companion_profile: input.companionProfile ?? 'essential-static',
		...(existing?.id ? { id: existing.id } : {}),
		clients: existing?.clients ?? {},
	});

	try {
		registry = upsertSite(registry, site, {
			...(input.replace !== undefined ? { replace: input.replace } : {}),
			makeDefault: input.makeDefault ?? registry.sites.length === 0,
		});
	} catch (err) {
		if (err instanceof ConnectError) {
			writeErr(`${err.code}: ${err.message}`);
			if (err.details) writeErr(JSON.stringify(err.details));
			return 1;
		}
		throw err;
	}

	// Bind client if requested
	let clientApply: Record<string, unknown> | undefined;
	if (input.client) {
		const bindCtx: ConnectContext = { ...ctx };
		const configPath = input.clientConfigPath ?? ctx.clientConfigPath;
		if (configPath !== undefined) bindCtx.clientConfigPath = configPath;
		const result = applyClientBinding(registry, site, input.client, bindCtx);
		registry = result.registry;
		clientApply = result.receipt;
		// refresh site from registry
		const updated = findSiteById(registry, site.id);
		if (updated) Object.assign(site, updated);
	}

	const { path, backupPath } = saveRegistry(registry, ctxPaths(ctx));

	const receipt: ConnectReceipt = {
		ok: true,
		site_id: site.id,
		site_alias: site.alias,
		environment: site.environment,
		configured_mode: site.configured_mode,
		active_mode: site.preferred_active_mode,
		message: `Site registered in ${path}`,
		details: {
			credential_ref: site.credential_ref.startsWith('env://')
				? site.credential_ref
				: site.credential_ref.replace(/^(keychain|memory|credman|secretservice):\/\/.*$/, '$1://***'),
			registry_backup: backupPath,
			client: clientApply,
		},
	};
	writeOut(receiptLines(receipt));
	if (clientApply) {
		writeOut(JSON.stringify({ client: clientApply }, null, 2));
	}
	return 0;
}

function applyClientBinding(
	registry: SitesRegistryV2,
	site: SiteRecordV2,
	clientId: string,
	ctx: ConnectContext,
): { registry: SitesRegistryV2; receipt: Record<string, unknown> } {
	const adapter = getClientAdapter(clientId);
	if (!adapter) {
		throw new ConnectError(
			'client_unsupported',
			`Client "${clientId}" has no implemented adapter (community/untested). Use detect-client to list support tiers.`,
		);
	}
	const home = ctx.homeDir ?? homedir();
	const configPath = ctx.clientConfigPath ?? adapter.defaultConfigPath(home);
	const taken = takenServerNames(registry, site.id);
	// Preserve existing server name for this client+site when present
	const existingName = site.clients[adapter.id]?.server_name;
	const serverName =
		existingName && (!taken.has(existingName) || site.clients[adapter.id]?.server_name === existingName)
			? existingName
			: mcpServerName(site.alias, site.id, taken);

	const entry = buildStdioServerEntry({
		serverName,
		packageSpec: ctx.packageSpec ?? companionPackageSpec(),
		siteAlias: site.alias,
		modeEnv: configuredModeToEnv(site.configured_mode),
		toolProfile: site.companion_profile,
	});

	const applied = adapter.upsert(configPath, entry);
	const now = new Date().toISOString();
	const nextSite: SiteRecordV2 = {
		...site,
		clients: {
			...site.clients,
			[adapter.id]: {
				server_name: serverName,
				install_profile: site.companion_profile,
				config_path: configPath,
				last_applied_at: now,
			},
		},
		updated_at: now,
	};
	const nextRegistry = upsertSite(registry, nextSite, { replace: true });
	return {
		registry: nextRegistry,
		receipt: {
			client: adapter.id,
			server_name: serverName,
			config_path: configPath,
			backup_path: applied.backupPath,
			created: applied.created,
			diff: applied.diff,
			support_tier: adapter.supportTier,
		},
	};
}

export function connectList(ctx: ConnectContext = {}): number {
	const { registry, path, schema_was } = loadRegistry(ctxPaths(ctx));
	const rows = registry.sites.map((s) => ({
		id: s.id,
		alias: s.alias,
		environment: s.environment,
		canonical_url: s.canonical_url,
		configured_mode: s.configured_mode,
		preferred_active_mode: s.preferred_active_mode,
		is_default: s.id === registry.default_site_id,
		clients: Object.keys(s.clients),
		username_hint: s.username_hint,
	}));
	writeOut(
		JSON.stringify(
			{
				path,
				schema_version: 2,
				schema_was,
				default_site_id: registry.default_site_id,
				sites: rows,
			},
			null,
			2,
		),
	);
	return 0;
}

export function connectUse(alias: string, ctx: ConnectContext = {}): number {
	const { registry } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}
	const next = setDefaultSite(registry, alias);
	const { path } = saveRegistry(next, ctxPaths(ctx));
	const receipt: ConnectReceipt = {
		ok: true,
		site_id: site.id,
		site_alias: site.alias,
		environment: site.environment,
		configured_mode: site.configured_mode,
		active_mode: site.preferred_active_mode,
		message: `Default site set to "${site.alias}" in ${path}`,
	};
	writeOut(receiptLines(receipt));
	return 0;
}

export async function connectVerify(
	alias: string,
	opts: { client?: string } = {},
	ctx: ConnectContext = {},
): Promise<number> {
	const { registry, path } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}

	let password: string | null = null;
	try {
		password = resolveSitePassword(site, {
			sitesFile: path,
			...(ctx.credentials ? { credentials: ctx.credentials } : {}),
		});
	} catch (err) {
		writeErr(err instanceof Error ? err.message : String(err));
		return 1;
	}

	const checks: Array<{ id: string; ok: boolean; detail: string }> = [];

	// Mode policy structural check
	const probeAllowed = mayProbePlugin(site.configured_mode);
	checks.push({
		id: 'mode_policy',
		ok: true,
		detail: `configured_mode=${site.configured_mode}; may_probe_plugin=${probeAllowed}`,
	});

	if (!ctx.skipAuth && password) {
		const auth = await verifyAuth(site.canonical_url, site.username_hint, password, ctx.fetchImpl ?? fetch);
		checks.push({ id: 'rest_auth', ok: auth.ok, detail: auth.detail });
	} else {
		checks.push({ id: 'rest_auth', ok: true, detail: 'skipped' });
	}

	// Optional plugin probe (never for direct-only). skipAuth only skips REST auth, not mode probe.
	let active = resolveActiveMode({
		configured: site.configured_mode,
		fallbackPolicy: site.fallback_policy,
		probePresent: null,
	});
	if (!probeAllowed) {
		checks.push({
			id: 'plugin_probe',
			ok: true,
			detail: 'skipped (direct-only never probes plugin)',
		});
	} else {
		try {
			const endpoint = `${site.canonical_url}/wp-json/mcp/stonewright`;
			const res = await (ctx.fetchImpl ?? fetch)(endpoint, {
				method: 'GET',
				headers: { Accept: 'application/json' },
			});
			const present = res.status !== 404;
			active = resolveActiveMode({
				configured: site.configured_mode,
				fallbackPolicy: site.fallback_policy,
				probePresent: present,
			});
			checks.push({
				id: 'plugin_probe',
				ok: site.configured_mode !== 'plugin-only' || present,
				detail: `HTTP ${res.status}; ${active.reason}`,
			});
		} catch (err) {
			checks.push({
				id: 'plugin_probe',
				ok: site.configured_mode !== 'plugin-only',
				detail: err instanceof Error ? err.message : String(err),
			});
		}
	}

	if (opts.client) {
		const adapter = getClientAdapter(opts.client);
		if (!adapter) {
			checks.push({
				id: 'client_config',
				ok: false,
				detail: `No adapter for client "${opts.client}"`,
			});
		} else {
			const binding = site.clients[adapter.id];
			const configPath = binding?.config_path ?? adapter.defaultConfigPath(ctx.homeDir ?? homedir());
			const serverName = binding?.server_name ?? mcpServerName(site.alias, site.id, new Set());
			const v = adapter.verify(configPath, serverName);
			checks.push({
				id: 'client_config',
				ok: v.ok,
				detail: v.detail,
			});
		}
	}

	const ok = checks.every((c) => c.ok);
	const now = new Date().toISOString();
	const nextSite: SiteRecordV2 = {
		...site,
		last_verification: {
			at: now,
			ok,
			...(opts.client ? { client: opts.client } : {}),
			detail: checks.map((c) => `${c.id}:${c.ok ? 'ok' : 'fail'}`).join(','),
			active_mode: active.active,
		},
		updated_at: now,
	};
	const nextRegistry = upsertSite(registry, nextSite, { replace: true });
	saveRegistry(nextRegistry, ctxPaths(ctx));

	const receipt: ConnectReceipt = {
		ok,
		site_id: site.id,
		site_alias: site.alias,
		environment: site.environment,
		configured_mode: site.configured_mode,
		active_mode: active.active,
		message: ok ? 'Verify passed' : 'Verify failed',
		details: { checks, transition: active.transition },
	};
	writeOut(receiptLines(receipt));
	writeOut(JSON.stringify({ checks }, null, 2));
	return ok ? 0 : 1;
}

export function connectRepair(
	alias: string,
	opts: { client?: string } = {},
	ctx: ConnectContext = {},
): number {
	const { registry } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}
	const clientId = opts.client ?? Object.keys(site.clients)[0];
	if (!clientId) {
		writeErr('No client binding on this site. Pass --client <id>.');
		return 1;
	}
	try {
		const { registry: next, receipt } = applyClientBinding(registry, site, clientId, ctx);
		saveRegistry(next, ctxPaths(ctx));
		const r: ConnectReceipt = {
			ok: true,
			site_id: site.id,
			site_alias: site.alias,
			environment: site.environment,
			configured_mode: site.configured_mode,
			active_mode: site.preferred_active_mode,
			message: `Repaired client binding for ${clientId}`,
			details: receipt,
		};
		writeOut(receiptLines(r));
		writeOut(JSON.stringify(receipt, null, 2));
		return 0;
	} catch (err) {
		if (err instanceof ConnectError) {
			writeErr(`${err.code}: ${err.message}`);
			return 1;
		}
		throw err;
	}
}

export function connectRemove(
	alias: string,
	opts: { client?: string } = {},
	ctx: ConnectContext = {},
): number {
	const { registry } = loadRegistry(ctxPaths(ctx));
	const site = findSiteByAlias(registry, alias);
	if (!site) {
		writeErr(`Unknown alias "${alias}"`);
		return 1;
	}

	if (opts.client) {
		// Remove only one client binding + its named server entry
		const adapter = getClientAdapter(opts.client);
		const binding = site.clients[opts.client];
		if (adapter && binding) {
			const configPath = binding.config_path ?? adapter.defaultConfigPath(ctx.homeDir ?? homedir());
			adapter.remove(configPath, binding.server_name);
		}
		const rest = { ...site.clients };
		delete rest[opts.client];
		const nextSite: SiteRecordV2 = { ...site, clients: rest, updated_at: new Date().toISOString() };
		const next = upsertSite(registry, nextSite, { replace: true });
		saveRegistry(next, ctxPaths(ctx));
		writeOut(
			receiptLines({
				ok: true,
				site_id: site.id,
				site_alias: site.alias,
				environment: site.environment,
				configured_mode: site.configured_mode,
				message: `Removed client "${opts.client}" from site "${site.alias}"`,
			}),
		);
		return 0;
	}

	// Remove entire site; clean client entries we know about
	for (const [clientId, binding] of Object.entries(site.clients)) {
		const adapter = getClientAdapter(clientId);
		if (adapter && binding.config_path) {
			try {
				adapter.remove(binding.config_path, binding.server_name);
			} catch {
				// best effort
			}
		}
	}

	const { registry: next, removed } = registryRemoveSite(registry, alias);
	saveRegistry(next, ctxPaths(ctx));
	writeOut(
		receiptLines({
			ok: true,
			site_id: removed.id,
			site_alias: removed.alias,
			environment: removed.environment,
			configured_mode: removed.configured_mode,
			message: `Removed site "${removed.alias}"`,
		}),
	);
	return 0;
}

export function connectMigrate(ctx: ConnectContext & { allowEnvRef?: boolean | undefined } = {}): number {
	try {
		const result = migrateSitesFile({
			...ctxPaths(ctx),
			...(ctx.allowEnvRef !== undefined ? { allowEnvRef: ctx.allowEnvRef } : {}),
		});
		writeOut(
			JSON.stringify(
				{
					ok: true,
					path: result.path,
					migrated: result.migrated,
					site_count: result.site_count,
					backup: result.backupPath,
				},
				null,
				2,
			),
		);
		return 0;
	} catch (err) {
		if (err instanceof ConnectError) {
			writeErr(`${err.code}: ${err.message}`);
			return 1;
		}
		throw err;
	}
}

/** Test helper: force memory credential store. */
export function testCredentialOptions(store = new MemoryCredentialStore()): CreateCredentialStoreOptions {
	return { store, prefer: 'memory', allowMemoryFallback: true };
}

export type { ClientAdapter };
